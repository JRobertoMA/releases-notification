<?php

declare(strict_types=1);

namespace App;

/**
 * Interpreta lo que el usuario pega en «URL o nombre» (PROPUESTA_UX §1.2). assets/app.js hace la misma
 * detección para preseleccionar el tipo mientras se escribe; esta clase es la que decide al guardar.
 */
final class RepoInput
{
    /**
     * Tipo y nombre a partir de una URL o referencia con host. null si no se reconoce el host.
     *
     * @return array{type:string, name:string}|null
     */
    public static function detect(string $input): ?array
    {
        $raw = trim($input);
        $s   = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $raw);
        $s   = preg_replace('#^www\.#i', '', $s);
        $s   = rtrim(preg_replace('/[?#].*$/', '', $s), '/');

        if (preg_match('#^github\.com/([\w.-]+)/([\w.-]+)#i', $s, $m)) {
            return ['type' => 'github', 'name' => $m[1] . '/' . preg_replace('/\.git$/', '', $m[2])];
        }
        if (preg_match('#^gitlab\.com/(.+)$#i', $s, $m)) {
            $path = preg_replace('#/-/.*$#', '', $m[1]);
            $path = preg_replace('/\.git$/', '', $path);
            return substr_count($path, '/') >= 1 ? ['type' => 'gitlab', 'name' => $path] : null;
        }
        if (preg_match('#^hub\.docker\.com/_/([a-z0-9._-]+)#i', $s, $m)) {
            return ['type' => 'docker', 'name' => '_/' . strtolower($m[1])];
        }
        if (preg_match('#^hub\.docker\.com/(?:r|repository/docker)/([a-z0-9._-]+)/([a-z0-9._-]+)#i', $s, $m)) {
            return ['type' => 'docker', 'name' => strtolower($m[1] . '/' . $m[2])];
        }
        if (preg_match('#^(?:registry-1\.)?docker\.io/(.+)$#i', $s, $m)) {
            $name = self::dockerName(preg_replace('#^library/#', '_/', $m[1]));
            return $name === null ? null : ['type' => 'docker', 'name' => $name];
        }
        // Cualquier otro host con punto (ghcr.io, lscr.io, quay.io, registry.gitlab.com…) → registro OCI.
        // Los hosts conocidos que no encajaron arriba (URL incompleta) no se reinterpretan como OCI.
        if (preg_match('#^(github\.com|gitlab\.com|hub\.docker\.com)(/|$)#i', $s)) {
            return null;
        }
        if (preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(:\d+)?/#i', $s)) {
            $name = OciRegistry::normalizeName($raw);
            return $name === null ? null : ['type' => 'oci', 'name' => $name];
        }
        return null;
    }

    /**
     * Tipo y nombre definitivos para guardar. Una URL reconocible manda sobre el tipo elegido en el select.
     *
     * @return array{type:string, name:string}|null
     */
    public static function resolve(string $type, string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $detected = self::detect($input);
        if ($detected !== null) {
            return $detected;
        }
        if (preg_match('#^([a-z]+://)?(www\.)?(github\.com|gitlab\.com|hub\.docker\.com|docker\.io)(/|$)#i', $input)) {
            return null;   // URL de un host conocido pero incompleta: mejor rechazar que guardar «github.com/x»
        }
        $name = match ($type) {
            'github' => self::pathName($input, false),
            'gitlab' => self::pathName($input, true),
            'docker' => self::dockerName($input),
            'oci'    => OciRegistry::normalizeName($input),
            default  => null,
        };
        return $name === null ? null : ['type' => $type, 'name' => $name];
    }

    /** owner/repo (GitLab admite subgrupos: grupo/subgrupo/proyecto). */
    private static function pathName(string $input, bool $nested): ?string
    {
        $name = preg_replace('/\.git$/', '', trim($input, " /"));
        $re   = $nested ? '#^[\w.-]+(/[\w.-]+)+$#' : '#^[\w.-]+/[\w.-]+$#';
        return preg_match($re, $name) ? $name : null;
    }

    /** `php`, `_/php`, `owner/image`; quita `:tag` y `@digest`. */
    private static function dockerName(string $input): ?string
    {
        $name = strtolower(trim($input, ' /'));
        $name = preg_replace('/@sha256:[0-9a-f]+$/', '', $name);
        $name = preg_replace('/:[^\/]+$/', '', $name);
        return preg_match('#^(_/)?[a-z0-9]+([._-][a-z0-9]+)*(/[a-z0-9]+([._-][a-z0-9]+)*)?$#', $name) ? $name : null;
    }
}
