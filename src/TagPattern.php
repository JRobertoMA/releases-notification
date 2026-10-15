<?php

declare(strict_types=1);

namespace App;

/**
 * Patrón de tag con dos comodines, compartido por Docker Hub y los registros OCI.
 *
 *   #  → \d+  (exactamente un segmento numérico)
 *   *  → .*   (cualquier texto, incluido vacío)
 *
 *   #.#-apache  →  /^\d+\.\d+-apache$/   encuentra 8.5-apache, 9.0-apache
 *   stable*     →  /^stable.*$/          encuentra stable, stable-fpm-alpine
 *   *-slim      →  /^.*-slim$/           encuentra lts-trixie-slim
 *
 * Sin comodines el patrón debe ser igual al tag completo (latest solo encuentra latest).
 */
final class TagPattern
{
    /** Regex anclada, o null si el patrón no tiene comodines (coincidencia exacta). */
    public static function regex(string $pattern): ?string
    {
        if ($pattern === '' || (strpos($pattern, '#') === false && strpos($pattern, '*') === false)) {
            return null;
        }
        $tokens = preg_split('/([#*])/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $body   = '';
        foreach ($tokens as $token) {
            if ($token === '#') {
                $body .= '\d+';
            } elseif ($token === '*') {
                $body .= '.*';
            } else {
                $body .= preg_quote($token, '/');
            }
        }
        return '/^' . $body . '$/';
    }

    public static function matches(string $tag, string $pattern, ?string $regex = null): bool
    {
        $regex ??= self::regex($pattern);
        if ($regex !== null) {
            return (bool) preg_match($regex, $tag);
        }
        return $tag === $pattern;
    }

    public static function hasWildcards(string $pattern): bool
    {
        return self::regex($pattern) !== null;
    }

    /** Con `#` gana la versión más alta en lugar de la más reciente (PROPUESTA_UX §0.3). */
    public static function prefersHighest(string $pattern): bool
    {
        return strpos($pattern, '#') !== false;
    }

    /**
     * Fragmento literal más largo del patrón, para filtrar en el servidor (Docker Hub `?name=`).
     * '' si no hay ninguno útil (menos de 2 caracteres).
     */
    public static function literalHint(string $pattern): string
    {
        $best = '';
        foreach (preg_split('/[#*]/', $pattern) as $part) {
            if (strlen($part) > strlen($best)) {
                $best = $part;
            }
        }
        return strlen($best) >= 2 ? $best : '';
    }

    /** Convierte un tag concreto en patrón: cada grupo de dígitos pasa a `#` (8.5-apache → #.#-apache). */
    public static function fromTag(string $tag): string
    {
        return preg_replace('/\d+/', '#', $tag);
    }

    /**
     * Si el patrón no encuentra nada, propone una variante que sí lo haga (p. ej. `-slim` → `*-slim`).
     *
     * @param list<string> $tags
     * @return array{pattern:string, count:int}|null
     */
    public static function suggest(string $pattern, array $tags): ?array
    {
        if ($pattern === '' || $tags === []) {
            return null;
        }
        $candidates = [];
        if (!self::hasWildcards($pattern)) {
            $candidates = ['*' . $pattern, $pattern . '*', '*' . $pattern . '*'];
        }
        $candidates[] = self::fromTag($pattern);
        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === $pattern) {
                continue;
            }
            $regex = self::regex($candidate);
            $count = count(array_filter($tags, fn ($t) => self::matches((string) $t, $candidate, $regex)));
            if ($count > 0) {
                return ['pattern' => $candidate, 'count' => $count];
            }
        }
        return null;
    }

    /** Compara los grupos numéricos como enteros (1.10 > 1.9); a igualdad, el más largo gana. */
    public static function compareVersions(string $a, string $b): int
    {
        preg_match_all('/\d+/', $a, $ma);
        preg_match_all('/\d+/', $b, $mb);
        $na = array_map('intval', $ma[0]);
        $nb = array_map('intval', $mb[0]);
        for ($i = 0, $n = min(count($na), count($nb)); $i < $n; $i++) {
            if ($na[$i] !== $nb[$i]) {
                return $na[$i] <=> $nb[$i];
            }
        }
        return (count($na) <=> count($nb)) ?: strcmp($a, $b);
    }
}
