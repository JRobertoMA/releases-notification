<?php
/**
 * Verificación de releases: consulta GitHub, GitLab, Docker Hub y registros OCI y avisa por Telegram.
 *
 * Solo por consola (no se sirve por web: está fuera de public/ y además responde 404 fuera del CLI).
 * Usa siempre un PHP 8 con su ruta completa: en IONOS `php` a secas es PHP 4.4.
 *
 *   IONOS:  /usr/bin/php8.4-cli <ruta>/check_releases.php
 *   Docker: docker exec -u www-data php-apache php /var/www/html/telegram-notification/check_releases.php
 *
 *   php check_releases.php                         todos los repos
 *   php check_releases.php --repo=a1b2c3[,d4e5f6]  solo esos repos (id estable de repositories.json)
 *   php check_releases.php --id=N                  por posición en repositories.json (forma antigua)
 *   php check_releases.php --job=<id> [--repo=…]   lo lanza run_check.php para verificar en segundo plano
 *
 * Concurrencia: cada repo se guarda en cuanto termina, releyendo repositories.json bajo bloqueo (RepoStore),
 * así que editar, agregar o borrar repos durante una verificación ya no se pierde. Las verificaciones
 * completas y los jobs toman además data/worker.lock: solo corre una a la vez.
 */
if (PHP_SAPI !== 'cli') {
    // El cron por HTTP (?token=) se eliminó: en IONOS se usa crontab por SSH (SECURITY_AUDIT.md §8)
    http_response_code(404);
    exit;
}

// Todo lo que cree PHP (data/, sesiones, el lock del worker) nace solo para el propietario: 600/700.
// En IONOS el checkout queda legible por «otros» y el servidor es compartido (SECURITY_AUDIT.md, v1.0.1).
umask(0077);

require_once __DIR__ . '/vendor/autoload.php';

use App\AppConfig;
use App\Database;
use App\DockerHub;
use App\Env;
use App\EventLog;
use App\Jobs;
use App\Files;
use App\OciRegistry;
use App\RepoStore;
use App\RunHealth;
use App\TelegramClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// --- Configuración: secretos y entorno en .env, ajustes web en la tabla settings (App\AppConfig) ---
Env::load(__DIR__);

// Misma zona horaria que la web (includes/bootstrap.php). El cron de IONOS ignora el php.ini propio y el
// servidor está en America/New_York: sin esto, web y cron escribirían horas distintas.
date_default_timezone_set(AppConfig::timezone());

if (!Database::isConfigured()) {
    die("Error: falta configurar la base de datos en .env (ver .env.example)\n");
}
try {
    if (!Database::hasSchema()) {
        die("Error: faltan las tablas. Ejecuta cli/migrate.php\n");
    }
} catch (PDOException $e) {
    die('Error: no se pudo conectar a la base de datos: ' . $e->getMessage() . "\n");
}

OciRegistry::setAllowedHosts((array) AppConfig::get('OCI_REGISTRIES'));

$telegram_chat_id   = (string) AppConfig::get('TELEGRAM_CHAT_ID');
$github_token       = (string) AppConfig::get('GITHUB_TOKEN');

// --- Arguments ---
$repo_ids     = null;   // null = todos
$legacy_index = null;
$job_id       = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--repo=([0-9a-f,]+)$/', $arg, $m)) {
        $repo_ids = array_values(array_filter(explode(',', $m[1]), [RepoStore::class, 'isValidId']));
    } elseif (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $legacy_index = (int) $m[1];
    } elseif (preg_match('/^--job=([0-9]{8}-[0-9]{6}-[0-9a-f]{4})$/', $arg, $m)) {
        $job_id = $m[1];
    }
}

$store = new RepoStore();
$jobs  = new Jobs();

if (TelegramClient::mode() === null || empty($telegram_chat_id)) {
    if ($job_id !== null) {
        $jobs->update($job_id, ['status' => 'failed', 'message' => 'Falta configurar el bot de Telegram.', 'finished_at' => date('c')]);
    }
    die("Error: faltan BOT_BACKEND_URL y BOT_PROJECT_KEY (o TELEGRAM_BOT_TOKEN) en .env, o el chat de Telegram en Configuración\n");
}

// --- Logging & History ---

function log_entry($level, $message) {
    EventLog::log($level, $message);
    echo "[{$level}] {$message}\n";
}

function record_release($repo_name, $type, $tag, $url = null) {
    EventLog::release($repo_name, $type, $tag, $url);
}

// --- API Functions ---

// A través del backend del bot (o directo con el token, de respaldo): App\TelegramClient
function send_telegram_message($message, $chat_id, $repo_id) {
    $r = TelegramClient::send($chat_id, $message, $repo_id);
    if (!$r['ok']) {
        log_entry('warning', 'No se pudo enviar el aviso por Telegram: ' . $r['error']);
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

function build_release_message(array $repo, array $outcome) {
    $repo_name  = $repo['name'];
    $repo_type  = $repo['type'] ?? 'github';
    $tag        = $outcome['tag'];
    $type_emoji = ['github' => '🐙', 'gitlab' => '🦊', 'docker' => '🐋', 'oci' => '📦'];
    $emoji      = $type_emoji[$repo_type] ?? '📦';
    $date_ts    = !empty($outcome['date']) ? strtotime($outcome['date']) : false;
    $date_str   = $date_ts !== false ? "\n📅 " . date('Y-m-d', $date_ts) : '';  // en APP_TIMEZONE, como Repo::isOutdated()

    $message = sprintf(
        "🔔 <b>Nuevo Release Detectado</b>\n──────────────────────\n%s <b>%s</b>\n📦 <b>%s</b>%s",
        $emoji,
        htmlspecialchars($repo_name),
        htmlspecialchars($tag),
        $date_str
    );
    if (!$outcome['tag_changed']) {
        $message .= "\n<i>Misma etiqueta, contenido actualizado</i>";
    }
    if (($repo_type === 'github' || $repo_type === 'gitlab') && !empty($outcome['url'])) {
        $message .= sprintf("\n\n<a href=\"%s\">→ Ver Release</a>", $outcome['url']);
    }
    if ($repo_type === 'oci' && ($package_url = OciRegistry::webUrl($repo_name))) {
        $message .= sprintf("\n\n<a href=\"%s\">→ Ver paquete</a>", htmlspecialchars($package_url));
    }
    if ($repo_type === 'docker' || $repo_type === 'oci') {
        $message .= sprintf(
            "\n\n<code>docker pull %s:%s</code>",
            htmlspecialchars($repo_name),
            htmlspecialchars($tag)
        );
    }
    return $message;
}

// --- Targets ---
$repos = $store->all();
if ($legacy_index !== null) {
    $repo_ids = isset($repos[$legacy_index]) ? [$repos[$legacy_index]['id']] : [];
}
if ($job_id !== null && $repo_ids === null) {
    $job = $jobs->get($job_id);
    $repo_ids = $job['repo_ids'] ?? null;
}
$targets = $repo_ids === null
    ? $repos
    : array_values(array_filter($repos, fn ($r) => in_array($r['id'], $repo_ids, true)));
$is_full = $repo_ids === null;

// --- Un solo worker para verificaciones completas y jobs ---
$worker_lock = null;
if ($is_full || $job_id !== null) {
    $worker_lock = Files::openLock(__DIR__ . '/data/worker.lock');
    if (!flock($worker_lock, LOCK_EX | LOCK_NB)) {
        $msg = 'Ya hay una verificación en curso; se omite esta.';
        if ($job_id !== null) {
            $jobs->update($job_id, ['status' => 'busy', 'message' => $msg, 'finished_at' => date('c')]);
        }
        log_entry('info', $msg);
        exit(0);
    }
}

// Las verificaciones completas del cron/HTTP también registran un job: así la web muestra su progreso
$source = $job_id !== null ? 'web' : 'cron';
if ($job_id === null && $is_full) {
    $job_id = $jobs->create($source, null, count($targets));
}
if ($job_id !== null) {
    $jobs->update($job_id, ['status' => 'running', 'total' => count($targets), 'started_at' => date('c')]);
}

// --- Main Logic ---
$label = $is_full
    ? 'todos los repos'
    : (count($targets) === 1 ? $targets[0]['name'] : count($targets) . ' repos');
log_entry('info', "Iniciando verificación ({$label})...");

$client      = new Client();
$started_at  = date('Y-m-d H:i:s');
$new_count   = 0;
$error_count = 0;
$done        = 0;

foreach ($targets as $snapshot) {
    $repo_id   = $snapshot['id'];
    $repo_name = $snapshot['name'];
    $repo_type = $snapshot['type'] ?? 'github';
    if ($job_id !== null) {
        $jobs->update($job_id, ['current' => $repo_name]);
    }

    // 1) Consulta a la API, fuera del bloqueo (puede tardar segundos)
    $data        = null;
    $fetch_error = null;
    try {
        $pattern = $snapshot['tag_pattern'] ?? '';
        $data = match ($repo_type) {
            'github' => get_latest_github_release($client, $repo_name, $github_token),
            'gitlab' => get_latest_gitlab_release($client, $repo_name),
            'docker' => DockerHub::latest($client, $repo_name, $pattern),
            'oci'    => OciRegistry::latest($client, $repo_name, $pattern, $github_token),
            default  => null,
        };
    } catch (Throwable $e) {
        $fetch_error = $e->getMessage();
    }

    // 2) Decisión y guardado bajo bloqueo, sobre los datos actuales del repo (no la foto del inicio)
    $now     = date('Y-m-d H:i:s');
    $outcome = $store->update(function (array &$all) use ($repo_id, $data, $fetch_error, $now) {
        $i = RepoStore::indexOf($all, $repo_id);
        if ($i === null) {
            return ['kind' => 'gone'];
        }
        $repo = &$all[$i];
        $repo['last_checked'] = $now;

        $tag      = is_array($data) ? ($data['tag'] ?? null) : null;
        $digest   = is_array($data) ? ($data['digest'] ?? null) : null;
        $date     = is_array($data) ? ($data['released_at'] ?? null) : null;
        $no_match = is_array($data) && ($data['reason'] ?? null) === 'no_match';

        if ($fetch_error !== null) {
            $repo['check_status'] = 'error';
            return ['kind' => 'error', 'detail' => $fetch_error];
        }
        if (is_array($data) && ($data['reason'] ?? null) === 'rate_limited') {
            // Límite de peticiones del registro (IP compartida en IONOS): no es culpa del repo, se conserva
            // su estado anterior y se reintenta en la próxima verificación (SECURITY_AUDIT.md §13)
            return ['kind' => 'rate_limited'];
        }
        if ($tag === null) {
            // Patrón sin coincidencias: no es un error de la API, pero tampoco «al día» (PROPUESTA_UX §0.1)
            $repo['check_status'] = $no_match ? 'no_match' : 'error';
            return ['kind' => $no_match ? 'no_match' : 'error'];
        }

        $tag_changed    = ($repo['last_seen_release'] ?? null) !== $tag;
        $digest_changed = $digest !== null
            && !empty($repo['last_seen_release'])          // skip on first run
            && !empty($repo['last_seen_digest'])           // skip if digest was cleared (edit_repo)
            && $repo['last_seen_digest'] !== $digest;

        if ($tag_changed || $digest_changed) {
            $repo['check_status']      = 'new';
            $repo['last_seen_release'] = $tag;
            $repo['last_release_date'] = $date;
            if ($digest !== null) {
                $repo['last_seen_digest'] = $digest;
            }
            return [
                'kind'        => 'new',
                'repo'        => $repo,
                'tag'         => $tag,
                'tag_changed' => $tag_changed,
                'date'        => $date,
                'url'         => $data['url'] ?? null,
            ];
        }

        $repo['check_status'] = 'ok';
        // Keep digest and date fresh even when there is no new release
        if ($digest !== null) {
            $repo['last_seen_digest'] = $digest;
        }
        if ($date !== null && empty($repo['last_release_date'])) {
            $repo['last_release_date'] = $date;
        }
        return ['kind' => 'ok', 'tag' => $tag];
    });

    // 3) Log y Telegram, ya fuera del bloqueo
    switch ($outcome['kind']) {
        case 'gone':
            log_entry('info', "{$repo_name} se eliminó durante la verificación; se omite.");
            break;
        case 'error':
            $error_count++;
            log_entry('error', isset($outcome['detail'])
                ? "Error procesando {$repo_name}: {$outcome['detail']}"
                : "No se pudo obtener la versión de {$repo_name}");
            break;
        case 'no_match':
            log_entry('warning', "Sin tags que coincidan con el patrón de {$repo_name}.");
            break;
        case 'rate_limited':
            log_entry('warning', "Límite de peticiones alcanzado al consultar {$repo_name}; se reintentará en la próxima verificación.");
            break;
        case 'new':
            $new_count++;
            log_entry('success', "Nuevo release para {$repo_name}: "
                . ($outcome['tag_changed'] ? $outcome['tag'] : "{$outcome['tag']} (digest actualizado)"));
            record_release($repo_name, $repo_type, $outcome['tag'], $outcome['url']);
            send_telegram_message(build_release_message($outcome['repo'], $outcome), $telegram_chat_id, $outcome['repo']['id']);
            break;
        default:
            log_entry('info', "Sin cambios en {$repo_name} ({$outcome['tag']})");
    }

    $done++;
    if ($job_id !== null) {
        $jobs->update($job_id, ['done' => $done, 'new' => $new_count, 'errors' => $error_count]);
    }
}

log_entry('info', "Verificación completada ({$label}). {$new_count} nuevo(s) release(s) detectado(s).");

if ($is_full) {
    // Para la «salud del cron» del índice
    EventLog::prune();
    RunHealth::record([
        'started_at'  => $started_at,
        'finished_at' => date('Y-m-d H:i:s'),
        'total'       => count($targets),
        'new'         => $new_count,
        'errors'      => $error_count,
        'source'      => $source,
    ]);
}
if ($job_id !== null) {
    $jobs->update($job_id, ['status' => 'done', 'current' => null, 'finished_at' => date('c')]);
}
