<?php

declare(strict_types=1);

/**
 * Arranque común de todas las páginas (public/*.php) y endpoints web (public/api/*.php).
 * No lo usan los scripts de consola (check_releases.php, cli/*.php).
 *
 * includes/ queda fuera de public/ y además tiene su .htaccess con Require all denied.
 * Antes de incluirlo, una página puede definir:
 *   $allow_missing_config = true   no redirigir a settings.php si falta config.json (settings.php, login.php)
 *   $public_page = true            no exigir sesión (solo login.php)
 * Todo lo demás, páginas y api/, exige sesión iniciada (ver final del fichero).
 *
 * Orden (importa): configuración → zona horaria → HTTPS y cabeceras → cookies → sesión → autenticación.
 */

// Todo lo que cree PHP (config.json, data/, sesiones, JSON de datos) nace solo para el propietario: 600/700.
// En IONOS el checkout queda legible por «otros» y el servidor es compartido (SECURITY_AUDIT.md, v1.0.1).
umask(0077);

define('BASE_DIR', dirname(__DIR__));

require_once BASE_DIR . '/vendor/autoload.php';

use App\Auth;
use App\JsonFile;
use App\Repo;
use App\RepoStore;
use App\Users;

$config_file = BASE_DIR . '/config.json';
$config      = is_file($config_file) ? (json_decode((string) file_get_contents($config_file), true) ?? []) : [];

// Zona horaria de la app, no la del servidor (IONOS: America/New_York). El cron usa la misma (check_releases.php).
date_default_timezone_set(app_timezone($config));

// Registros OCI a los que se permite consultar (lista blanca contra SSRF, SECURITY_AUDIT.md §10)
App\OciRegistry::setAllowedHosts(is_array($config['OCI_REGISTRIES'] ?? null) ? $config['OCI_REGISTRIES'] : null);

/** Zona horaria configurada (APP_TIMEZONE), validada; por defecto America/Mexico_City. */
function app_timezone(array $config): string
{
    $tz = (string) ($config['APP_TIMEZONE'] ?? '');
    return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'America/Mexico_City';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * HTTPS obligatorio salvo en local: localhost, IPs privadas, nombres sin punto o *.local.
 * FORCE_HTTPS=false en config.json lo desactiva; así funciona en IONOS aunque aún no exista config.json.
 */
function must_force_https(array $config): bool
{
    if (array_key_exists('FORCE_HTTPS', $config)) {
        return (bool) $config['FORCE_HTTPS'];
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = trim($host, '[]');
    if ($host === '' || $host === 'localhost' || !str_contains($host, '.') || str_ends_with($host, '.local')) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    return true;
}

if (must_force_https($config) && !is_https()) {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

// Cabeceras de seguridad en todas las respuestas dinámicas (SECURITY_AUDIT.md §6)
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000');
}

/**
 * Ruta web de la app para las cookies: /telegram-notification/ en local, / en IONOS.
 * Sale de REQUEST_URI y no de SCRIPT_NAME: en local el .htaccess raíz reescribe a public/ y SCRIPT_NAME
 * sería /telegram-notification/public/…, una ruta que el navegador no usa (la cookie no se enviaría).
 */
function detect_app_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    if (!str_ends_with($path, '/')) {
        $path = dirname($path);
    }
    $path = preg_replace('#/api/?$#', '', rtrim(str_replace('\\', '/', $path), '/'));
    return rtrim((string) $path, '/') . '/';
}

define('APP_PATH', detect_app_path());

// Sesiones en una carpeta propia: en IONOS /tmp/.priv lo limpia una tarea del sistema a los 24 min
// (gc_maxlifetime=1440, gc_probability=0), por debajo de las 2 h de Auth::IDLE (SECURITY_AUDIT.md §15).
$session_dir = BASE_DIR . '/data/sessions';
if (!is_dir($session_dir)) {
    JsonFile::ensureDir($session_dir);
    @chmod($session_dir, 0700);
}
session_save_path($session_dir);
ini_set('session.gc_maxlifetime', (string) Auth::IDLE);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');

// Nombre de sesión propio: todos los proyectos de htdocs/ comparten localhost:81 y, con él, PHPSESSID
session_name('tn_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => APP_PATH,
    'secure'   => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!is_file($config_file) && empty($allow_missing_config)) {
    header('Location: settings.php');
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function app_config(): array
{
    global $config;
    return $config;
}

function repo_store(): RepoStore
{
    return RepoStore::fromConfig(app_config(), BASE_DIR);
}

// --- CSRF ---------------------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

/** Exige POST con token CSRF válido (campo csrf_token o cabecera X-CSRF-Token). */
function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wants_json() ? json_out(null, 405, 'Método no permitido') : redirect_with('Acción no permitida.', 'danger');
    }
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        wants_json()
            ? json_out(null, 403, 'La sesión caducó. Recarga la página.')
            : redirect_with('La sesión caducó. Vuelve a intentarlo.', 'warning');
    }
}

// --- Entrada ------------------------------------------------------------------------------------

/** Ids de repo enviados como id=… o ids[]=…, solo los que tienen formato válido. */
function post_ids(): array
{
    $raw = $_POST['ids'] ?? (isset($_POST['id']) ? [$_POST['id']] : []);
    $raw = is_array($raw) ? $raw : [$raw];
    return array_values(array_unique(array_filter($raw, [RepoStore::class, 'isValidId'])));
}

/** «media, gaming, Nintendo Switch» → ['media', 'gaming', 'Nintendo Switch'] */
function parse_tags(string $input): array
{
    return array_values(array_unique(array_filter(array_map('trim', explode(',', $input)), 'strlen')));
}

// --- Respuestas ---------------------------------------------------------------------------------

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['message']      = $message;
    $_SESSION['message_type'] = in_array($type, ['success', 'danger', 'warning', 'info', 'primary'], true) ? $type : 'info';
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function redirect_with(string $message, string $type = 'success', string $to = 'index.php'): never
{
    flash($message, $type);
    redirect($to);
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
}

/** Sobre JSON común de los endpoints de api/: {data, error, status}. */
function json_out(mixed $data, int $status = 200, ?string $error = null): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => $data, 'error' => $error, 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Vista --------------------------------------------------------------------------------------

/** <time> que assets/app.js convierte en «hace 3 h»; la fecha absoluta queda en el title. */
function time_tag(?string $datetime, string $empty = '—', bool $dateOnly = false): string
{
    if ($datetime === null || $datetime === '') {
        return '<span class="text-body-secondary">' . h($empty) . '</span>';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return h($datetime);
    }
    $absolute = date($dateOnly ? 'Y-m-d' : 'Y-m-d H:i', $ts);
    return sprintf(
        '<time datetime="%s" title="%s" data-rel%s>%s</time>',
        h(date('c', $ts)),
        h($absolute),
        $dateOnly ? ' data-rel-date' : '',
        h($absolute)
    );
}

function type_badge(string $type): string
{
    return sprintf(
        '<span class="badge %s">%s%s</span>',
        Repo::TYPE_BADGE[$type] ?? 'text-bg-secondary',
        isset(Repo::TYPE_ICON[$type]) ? icon(Repo::TYPE_ICON[$type], 'me-1') : '',
        h(Repo::TYPES[$type] ?? $type)
    );
}

function status_badge(?string $status): string
{
    $s = Repo::STATUS[$status ?? ''] ?? null;
    if ($s === null) {
        return '<span class="badge text-bg-light border" title="Aún no se ha verificado">Sin verificar</span>';
    }
    return sprintf('<span class="badge %s" title="%s">%s%s</span>', $s['badge'], h($s['hint']), icon($s['icon'], 'me-1'), h($s['label']));
}

/** Versión mayor de un binario de PHP (0 si no es ejecutable o no responde como PHP de consola). */
function php_cli_version(string $bin): int
{
    if ($bin === '' || !is_file($bin) || !is_executable($bin)) {
        return 0;
    }
    exec(escapeshellarg($bin) . ' -r ' . escapeshellarg('echo PHP_MAJOR_VERSION;') . ' 2>/dev/null', $out, $code);
    return $code === 0 && isset($out[0]) && ctype_digit(trim($out[0])) ? (int) trim($out[0]) : 0;
}

/**
 * PHP de consola para lanzar check_releases.php. Nunca devuelve un PHP < 8: en IONOS `php` es PHP 4.4.
 * Orden: PHP_CLI de config.json → binarios versionados de IONOS → el de este servidor.
 */
function php_cli_binary(): ?string
{
    static $resolved = false, $binary = null;
    if ($resolved) {
        return $binary;
    }
    $resolved   = true;
    $candidates = [
        trim((string) (app_config()['PHP_CLI'] ?? '')),
        '/usr/bin/php8.5-cli', '/usr/bin/php8.4-cli', '/usr/bin/php8.3-cli',
        PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php',
    ];
    foreach (array_unique(array_filter($candidates)) as $candidate) {
        if (php_cli_version($candidate) >= 8) {
            return $binary = $candidate;
        }
    }
    return null;
}

/** Valor para insertar dentro de <script>: JSON sin <, >, &, comillas literales (SECURITY_AUDIT.md §21). */
function js(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/** <i class="bi bi-…"> decorativo (Bootstrap Icons): el texto o el aria-label del elemento dan el significado. */
function icon(string $name, string $class = ''): string
{
    return '<i class="bi bi-' . h($name) . ($class !== '' ? ' ' . h($class) : '') . '" aria-hidden="true"></i>';
}

// --- Autenticación ------------------------------------------------------------------------------

function users(): Users
{
    static $users = null;
    return $users ??= Users::fromBase(BASE_DIR);
}

function auth(): Auth
{
    static $auth = null;
    return $auth ??= new Auth(users(), APP_PATH);
}

/** Usuario con sesión iniciada (sesión o cookie «Recordarme»), o null. */
function current_user(): ?array
{
    static $resolved = false, $user = null;
    if (!$resolved) {
        $user     = auth()->user() ?? auth()->fromRememberCookie();
        $resolved = true;
    }
    return $user;
}

/** Destino tras el login: solo páginas de la propia app (sin redirecciones abiertas). */
function safe_next(?string $next): string
{
    $next = (string) $next;
    return preg_match('/^[a-z_]+\.php(\?[^\s]*)?$/', $next) && !preg_match('/^(login|logout)\.php/', $next)
        ? $next
        : 'index.php';
}

if (empty($public_page) && current_user() === null) {
    if (wants_json()) {
        json_out(null, 401, 'Tu sesión ha caducado. Vuelve a iniciar sesión.');
    }
    // Tras el login se vuelve a la misma página (solo si era un GET; un POST no se puede repetir)
    $next = $_SERVER['REQUEST_METHOD'] === 'GET'
        ? basename($_SERVER['SCRIPT_NAME'] ?? 'index.php') . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING'])
        : 'index.php';
    redirect('login.php?next=' . urlencode(safe_next($next)));
}
