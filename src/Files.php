<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Ficheros que aún usa la app (los datos están en MariaDB desde la v1.1.0):
 * carpeta de sesiones (data/sessions) y bloqueo del worker (data/worker.lock).
 *
 * Todo lo que se crea aquí queda privado (600/700) y, si quien lo crea es root, se cede al dueño de la carpeta.
 */
final class Files
{
    /** Crea la carpeta si falta (700). */
    public static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("No se puede crear {$dir}");
        }
        self::claimNew($dir);
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
            self::claimNew($path);
        }
        return $h;
    }

    /**
     * Permisos privados explícitos (600 fichero / 700 carpeta) y, si escribe root, cesión al dueño de la carpeta.
     * El chmod es explícito porque umask(0077) no basta: con ACL por defecto en la carpeta (el entrypoint del
     * Docker local las pone) Linux ignora la umask.
     */
    public static function claimNew(string $path): void
    {
        @chmod($path, is_dir($path) ? 0700 : 0600);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $parent = dirname($path);
            @chown($path, fileowner($parent));
            @chgrp($path, filegroup($parent));
        }
    }
}
