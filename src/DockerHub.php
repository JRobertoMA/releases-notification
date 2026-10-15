<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Docker Hub: lista de tags y selección según el patrón. La usan check_releases.php (verificación) y
 * api/tags.php (vista previa en vivo), así que ambas aplican exactamente las mismas reglas.
 *
 * Reglas de selección:
 *  - Sin patrón: el tag actualizado más recientemente, excluyendo `latest`.
 *  - Patrón con `#`: la versión numéricamente más alta (a igualdad, la más reciente). Docker Hub reconstruye
 *    todas las ramas a la vez (8.2-apache … 8.5-apache), así que «la más reciente» saltaba de rama.
 *  - Patrón exacto o solo con `*`: el más reciente que coincida.
 *
 * Docker Hub devuelve como mucho 100 tags por página. Con patrón se pide `?name=<fragmento literal>` para que
 * las imágenes con cientos de variantes (php) no dejen fuera el tag buscado.
 */
final class DockerHub
{
    private const TIMEOUT = 20;

    /** `_/php` y `php` → `library/php`. */
    public static function apiName(string $name): string
    {
        $api = preg_replace('/^_\//', 'library/', $name);
        return strpos($api, '/') === false ? 'library/' . $api : $api;
    }

    public static function isOfficial(string $name): bool
    {
        return strpos($name, '/') === false || strpos($name, '_/') === 0;
    }

    public static function webUrl(string $name): string
    {
        return self::isOfficial($name)
            ? 'https://hub.docker.com/_/' . ltrim(str_replace('_/', '', $name), '/')
            : 'https://hub.docker.com/r/' . $name;
    }

    public static function tagsUrl(string $name): string
    {
        return self::webUrl($name) . '/tags';
    }

    /** Enlace a la capa exacta de un tag+digest en Docker Hub. */
    public static function layerUrl(string $name, string $tag, string $digest): string
    {
        return sprintf(
            'https://hub.docker.com/layers/%s/%s/images/%s',
            self::apiName($name),
            $tag,
            str_replace('sha256:', 'sha256-', $digest)
        );
    }

    /**
     * Tags tal como los devuelve Docker Hub (más recientes primero), filtrados por el literal del patrón.
     *
     * @return list<array{name:string, digest:?string, updated:?string}>
     * @throws GuzzleException
     */
    public static function fetchTags(Client $client, string $name, string $pattern): array
    {
        $query = ['page_size' => $pattern !== '' ? 100 : 50];
        $hint  = TagPattern::literalHint($pattern);
        if ($hint !== '') {
            $query['name'] = $hint;
        }
        $response = $client->get(
            'https://hub.docker.com/v2/repositories/' . self::apiName($name) . '/tags/',
            ['query' => $query, 'timeout' => self::TIMEOUT]
        );
        $data = json_decode((string) $response->getBody(), true) ?: [];
        return array_map(
            fn ($t) => [
                'name'    => (string) $t['name'],
                'digest'  => $t['digest'] ?? null,
                'updated' => $t['last_updated'] ?? null,
            ],
            $data['results'] ?? []
        );
    }

    /**
     * Elige el tag a seguir entre los recibidos.
     *
     * @param list<array{name:string, digest:?string, updated:?string}> $tags
     * @return array{tag:string, digest:?string, released_at:?string}|null
     */
    public static function select(array $tags, string $pattern): ?array
    {
        $regex   = TagPattern::regex($pattern);
        $highest = TagPattern::prefersHighest($pattern);
        $best    = null;
        $bestTs  = 0;

        foreach ($tags as $t) {
            // Sin patrón se omite `latest`: no es un número de versión. Con patrón decide el usuario
            // (tag_pattern = latest sigue ese tag por digest).
            if ($pattern === '' && $t['name'] === 'latest') {
                continue;
            }
            if ($pattern !== '' && !TagPattern::matches($t['name'], $pattern, $regex)) {
                continue;
            }
            $ts = $t['updated'] ? (int) strtotime($t['updated']) : 0;
            if ($highest) {
                $cmp = $best === null ? 1 : TagPattern::compareVersions($t['name'], $best['name']);
                if ($cmp > 0 || ($cmp === 0 && $ts > $bestTs)) {
                    $best   = $t;
                    $bestTs = $ts;
                }
            } elseif ($ts > $bestTs) {
                $best   = $t;
                $bestTs = $ts;
            }
        }

        return $best === null ? null : [
            'tag'         => $best['name'],
            'digest'      => $best['digest'],
            'released_at' => $best['updated'],
        ];
    }

    /**
     * Mismo formato que OciRegistry::latest(): resultado, `reason => no_match` si el patrón no encontró nada
     * (no es un error), o null si falló la API.
     */
    public static function latest(Client $client, string $name, string $pattern): ?array
    {
        $noMatch = ['tag' => null, 'digest' => null, 'released_at' => null, 'reason' => 'no_match'];
        try {
            $tags = self::fetchTags($client, $name, $pattern);
        } catch (GuzzleException $e) {
            // 429: la API limita por IP y en un hosting compartido la IP es de muchos clientes. No es un error
            // del repo: check_releases.php conserva su estado y reintenta en la próxima verificación.
            if ($e instanceof RequestException && $e->getResponse()?->getStatusCode() === 429) {
                return ['tag' => null, 'digest' => null, 'released_at' => null, 'reason' => 'rate_limited'];
            }
            error_log("Docker Hub API Error for {$name}: " . $e->getMessage());
            return null;
        }
        if ($tags === []) {
            // Con filtro `name=` una lista vacía significa que nada contiene el literal: sin coincidencias.
            return TagPattern::literalHint($pattern) !== '' ? $noMatch : null;
        }
        $selected = self::select($tags, $pattern);
        if ($selected === null) {
            return $pattern !== '' ? $noMatch : null;
        }
        return $selected;
    }
}
