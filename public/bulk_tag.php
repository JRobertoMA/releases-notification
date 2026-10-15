<?php

declare(strict_types=1);

/** Acción masiva: añadir o quitar una etiqueta a los repos seleccionados (ids[], tag, mode=add|remove). */

require __DIR__ . '/../includes/bootstrap.php';

require_permission('repos.write');
require_post_csrf();

$ids  = post_ids();
$tags = parse_tags((string) ($_POST['tag'] ?? ''));
$mode = ($_POST['mode'] ?? 'add') === 'remove' ? 'remove' : 'add';

if ($ids === [] || $tags === []) {
    redirect_with('Selecciona repositorios y escribe una etiqueta.', 'warning');
}

$changed = repo_store()->update(function (array &$repos) use ($ids, $tags, $mode) {
    $n = 0;
    foreach ($repos as &$repo) {
        if (!in_array($repo['id'], $ids, true)) {
            continue;
        }
        $before       = $repo['tags'] ?? [];
        $repo['tags'] = $mode === 'add'
            ? array_values(array_unique(array_merge($before, $tags)))
            : array_values(array_diff($before, $tags));
        $n += $repo['tags'] !== $before ? 1 : 0;
    }
    unset($repo);
    return $n;
});

$label = implode(', ', $tags);
redirect_with($mode === 'add'
    ? "Etiqueta «{$label}» añadida a {$changed} repositorio(s)."
    : "Etiqueta «{$label}» quitada de {$changed} repositorio(s).");
