<?php
require_once 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// --- Configuration Loading ---
$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    die("Error: config.json not found. Please configure the application via settings.php\n");
}
$config = json_decode(file_get_contents($config_file), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON in config.json\n");
}

$telegram_bot_token = $config['TELEGRAM_BOT_TOKEN'] ?? '';
$telegram_chat_id   = $config['TELEGRAM_CHAT_ID'] ?? '';
$github_token       = $config['GITHUB_TOKEN'] ?? '';
$repositories_file  = __DIR__ . '/' . ($config['REPOSITORIES_FILE'] ?? 'repositories.json');
$log_file           = __DIR__ . '/app_log.json';
$history_file       = __DIR__ . '/release_history.json';

if (empty($telegram_bot_token) || empty($telegram_chat_id)) {
    die("Error: Telegram Bot Token and Chat ID must be set in settings.php\n");
}

// --- Logging & History ---

function log_entry($level, $message) {
    global $log_file;
    $logs = [];
    if (file_exists($log_file)) {
        $logs = json_decode(file_get_contents($log_file), true) ?? [];
    }
    $logs[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level'     => $level,
        'message'   => $message,
    ];
    if (count($logs) > 300) {
        $logs = array_slice($logs, -300);
    }
    file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT));
    echo "[{$level}] {$message}\n";
}

function record_release($repo_name, $type, $tag, $url = null) {
    global $history_file;
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true) ?? [];
    }
    $history[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'repo'      => $repo_name,
        'type'      => $type,
        'version'   => $tag,
        'url'       => $url,
    ];
    if (count($history) > 500) {
        $history = array_slice($history, -500);
    }
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

// --- API Functions ---

function send_telegram_message($message, $token, $chat_id) {
    $client = new Client();
    $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $token);
    try {
        $client->post($url, [
            'json' => [
                'chat_id'    => $chat_id,
                'text'       => $message,
                'parse_mode' => 'HTML'
            ]
        ]);
    } catch (RequestException $e) {
        error_log('Telegram API Error: ' . $e->getMessage());
    }
}

function get_latest_github_release(Client $client, $repo_name, $github_token = '') {
    $headers = ['Accept' => 'application/vnd.github.v3+json', 'User-Agent' => 'telegram-notifier'];
    if (!empty($github_token)) {
        $headers['Authorization'] = 'Bearer ' . $github_token;
    }
    try {
        $response = $client->get("https://api.github.com/repos/{$repo_name}/releases/latest", [
            'headers' => $headers
        ]);
        $release = json_decode($response->getBody(), true);
        if (isset($release['tag_name']) && isset($release['html_url'])) {
            return ['tag' => $release['tag_name'], 'url' => $release['html_url']];
        }
        return null;
    } catch (RequestException $e) {
        error_log("GitHub API Error for {$repo_name}: " . $e->getMessage());
        return null;
    }
}

function get_latest_gitlab_release(Client $client, $repo_name) {
    $encoded_repo_name = urlencode($repo_name);
    try {
        $response = $client->get("https://gitlab.com/api/v4/projects/{$encoded_repo_name}/releases?order_by=released_at&sort=desc&per_page=1");
        $releases = json_decode($response->getBody(), true);
        if (empty($releases)) {
            return null;
        }
        $latest_release = $releases[0];
        if (isset($latest_release['tag_name'])) {
            return [
                'tag' => $latest_release['tag_name'],
                'url' => "https://gitlab.com/{$repo_name}/-/releases/" . $latest_release['tag_name']
            ];
        }
        return null;
    } catch (RequestException $e) {
        error_log("GitLab API Error for {$repo_name}: " . $e->getMessage());
        return null;
    }
}

function get_latest_docker_tag(Client $client, $repo_name) {
    $api_repo_name = $repo_name;
    if (strpos($repo_name, '/') === false) {
        $api_repo_name = 'library/' . $repo_name;
    }
    try {
        $response = $client->get("https://hub.docker.com/v2/repositories/{$api_repo_name}/tags/?page_size=25");
        $data = json_decode($response->getBody(), true);
        if (empty($data['results'])) {
            return null;
        }
        $latest_tag = null;
        $latest_date = 0;
        $pre_release_keywords = ['alpha', 'beta', 'rc', 'dev', 'test', 'snapshot', 'nightly', 'sha256', 'fastcgi', 'standalone', 'cgi', 'fpm-alpine', 'fpm', 'apache'];
        foreach ($data['results'] as $tag) {
            $tag_name = $tag['name'];
            if ($tag_name === 'latest') continue;
            $is_pre_release = false;
            foreach ($pre_release_keywords as $keyword) {
                if (strpos($tag_name, $keyword) !== false) {
                    $is_pre_release = true;
                    break;
                }
            }
            if ($is_pre_release) continue;
            $tag_date = strtotime($tag['last_updated']);
            if ($tag_date > $latest_date) {
                $latest_date = $tag_date;
                $latest_tag = $tag_name;
            }
        }
        return $latest_tag;
    } catch (RequestException $e) {
        error_log("Docker Hub API Error for {$repo_name}: " . $e->getMessage());
        return null;
    }
}

// --- Main Logic ---
log_entry('info', 'Iniciando verificación de releases...');

$repos = file_exists($repositories_file) ? json_decode(file_get_contents($repositories_file), true) : [];
if (!$repos) $repos = [];

$client  = new Client();
$now     = date('Y-m-d H:i:s');
$new_count = 0;

foreach ($repos as &$repo) {
    $repo_name = $repo['name'];
    $repo_type = $repo['type'] ?? 'github';
    $latest_release_data = null;
    $release_tag  = null;
    $check_status = 'ok';

    try {
        if ($repo_type === 'github') {
            $latest_release_data = get_latest_github_release($client, $repo_name, $github_token);
        } elseif ($repo_type === 'gitlab') {
            $latest_release_data = get_latest_gitlab_release($client, $repo_name);
        } elseif ($repo_type === 'docker') {
            $release_tag = get_latest_docker_tag($client, $repo_name);
        }

        if ($latest_release_data) {
            $release_tag = $latest_release_data['tag'] ?? null;
        }

        if ($release_tag === null) {
            $check_status = 'error';
            log_entry('error', "No se pudo obtener la versión de {$repo_name}");
        } elseif ($repo['last_seen_release'] !== $release_tag) {
            $check_status = 'new';
            log_entry('success', "Nuevo release para {$repo_name}: {$release_tag}");
            $new_count++;

            $release_url = $latest_release_data['url'] ?? null;
            record_release($repo_name, $repo_type, $release_tag, $release_url);

            $message = sprintf(
                "<b>Nuevo Release Detectado!</b>\n\nRepositorio: <b>%s</b>\nTipo: %s\nVersión: <b>%s</b>",
                htmlspecialchars($repo_name),
                ucfirst($repo_type),
                htmlspecialchars($release_tag)
            );
            if (($repo_type === 'github' || $repo_type === 'gitlab') && !empty($latest_release_data['url'])) {
                $message .= sprintf("\n\n<a href=\"%s\">Ver Release</a>", $latest_release_data['url']);
            }
            if ($repo_type === 'docker') {
                $message .= sprintf("\n\nComando de actualización:\n<code>docker pull %s:%s</code>", htmlspecialchars($repo_name), htmlspecialchars($release_tag));
            }

            send_telegram_message($message, $telegram_bot_token, $telegram_chat_id);
            $repo['last_seen_release'] = $release_tag;
        } else {
            log_entry('info', "Sin cambios en {$repo_name} ({$release_tag})");
        }
    } catch (Exception $e) {
        $check_status = 'error';
        log_entry('error', "Error procesando {$repo_name}: " . $e->getMessage());
    }

    $repo['last_checked']  = $now;
    $repo['check_status']  = $check_status;
}
unset($repo);

file_put_contents($repositories_file, json_encode($repos, JSON_PRETTY_PRINT));
log_entry('info', "Verificación completada. {$new_count} nuevo(s) release(s) detectado(s).");
