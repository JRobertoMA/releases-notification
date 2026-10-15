<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

use App\Repo;
use App\RepoStore;

$id = (string) ($_GET['id'] ?? '');
if (!RepoStore::isValidId($id) || ($repo = repo_store()->find($id)) === null) {
    redirect_with('Repositorio no encontrado.', 'danger');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $tags          = parse_tags((string) ($_POST['repo_tags'] ?? ''));
    $tag_pattern   = trim((string) ($_POST['tag_pattern'] ?? ''));
    $my_version    = trim((string) ($_POST['my_version'] ?? ''));
    $my_updated_at = trim((string) ($_POST['my_updated_at'] ?? ''));
    $last_seen     = trim((string) ($_POST['last_seen_release'] ?? ''));

    if ($my_updated_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $my_updated_at)) {
        redirect_with('La fecha de actualización no es válida.', 'danger', 'edit_repo.php?id=' . $id);
    }

    $name = repo_store()->update(function (array &$repos) use ($id, $tags, $tag_pattern, $my_version, $my_updated_at, $last_seen) {
        $i = RepoStore::indexOf($repos, $id);
        if ($i === null) {
            return null;
        }
        $r = &$repos[$i];
        $r['tags'] = $tags;

        if (Repo::hasTagPattern($r['type'])) {
            if ($tag_pattern !== '') {
                $r['tag_pattern'] = $tag_pattern;
            } else {
                unset($r['tag_pattern']);
            }
        }
        foreach (['my_version' => $my_version, 'my_updated_at' => $my_updated_at] as $key => $value) {
            if ($value !== '') {
                $r[$key] = $value;
            } else {
                unset($r[$key]);
            }
        }
        // Corregir la versión registrada borra el digest, para que la próxima verificación lo actualice
        // sin disparar un aviso de «contenido actualizado»
        $new_last = $last_seen !== '' ? $last_seen : null;
        if (($r['last_seen_release'] ?? null) !== $new_last) {
            $r['last_seen_release'] = $new_last;
            unset($r['last_seen_digest']);
        }
        return $r['name'];
    });

    $name === null
        ? redirect_with('El repositorio ya no existe.', 'warning')
        : redirect_with("Repositorio «{$name}» actualizado.");
}

$type      = $repo['type'] ?? 'github';
$web_url   = Repo::webUrl($repo);
$releases  = Repo::releasesUrl($repo);
$latest    = $repo['last_seen_release'] ?? '';
$status    = $repo['check_status'] ?? null;

$page_title = 'Editar ' . $repo['name'];
$active     = 'repos';
require __DIR__ . '/../includes/header.php';
?>
<nav aria-label="Ruta" class="mb-2">
    <ol class="breadcrumb mb-0 small">
        <li class="breadcrumb-item"><a href="index.php">Repositorios</a></li>
        <li class="breadcrumb-item active" aria-current="page">Editar</li>
    </ol>
</nav>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1 text-break"><?= h($repo['name']) ?></h1>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?= type_badge($type) ?>
            <?= status_badge($status) ?>
            <span class="meta">Verificado <?= time_tag($repo['last_checked'] ?? null, 'nunca') ?></span>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="history.php?repo=<?= urlencode($repo['name']) ?>" class="btn btn-outline-secondary btn-sm"><?= icon('clock-history', 'me-1') ?>Historial</a>
        <?php if ($web_url): ?>
            <a href="<?= h($web_url) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><?= icon('box-arrow-up-right', 'me-1') ?>Abrir en <?= h(Repo::TYPES[$type] ?? $type) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if (in_array($status, ['no_match', 'error'], true)): ?>
    <div class="alert <?= $status === 'error' ? 'alert-danger' : 'alert-warning' ?> py-2">
        <?= h(Repo::STATUS[$status]['hint']) ?>
        <?php if ($status === 'error'): ?><a href="logs.php" class="alert-link">Ver logs</a>.<?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="edit_repo.php?id=<?= h($repo['id']) ?>" class="card">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="mb-4">
            <label for="repo_tags" class="form-label">Etiquetas <span class="text-body-secondary fw-normal">(separadas por coma)</span></label>
            <input type="text" class="form-control" id="repo_tags" name="repo_tags"
                   value="<?= h(implode(', ', $repo['tags'] ?? [])) ?>" placeholder="media, gaming, self-hosted">
        </div>

        <?php if (Repo::hasTagPattern($type)): ?>
            <div class="mb-4" id="pattern-block">
                <?php $pattern_value = $repo['tag_pattern'] ?? ''; require __DIR__ . '/../includes/pattern_widget.php'; ?>
            </div>
        <?php endif; ?>

        <h2 class="h6 text-body-secondary border-top pt-3">Último release conocido</h2>
        <div class="row g-3 mb-4 align-items-end">
            <div class="col-md-5">
                <label for="last_seen_release" class="form-label">Versión registrada</label>
                <input type="text" class="form-control font-monospace" id="last_seen_release" name="last_seen_release"
                       value="<?= h($latest) ?>" placeholder="v1.2.3" aria-describedby="last_seen_help">
            </div>
            <div class="col-md-3">
                <span class="form-label d-block">Fecha del release</span>
                <span class="form-control-plaintext"><?= time_tag($repo['last_release_date'] ?? null, '—', true) ?></span>
            </div>
            <div class="col-md-4">
                <?php if ($releases): ?>
                    <a href="<?= h($releases['url']) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><?= icon('box-arrow-up-right', 'me-1') ?><?= h($releases['label']) ?></a>
                <?php endif; ?>
            </div>
            <div class="col-12">
                <div id="last_seen_help" class="form-text mt-0">Corrígela solo si la verificación detectó una versión equivocada. Al cambiarla se borra el digest para que la próxima verificación lo actualice.</div>
            </div>
        </div>

        <h2 class="h6 text-body-secondary border-top pt-3">Versión instalada <span class="fw-normal">(registro personal, no afecta la verificación)</span></h2>
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="my_version" class="form-label">Versión instalada</label>
                <input type="text" class="form-control font-monospace" id="my_version" name="my_version"
                       value="<?= h($repo['my_version'] ?? '') ?>" placeholder="v1.2.3">
            </div>
            <div class="col-md-3">
                <label for="my_updated_at" class="form-label">Fecha de actualización</label>
                <input type="date" class="form-control" id="my_updated_at" name="my_updated_at" value="<?= h($repo['my_updated_at'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <?php if ($latest !== ''): ?>
                    <button type="button" class="btn btn-outline-warning btn-sm" id="use-latest"
                            data-version="<?= h($latest) ?>" data-today="<?= date('Y-m-d') ?>"><?= icon('check2-circle', 'me-1') ?>Usar <?= h($latest) ?>, hoy</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 justify-content-between">
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?= icon('check2', 'me-1') ?>Guardar cambios</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
        </div>
        <button type="button" class="btn btn-outline-danger"
                data-bs-toggle="modal" data-bs-target="#confirmModal"
                data-action="delete_repo.php" data-params="<?= h(json_encode(['id' => $repo['id']])) ?>"
                data-title="Eliminar repositorio"
                data-body="¿Eliminar «<?= h($repo['name']) ?>»? Se pierde su configuración (patrón, versión instalada). El historial de releases se conserva."
                data-variant="danger" data-confirm-label="Eliminar"><?= icon('trash', 'me-1') ?>Eliminar…</button>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    'use strict';
    var block = document.getElementById('pattern-block');
    if (block) {
        var name = <?= js($repo['name']) ?>, type = <?= js($type) ?>;
        App.patternWidget(block.querySelector('[data-pattern-widget]'), {
            type: function () { return type; },
            name: function () { return name; }
        }).refresh();
    }
    var useLatest = document.getElementById('use-latest');
    if (useLatest) {
        useLatest.addEventListener('click', function () {
            document.getElementById('my_version').value    = useLatest.dataset.version;
            document.getElementById('my_updated_at').value = useLatest.dataset.today;
        });
    }
})();
</script>
</body>
</html>
