<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Conexión a MariaDB (local: contenedor `mariadb`; IONOS: MariaDB 11.8). Credenciales en el .env.
 *
 * Regla: ninguna consulta usa NOW()/CURRENT_TIMESTAMP. Las fechas las genera PHP (Database::now()) con
 * APP_TIMEZONE, porque el servidor de base de datos de IONOS está en otra zona horaria.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function isConfigured(): bool
    {
        return Env::has('DB_HOST') && Env::has('DB_NAME') && Env::has('DB_USER');
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (!self::isConfigured()) {
                throw new RuntimeException('Falta configurar la base de datos en .env (DB_HOST, DB_NAME, DB_USER, DB_PASS).');
            }
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST'),
                (int) Env::get('DB_PORT', '3306'),
                Env::get('DB_NAME')
            );
            self::$pdo = new PDO($dsn, (string) Env::get('DB_USER'), (string) Env::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        }
        return self::$pdo;
    }

    /** Fecha/hora actual en la zona de la app, para columnas DATETIME. */
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** @return list<array> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Ejecuta y devuelve las filas afectadas. */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** Transacción; si ya hay una abierta, se reutiliza (anidable). */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Bloqueo con nombre entre procesos (GET_LOCK): la web, el cron y el worker se turnan igual que con flock.
     * Se libera solo aunque el proceso muera (al cerrarse la conexión).
     */
    public static function withLock(string $name, callable $fn, int $timeout = 15): mixed
    {
        $key = 'tn:' . Env::get('DB_NAME') . ':' . $name;
        if ((int) self::value('SELECT GET_LOCK(?, ?)', [$key, $timeout]) !== 1) {
            throw new RuntimeException("No se pudo obtener el bloqueo «{$name}» en {$timeout} s");
        }
        try {
            return $fn();
        } finally {
            self::value('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }

    /** ¿Están creadas las tablas? (cli/migrate.php aplicado) */
    public static function hasSchema(): bool
    {
        return self::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'") > 0;
    }

    public static function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function decodeJson(?string $json, mixed $default = null): mixed
    {
        if ($json === null || $json === '') {
            return $default;
        }
        $v = json_decode($json, true);
        return $v ?? $default;
    }
}
