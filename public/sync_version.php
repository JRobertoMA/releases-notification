<?php

declare(strict_types=1);

/**
 * Marca como instalada la última versión detectada (my_version = last_seen_release, my_updated_at = hoy).
 * Acepta un id (botón de la fila) o ids[] (acción masiva). Lo mismo que «✅ Ya actualicé» en Telegram (App\RepoActions).
 */

require __DIR__ . '/../includes/bootstrap.php';

use App\RepoActions;

require_permission('repos.write');
require_post_csrf();

$ids = post_ids();
if ($ids === []) {
    redirect_with('No se indicó qué repositorio sincronizar.', 'danger');
}

$result = RepoActions::sync(repo_store(), $ids);

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
