<?php

declare(strict_types=1);

namespace App;

/**
 * Metadatos de presentación y reglas de un repo vigilado, en un solo sitio (antes repetidos en cada página).
 */
final class Repo
{
    public const TYPES = [
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'docker' => 'Docker Hub',
        'oci'    => 'Registro OCI',
    ];

    /** Mismo color por tipo en badges, filtros y gráficos (PROPUESTA_UX §3.2). */
    public const TYPE_BADGE = [
        'github' => 'text-bg-secondary',
        'gitlab' => 'text-bg-warning',
        'docker' => 'text-bg-info',
        'oci'    => 'text-bg-primary',
    ];

    /** Bootstrap Icons por tipo. No hay logo de Docker en Bootstrap Icons (ni en 1.13): boxes / box-seam. */
    public const TYPE_ICON = [
        'github' => 'github',
        'gitlab' => 'gitlab',
        'docker' => 'boxes',
        'oci'    => 'box-seam',
    ];

    public const TYPE_COLOR = [
        'github' => '#6c757d',
        'gitlab' => '#ffc107',
        'docker' => '#0dcaf0',
        'oci'    => '#0d6efd',
    ];

    public const STATUS = [
        'ok'       => ['label' => 'Al día',            'badge' => 'text-bg-success', 'icon' => 'check-circle', 'hint' => 'La última verificación no encontró nada nuevo.'],
        'new'      => ['label' => 'Nuevo',             'badge' => 'text-bg-primary', 'icon' => 'stars', 'hint' => 'La última verificación detectó una versión nueva.'],
        'no_match' => ['label' => 'Sin coincidencias', 'badge' => 'text-bg-orange', 'icon' => 'funnel', 'hint' => 'El patrón de tag no encontró ningún tag en la última verificación. Revisa el patrón.'],
        'error'    => ['label' => 'Error',             'badge' => 'text-bg-danger', 'icon' => 'x-octagon', 'hint' => 'La API no respondió o el repositorio no existe. Mira los logs.'],
    ];

    public static function hasTagPattern(string $type): bool
    {
        return $type === 'docker' || $type === 'oci';
    }

    /**
     * Desactualizado si la versión instalada no es la última detectada, o si es el mismo tag (latest, stable…)
     * pero el release es de un día posterior a la última actualización registrada.
     */
    public static function isOutdated(array $repo): bool
    {
        if (empty($repo['my_version']) || empty($repo['last_seen_release'])) {
            return false;
        }
        if ($repo['my_version'] !== $repo['last_seen_release']) {
            return true;
        }
        if (!empty($repo['my_updated_at']) && !empty($repo['last_release_date'])) {
            // Fecha del release en la zona de la app, no la de la API (UTC): un release de las 20:00 en México es
            // «mañana» en UTC y quedaba pendiente aunque se sincronizara ese mismo día.
            $ts = strtotime($repo['last_release_date']);
            $released = $ts === false ? substr($repo['last_release_date'], 0, 10) : date('Y-m-d', $ts);
            return $released > $repo['my_updated_at'];
        }
        return false;
    }

    /** Orden de la tabla: lo que pide atención primero. */
    public static function priority(array $repo): int
    {
        return match (true) {
            ($repo['check_status'] ?? '') === 'error'    => 0,
            ($repo['check_status'] ?? '') === 'no_match' => 1,
            ($repo['check_status'] ?? '') === 'new'      => 2,
            self::isOutdated($repo)                      => 3,
            default                                      => 4,
        };
    }

    public static function webUrl(array $repo): ?string
    {
        $name = $repo['name'] ?? '';
        return match ($repo['type'] ?? 'github') {
            'github' => 'https://github.com/' . $name,
            'gitlab' => 'https://gitlab.com/' . $name,
            'docker' => DockerHub::webUrl($name),
            'oci'    => OciRegistry::webUrl($name),
            default  => null,
        };
    }

    /** @return array{url:string, label:string}|null  dónde comprobar a mano la versión publicada */
    public static function releasesUrl(array $repo): ?array
    {
        $name = $repo['name'] ?? '';
        switch ($repo['type'] ?? 'github') {
            case 'github':
                return ['url' => "https://github.com/{$name}/releases", 'label' => 'Releases en GitHub'];
            case 'gitlab':
                return ['url' => "https://gitlab.com/{$name}/-/releases", 'label' => 'Releases en GitLab'];
            case 'docker':
                if (!empty($repo['last_seen_release']) && !empty($repo['last_seen_digest'])) {
                    return [
                        'url'   => DockerHub::layerUrl($name, $repo['last_seen_release'], $repo['last_seen_digest']),
                        'label' => 'Capa exacta en Docker Hub',
                    ];
                }
                return ['url' => DockerHub::tagsUrl($name), 'label' => 'Tags en Docker Hub'];
            case 'oci':
                $url = OciRegistry::webUrl($name);
                return $url === null ? null : ['url' => $url, 'label' => 'Paquete en el registro'];
        }
        return null;
    }
}
