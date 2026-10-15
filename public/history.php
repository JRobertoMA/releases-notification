<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

use App\JsonFile;
use App\Repo;

$history = array_reverse(JsonFile::read(BASE_DIR . '/release_history.json', []));   // más recientes primero

$filter_repo = trim((string) ($_GET['repo'] ?? ''));
if ($filter_repo !== '') {
    $history = array_values(array_filter($history, fn ($e) => ($e['repo'] ?? '') === $filter_repo));
}

// Ficha del repo (solo con ?repo=): el historial guarda solo el nombre, así que se busca por nombre
$matched_repo = null;
if ($filter_repo !== '') {
    foreach (repo_store()->all() as $r) {
        if (($r['name'] ?? '') === $filter_repo) {
            $matched_repo = $r;
            break;
        }
    }
}

// Línea de tiempo (solo con ?repo=)
$chart_points     = [];
$chart_categories = [];
if ($filter_repo !== '' && $history !== []) {
    $chronological = array_reverse($history);
    foreach ($chronological as $entry) {
        $chart_points[] = ['x' => str_replace(' ', 'T', $entry['timestamp']), 'label' => $entry['version'] ?? ''];
    }
    $chart_categories = array_values(array_unique(array_column($chronological, 'version')));
}

$page_title = $filter_repo !== '' ? 'Historial de ' . $filter_repo : 'Historial';
$active     = 'history';
$head_extra = $chart_points !== []
    ? '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>'
      . '<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>'
    : '';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($filter_repo !== ''): ?>
    <nav aria-label="Ruta" class="mb-2">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="history.php">Historial</a></li>
            <li class="breadcrumb-item active text-break" aria-current="page"><?= h($filter_repo) ?></li>
        </ol>
    </nav>
<?php endif; ?>
<h1 class="h3 mb-3 text-break"><?= $filter_repo !== '' ? 'Historial de ' . h($filter_repo) : 'Historial de releases' ?></h1>

<?php if ($matched_repo !== null):
    $mtype = $matched_repo['type'] ?? 'github'; ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                <?= type_badge($mtype) ?>
                <?= status_badge($matched_repo['check_status'] ?? null) ?>
                <?php foreach ($matched_repo['tags'] ?? [] as $tag): ?>
                    <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis fw-normal"><?= h($tag) ?></span>
                <?php endforeach; ?>
                <?php if (Repo::hasTagPattern($mtype) && !empty($matched_repo['tag_pattern'])): ?>
                    <span class="meta">Patrón: <code><?= h($matched_repo['tag_pattern']) ?></code></span>
                <?php endif; ?>
                <a href="edit_repo.php?id=<?= h($matched_repo['id']) ?>" class="btn btn-outline-secondary btn-sm ms-auto"><?= icon('pencil', 'me-1') ?>Editar</a>
            </div>
            <div class="row g-3">
                <div class="col-sm-4">
                    <div class="meta">Última verificación</div>
                    <div><?= time_tag($matched_repo['last_checked'] ?? null, 'nunca') ?></div>
                </div>
                <div class="col-sm-4">
                    <div class="meta">Última versión detectada</div>
                    <div class="font-monospace"><?= h($matched_repo['last_seen_release'] ?? '—') ?></div>
                </div>
                <div class="col-sm-4">
                    <?php $outdated = Repo::isOutdated($matched_repo); ?>
                    <div class="meta">Versión instalada</div>
                    <div class="font-monospace <?= $outdated ? 'text-warning-emphasis fw-semibold' : 'text-success-emphasis' ?>">
                        <?= h($matched_repo['my_version'] ?? '—') ?>
                        <?php if ($outdated): ?><?= icon('exclamation-triangle-fill', 'text-warning') ?><span class="visually-hidden">(actualización disponible)</span><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($chart_points !== []): ?>
    <div class="card mb-3">
        <div class="card-header">Línea de tiempo de versiones</div>
        <div class="card-body">
            <div style="position:relative; height:300px">
                <canvas id="repo-timeline-chart" aria-label="Versiones detectadas a lo largo del tiempo" role="img"></canvas>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-2 mb-3 align-items-center">
    <div class="col-12 col-md-4">
        <label for="filter-search" class="visually-hidden">Buscar</label>
        <input type="search" id="filter-search" class="form-control" placeholder="Buscar por repositorio o versión…" autocomplete="off">
    </div>
    <div class="col-6 col-md-2">
        <label for="filter-date-from" class="visually-hidden">Desde</label>
        <input type="date" id="filter-date-from" class="form-control" title="Desde">
    </div>
    <div class="col-6 col-md-2">
        <label for="filter-date-to" class="visually-hidden">Hasta</label>
        <input type="date" id="filter-date-to" class="form-control" title="Hasta">
    </div>
    <div class="col-12 col-md-4">
        <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Filtrar por tipo" id="history-type-filter">
            <input type="radio" class="btn-check" name="hist-type" id="hf-all" value="" checked>
            <label class="btn btn-outline-secondary" for="hf-all">Todos</label>
            <?php foreach (Repo::TYPES as $k => $label): ?>
                <input type="radio" class="btn-check" name="hist-type" id="hf-<?= $k ?>" value="<?= $k ?>">
                <label class="btn btn-outline-secondary" for="hf-<?= $k ?>"><?= h($label) ?></label>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($history === []): ?>
    <div class="alert alert-info">Aún no hay releases registrados. Se guardan solos cuando se detecta una versión nueva.</div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><?= count($history) ?> releases detectados, los más recientes primero</span>
            <span class="d-flex align-items-center gap-2">
                <span id="history-count" class="meta" aria-live="polite"></span>
                <button type="button" class="btn btn-sm btn-link p-0" id="clear-filters" hidden>Limpiar filtros</button>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:9rem">Fecha</th>
                        <th>Repositorio</th>
                        <th style="width:8rem">Tipo</th>
                        <th>Versión</th>
                        <th style="width:5rem"><span class="visually-hidden">Enlace</span></th>
                    </tr>
                </thead>
                <tbody id="history-tbody">
                    <?php foreach ($history as $entry): $type = $entry['type'] ?? 'github'; ?>
                        <tr data-type="<?= h($type) ?>"
                            data-repo="<?= h(strtolower($entry['repo'] ?? '')) ?>"
                            data-version="<?= h(strtolower((string) ($entry['version'] ?? ''))) ?>"
                            data-date="<?= h(substr($entry['timestamp'] ?? '', 0, 10)) ?>">
                            <td class="meta"><?= time_tag($entry['timestamp'] ?? null) ?></td>
                            <td class="text-break">
                                <?php if ($filter_repo === ''): ?>
                                    <a href="history.php?repo=<?= urlencode($entry['repo']) ?>"><?= h($entry['repo']) ?></a>
                                <?php else: ?>
                                    <?= h($entry['repo']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= type_badge($type) ?></td>
                            <td><code><?= h($entry['version'] ?? '') ?></code></td>
                            <td>
                                <?php if (!empty($entry['url'])): ?>
                                    <a href="<?= h($entry['url']) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"
                                       aria-label="Ver release <?= h($entry['version'] ?? '') ?> (nueva pestaña)"><?= icon('box-arrow-up-right', 'me-1') ?>Ver</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="no-filter-results" hidden>
                        <td colspan="5" class="text-center py-4 text-body-secondary">
                            No hay releases que coincidan con los filtros.
                            <button type="button" class="btn btn-link p-0 align-baseline" data-clear-filters>Limpiar filtros</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="text-center mt-2">
        <button type="button" id="btn-load-more" class="btn btn-outline-secondary btn-sm" hidden>Mostrar más</button>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<?php if ($chart_points !== []): ?>
<script>
(function () {
    var canvas = document.getElementById('repo-timeline-chart');
    if (!canvas || typeof Chart === 'undefined') return;
    var points     = <?= js($chart_points) ?>;
    var categories = <?= js($chart_categories) ?>;
    var isDark     = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var textColor  = isDark ? '#e9ecef' : '#212529';
    var gridColor  = isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)';
    Chart.defaults.color = textColor;
    new Chart(canvas.getContext('2d'), {
        type: 'scatter',
        data: { datasets: [{
            label: 'Versión detectada',
            data: points.map(function (p) { return { x: p.x, y: p.label }; }),
            backgroundColor: '#0d6efd', pointRadius: 6, pointHoverRadius: 8
        }] },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: { type: 'time', time: { tooltipFormat: 'dd/MM/yyyy HH:mm' }, grid: { color: gridColor }, ticks: { color: textColor } },
                y: { type: 'category', labels: categories, grid: { color: gridColor }, ticks: { color: textColor } }
            },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function (ctx) { return 'Versión: ' + points[ctx.dataIndex].label; } } }
            }
        }
    });
})();
</script>
<?php endif; ?>
<script>
(function () {
    var fg = document.getElementById('history-type-filter');
    var tb = document.getElementById('history-tbody');
    if (!fg || !tb) return;
    var span      = document.getElementById('history-count');
    var search    = document.getElementById('filter-search');
    var dateFrom  = document.getElementById('filter-date-from');
    var dateTo    = document.getElementById('filter-date-to');
    var noResults = document.getElementById('no-filter-results');
    var loadMore  = document.getElementById('btn-load-more');
    var clearBtn  = document.getElementById('clear-filters');
    var rows      = tb.querySelectorAll('tr[data-type]');
    var PAGE_SIZE = 50, limit = PAGE_SIZE;

    function applyFilters(resetLimit) {
        if (resetLimit !== false) limit = PAGE_SIZE;
        var type = fg.querySelector('input:checked').value;
        var term = search.value.toLowerCase().trim();
        var from = dateFrom.value, to = dateTo.value, matched = [];
        rows.forEach(function (r) {
            var show = (!type || r.dataset.type === type)
                && (!term || r.dataset.repo.indexOf(term) !== -1 || r.dataset.version.indexOf(term) !== -1)
                && (!from || r.dataset.date >= from)
                && (!to || r.dataset.date <= to);
            if (show) matched.push(r);
            r.hidden = true;
        });
        matched.slice(0, limit).forEach(function (r) { r.hidden = false; });
        var active = !!(type || term || from || to);
        span.textContent = active ? Math.min(limit, matched.length) + ' de ' + matched.length + ' coincidencias' : '';
        clearBtn.hidden = !active;
        noResults.hidden = matched.length !== 0;
        loadMore.hidden = matched.length <= limit;
    }
    function clearFilters() {
        search.value = ''; dateFrom.value = ''; dateTo.value = '';
        document.getElementById('hf-all').checked = true;
        applyFilters();
    }
    fg.addEventListener('change', function () { applyFilters(); });
    search.addEventListener('input', function () { applyFilters(); });
    dateFrom.addEventListener('change', function () { applyFilters(); });
    dateTo.addEventListener('change', function () { applyFilters(); });
    clearBtn.addEventListener('click', clearFilters);
    document.querySelectorAll('[data-clear-filters]').forEach(function (b) { b.addEventListener('click', clearFilters); });
    loadMore.addEventListener('click', function () { limit += PAGE_SIZE; applyFilters(false); });
    applyFilters();
})();
</script>
</body>
</html>
