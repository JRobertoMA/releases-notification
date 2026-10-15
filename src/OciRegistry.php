<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Consulta de imágenes en registros OCI (ghcr.io, lscr.io, quay.io…).
 *
 * Dos vías:
 *  1. ghcr.io / lscr.io con GITHUB_TOKEN (scope read:packages) → API de Packages de GitHub: una petición,
 *     versiones ordenadas por fecha, con tags y digest. Es la vía principal.
 *  2. Registro OCI anónimo (/v2/…): el resto de hosts, o respaldo si la API de Packages falla. La lista de tags
 *     no trae fechas ni orden cronológico, así que sin patrón se sigue `latest` por digest y con comodines gana
 *     la versión numéricamente más alta.
 *
 * latest() devuelve el mismo formato que get_latest_docker_tag() en check_releases.php.
 */
final class OciRegistry
{
    /** Prefijos de arquitectura que algunas imágenes (linuxserver) publican como tags sueltos. */
    private const ARCH_PREFIX = '/^(amd64|arm64v8|arm64|arm32v7|arm32v6|armhf|armv7|i386|ppc64le|s390x|riscv64)-/';

    private const MANIFEST_ACCEPT = 'application/vnd.oci.image.index.v1+json, '
        . 'application/vnd.docker.distribution.manifest.list.v2+json, '
        . 'application/vnd.oci.image.manifest.v1+json, '
        . 'application/vnd.docker.distribution.manifest.v2+json';

    private const PACKAGES_PER_PAGE = 100;
    private const PACKAGES_MAX_PAGES = 3;
    private const REGISTRY_MAX_PAGES = 20;
    private const TIMEOUT = 20;

    /** Tokens anónimos del registro, por host/ruta, durante este proceso. */
    private static array $tokens = [];

    /**
     * Registros a los que la app puede hacer peticiones (SECURITY_AUDIT.md §10: sin lista blanca, un usuario
     * podía hacer que el servidor consultara cualquier host, incluidos los de la red interna).
     * Se amplía con OCI_REGISTRIES en config.json (setAllowedHosts).
     */
    public const DEFAULT_ALLOWED_HOSTS = [
        'ghcr.io', 'lscr.io', 'quay.io', 'registry.gitlab.com', 'public.ecr.aws', 'gcr.io', 'mcr.microsoft.com',
    ];
    private static array $allowedHosts = self::DEFAULT_ALLOWED_HOSTS;

    /** Redirecciones: pocas y solo HTTPS (los blobs de ghcr.io redirigen a su CDN). */
    private const REDIRECTS = ['max' => 3, 'protocols' => ['https'], 'strict' => false, 'referer' => false];

    /** @param list<string>|null $hosts  los de config.json (OCI_REGISTRIES); se suman a los de por defecto */
    public static function setAllowedHosts(?array $hosts): void
    {
        $extra = array_map(fn ($h) => strtolower(trim((string) $h)), $hosts ?? []);
        self::$allowedHosts = array_values(array_unique(array_filter(array_merge(self::DEFAULT_ALLOWED_HOSTS, $extra))));
    }

    /** @return list<string> */
    public static function allowedHosts(): array
    {
        return self::$allowedHosts;
    }

    public static function isAllowed(string $name): bool
    {
        return in_array(self::split($name)[0], self::$allowedHosts, true);
    }

    public static function hostOf(string $name): string
    {
        return self::split($name)[0];
    }

    /**
     * Deja el nombre como host/ruta: sin esquema, sin :tag y sin @digest. Sin host asume ghcr.io.
     * Devuelve null si el formato no es válido.
     */
    public static function normalizeName(string $input): ?string
    {
        $n = strtolower(trim($input));
        $n = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $n);
        $n = preg_replace('/@sha256:[0-9a-f]+$/', '', $n);
        $n = preg_replace('#(/[^/:]+):[^/]+$#', '$1', $n);   // :tag del último segmento (el host puede llevar :puerto)
        $n = trim($n, '/');

        $parts = explode('/', $n);
        if (!self::looksLikeHost($parts[0])) {
            array_unshift($parts, 'ghcr.io');
        }
        $host = array_shift($parts);
        if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host) || count($parts) === 0) {
            return null;
        }
        if (self::isGhcr($host) && count($parts) < 2) {
            return null;   // ghcr.io siempre es owner/imagen
        }
        foreach ($parts as $p) {
            if (!preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/', $p)) {
                return null;
            }
        }
        return $host . '/' . implode('/', $parts);
    }

    /** URL pública para abrir la imagen en el navegador, o null si el host no tiene una conocida. */
    public static function webUrl(string $name): ?string
    {
        [$host, $path] = self::split($name);
        if (self::isGhcr($host)) {
            return 'https://ghcr.io/' . $path;   // redirige a la página del paquete en GitHub
        }
        if ($host === 'quay.io') {
            return 'https://quay.io/repository/' . $path;
        }
        return null;
    }

    /**
     * Último tag según el patrón.
     *
     * @return array{tag:?string, digest:?string, released_at:?string, reason?:string}|null
     */
    public static function latest(Client $client, string $name, string $pattern, string $githubToken): ?array
    {
        if (!self::isAllowed($name)) {
            error_log("OCI registry not allowed: {$name}");
            return null;
        }
        [$host, $path] = self::split($name);

        if (self::isGhcr($host) && $githubToken !== '') {
            $result = self::latestFromPackages($client, $path, $pattern, $githubToken);
            if ($result !== false) {
                return $result;
            }
        }
        return self::latestFromRegistry($client, self::registryHost($host), $path, $pattern);
    }

    /**
     * Tags recientes para el selector de variantes.
     *
     * @return list<array{name:string, updated:?string}>
     */
    public static function listTags(Client $client, string $name, string $githubToken): array
    {
        if (!self::isAllowed($name)) {
            throw new RuntimeException('Registro no permitido: ' . self::hostOf($name));
        }
        [$host, $path] = self::split($name);

        if (self::isGhcr($host) && $githubToken !== '') {
            $versions = self::packageVersions($client, $path, $githubToken, 1);
            if ($versions !== false) {
                $tags = [];
                foreach ($versions as $v) {
                    foreach (self::cleanTags($v['metadata']['container']['tags'] ?? []) as $t) {
                        $tags[$t] ??= ['name' => $t, 'updated' => $v['updated_at'] ?? null];
                    }
                }
                return array_values($tags);
            }
        }

        $registry = self::registryHost($host);
        $response = self::registryRequest($client, 'GET', $registry, $path, "/v2/{$path}/tags/list?n=1000");
        // Los registros responden 401 (no 404) a imágenes inexistentes o privadas
        if (in_array($response->getStatusCode(), [401, 403, 404], true)) {
            throw new RuntimeException('Imagen no encontrada en el registro (o es privada)');
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('El registro respondió HTTP ' . $response->getStatusCode());
        }
        $tags = self::cleanTags(json_decode((string) $response->getBody(), true)['tags'] ?? []);
        usort($tags, fn ($a, $b) => TagPattern::compareVersions($b, $a));
        return array_map(fn ($t) => ['name' => $t, 'updated' => null], array_slice($tags, 0, 100));
    }

    /**
     * Vista previa para el editor de patrones: tags recientes y cuál se seguiría con el patrón dado.
     * `approximate` = el resultado puede diferir de la verificación real (solo se miró la primera página).
     *
     * @return array{tags:list<array{name:string, updated:?string}>, selected:?array, approximate:bool, source:string}
     */
    public static function preview(Client $client, string $name, string $pattern, string $githubToken): array
    {
        if (!self::isAllowed($name)) {
            throw new RuntimeException('Registro no permitido: ' . self::hostOf($name));
        }
        [$host, $path] = self::split($name);

        if (self::isGhcr($host) && $githubToken !== '') {
            $versions = self::packageVersions($client, $path, $githubToken, 1);
            if ($versions !== false) {
                $tags = [];
                foreach ($versions as $v) {
                    foreach (self::cleanTags($v['metadata']['container']['tags'] ?? []) as $t) {
                        $tags[$t] ??= ['name' => $t, 'updated' => $v['updated_at'] ?? null];
                    }
                }
                $selected = self::selectFromVersions($versions, $pattern);
                return [
                    'tags'        => array_values($tags),
                    'selected'    => $selected,
                    'approximate' => $selected === null && count($versions) >= self::PACKAGES_PER_PAGE,
                    'source'      => 'packages',
                ];
            }
        }

        $tags  = self::listTags($client, $name, '');
        $names = array_column($tags, 'name');
        $pick  = null;
        if ($pattern === '') {
            $pick = in_array('latest', $names, true) ? 'latest' : null;
        } elseif (!TagPattern::hasWildcards($pattern)) {
            $pick = in_array($pattern, $names, true) ? $pattern : null;
        } else {
            $regex = TagPattern::regex($pattern);
            foreach ($names as $t) {
                if (preg_match($regex, $t) && ($pick === null || TagPattern::compareVersions($t, $pick) > 0)) {
                    $pick = $t;
                }
            }
        }
        return [
            'tags'        => $tags,
            'selected'    => $pick === null ? null : ['tag' => $pick, 'digest' => null, 'released_at' => null],
            'approximate' => true,
            'source'      => 'registry',
        ];
    }

    // --- API de Packages (ghcr.io) ----------------------------------------------------------------

    /** @return array|null|false  false = la API no sirve (sin permiso, 404, red) → usar el registro. */
    private static function latestFromPackages(Client $client, string $path, string $pattern, string $token): array|null|false
    {
        $versions = self::packageVersions($client, $path, $token, self::PACKAGES_MAX_PAGES);
        if ($versions === false) {
            return false;
        }
        return self::selectFromVersions($versions, $pattern)
            ?? ($pattern !== ''
                ? ['tag' => null, 'digest' => null, 'released_at' => null, 'reason' => 'no_match']
                : null);
    }

    /** La versión más reciente con un tag que encaje (sin patrón: el primer tag que no sea latest ni de arquitectura). */
    private static function selectFromVersions(array $versions, string $pattern): ?array
    {
        $regex = TagPattern::regex($pattern);

        // Con `#` gana la versión más alta (como en Docker Hub); a igualdad, la más reciente (la primera vista)
        if (TagPattern::prefersHighest($pattern)) {
            $best = null;
            foreach ($versions as $v) {
                foreach (self::withoutSignatures($v['metadata']['container']['tags'] ?? []) as $t) {
                    if (TagPattern::matches($t, $pattern, $regex)
                        && ($best === null || TagPattern::compareVersions($t, $best['tag']) > 0)) {
                        $best = [
                            'tag'         => $t,
                            'digest'      => $v['name'] ?? null,
                            'released_at' => $v['updated_at'] ?? ($v['created_at'] ?? null),
                        ];
                    }
                }
            }
            return $best;
        }

        foreach ($versions as $v) {
            $tags = self::withoutSignatures($v['metadata']['container']['tags'] ?? []);
            foreach ($tags as $t) {
                $hit = $pattern !== ''
                    ? TagPattern::matches($t, $pattern, $regex)
                    : ($t !== 'latest' && !preg_match(self::ARCH_PREFIX, $t));
                if ($hit) {
                    return [
                        'tag'         => $t,
                        'digest'      => $v['name'] ?? null,
                        'released_at' => $v['updated_at'] ?? ($v['created_at'] ?? null),
                    ];
                }
            }
        }
        return null;
    }

    /** Versiones de la más reciente a la más antigua, o false si la API no responde 200. */
    private static function packageVersions(Client $client, string $path, string $token, int $maxPages): array|false
    {
        [$owner, $package] = explode('/', $path, 2);
        // /users/ responde también para organizaciones (verificado con linuxserver y home-assistant).
        $url = sprintf(
            'https://api.github.com/users/%s/packages/container/%s/versions',
            rawurlencode($owner),
            rawurlencode($package)
        );

        $all = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $response = $client->get($url, [
                    'query'       => ['per_page' => self::PACKAGES_PER_PAGE, 'page' => $page],
                    'headers'     => [
                        'Authorization'        => 'Bearer ' . $token,
                        'Accept'               => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ],
                    'timeout'     => self::TIMEOUT,
                    'http_errors' => false,
                ]);
            } catch (GuzzleException $e) {
                error_log("GitHub Packages API error for {$path}: " . $e->getMessage());
                return $page === 1 ? false : $all;
            }
            if ($response->getStatusCode() !== 200) {
                if ($page === 1) {
                    $detail = json_decode((string) $response->getBody(), true)['message'] ?? '';
                    error_log("GitHub Packages API HTTP {$response->getStatusCode()} for {$path} ({$detail}), usando el registro");
                    return false;
                }
                break;
            }
            $batch = json_decode((string) $response->getBody(), true) ?: [];
            // La API ya ordena por fecha; se reordena por si acaso, dentro de la página.
            usort($batch, fn ($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
            array_push($all, ...$batch);
            if (count($batch) < self::PACKAGES_PER_PAGE) {
                break;
            }
        }
        return $all;
    }

    // --- Registro OCI anónimo ---------------------------------------------------------------------

    private static function latestFromRegistry(Client $client, string $host, string $path, string $pattern): ?array
    {
        $noMatch = ['tag' => null, 'digest' => null, 'released_at' => null, 'reason' => 'no_match'];
        try {
            if ($pattern === '') {
                $tag = 'latest';   // sin fechas no se puede saber cuál es «el más reciente»
            } elseif (!TagPattern::hasWildcards($pattern)) {
                $tag = $pattern;
            } else {
                $regex = TagPattern::regex($pattern);
                $tag   = null;
                foreach (self::registryTags($client, $host, $path) as $t) {
                    if (preg_match($regex, $t) && ($tag === null || TagPattern::compareVersions($t, $tag) > 0)) {
                        $tag = $t;
                    }
                }
                if ($tag === null) {
                    return $noMatch;
                }
            }

            $manifest = self::manifest($client, $host, $path, $tag);
            if ($manifest === null) {
                return $pattern === '' ? null : $noMatch;
            }
            return [
                'tag'         => $tag,
                'digest'      => $manifest['digest'],
                'released_at' => self::createdAt($client, $host, $path, $manifest['body']),
            ];
        } catch (GuzzleException | RuntimeException $e) {
            if ($e->getCode() === 429) {
                return ['tag' => null, 'digest' => null, 'released_at' => null, 'reason' => 'rate_limited'];
            }
            error_log("OCI registry error for {$host}/{$path}: " . $e->getMessage());
            return null;
        }
    }

    /** Todos los tags siguiendo la cabecera Link, con tope de páginas. */
    private static function registryTags(Client $client, string $host, string $path): array
    {
        $tags = [];
        $next = "/v2/{$path}/tags/list?n=1000";
        for ($i = 0; $next !== null && $i < self::REGISTRY_MAX_PAGES; $i++) {
            $response = self::registryRequest($client, 'GET', $host, $path, $next);
            if ($response->getStatusCode() !== 200) {
                if ($i === 0) {
                    throw new RuntimeException('tags/list HTTP ' . $response->getStatusCode());
                }
                break;
            }
            array_push($tags, ...(json_decode((string) $response->getBody(), true)['tags'] ?? []));
            $next = preg_match('/<([^>]+)>\s*;\s*rel="?next"?/', $response->getHeaderLine('Link'), $m) ? $m[1] : null;
        }
        return $tags;
    }

    /** @return array{digest:string, body:array}|null  null si el tag no existe. */
    private static function manifest(Client $client, string $host, string $path, string $reference): ?array
    {
        $response = self::registryRequest($client, 'GET', $host, $path, "/v2/{$path}/manifests/{$reference}", [
            'Accept' => self::MANIFEST_ACCEPT,
        ]);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException("manifest {$reference} HTTP " . $response->getStatusCode());
        }
        $raw    = (string) $response->getBody();
        $digest = $response->getHeaderLine('Docker-Content-Digest') ?: 'sha256:' . hash('sha256', $raw);
        return ['digest' => $digest, 'body' => json_decode($raw, true) ?: []];
    }

    /** Fecha `created` del config blob (índice → linux/amd64 → config). null si algo falla: no es un error. */
    private static function createdAt(Client $client, string $host, string $path, array $manifest): ?string
    {
        try {
            if (isset($manifest['manifests'])) {
                $child = null;
                foreach ($manifest['manifests'] as $m) {
                    $os   = $m['platform']['os'] ?? '';
                    $arch = $m['platform']['architecture'] ?? '';
                    if ($os === 'linux' && $arch === 'amd64') {
                        $child = $m;
                        break;
                    }
                    if ($child === null && $os !== 'unknown') {
                        $child = $m;   // las atestaciones vienen como os=unknown
                    }
                }
                if ($child === null) {
                    return null;
                }
                $manifest = self::manifest($client, $host, $path, $child['digest'])['body'] ?? [];
            }
            $config = $manifest['config']['digest'] ?? null;
            if ($config === null) {
                return null;
            }
            $response = self::registryRequest($client, 'GET', $host, $path, "/v2/{$path}/blobs/{$config}");
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            return json_decode((string) $response->getBody(), true)['created'] ?? null;
        } catch (GuzzleException | RuntimeException $e) {
            return null;
        }
    }

    /** Petición al registro; si responde 401 con reto Bearer, pide un token anónimo y reintenta una vez. */
    private static function registryRequest(
        Client $client,
        string $method,
        string $host,
        string $path,
        string $uri,
        array $headers = []
    ): ResponseInterface {
        $key     = $host . '/' . $path;
        $options = ['headers' => $headers, 'timeout' => self::TIMEOUT, 'http_errors' => false, 'allow_redirects' => self::REDIRECTS];
        if (isset(self::$tokens[$key])) {
            $options['headers']['Authorization'] = 'Bearer ' . self::$tokens[$key];
        }
        $response = $client->request($method, 'https://' . $host . $uri, $options);
        if ($response->getStatusCode() === 429) {
            throw new RuntimeException('rate_limited', 429);
        }
        if ($response->getStatusCode() !== 401) {
            return $response;
        }

        $challenge = $response->getHeaderLine('WWW-Authenticate');
        if (stripos($challenge, 'Bearer ') !== 0) {
            return $response;
        }
        preg_match_all('/(\w+)="([^"]*)"/', $challenge, $m, PREG_SET_ORDER);
        $params = [];
        foreach ($m as $pair) {
            $params[strtolower($pair[1])] = $pair[2];
        }
        // El realm lo decide el servidor remoto: solo HTTPS y del mismo dominio base que el registro
        // (ghcr.io → ghcr.io/token, registry.gitlab.com → gitlab.com/jwt/auth). Si no, no se sigue.
        if (empty($params['realm']) || !self::isTrustedRealm($params['realm'], $host)) {
            return $response;
        }
        $tokenResponse = $client->get($params['realm'], [
            'query'       => [
                'service' => $params['service'] ?? $host,
                'scope'   => $params['scope'] ?? "repository:{$path}:pull",
            ],
            'timeout'         => self::TIMEOUT,
            'http_errors'     => false,
            'allow_redirects' => self::REDIRECTS,
        ]);
        $data  = json_decode((string) $tokenResponse->getBody(), true) ?: [];
        $token = $data['token'] ?? $data['access_token'] ?? null;
        if ($token === null) {
            return $response;
        }
        self::$tokens[$key] = $token;
        $options['headers']['Authorization'] = 'Bearer ' . $token;
        $response = $client->request($method, 'https://' . $host . $uri, $options);
        if ($response->getStatusCode() === 429) {
            throw new RuntimeException('rate_limited', 429);
        }
        return $response;
    }

    private static function isTrustedRealm(string $realm, string $registryHost): bool
    {
        $parts = parse_url($realm);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'])) {
            return false;
        }
        $base = fn (string $h) => implode('.', array_slice(explode('.', strtolower($h)), -2));
        return $base($parts['host']) === $base($registryHost);
    }

    // --- Utilidades -------------------------------------------------------------------------------

    /** @return array{0:string, 1:string} [host, ruta] */
    private static function split(string $name): array
    {
        $parts = explode('/', $name, 2);
        return count($parts) === 2 && self::looksLikeHost($parts[0])
            ? [$parts[0], $parts[1]]
            : ['ghcr.io', $name];
    }

    private static function looksLikeHost(string $segment): bool
    {
        return strpos($segment, '.') !== false || strpos($segment, ':') !== false || $segment === 'localhost';
    }

    /** lscr.io es un alias de ghcr.io/linuxserver: se consulta como ghcr. */
    private static function isGhcr(string $host): bool
    {
        return $host === 'ghcr.io' || $host === 'lscr.io';
    }

    private static function registryHost(string $host): string
    {
        return $host === 'lscr.io' ? 'ghcr.io' : $host;
    }

    private static function withoutSignatures(array $tags): array
    {
        return array_values(array_filter($tags, fn ($t) => strpos($t, 'sha256-') !== 0));
    }

    /** Sin firmas ni tags por arquitectura: lo que tiene sentido ofrecer como variante. */
    private static function cleanTags(array $tags): array
    {
        return array_values(array_filter(
            self::withoutSignatures($tags),
            fn ($t) => !preg_match(self::ARCH_PREFIX, $t)
        ));
    }
}
