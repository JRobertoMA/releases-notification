<?php

declare(strict_types=1);

/**
 * Importa los datos de la versión con ficheros JSON (≤ 1.0.x) a MariaDB. Una sola vez por entorno.
 *
 *   php cli/import_json.php            importa (se niega si la base ya tiene repos, usuarios o historial)
 *   php cli/import_json.php --force    BORRA repos, usuarios, historial y log de la base y reimporta
 *
 * Qué hace:
 *   repositories.json      → repos (mismos ids y orden)
 *   release_history.json   → release_history
 *   app_log.json           → app_log
 *   data/users.json        → users (como admin) + user_tokens (los «Recordarme» vigentes siguen valiendo)
 *   data/last_run.json     → app_state.last_run
 *   config.json            → secretos y entorno al .env (solo si faltan allí; no se imprimen),
 *                            el resto a la tabla settings
 * Cada fichero importado se renombra a *.imported (no se borra). config.json.imported aún contiene tus tokens:
 * bórralo cuando compruebes que todo funciona.
 *
 * Requiere .env con la base de datos y haber ejecutado cli/migrate.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

umask(0077);

$base = dirname(__DIR__);
require_once $base . '/vendor/autoload.php';

use App\AppConfig;
use App\Database;
use App\Env;
use App\RepoStore;
use App\RunHealth;
use App\Users;

Env::load($base);
date_default_timezone_set(AppConfig::timezone());

function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

function read_json(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        fail('JSON no válido: ' . basename($file));
    }
    return $data;
}

/** Fecha 'Y-m-d H:i:s' válida o null. */
function datetime_or_null(mixed $value): ?string
{
    $ts = is_string($value) && $value !== '' ? strtotime($value) : false;
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

try {
    if (!Database::isConfigured() || !Database::hasSchema()) {
        fail('configura la base de datos en .env y ejecuta antes cli/migrate.php');
    }
} catch (PDOException $e) {
    fail('no se pudo conectar a la base de datos: ' . $e->getMessage());
}

$force = in_array('--force', $argv, true);
$files = [
    'config'   => $base . '/config.json',
    'repos'    => $base . '/repositories.json',
    'history'  => $base . '/release_history.json',
    'log'      => $base . '/app_log.json',
    'users'    => $base . '/data/users.json',
    'last_run' => $base . '/data/last_run.json',
];
$present = array_filter($files, 'is_file');
if ($present === []) {
    echo "No hay ficheros JSON que importar.\n";
    exit(0);
}

$existing = [
    'repos'           => (int) Database::value('SELECT COUNT(*) FROM repos'),
    'users'           => (int) Database::value('SELECT COUNT(*) FROM users'),
    'release_history' => (int) Database::value('SELECT COUNT(*) FROM release_history'),
];
if (array_sum($existing) > 0 && !$force) {
    fail('la base ya tiene datos (' . http_build_query($existing, '', ', ') . '). '
        . 'Usa --force solo si quieres BORRARLOS y reimportar los JSON.');
}

$data = array_map('read_json', $present);
$counts = ['repos' => 0, 'release_history' => 0, 'app_log' => 0, 'users' => 0, 'user_tokens' => 0];

Database::transaction(function () use ($data, $force, &$counts) {
    if ($force) {
        foreach (['user_tokens', 'users', 'repos', 'release_history', 'app_log'] as $table) {
            Database::run("DELETE FROM {$table}");
        }
    }

    $repos = array_values($data['repos'] ?? []);
    foreach ($repos as $i => $repo) {
        $repo['id'] = RepoStore::isValidId($repo['id'] ?? null) ? $repo['id'] : RepoStore::newId($repos);
        $repos[$i]  = $repo;
        RepoStore::insertRow($repo, $i);
        $counts['repos']++;
    }

    foreach ($data['history'] ?? [] as $e) {
        Database::run(
            'INSERT INTO release_history (repo_name, type, version, url, detected_at) VALUES (?, ?, ?, ?, ?)',
            [(string) ($e['repo'] ?? ''), (string) ($e['type'] ?? ''), (string) ($e['version'] ?? ''), $e['url'] ?? null,
             datetime_or_null($e['timestamp'] ?? null) ?? Database::now()]
        );
        $counts['release_history']++;
    }

    foreach ($data['log'] ?? [] as $e) {
        Database::run(
            'INSERT INTO app_log (level, message, created_at) VALUES (?, ?, ?)',
            [(string) ($e['level'] ?? 'info'), mb_substr((string) ($e['message'] ?? ''), 0, 2000),
             datetime_or_null($e['timestamp'] ?? null) ?? Database::now()]
        );
        $counts['app_log']++;
    }

    foreach ($data['users'] ?? [] as $u) {
        Users::insertRow([
            'id'            => $u['id'],
            'username'      => $u['username'],
            'password_hash' => $u['password_hash'],
            'role'          => 'admin',   // hasta la 1.1.x todos los usuarios tenían todos los permisos
            'created_at'    => datetime_or_null($u['created_at'] ?? null) ?? Database::now(),
            'last_login_at' => datetime_or_null($u['last_login_at'] ?? null),
        ]);
        $counts['users']++;
        foreach ($u['tokens'] ?? [] as $t) {
            if (($t['expires_at'] ?? 0) > time()) {
                Users::insertToken($u['id'], $t['selector'], $t['hash'], (int) $t['expires_at']);
                $counts['user_tokens']++;
            }
        }
    }

    if (isset($data['last_run'])) {
        RunHealth::record($data['last_run']);
    }

    if (isset($data['config'])) {
        $c = $data['config'];
        AppConfig::setSettings(array_filter([
            'TELEGRAM_CHAT_ID'     => isset($c['TELEGRAM_CHAT_ID']) ? (string) $c['TELEGRAM_CHAT_ID'] : null,
            'CHECK_INTERVAL_HOURS' => isset($c['CHECK_INTERVAL_HOURS']) ? (int) $c['CHECK_INTERVAL_HOURS'] : null,
            'OCI_REGISTRIES'       => isset($c['OCI_REGISTRIES']) ? array_values((array) $c['OCI_REGISTRIES']) : null,
            'THEME'                => $c['THEME'] ?? null,
        ], fn ($v) => $v !== null));
    }
});

// Secretos y entorno de config.json → .env (solo los que falten allí; nunca se imprimen los valores).
// TELEGRAM_BOT_TOKEN no se copia: desde v1.5.0 el token vive solo en el backend del bot (BOT_* en el .env).
$added = [];
if (isset($data['config'])) {
    $c = $data['config'];
    $added = Env::appendMissing(array_map('strval', array_filter([
        'GITHUB_TOKEN'       => $c['GITHUB_TOKEN'] ?? null,
        'PHP_CLI'            => $c['PHP_CLI'] ?? null,
        'APP_TIMEZONE'       => $c['APP_TIMEZONE'] ?? null,
    ], fn ($v) => is_string($v) && $v !== '')));
}

foreach ($present as $file) {
    rename($file, $file . '.imported');
}

echo "Importado:\n";
foreach ($counts as $table => $n) {
    printf("  %-16s %d\n", $table, $n);
}
echo isset($data['config']) ? "  settings         chat, intervalo, registros OCI y tema\n" : '';
echo $added !== [] ? '  .env             añadidas: ' . implode(', ', $added) . "\n" : '';
echo "\nLos JSON se renombraron a *.imported.\n";
if (isset($present['config'])) {
    echo "ATENCIÓN: config.json.imported todavía contiene tus tokens. Cuando compruebes que todo funciona, bórralo:\n"
       . "  rm " . $present['config'] . ".imported\n";
}
