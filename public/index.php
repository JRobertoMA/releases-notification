<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

use App\Jobs;
use App\Repo;
use App\RunHealth;

$repos = repo_store()->all();
usort($repos, fn ($a, $b) => Repo::priority($a) <=> Repo::priority($b));

$stats    = ['total' => count($repos), 'ok' => 0, 'new' => 0, 'no_match' => 0, 'error' => 0, 'outdated' => 0];
$all_tags = [];
foreach ($repos as $r) {
    $cs = $r['check_status'] ?? null;
    if ($cs !== null && isset($stats[$cs])) {
        $stats[$cs]++;
    }
    if (Repo::isOutdated($r)) {
        $stats['outdated']++;
    }
    foreach ($r['tags'] ?? [] as $tag) {
        $all_tags[$tag] = true;
    }
}
ksort($all_tags, SORT_NATURAL | SORT_FLAG_CASE);

// Salud del cron (PROPUESTA_UX §1.5)
$interval = max(1, (int) cfg('CHECK_INTERVAL_HOURS'));

// Sin token (.env) o sin chat (Configuración) no se puede avisar: se dice en la portada
$missing_telegram = array_filter([
    (string) cfg('TELEGRAM_BOT_TOKEN') === '' ? 'falta <code>TELEGRAM_BOT_TOKEN</code> en el <code>.env</code>' : null,
    (string) cfg('TELEGRAM_CHAT_ID') === '' ? 'falta elegir el chat en Configuración' : null,
]);
$last_run = RunHealth::lastFullRun();
$health   = RunHealth::status($last_run, $interval);

// Verificación en segundo plano: la recién lanzada (?job=) o la que esté corriendo (cron incluido)
$jobs = new Jobs();
$job  = null;
if (!empty($_GET['job'])) {
    $job = $jobs->get((string) $_GET['job']);
}
if ($job === null || !in_array($job['status'], ['queued', 'running'], true)) {
    $job = $jobs->running() ?? ($job !== null && in_array($job['status'], ['busy', 'failed'], true) ? $job : null);
}

// Controles según el rol (la decisión real la toma cada endpoint con require_permission())
$can_write = can('repos.write');
$can_check = can('repos.check');

$page_title = 'Repositorios';
$active     = 'repos';
require __DIR__ . '/../includes/header.php';

$stat_cards = [
    ['key' => '',         'label' => 'Total',              'icon' => 'collection',  'class' => 'bg-body-secondary',  'value' => $stats['total']],
    ['key' => 'ok',       'label' => 'Al día',             'icon' => 'check-circle', 'class' => 'bg-success-subtle text-success-emphasis', 'value' => $stats['ok']],
    ['key' => 'new',      'label' => 'Nuevo',              'icon' => 'stars', 'class' => 'bg-primary-subtle text-primary-emphasis', 'value' => $stats['new']],
    ['key' => 'outdated', 'label' => 'Pendientes',         'icon' => 'exclamation-triangle', 'class' => 'bg-warning-subtle text-warning-emphasis', 'value' => $stats['outdated'], 'hint' => 'Tu versión instalada no es la última detectada'],
    ['key' => 'no_match', 'label' => 'Sin coincidencias',  'icon' => 'funnel', 'class' => 'bg-orange-subtle text-orange-emphasis',   'value' => $stats['no_match'], 'hint' => 'El patrón de tag no encontró ningún tag'],
    ['key' => 'error',    'label' => 'Con error',          'icon' => 'x-octagon', 'class' => 'bg-danger-subtle text-danger-emphasis',   'value' => $stats['error']],
];
?>
<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">Repositorios</h1>
        <div class="meta" id="run-health">
            <?php if ($health['level'] === 'never'): ?>
                Aún no hay registro de una verificación completa.
            <?php elseif ($health['level'] === 'late'): ?>
                <span class="badge text-bg-danger">¿Cron detenido?</span>
                Última verificación completa <?= time_tag($last_run['finished_at']) ?>; se espera cada <?= $interval ?> h.
            <?php else: ?>
                Última verificación completa <?= time_tag($last_run['finished_at']) ?>
                <?php if (!empty($last_run['source']) && $last_run['source'] !== 'log'): ?>
                    (<?= h(['cron' => 'cron', 'web' => 'desde la web', 'http' => 'cron HTTP'][$last_run['source']] ?? $last_run['source']) ?>)
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($can_write): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><?= icon('plus-lg', 'me-1') ?>Agregar</button>
        <?php endif; ?>
        <?php if ($can_check): ?>
        <button type="button" class="btn btn-outline-success"
                data-bs-toggle="modal" data-bs-target="#confirmModal"
                data-action="run_check.php" data-params='{"all":1}'
                data-title="Verificar todos los repositorios"
                data-body="Se consultarán los <?= $stats['total'] ?> repositorios en segundo plano. Puedes seguir usando la página mientras tanto."
                data-variant="success" data-confirm-label="Verificar todo"
                <?= $job ? 'disabled' : '' ?>>
            <?= icon('arrow-repeat', 'me-1') ?>Verificar todo
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($missing_telegram !== []): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
        <?= icon('exclamation-triangle-fill', 'mt-1') ?>
        <div>
            <strong>Los avisos de Telegram no están configurados:</strong> <?= implode('; ', $missing_telegram) ?>.
            <?php if (can('settings')): ?>
                <a href="settings.php" class="alert-link">Ir a Configuración</a>
            <?php else: ?>
                Avisa a un administrador.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div id="job-progress" class="card card-body mb-3 run-status" hidden>
    <div class="job-text small" aria-live="polite">Verificación en curso…</div>
    <div class="progress mt-2" role="progressbar" aria-label="Progreso de la verificación" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
        <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
    </div>
</div>

<div class="row row-cols-2 row-cols-sm-3 row-cols-lg-6 g-2 mb-3" role="group" aria-label="Filtrar por estado">
    <?php foreach ($stat_cards as $c): ?>
        <div class="col">
            <button type="button" class="card stat-card w-100 h-100 text-center <?= $c['class'] ?>"
                    data-stat="<?= h($c['key']) ?>" aria-pressed="false"
                    <?= isset($c['hint']) ? 'title="' . h($c['hint']) . '"' : '' ?>>
                <span class="card-body py-2 px-1 w-100">
                    <span class="d-block stat-value"><?= $c['value'] ?></span>
                    <span class="d-block small"><?= icon($c['icon'], 'me-1') ?><?= h($c['label']) ?></span>
                </span>
            </button>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-2 mb-3 align-items-center">
    <div class="col-12 col-md-4">
        <label for="filter-search" class="visually-hidden">Buscar por nombre</label>
        <input type="search" id="filter-search" class="form-control" placeholder="Buscar por nombre…" autocomplete="off">
    </div>
    <div class="col-6 col-md-2">
        <label for="filter-type" class="visually-hidden">Tipo</label>
        <select id="filter-type" class="form-select">
            <option value="">Todos los tipos</option>
            <?php foreach (Repo::TYPES as $k => $label): ?>
                <option value="<?= $k ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label for="filter-status" class="visually-hidden">Estado</label>
        <select id="filter-status" class="form-select">
            <option value="">Todos los estados</option>
            <?php foreach (Repo::STATUS as $k => $s): ?>
                <option value="<?= $k ?>"><?= h($s['label']) ?></option>
            <?php endforeach; ?>
            <option value="unknown">Sin verificar</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label for="filter-tag" class="visually-hidden">Etiqueta</label>
        <select id="filter-tag" class="form-select">
            <option value="">Todas las etiquetas</option>
            <?php foreach (array_keys($all_tags) as $tag): ?>
                <option value="<?= h($tag) ?>"><?= h($tag) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2 d-flex align-items-center gap-2">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="filter-outdated">
            <label class="form-check-label" for="filter-outdated">Solo pendientes</label>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <?php if ($can_write): ?>
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="select-all">
                <label class="form-check-label" for="select-all">Repositorios monitoreados</label>
            </div>
        <?php else: ?>
            <span>Repositorios monitoreados</span>
        <?php endif; ?>
        <div class="d-flex align-items-center gap-2">
            <span id="filter-count" class="meta" aria-live="polite"></span>
            <button type="button" class="btn btn-sm btn-link p-0" id="clear-filters" hidden>Limpiar filtros</button>
        </div>
    </div>
    <div class="repo-table-wrap">
        <table class="table table-hover align-middle mb-0 repo-table">
            <thead>
                <tr>
                    <?php if ($can_write): ?>
                        <th class="c-select" style="width:2.5rem"><span class="visually-hidden">Seleccionar</span></th>
                    <?php endif; ?>
                    <th>Repositorio</th>
                    <th>Tipo</th>
                    <th>Último release</th>
                    <th>Estado</th>
                    <th>Verificado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody id="repos-tbody">
            <?php if ($repos === []): ?>
                <tr class="empty-row">
                    <td colspan="<?= $can_write ? 7 : 6 ?>" class="text-center py-4">
                        Aún no se vigila ningún repositorio.
                        <?php if ($can_write): ?>
                            <button type="button" class="btn btn-link p-0 align-baseline" data-bs-toggle="modal" data-bs-target="#addModal">Agrega el primero</button>.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($repos as $repo):
                    $id       = $repo['id'];
                    $name     = $repo['name'];
                    $type     = $repo['type'] ?? 'github';
                    $latest   = $repo['last_seen_release'] ?? null;
                    $my_ver   = $repo['my_version'] ?? null;
                    $outdated = Repo::isOutdated($repo);
                    $web_url  = Repo::webUrl($repo);
                    $params   = h(json_encode(['id' => $id]));
                    $verify_attrs = 'data-post-action="run_check.php" data-post-params="' . $params . '" data-loading-text="' . h("Verificando {$name}…") . '"';
                    $sync_attrs   = 'data-post-action="sync_version.php" data-post-params="' . $params . '"';
                ?>
                <tr data-id="<?= h($id) ?>"
                    data-name="<?= h(strtolower($name)) ?>"
                    data-type="<?= h($type) ?>"
                    data-status="<?= h($repo['check_status'] ?? 'unknown') ?>"
                    data-tags="<?= h(implode('|', $repo['tags'] ?? [])) ?>"
                    data-outdated="<?= $outdated ? '1' : '0' ?>">
                    <?php if ($can_write): ?>
                        <td class="c-select">
                            <input class="form-check-input row-check" type="checkbox" value="<?= h($id) ?>" aria-label="Seleccionar <?= h($name) ?>">
                        </td>
                    <?php endif; ?>
                    <td class="c-name">
                        <a href="history.php?repo=<?= urlencode($name) ?>" class="fw-semibold link-body-emphasis text-decoration-none text-break"
                           title="Ver historial"><?= h($name) ?></a>
                        <?php if ($web_url): ?>
                            <a href="<?= h($web_url) ?>" target="_blank" rel="noopener" class="link-secondary ms-1 text-decoration-none"
                               aria-label="Abrir <?= h($name) ?> en <?= h(Repo::TYPES[$type] ?? $type) ?> (nueva pestaña)"><?= icon('box-arrow-up-right') ?></a>
                        <?php endif; ?>
                        <?php if (!empty($repo['tags'])): ?>
                            <div class="mt-1">
                                <?php foreach ($repo['tags'] as $tag): ?>
                                    <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis fw-normal"><?= h($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="c-type">
                        <?= type_badge($type) ?>
                        <?php if (!empty($repo['tag_pattern'])): ?>
                            <span class="meta font-monospace ms-1" title="Patrón de tag"><?= h($repo['tag_pattern']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="c-release">
                        <?php if ($latest): ?>
                            <code><?= h($latest) ?></code>
                            <?php if (!empty($repo['last_release_date'])): ?>
                                <span class="meta">· <?= time_tag($repo['last_release_date'], '', true) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="meta">Sin datos</span>
                        <?php endif; ?>
                        <?php if ($my_ver): ?>
                            <div class="meta">
                                Instalada:
                                <span class="font-monospace <?= $outdated ? 'text-warning-emphasis fw-semibold' : 'text-success-emphasis' ?>"><?= h($my_ver) ?></span>
                                <?php if ($outdated): ?>
                                    <?= icon('exclamation-triangle-fill', 'text-warning') ?><span class="visually-hidden">(actualización disponible)</span>
                                <?php endif; ?>
                                <?php if (!empty($repo['my_updated_at'])): ?>· <?= time_tag($repo['my_updated_at'], '', true) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="c-status">
                        <?= status_badge($repo['check_status'] ?? null) ?>
                        <?php if ($outdated): ?>
                            <span class="badge text-bg-warning" title="Tu versión instalada no es la última detectada"><?= icon('exclamation-triangle', 'me-1') ?>Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td class="c-checked meta"><?= time_tag($repo['last_checked'] ?? null, 'nunca') ?></td>
                    <td class="c-actions text-end">
                        <?php if (!$can_write): ?>
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-secondary" href="history.php?repo=<?= urlencode($name) ?>"><?= icon('clock-history', 'me-1') ?>Historial</a>
                                <?php if ($web_url): ?>
                                    <a class="btn btn-outline-secondary" href="<?= h($web_url) ?>" target="_blank" rel="noopener"
                                       aria-label="Abrir <?= h($name) ?> en <?= h(Repo::TYPES[$type] ?? $type) ?> (nueva pestaña)"><?= icon('box-arrow-up-right') ?></a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                        <div class="btn-group btn-group-sm">
                            <?php if ($outdated): ?>
                                <button type="button" class="btn btn-warning" <?= $sync_attrs ?>
                                        title="Marcar <?= h($latest) ?> como instalada"><?= icon('check2-circle', 'me-1') ?>Sincronizar</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-success" <?= $verify_attrs ?>><?= icon('arrow-repeat', 'me-1') ?>Verificar</button>
                            <?php endif; ?>
                            <button type="button" class="btn <?= $outdated ? 'btn-warning' : 'btn-outline-success' ?> dropdown-toggle dropdown-toggle-split"
                                    data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">
                                <span class="visually-hidden">Más acciones para <?= h($name) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($outdated): ?>
                                    <li><button type="button" class="dropdown-item" <?= $verify_attrs ?>><?= icon('arrow-repeat', 'me-2') ?>Verificar ahora</button></li>
                                <?php elseif ($latest): ?>
                                    <li><button type="button" class="dropdown-item" <?= $sync_attrs ?>><?= icon('check2-circle', 'me-2') ?>Marcar <?= h($latest) ?> como instalada</button></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" href="edit_repo.php?id=<?= h($id) ?>"><?= icon('pencil', 'me-2') ?>Editar</a></li>
                                <li><a class="dropdown-item" href="history.php?repo=<?= urlencode($name) ?>"><?= icon('clock-history', 'me-2') ?>Historial</a></li>
                                <?php if ($web_url): ?>
                                    <li><a class="dropdown-item" href="<?= h($web_url) ?>" target="_blank" rel="noopener"><?= icon('box-arrow-up-right', 'me-2') ?>Abrir en <?= h(Repo::TYPES[$type] ?? $type) ?></a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button type="button" class="dropdown-item text-danger"
                                            data-bs-toggle="modal" data-bs-target="#confirmModal"
                                            data-action="delete_repo.php" data-params="<?= $params ?>"
                                            data-title="Eliminar repositorio"
                                            data-body="¿Eliminar «<?= h($name) ?>»? Se pierde su configuración (patrón, versión instalada). El historial de releases se conserva."
                                            data-variant="danger" data-confirm-label="Eliminar"><?= icon('trash', 'me-2') ?>Eliminar…</button>
                                </li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="no-filter-results" class="empty-row" hidden>
                    <td colspan="<?= $can_write ? 7 : 6 ?>" class="text-center py-4 text-body-secondary">
                        No hay repositorios que coincidan con los filtros.
                        <button type="button" class="btn btn-link p-0 align-baseline" data-clear-filters>Limpiar filtros</button>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($can_write): ?>
<!-- Acciones masivas (PROPUESTA_UX §2.6) -->
<div class="bulk-bar card shadow" id="bulk-bar" hidden>
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
        <span><strong id="bulk-count">0</strong> seleccionados</span>
        <button type="button" class="btn btn-sm btn-warning" data-bulk="sync"><?= icon('check2-circle', 'me-1') ?>Sincronizar</button>
        <?php if ($can_check): ?>
            <button type="button" class="btn btn-sm btn-success" data-bulk="check"><?= icon('arrow-repeat', 'me-1') ?>Verificar</button>
        <?php endif; ?>
        <div class="input-group input-group-sm" style="width:auto">
            <label class="visually-hidden" for="bulk-tag">Etiqueta</label>
            <input type="text" id="bulk-tag" class="form-control" placeholder="etiqueta" list="tag-list" style="max-width:9rem">
            <button type="button" class="btn btn-outline-secondary" data-bulk="tag-add" title="Añadir etiqueta"><?= icon('tag', 'me-1') ?>Etiquetar</button>
            <button type="button" class="btn btn-outline-secondary" data-bulk="tag-remove" title="Quitar etiqueta" aria-label="Quitar etiqueta"><?= icon('dash-lg') ?></button>
        </div>
        <button type="button" class="btn btn-sm btn-link" data-bulk="clear">Deseleccionar</button>
    </div>
</div>
<?php endif; ?>
<datalist id="tag-list">
    <?php foreach (array_keys($all_tags) as $tag): ?>
        <option value="<?= h($tag) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php if ($can_write): ?>
<!-- Alta de repositorio (PROPUESTA_UX §1.1–1.4) -->
<div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" method="post" action="add_repo.php" id="add-form">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="addModalTitle">Agregar repositorio</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label for="repo_name" class="form-label">URL o nombre</label>
                        <input type="text" class="form-control" id="repo_name" name="repo_name" required autocomplete="off" spellcheck="false"
                               placeholder="Pega una URL de GitHub, GitLab, Docker Hub o ghcr.io" aria-describedby="repo_detect">
                        <div id="repo_detect" class="form-text" aria-live="polite">
                            Ej.: <code>https://github.com/jellyfin/jellyfin</code>, <code>hub.docker.com/_/php</code>, <code>ghcr.io/linuxserver/jellyfin</code> o <code>owner/repo</code>.
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="repo_type" class="form-label">Tipo</label>
                        <select class="form-select" id="repo_type" name="repo_type">
                            <?php foreach (Repo::TYPES as $k => $label): ?>
                                <option value="<?= $k ?>"><?= h($label) ?><?= $k === 'oci' ? ' (ghcr.io, quay.io…)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="repo_tags" class="form-label">Etiquetas <span class="text-body-secondary fw-normal">(opcional, separadas por coma)</span></label>
                        <input type="text" class="form-control" id="repo_tags" name="repo_tags" placeholder="media, gaming, self-hosted" list="tag-list">
                    </div>
                    <div class="col-12" id="pattern-block" hidden>
                        <?php $pattern_value = ''; require __DIR__ . '/../includes/pattern_widget.php'; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><?= icon('plus-lg', 'me-1') ?>Agregar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    'use strict';

    // --- Filtros (recordados en localStorage, PROPUESTA_UX §3.8) ---
    var STORE_KEY = 'telegram-notification.filters';
    var tbody     = document.getElementById('repos-tbody');
    var rows      = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-id]'));
    var f = {
        search:   document.getElementById('filter-search'),
        type:     document.getElementById('filter-type'),
        status:   document.getElementById('filter-status'),
        tag:      document.getElementById('filter-tag'),
        outdated: document.getElementById('filter-outdated')
    };
    var countEl   = document.getElementById('filter-count');
    var noResults = document.getElementById('no-filter-results');
    var clearBtn  = document.getElementById('clear-filters');
    var statCards = document.querySelectorAll('[data-stat]');

    function state() {
        return {
            search: f.search.value.trim(), type: f.type.value, status: f.status.value,
            tag: f.tag.value, outdated: f.outdated.checked
        };
    }
    function isActive(s) { return !!(s.search || s.type || s.status || s.tag || s.outdated); }

    function applyFilters() {
        var s = state(), term = s.search.toLowerCase(), visible = 0;
        rows.forEach(function (row) {
            var tags = row.dataset.tags ? row.dataset.tags.split('|') : [];
            var show = (!term || row.dataset.name.indexOf(term) !== -1)
                && (!s.type || row.dataset.type === s.type)
                && (!s.status || row.dataset.status === s.status)
                && (!s.tag || tags.indexOf(s.tag) !== -1)
                && (!s.outdated || row.dataset.outdated === '1');
            row.hidden = !show;
            var check = row.querySelector('.row-check');
            if (show) { visible++; } else if (check) { check.checked = false; }
        });
        if (noResults) { noResults.hidden = visible !== 0 || rows.length === 0; }
        countEl.textContent = isActive(s) ? visible + ' de ' + rows.length : rows.length + ' repositorios';
        clearBtn.hidden = !isActive(s);
        statCards.forEach(function (card) {
            var key = card.dataset.stat;
            var on  = key === 'outdated' ? s.outdated && !s.status
                    : key === ''         ? !isActive(s)
                    : s.status === key && !s.outdated;
            card.classList.toggle('active', on);
            card.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        try { localStorage.setItem(STORE_KEY, JSON.stringify(s)); } catch (e) { /* modo privado */ }
        updateBulk();
    }

    function setFilters(s) {
        f.search.value = s.search || '';
        f.type.value = s.type || '';
        f.status.value = s.status || '';
        f.tag.value = s.tag || '';
        f.outdated.checked = !!s.outdated;
        // Una etiqueta guardada que ya no existe no debe dejar la tabla vacía
        if (f.tag.value !== (s.tag || '')) { f.tag.value = ''; }
        applyFilters();
    }

    function clearFilters() { setFilters({}); }

    [f.type, f.status, f.tag, f.outdated].forEach(function (el) { el.addEventListener('change', applyFilters); });
    f.search.addEventListener('input', applyFilters);
    clearBtn.addEventListener('click', clearFilters);
    document.querySelectorAll('[data-clear-filters]').forEach(function (b) { b.addEventListener('click', clearFilters); });

    // Tarjetas de estado = filtros rápidos; una segunda pulsación lo quita
    statCards.forEach(function (card) {
        card.addEventListener('click', function () {
            var key = card.dataset.stat;
            if (key === '' || card.classList.contains('active')) { clearFilters(); return; }
            setFilters(key === 'outdated' ? { outdated: true } : { status: key });
        });
    });

    // --- Selección y acciones masivas (solo con permiso de edición: si no, no existen) ---
    var selectAll = document.getElementById('select-all');
    var bulkBar   = document.getElementById('bulk-bar');
    var bulkCount = document.getElementById('bulk-count');

    function selectedIds() {
        return rows.filter(function (r) { return !r.hidden && r.querySelector('.row-check').checked; })
                   .map(function (r) { return r.dataset.id; });
    }
    function updateBulk() {
        if (!bulkBar) { return; }
        var ids = selectedIds();
        var visible = rows.filter(function (r) { return !r.hidden; }).length;
        bulkBar.hidden = ids.length === 0;
        bulkCount.textContent = ids.length;
        selectAll.checked = ids.length > 0 && ids.length === visible;
        selectAll.indeterminate = ids.length > 0 && ids.length < visible;
    }
    if (bulkBar) {
        tbody.addEventListener('change', function (e) { if (e.target.classList.contains('row-check')) { updateBulk(); } });
        selectAll.addEventListener('change', function () {
            rows.forEach(function (r) { if (!r.hidden) { r.querySelector('.row-check').checked = selectAll.checked; } });
            updateBulk();
        });
        bulkBar.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-bulk]');
            if (!btn) { return; }
            var ids = selectedIds(), action = btn.dataset.bulk;
            var tag = document.getElementById('bulk-tag').value.trim();
            if (action === 'clear') {
                rows.forEach(function (r) { r.querySelector('.row-check').checked = false; });
                updateBulk();
            } else if (action === 'sync') {
                App.submit('sync_version.php', { ids: ids });
            } else if (action === 'check') {
                App.submit('run_check.php', { ids: ids }, ids.length === 1 ? 'Verificando…' : null);
            } else if (action === 'tag-add' || action === 'tag-remove') {
                if (!tag) { App.toast('Escribe la etiqueta primero.', 'warning'); document.getElementById('bulk-tag').focus(); return; }
                App.submit('bulk_tag.php', { ids: ids, tag: tag, mode: action === 'tag-add' ? 'add' : 'remove' });
            }
        });
    }

    // --- Alta: detección por URL + patrón con vista previa (solo con permiso de edición) ---
    var addModal  = document.getElementById('addModal');
    if (addModal) {
        var nameInput = document.getElementById('repo_name');
        var typeSel   = document.getElementById('repo_type');
        var detectEl  = document.getElementById('repo_detect');
        var patBlock  = document.getElementById('pattern-block');
        var detectDefault = detectEl.innerHTML;
        var TYPE_LABELS = <?= js(Repo::TYPES) ?>;

        var widget = App.patternWidget(patBlock.querySelector('[data-pattern-widget]'), {
            type: function () { return typeSel.value; },
            name: function () { return nameInput.value; }
        });

        function onRepoChange() {
            var d = App.detectRepo(nameInput.value);
            if (d) {
                typeSel.value = d.type;
                detectEl.innerHTML = App.ok('Detectado: <strong>') + App.esc(TYPE_LABELS[d.type]) + '</strong> · <code>' + App.esc(d.name) + '</code>';
            } else {
                detectEl.innerHTML = detectDefault;
            }
            patBlock.hidden = !(typeSel.value === 'docker' || typeSel.value === 'oci');
            widget.schedule();
        }
        nameInput.addEventListener('input', onRepoChange);
        typeSel.addEventListener('change', function () {
            patBlock.hidden = !(typeSel.value === 'docker' || typeSel.value === 'oci');
            widget.schedule();
        });
        addModal.addEventListener('shown.bs.modal', function () { nameInput.focus(); });
        if (new URLSearchParams(location.search).has('add')) { new bootstrap.Modal(addModal).show(); }
    }

    // Filtros recordados: al final, cuando ya existen todos los elementos que applyFilters() toca
    var saved = {};
    try { saved = JSON.parse(localStorage.getItem(STORE_KEY) || '{}') || {}; } catch (e) { saved = {}; }
    setFilters(saved);

    // --- Progreso de la verificación en segundo plano ---
    <?php if ($job): ?>
    App.watchJob(<?= js($job['id']) ?>, document.getElementById('job-progress'));
    <?php endif; ?>
})();
</script>
</body>
</html>
