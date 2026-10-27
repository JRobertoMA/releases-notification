<?php

declare(strict_types=1);

/**
 * Arranque común de todas las páginas (public/*.php) y endpoints web (public/api/*.php).
 * No lo usan los scripts de consola (check_releases.php, cli/*.php).
 *
 * includes/ queda fuera de public/ y además tiene su .htaccess con Require all denied.
 * Antes de incluirlo, una página puede definir:
 *   $public_page = true            no exigir sesión (login.php, login_2fa.php)
 *   $allow_without_2fa = true      accesible para un admin que aún no activó el 2FA (account.php, logout.php…)
 *   $stateless = true              sin sesión ni cookies (api/bot.php: lo llama el backend del bot, no un navegador)
 * Todo lo demás, páginas y api/, exige sesión iniciada (ver final del fichero). Los permisos por rol se
 * comprueban en cada página o endpoint con require_permission() (App\Access).
 *
 * Orden (importa): .env → zona horaria → HTTPS y cabeceras → cookies → sesión → base de datos → autenticación.
 * Configuración: secretos y entorno en .env (solo lectura), ajustes web en la tabla `settings` (App\AppConfig).
 */

// Todo lo que cree PHP (data/, sesiones) nace solo para el propietario: 600/700.
// En IONOS el checkout queda legible por «otros» y el servidor es compartido (SECURITY_AUDIT.md, v1.0.1).
umask(0077);

define('BASE_DIR', dirname(__DIR__));

require_once BASE_DIR . '/vendor/autoload.php';

use App\Access;
use App\AppConfig;
use App\Auth;
use App\Database;
use App\Env;
use App\Files;
use App\OciRegistry;
use App\Repo;
use App\RepoStore;
use App\Users;

Env::load(BASE_DIR);

// Zona horaria de la app (APP_TIMEZONE del .env), no la del servidor (IONOS: America/New_York).
// El cron usa la misma (check_releases.php).
date_default_timezone_set(AppConfig::timezone());

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * HTTPS obligatorio salvo en local: localhost, IPs privadas, nombres sin punto o *.local.
 * FORCE_HTTPS=false/true en el .env lo fija; sin él, automático (funciona en IONOS desde el primer acceso).
 */
function must_force_https(): bool
{
    $forced = AppConfig::forceHttps();
    if ($forced !== null) {
        return $forced;
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

if (must_force_https() && !is_https()) {
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
    Files::ensureDir($session_dir);
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
if (empty($stateless)) {
    session_start();
}

/** Página (o JSON en api/) de «falta configurar»: sin .env, sin base de datos o sin tablas. Nunca muestra secretos. */
function setup_error(string $title, string $detail): never
{
    http_response_code(503);
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['data' => null, 'error' => $title, 'status' => 503], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Configuración pendiente · Notificador</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        . '<body class="bg-body-tertiary"><main class="container py-5" style="max-width:720px"><div class="card shadow-sm"><div class="card-body p-4">'
        . '<h1 class="h4">' . htmlspecialchars($title) . '</h1><p class="mb-0">' . $detail . '</p>'
        . '</div></div></main></body></html>';
    exit;
}

// Base de datos (credenciales en .env). Sin ella no hay nada que mostrar.
if (!Env::exists() || !Database::isConfigured()) {
    setup_error('Falta configurar la base de datos',
        'Crea el fichero <code>.env</code> a partir de <code>.env.example</code> (en la carpeta de la app, fuera de <code>public/</code>) con '
        . '<code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code> y <code>DB_PASS</code>, y ejecuta <code>cli/migrate.php</code>. Ver el README.');
}
try {
    if (!Database::hasSchema()) {
        setup_error('Faltan las tablas de la base de datos',
            'Ejecuta <code>cli/migrate.php</code> con el PHP de consola (en IONOS: <code>/usr/bin/php8.4-cli cli/migrate.php</code>).');
    }
} catch (PDOException $e) {
    error_log('Notificador: no se pudo conectar a la base de datos: ' . $e->getMessage());
    setup_error('No se puede conectar a la base de datos',
        'Revisa <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code> y <code>DB_PASS</code> en el <code>.env</code>. El detalle está en el log de errores del servidor.');
}

// Registros OCI a los que se permite consultar (lista blanca contra SSRF, SECURITY_AUDIT.md §10)
OciRegistry::setAllowedHosts((array) AppConfig::get('OCI_REGISTRIES'));

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Configuración: secretos/entorno del .env o ajustes web de la tabla `settings` (ver App\AppConfig). */
function cfg(string $key): mixed
{
    return AppConfig::get($key);
}

function repo_store(): RepoStore
{
    static $store = null;
    return $store ??= new RepoStore();
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
 * Orden: PHP_CLI del .env → binarios versionados de IONOS → el de este servidor.
 */
function php_cli_binary(): ?string
{
    static $resolved = false, $binary = null;
    if ($resolved) {
        return $binary;
    }
    $resolved   = true;
    $candidates = [
        trim((string) cfg('PHP_CLI')),
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
    return $users ??= new Users();
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
    return preg_match('/^[a-z0-9_]+\.php(\?[^\s]*)?$/', $next) && !preg_match('/^(login|login_2fa|logout)\.php/', $next)
        ? $next
        : 'index.php';
}

/** ¿Puede el usuario actual…? Para mostrar u ocultar controles; la decisión real es require_permission(). */
function can(string $permission): bool
{
    return Access::can(current_user(), $permission);
}

/** Corta la petición con 403 si el usuario actual no tiene el permiso (página o JSON según el caso). */
function require_permission(string $permission): void
{
    if (can($permission)) {
        return;
    }
    if (wants_json()) {
        json_out(null, 403, 'Tu rol no permite esta acción.');
    }
    http_response_code(403);
    $page_title = 'Sin permiso';
    require __DIR__ . '/header.php';
    echo '<div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">' . icon('lock-fill', 'mt-1')
        . '<div><strong>Tu rol no permite esta acción.</strong> Eres ' . h(Access::ROLES[current_user()['role'] ?? ''] ?? 'usuario')
        . '; pide a un administrador que te cambie el rol si lo necesitas. <a href="index.php" class="alert-link">Volver a los repositorios</a></div></div>';
    require __DIR__ . '/footer.php';
    echo '</body></html>';
    exit;
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

// 2FA obligatorio para administradores: hasta activarlo solo pueden entrar en «Mi cuenta» y cerrar sesión
if (empty($public_page) && empty($allow_without_2fa) && Access::needsTwoFactorSetup(current_user())) {
    if (wants_json()) {
        json_out(null, 403, 'Activa primero la verificación en dos pasos en «Mi cuenta».');
    }
    redirect_with('Los administradores deben activar la verificación en dos pasos antes de continuar.', 'warning', 'account.php');
}
