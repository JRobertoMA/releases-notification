<?php

declare(strict_types=1);

/**
 * Marca como instalada la última versión detectada (my_version = last_seen_release, my_updated_at = hoy).
 * Acepta un id (botón de la fila) o ids[] (acción masiva).
 */

require __DIR__ . '/../includes/bootstrap.php';

require_post_csrf();

$ids = post_ids();
if ($ids === []) {
    redirect_with('No se indicó qué repositorio sincronizar.', 'danger');
}

$result = repo_store()->update(function (array &$repos) use ($ids) {
    $synced  = [];
    $skipped = [];
    foreach ($repos as &$repo) {
        if (!in_array($repo['id'], $ids, true)) {
            continue;
        }
        if (empty($repo['last_seen_release'])) {
            $skipped[] = $repo['name'];
            continue;
        }
        $repo['my_version']    = $repo['last_seen_release'];
        $repo['my_updated_at'] = date('Y-m-d');
        $synced[] = $repo['name'] . ' → ' . $repo['last_seen_release'];
    }
    unset($repo);
    return ['synced' => $synced, 'skipped' => $skipped];
});

if ($result['synced'] === []) {
    redirect_with('No había una versión detectada que sincronizar.', 'warning');
}
$message = count($result['synced']) === 1
    ? 'Versión instalada sincronizada: ' . $result['synced'][0] . '.'
    : count($result['synced']) . ' repositorios sincronizados.';
if ($result['skipped'] !== []) {
    $message .= ' Sin versión detectada: ' . implode(', ', $result['skipped']) . '.';
}
redirect_with($message);
