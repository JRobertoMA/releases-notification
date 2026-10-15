<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

use App\Repo;
use App\OciRegistry;
use App\RepoInput;
use App\RepoStore;

require_post_csrf();

$type  = (string) ($_POST['repo_type'] ?? '');
$input = trim((string) ($_POST['repo_name'] ?? ''));

if (!isset(Repo::TYPES[$type])) {
    redirect_with('Elige un tipo de repositorio válido.', 'danger');
}

// Acepta URL pegada (github.com/…, hub.docker.com/…, ghcr.io/…:tag): el tipo detectado manda
$resolved = RepoInput::resolve($type, $input);
if ($resolved === null) {
    redirect_with(match ($type) {
        'oci'    => 'Nombre de imagen OCI no válido. Usa host/owner/imagen, p. ej. ghcr.io/linuxserver/jellyfin.',
        'docker' => 'Nombre de imagen no válido. Usa imagen, _/imagen u owner/imagen.',
        default  => 'Nombre no válido. Usa owner/repo o pega la URL del repositorio.',
    }, 'danger');
}
$type = $resolved['type'];
$name = $resolved['name'];

if ($type === 'oci' && !OciRegistry::isAllowed($name)) {
    redirect_with('Registro no permitido: ' . OciRegistry::hostOf($name)
        . '. Solo se consultan registros conocidos; para añadir otro, inclúyelo en OCI_REGISTRIES de config.json.', 'warning');
}

$tags    = parse_tags((string) ($_POST['repo_tags'] ?? ''));
$pattern = trim((string) ($_POST['tag_pattern'] ?? ''));

$added = repo_store()->update(function (array &$repos) use ($type, $name, $tags, $pattern) {
    foreach ($repos as $repo) {
        if ($repo['name'] === $name && $repo['type'] === $type) {
            return false;
        }
    }
    $new = [
        'id'                => RepoStore::newId($repos),
        'name'              => $name,
        'type'              => $type,
        'tags'              => $tags,
        'last_seen_release' => null,
    ];
    if (Repo::hasTagPattern($type) && $pattern !== '') {
        $new['tag_pattern'] = $pattern;
    }
    $repos[] = $new;
    return true;
});

$added
    ? redirect_with("Repositorio «{$name}» agregado. Usa Verificar para consultar su última versión.")
    : redirect_with("El repositorio «{$name}» (" . Repo::TYPES[$type] . ') ya existe.', 'warning');
