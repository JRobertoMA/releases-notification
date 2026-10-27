<?php

declare(strict_types=1);

/**
 * «Ignorar» la versión detectada: deja de contar como pendiente hasta que salga otra. undo=1 lo deshace.
 * Lo mismo que «🔕 Ignorar» en Telegram (App\RepoActions).
 */

require __DIR__ . '/../includes/bootstrap.php';

use App\RepoActions;
use App\RepoStore;

require_permission('repos.write');
require_post_csrf();

$id = (string) ($_POST['id'] ?? '');
if (!RepoStore::isValidId($id)) {
    redirect_with('No se indicó qué repositorio.', 'danger');
}
$undo = !empty($_POST['undo']);

try {
    $repo = RepoActions::ignore(repo_store(), $id, !$undo);
} catch (InvalidArgumentException $e) {
    redirect_with($e->getMessage(), 'danger');
}
redirect_with($undo
    ? "«{$repo['name']}» vuelve a contar como pendiente si no tienes instalada la {$repo['last_seen_release']}."
    : "Ignorada la {$repo['ignored_release']} de «{$repo['name']}»: no contará como pendiente hasta que salga otra versión.", $undo ? 'info' : 'success');
