<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Lectura/escritura de JSON con bloqueo.
 *
 * Dos modos de escritura, a propósito:
 *  - modify()/writeInPlace(): reescriben el fichero existente (no lo sustituyen), así conservan propietario y
 *    permisos. Es lo que necesitan repositories.json, app_log.json, release_history.json y config.json: el cron
 *    puede correr como root y, si sustituyera el fichero, Apache (www-data) ya no podría escribirlo.
 *  - writeAtomic(): temporal + rename, para los ficheros de data/ (jobs, última ejecución), que solo escribe un
 *    proceso a la vez. Si quien escribe es root, el fichero se cede al dueño del directorio.
 */
final class JsonFile
{
    private const FLAGS = JSON_PRETTY_PRINT;

    public static function read(string $path, mixed $default = []): mixed
    {
        if (!is_file($path)) {
            return $default;
        }
        $h = @fopen($path, 'r');
        if ($h === false) {
            return $default;
        }
        flock($h, LOCK_SH);
        $raw = stream_get_contents($h);
        flock($h, LOCK_UN);
        fclose($h);
        $data = json_decode((string) $raw, true);
        return $data ?? $default;
    }

    /**
     * Lee, aplica $fn y reescribe en el sitio, todo bajo LOCK_EX del propio fichero.
     * $fn recibe los datos actuales y devuelve los nuevos.
     */
    public static function modify(string $path, callable $fn, mixed $default = []): mixed
    {
        $created = !is_file($path);
        $h = fopen($path, 'c+');
        if ($h === false) {
            throw new RuntimeException("No se puede abrir {$path} para escritura");
        }
        if ($created) {
            self::giveToDirOwner($path);
        }
        try {
            flock($h, LOCK_EX);
            $raw     = stream_get_contents($h);
            $current = $raw === '' ? $default : (json_decode((string) $raw, true) ?? $default);
            $new     = $fn($current);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode($new, self::FLAGS));
            fflush($h);
            return $new;
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    public static function writeInPlace(string $path, mixed $data): void
    {
        self::modify($path, fn () => $data);
    }

    /** Añade una entrada y conserva solo las últimas $cap. */
    public static function appendCapped(string $path, array $entry, int $cap): void
    {
        self::modify($path, function ($list) use ($entry, $cap) {
            $list   = is_array($list) ? $list : [];
            $list[] = $entry;
            return count($list) > $cap ? array_slice($list, -$cap) : $list;
        });
    }

    public static function writeAtomic(string $path, mixed $data): void
    {
        self::ensureDir(dirname($path));
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data, self::FLAGS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::giveToDirOwner($tmp);
        rename($tmp, $path);
    }

    /** Crea el directorio si falta; si lo crea root, se lo cede al dueño del directorio padre. */
    public static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("No se puede crear {$dir}");
        }
        self::giveToDirOwner($dir);
    }

    /** Abre (creándolo si hace falta) un fichero de bloqueo. Si es de root y no se puede escribir, basta con leer. */
    public static function openLock(string $path)
    {
        self::ensureDir(dirname($path));
        $existed = is_file($path);
        $h = @fopen($path, 'c') ?: @fopen($path, 'r');
        if ($h === false) {
            throw new RuntimeException("No se puede abrir el bloqueo {$path}");
        }
        if (!$existed) {
            self::giveToDirOwner($path);
        }
        return $h;
    }

    private static function giveToDirOwner(string $path): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $parent = dirname($path);
            @chown($path, fileowner($parent));
            @chgrp($path, filegroup($parent));
        }
    }
}
