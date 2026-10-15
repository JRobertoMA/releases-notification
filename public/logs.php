<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

use App\EventLog;

$logs = EventLog::recentLogs(1000);   // más recientes primero

$levels = [
    'info'    => ['Info',    'text-bg-secondary'],
    'success' => ['Nuevo',   'text-bg-success'],
    'warning' => ['Aviso',   'text-bg-warning'],
    'error'   => ['Error',   'text-bg-danger'],
];

$page_title = 'Logs';
$active     = 'logs';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3">Logs de ejecución</h1>

<div class="row g-2 mb-3 align-items-center">
    <div class="col-12 col-md-4">
        <label for="filter-search" class="visually-hidden">Buscar en el mensaje</label>
        <input type="search" id="filter-search" class="form-control" placeholder="Buscar en el mensaje…" autocomplete="off">
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
        <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Filtrar por nivel" id="log-level-filter">
            <input type="radio" class="btn-check" name="log-level" id="lf-all" value="" checked>
            <label class="btn btn-outline-secondary" for="lf-all">Todos</label>
            <?php foreach ($levels as $k => [$label]): ?>
                <input type="radio" class="btn-check" name="log-level" id="lf-<?= $k ?>" value="<?= $k ?>">
                <label class="btn btn-outline-secondary" for="lf-<?= $k ?>"><?= h($label) ?></label>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($logs === []): ?>
    <div class="alert alert-info">Aún no hay logs. Se generan con cada verificación.</div>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Últimos <?= count($logs) ?> registros</span>
            <span class="d-flex align-items-center gap-2">
                <span id="log-count" class="meta" aria-live="polite"></span>
                <button type="button" class="btn btn-sm btn-link p-0" id="clear-filters" hidden>Limpiar filtros</button>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:9rem">Fecha</th>
                        <th style="width:6rem">Nivel</th>
                        <th>Mensaje</th>
                    </tr>
                </thead>
                <tbody id="logs-tbody">
                    <?php foreach ($logs as $entry): $level = $entry['level'] ?? 'info'; ?>
                        <tr data-level="<?= h($level) ?>"
                            data-message="<?= h(mb_strtolower($entry['message'] ?? '')) ?>"
                            data-date="<?= h(substr($entry['timestamp'] ?? '', 0, 10)) ?>">
                            <td class="meta"><?= time_tag($entry['timestamp'] ?? null) ?></td>
                            <td><span class="badge <?= $levels[$level][1] ?? 'text-bg-secondary' ?>"><?= h($levels[$level][0] ?? $level) ?></span></td>
                            <td class="text-break"><?= h($entry['message'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="no-filter-results" hidden>
                        <td colspan="3" class="text-center py-4 text-body-secondary">
                            No hay registros que coincidan con los filtros.
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
<script>
(function () {
    var fg = document.getElementById('log-level-filter');
    var tb = document.getElementById('logs-tbody');
    if (!fg || !tb) return;
    var span      = document.getElementById('log-count');
    var search    = document.getElementById('filter-search');
    var dateFrom  = document.getElementById('filter-date-from');
    var dateTo    = document.getElementById('filter-date-to');
    var noResults = document.getElementById('no-filter-results');
    var loadMore  = document.getElementById('btn-load-more');
    var clearBtn  = document.getElementById('clear-filters');
    var rows      = tb.querySelectorAll('tr[data-level]');
    var PAGE_SIZE = 50, limit = PAGE_SIZE;

    function applyFilters(resetLimit) {
        if (resetLimit !== false) limit = PAGE_SIZE;
        var level = fg.querySelector('input:checked').value;
        var term  = search.value.toLowerCase().trim();
        var from  = dateFrom.value, to = dateTo.value, matched = [];
        rows.forEach(function (r) {
            var show = (!level || r.dataset.level === level)
                && (!term || r.dataset.message.indexOf(term) !== -1)
                && (!from || r.dataset.date >= from)
                && (!to || r.dataset.date <= to);
            if (show) matched.push(r);
            r.hidden = true;
        });
        matched.slice(0, limit).forEach(function (r) { r.hidden = false; });
        var active = !!(level || term || from || to);
        span.textContent = active ? Math.min(limit, matched.length) + ' de ' + matched.length + ' coincidencias' : '';
        clearBtn.hidden = !active;
        noResults.hidden = matched.length !== 0;
        loadMore.hidden = matched.length <= limit;
    }
    function clearFilters() {
        search.value = ''; dateFrom.value = ''; dateTo.value = '';
        document.getElementById('lf-all').checked = true;
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
