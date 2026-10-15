<?php

declare(strict_types=1);

/**
 * GET api/tags.php?type=docker|oci&repo=<nombre o URL>&pattern=<patrón>
 *
 * Tags recientes de la imagen y vista previa del patrón: cuáles coinciden, cuál se seguiría y, si ninguno
 * coincide, un patrón alternativo que sí lo haga. Usa DockerHub/OciRegistry, las mismas clases que
 * check_releases.php, así que la vista previa aplica las reglas reales.
 */

require __DIR__ . '/../../includes/bootstrap.php';

use App\DockerHub;
use App\OciRegistry;
use App\RepoInput;
use App\TagPattern;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

require_permission('repos.check');

$type    = (string) ($_GET['type'] ?? '');
$repo    = trim((string) ($_GET['repo'] ?? ''));
$pattern = trim((string) ($_GET['pattern'] ?? ''));

if (!in_array($type, ['docker', 'oci'], true)) {
    json_out(null, 422, 'Tipo no válido');
}
$resolved = RepoInput::resolve($type, $repo);
if ($resolved === null || !in_array($resolved['type'], ['docker', 'oci'], true)) {
    json_out(null, 422, $type === 'oci'
        ? 'Nombre de imagen no válido. Usa host/owner/imagen, p. ej. ghcr.io/linuxserver/jellyfin.'
        : 'Nombre de imagen no válido. Usa imagen, _/imagen u owner/imagen.');
}

if ($resolved['type'] === 'oci' && !OciRegistry::isAllowed($resolved['name'])) {
    json_out(null, 422, 'Registro no permitido: ' . OciRegistry::hostOf($resolved['name'])
        . '. Un administrador puede permitirlo en Configuración → Registros OCI permitidos.');
}

$client = new Client();
try {
    if ($resolved['type'] === 'docker') {
        $raw      = DockerHub::fetchTags($client, $resolved['name'], $pattern);
        $tags     = array_map(fn ($t) => ['name' => $t['name'], 'updated' => $t['updated']], $raw);
        $selected = DockerHub::select($raw, $pattern);
        $approx   = false;
        $source   = 'dockerhub';
        $rule     = match (true) {
            $pattern === ''                       => 'el tag actualizado más recientemente, sin contar latest',
            TagPattern::prefersHighest($pattern)  => 'la versión más alta que coincide',
            TagPattern::hasWildcards($pattern)    => 'el más reciente que coincide',
            default                               => 'tag exacto; avisa cuando cambia su contenido',
        };
    } else {
        $preview  = OciRegistry::preview($client, $resolved['name'], $pattern, (string) cfg('GITHUB_TOKEN'));
        $tags     = $preview['tags'];
        $selected = $preview['selected'];
        $approx   = $preview['approximate'];
        $source   = $preview['source'];
        $rule     = match (true) {
            !TagPattern::hasWildcards($pattern) && $pattern !== '' => 'tag exacto; avisa cuando cambia su contenido',
            TagPattern::prefersHighest($pattern)                   => 'la versión más alta que coincide',
            $source === 'packages' && $pattern === ''              => 'el tag más reciente, sin latest ni tags de arquitectura',
            $source === 'packages'                                 => 'el más reciente que coincide',
            $pattern === ''                                        => 'latest, por digest (este registro no da fechas)',
            default                                                => 'la versión más alta que coincide',
        };
    }
} catch (RequestException $e) {
    $code = $e->getResponse()?->getStatusCode();
    if ($code === 429) {
        json_out(null, 503, 'El registro limitó las peticiones (IP compartida). Prueba de nuevo en unos minutos.');
    }
    json_out(null, 502, $code === 404 ? 'Imagen no encontrada en el registro.' : 'El registro no respondió (' . ($code ?: 'red') . ').');
} catch (GuzzleException | RuntimeException $e) {
    if ($e->getCode() === 429) {
        json_out(null, 503, 'El registro limitó las peticiones (IP compartida). Prueba de nuevo en unos minutos.');
    }
    json_out(null, 502, $e->getMessage());
}

$names   = array_column($tags, 'name');
$regex   = TagPattern::regex($pattern);
$matches = $pattern === ''
    ? []
    : array_values(array_filter($names, fn ($n) => TagPattern::matches((string) $n, $pattern, $regex)));

json_out([
    'type'        => $resolved['type'],
    'name'        => $resolved['name'],
    'tags'        => array_slice($tags, 0, 100),
    'matches'     => $matches,
    'selected'    => $selected,
    'suggestion'  => $pattern !== '' && $matches === [] ? TagPattern::suggest($pattern, $names) : null,
    'approximate' => $approx,
    'source'      => $source,
    'rule'        => $rule,
]);
