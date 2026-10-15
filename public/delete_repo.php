<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

use App\RepoStore;

require_permission('repos.write');
require_post_csrf();

$ids = post_ids();
if (count($ids) !== 1) {
    redirect_with('No se indicó qué repositorio eliminar.', 'danger');
}

$deleted = repo_store()->update(function (array &$repos) use ($ids) {
    $i = RepoStore::indexOf($repos, $ids[0]);
    if ($i === null) {
        return null;
    }
    $name = $repos[$i]['name'];
    array_splice($repos, $i, 1);
    return $name;
});

$deleted === null
    ? redirect_with('El repositorio ya no existe.', 'warning')
    : redirect_with("Repositorio «{$deleted}» eliminado.");
