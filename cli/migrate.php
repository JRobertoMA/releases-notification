<?php

declare(strict_types=1);

/**
 * Aplica las migraciones pendientes de database/migrations/ (en orden por nombre) y las registra en
 * schema_migrations. Es idempotente: se puede ejecutar tras cada actualización.
 *
 *   php cli/migrate.php            aplica lo pendiente
 *   php cli/migrate.php --status   lista aplicadas y pendientes
 *
 * IONOS:  /usr/bin/php8.4-cli cli/migrate.php      Docker: docker exec -u www-data php-apache php …/cli/migrate.php
 * El usuario de la base de datos necesita permiso CREATE/ALTER/INDEX/REFERENCES sobre su base.
 *
 * Si falta APP_KEY en el .env (clave que cifra los secretos 2FA), la genera y la añade; nunca la imprime ni la
 * sustituye: cambiarla invalidaría el 2FA de todos los usuarios.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

umask(0077);

$base = dirname(__DIR__);
require_once $base . '/vendor/autoload.php';

use App\AppConfig;
use App\Crypto;
use App\Database;
use App\Env;

Env::load($base);
date_default_timezone_set(AppConfig::timezone());

function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

if (!Env::exists()) {
    fail('No existe .env. Cópialo de .env.example y rellena DB_HOST, DB_NAME, DB_USER y DB_PASS.');
}

try {
    $pdo = Database::pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(100) NOT NULL,
        applied_at DATETIME     NOT NULL,
        PRIMARY KEY (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
} catch (PDOException $e) {
    fail(match ((int) ($e->errorInfo[1] ?? 0)) {
        1045    => 'Usuario o contraseña de la base de datos incorrectos (.env).',
        1049    => 'La base de datos de DB_NAME no existe.',
        1142    => 'El usuario de la base de datos no tiene permiso CREATE sobre su base.',
        2002    => 'No se puede conectar a DB_HOST.',
        default => 'Base de datos: ' . $e->getMessage(),
    });
}

$applied = array_column(Database::all('SELECT version FROM schema_migrations'), 'version');
$files   = glob($base . '/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if (in_array('--status', $argv, true)) {
    foreach ($files as $file) {
        $v = basename($file, '.sql');
        printf("%-12s %s\n", in_array($v, $applied, true) ? 'aplicada' : 'PENDIENTE', $v);
    }
    exit(0);
}

$done = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $applied, true)) {
        continue;
    }
    // Sentencias separadas por «;» a final de línea; las migraciones no usan «;» dentro de textos
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    try {
        foreach (preg_split('/;\s*$/m', $sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    } catch (PDOException $e) {
        fail("{$version}: " . ((int) ($e->errorInfo[1] ?? 0) === 1142
            ? 'el usuario de la base de datos no tiene permisos CREATE/ALTER/INDEX/REFERENCES.'
            : $e->getMessage()));
    }
    Database::run('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, Database::now()]);
    echo "Aplicada: {$version}\n";
    $done++;
}

echo $done === 0 ? "La base de datos ya está al día.\n" : "{$done} migración(es) aplicada(s).\n";

if (!Env::has('APP_KEY')) {
    try {
        Env::appendMissing(['APP_KEY' => Crypto::generateKey()]);
        echo "APP_KEY generada y añadida al .env (cifra los secretos 2FA; no la cambies ni la pierdas).\n";
    } catch (Throwable $e) {
        fail('no se pudo escribir APP_KEY en el .env: ' . $e->getMessage());
    }
}
