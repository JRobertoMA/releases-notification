<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Lector del .env de la raíz: secretos (tokens, credenciales de la base de datos, APP_KEY) y ajustes propios de
 * cada entorno (PHP_CLI, APP_TIMEZONE, FORCE_HTTPS).
 *
 * La web solo lo LEE: así una sesión web robada no puede cambiar ni leer secretos. Lo escriben únicamente los
 * scripts de consola (cli/import_json.php, cli/migrate.php para APP_KEY) mediante appendMissing().
 *
 * Formato: KEY=VALUE por línea; comentarios con ; o #; comillas opcionales alrededor del valor.
 */
final class Env
{
    private static ?array $values = null;
    private static ?string $file  = null;

    public static function load(string $baseDir): void
    {
        self::$file   = $baseDir . '/.env';
        self::$values = is_file(self::$file) ? self::parse((string) file_get_contents(self::$file)) : [];
    }

    public static function exists(): bool
    {
        return self::$file !== null && is_file(self::$file);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$values === null) {
            throw new RuntimeException('Env::load() no se ha llamado');
        }
        $value = self::$values[$key] ?? null;
        return $value === null || $value === '' ? $default : $value;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * Añade al .env las claves que falten (nunca sobrescribe). Solo para scripts de consola.
     *
     * @param array<string,string> $pairs
     * @return list<string> claves añadidas
     */
    public static function appendMissing(array $pairs): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('El .env solo se escribe desde la consola');
        }
        $added = [];
        $lines = '';
        foreach ($pairs as $key => $value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || self::has($key) || $value === '') {
                continue;
            }
            if (preg_match('/[\r\n]/', $value)) {
                throw new RuntimeException("Valor no válido para {$key}");
            }
            $lines  .= $key . '=' . $value . "\n";
            $added[] = $key;
        }
        if ($lines !== '') {
            $created = !is_file(self::$file);
            file_put_contents(self::$file, ($created ? '' : "\n") . $lines, FILE_APPEND | LOCK_EX);
            @chmod(self::$file, 0600);
            self::$values = self::parse((string) file_get_contents(self::$file));
        }
        return $added;
    }

    /** @return array<string,string> */
    private static function parse(string $content): array
    {
        $values = [];
        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }
        return $values;
    }
}
