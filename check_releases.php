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
$cron_secret        = $config['CRON_SECRET'] ?? '';
$repositories_file  = __DIR__ . '/' . ($config['REPOSITORIES_FILE'] ?? 'repositories.json');
$log_file           = __DIR__ . '/app_log.json';
$history_file       = __DIR__ . '/release_history.json';

// HTTP protection: require token when not running as CLI
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    if (empty($cron_secret) || !hash_equals($cron_secret, $provided)) {
        http_response_code(403);
        die("Forbidden\n");
    }
}

if (empty($telegram_bot_token) || empty($telegram_chat_id)) {
    die("Error: Telegram Bot Token and Chat ID must be set in settings.php\n");
}

// Optional: check only a single repo by index (passed as --id=N from CLI)
$repo_filter_id = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $repo_filter_id = (int)$m[1];
    }
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

// Returns ['tag', 'url', 'released_at'] or null.
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
            return [
                'tag'         => $release['tag_name'],
                'url'         => $release['html_url'],
                'released_at' => $release['published_at'] ?? null,
            ];
        }
        return null;
    } catch (RequestException $e) {
        error_log("GitHub API Error for {$repo_name}: " . $e->getMessage());
        return null;
    }
}

// Returns ['tag', 'url', 'released_at'] or null.
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
                'tag'         => $latest_release['tag_name'],
                'url'         => "https://gitlab.com/{$repo_name}/-/releases/" . $latest_release['tag_name'],
                'released_at' => $latest_release['released_at'] ?? null,
            ];
        }
        return null;
    } catch (RequestException $e) {
        error_log("GitLab API Error for {$repo_name}: " . $e->getMessage());
        return null;
    }
}

// Returns ['tag', 'digest', 'released_at'] or null.
// Digest allows detecting updates to floating tags (latest, stable, lts)
// even when the tag name itself does not change.
function get_latest_docker_tag(Client $client, $repo_name, $tag_pattern = '') {
    $api_repo_name = preg_replace('/^_\//', 'library/', $repo_name);
    if (strpos($api_repo_name, '/') === false) {
        $api_repo_name = 'library/' . $api_repo_name;
    }
    try {
        $page_size = !empty($tag_pattern) ? 100 : 50;
        $response = $client->get("https://hub.docker.com/v2/repositories/{$api_repo_name}/tags/?page_size={$page_size}");
        $data = json_decode($response->getBody(), true);
        if (empty($data['results'])) {
            return null;
        }
        $latest_tag         = null;
        $latest_digest      = null;
        $latest_date        = 0;
        $latest_updated_str = null;

        // Build glob regex once if pattern contains '#' (version wildcard).
        // '#' expands to [\d.]+ so it matches any dot-separated version number:
        //   #.#-apache  →  /^[\d.]+\.[\d.]+-apache$/
        //   matches: 8.5-apache, 8.5.3-apache, 9.0-apache — not 8.5-fpm
        $pattern_regex = null;
        if (!empty($tag_pattern) && strpos($tag_pattern, '#') !== false) {
            $parts = explode('#', $tag_pattern);
            $pattern_regex = '/^' . implode('[\d.]+', array_map(
                function ($p) { return preg_quote($p, '/'); },
                $parts
            )) . '$/';
        }

        foreach ($data['results'] as $tag) {
            $tag_name = $tag['name'];

            // In auto-mode skip 'latest' — it is not a version number.
            // When a pattern is set the user decides; tag_pattern='latest'
            // enables tracking that tag via digest changes.
            if ($tag_name === 'latest' && empty($tag_pattern)) continue;

            // Pattern matching (no pre-release filter — pattern is the sole control)
            if (!empty($tag_pattern)) {
                if ($pattern_regex !== null) {
                    // '#' glob: e.g. '#.#-apache' → /^\d+\.\d+\-apache$/
                    if (!preg_match($pattern_regex, $tag_name)) continue;
                } else {
                    // Plain substring match (backward-compatible)
                    if (strpos($tag_name, $tag_pattern) === false) continue;
                }
            }
            $tag_date = strtotime($tag['last_updated']);
            if ($tag_date > $latest_date) {
                $latest_date        = $tag_date;
                $latest_tag         = $tag_name;
                $latest_digest      = $tag['digest'] ?? null;
                $latest_updated_str = $tag['last_updated'] ?? null;
            }
        }
        if ($latest_tag === null) {
            return null;
        }
        return [
            'tag'         => $latest_tag,
            'digest'      => $latest_digest,
            'released_at' => $latest_updated_str,
        ];
    } catch (RequestException $e) {
        error_log("Docker Hub API Error for {$repo_name}: " . $e->getMessage());
        return null;
    }
}

// --- Main Logic ---
$label = $repo_filter_id !== null ? "repo #{$repo_filter_id}" : 'todos los repos';
log_entry('info', "Iniciando verificación ({$label})...");

$repos = file_exists($repositories_file) ? json_decode(file_get_contents($repositories_file), true) : [];
if (!$repos) $repos = [];

$client    = new Client();
$now       = date('Y-m-d H:i:s');
$new_count = 0;

foreach ($repos as $index => &$repo) {
    if ($repo_filter_id !== null && $index !== $repo_filter_id) {
        continue;
    }

    $repo_name = $repo['name'];
    $repo_type = $repo['type'] ?? 'github';
    $latest_release_data = null;
    $release_tag         = null;
    $release_digest      = null;
    $release_date        = null;
    $check_status        = 'ok';

    try {
        if ($repo_type === 'github') {
            $latest_release_data = get_latest_github_release($client, $repo_name, $github_token);
        } elseif ($repo_type === 'gitlab') {
            $latest_release_data = get_latest_gitlab_release($client, $repo_name);
        } elseif ($repo_type === 'docker') {
            $latest_release_data = get_latest_docker_tag($client, $repo_name, $repo['tag_pattern'] ?? '');
        }

        if ($latest_release_data) {
            $release_tag    = $latest_release_data['tag']         ?? null;
            $release_digest = $latest_release_data['digest']      ?? null;
            $release_date   = $latest_release_data['released_at'] ?? null;
        }

        $tag_changed    = $release_tag !== null && $repo['last_seen_release'] !== $release_tag;
        $digest_changed = $release_digest !== null
            && !empty($repo['last_seen_release'])          // skip on first run
            && ($repo['last_seen_digest'] ?? null) !== $release_digest;

        if ($release_tag === null) {
            $check_status = 'error';
            log_entry('error', "No se pudo obtener la versión de {$repo_name}");
        } elseif ($tag_changed || $digest_changed) {
            $check_status = 'new';
            $log_detail = $tag_changed
                ? $release_tag
                : "{$release_tag} (digest actualizado)";
            log_entry('success', "Nuevo release para {$repo_name}: {$log_detail}");
            $new_count++;

            $release_url = $latest_release_data['url'] ?? null;
            record_release($repo_name, $repo_type, $release_tag, $release_url);

            $type_emoji = ['github' => '🐙', 'gitlab' => '🦊', 'docker' => '🐋'];
            $emoji      = $type_emoji[$repo_type] ?? '📦';
            $date_str   = !empty($release_date) ? "\n📅 " . substr($release_date, 0, 10) : '';

            $message = sprintf(
                "🔔 <b>Nuevo Release Detectado</b>\n──────────────────────\n%s <b>%s</b>\n📦 <b>%s</b>%s",
                $emoji,
                htmlspecialchars($repo_name),
                htmlspecialchars($release_tag),
                $date_str
            );
            if (!$tag_changed && $digest_changed) {
                $message .= "\n<i>Misma etiqueta, contenido actualizado</i>";
            }
            if (($repo_type === 'github' || $repo_type === 'gitlab') && !empty($release_url)) {
                $message .= sprintf("\n\n<a href=\"%s\">→ Ver Release</a>", $release_url);
            }
            if ($repo_type === 'docker') {
                $message .= sprintf(
                    "\n\n<code>docker pull %s:%s</code>",
                    htmlspecialchars($repo_name),
                    htmlspecialchars($release_tag)
                );
            }

            send_telegram_message($message, $telegram_bot_token, $telegram_chat_id);
            $repo['last_seen_release'] = $release_tag;
            $repo['last_release_date'] = $release_date;
            if ($release_digest !== null) {
                $repo['last_seen_digest'] = $release_digest;
            }
        } else {
            log_entry('info', "Sin cambios en {$repo_name} ({$release_tag})");
            // Keep digest and date fresh even when there is no new release
            if ($release_digest !== null) {
                $repo['last_seen_digest'] = $release_digest;
            }
            if ($release_date !== null && empty($repo['last_release_date'])) {
                $repo['last_release_date'] = $release_date;
            }
        }
    } catch (Exception $e) {
        $check_status = 'error';
        log_entry('error', "Error procesando {$repo_name}: " . $e->getMessage());
    }

    $repo['last_checked'] = $now;
    $repo['check_status'] = $check_status;
}
unset($repo);

file_put_contents($repositories_file, json_encode($repos, JSON_PRETTY_PRINT));
log_entry('info', "Verificación completada. {$new_count} nuevo(s) release(s) detectado(s).");
