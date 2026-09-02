<?php
session_start();

$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    header('Location: settings.php');
    exit;
}
$config = json_decode(file_get_contents($config_file), true);

$log_file = __DIR__ . '/app_log.json';
$logs = [];
if (file_exists($log_file)) {
    $logs = json_decode(file_get_contents($log_file), true) ?? [];
    $logs = array_reverse($logs); // Más recientes primero
}

$level_badges = [
    'info'    => 'bg-secondary',
    'success' => 'bg-success',
    'error'   => 'bg-danger',
    'warning' => 'bg-warning text-dark',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs - Notificador de Releases</title>
    <script>
    (function(){
        var configured = '<?php echo $config['THEME'] ?? 'auto'; ?>';
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        function resolve() { return configured === 'auto' ? (mq.matches ? 'dark' : 'light') : configured; }
        document.documentElement.setAttribute('data-bs-theme', resolve());
        if (configured === 'auto') {
            mq.addEventListener('change', function () {
                document.documentElement.setAttribute('data-bs-theme', resolve());
            });
        }
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1>Logs de Ejecución</h1>
            <div class="d-flex flex-wrap gap-2">
                <a href="history.php" class="btn btn-outline-primary">Historial de Releases</a>
                <a href="dashboard.php" class="btn btn-outline-info">Dashboard</a>
                <a href="index.php" class="btn btn-secondary">Volver</a>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-12 col-md-5">
                <input type="text" id="filter-search" class="form-control"
                       placeholder="Buscar en el mensaje..." autocomplete="off">
            </div>
            <div class="col-6 col-md-3">
                <input type="date" id="filter-date-from" class="form-control" title="Desde">
            </div>
            <div class="col-6 col-md-3">
                <input type="date" id="filter-date-to" class="form-control" title="Hasta">
            </div>
        </div>

        <div class="mb-3 d-flex align-items-center gap-3 flex-wrap">
            <span class="text-muted small">Filtrar por nivel:</span>
            <div class="btn-group btn-group-sm" role="group" id="log-level-filter">
                <input type="radio" class="btn-check" name="log-level" id="lf-all"     value=""        checked>
                <label class="btn btn-outline-secondary" for="lf-all">Todos</label>
                <input type="radio" class="btn-check" name="log-level" id="lf-info"    value="info">
                <label class="btn btn-outline-secondary" for="lf-info">Info</label>
                <input type="radio" class="btn-check" name="log-level" id="lf-success" value="success">
                <label class="btn btn-outline-success"   for="lf-success">Success</label>
                <input type="radio" class="btn-check" name="log-level" id="lf-error"   value="error">
                <label class="btn btn-outline-danger"    for="lf-error">Error</label>
                <input type="radio" class="btn-check" name="log-level" id="lf-warning" value="warning">
                <label class="btn btn-outline-warning"   for="lf-warning">Warning</label>
            </div>
            <span id="log-count" class="text-muted small"></span>
        </div>

        <?php if (empty($logs)): ?>
            <div class="alert alert-info">No hay logs registrados aún. Ejecuta la verificación para generar logs.</div>
        <?php else: ?>
            <p class="text-muted">Mostrando los últimos <?php echo count($logs); ?> registros (más recientes primero).</p>
            <div class="card">
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th style="width:170px">Timestamp</th>
                                <th style="width:90px">Nivel</th>
                                <th>Mensaje</th>
                            </tr>
                        </thead>
                        <tbody id="logs-tbody">
                            <?php foreach ($logs as $entry): ?>
                                <tr data-level="<?php echo htmlspecialchars($entry['level'] ?? 'info'); ?>"
                                    data-message="<?php echo strtolower(htmlspecialchars($entry['message'] ?? '')); ?>"
                                    data-date="<?php echo substr($entry['timestamp'] ?? '', 0, 10); ?>">
                                    <td class="text-muted small"><?php echo htmlspecialchars($entry['timestamp']); ?></td>
                                    <td>
                                        <?php
                                            $level = $entry['level'] ?? 'info';
                                            $badge = $level_badges[$level] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($level); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($entry['message']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="no-filter-results" style="display:none">
                                <td colspan="3" class="text-center py-3 text-muted">No hay resultados que coincidan con los filtros.</td>
                            </tr>
                        </tbody>
                    </table>
                  </div>
                </div>
            </div>
            <div class="text-center mt-2">
                <button type="button" id="btn-load-more" class="btn btn-outline-secondary btn-sm" style="display:none">Mostrar más</button>
            </div>
        <?php endif; ?>

         <footer class="mt-5 mb-3 text-center text-muted">
            <p>JRobertoMA | <a href="https://github.com/JRobertoMA">Github</a></p>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var fg          = document.getElementById('log-level-filter');
    var tb          = document.getElementById('logs-tbody');
    var span        = document.getElementById('log-count');
    var search      = document.getElementById('filter-search');
    var dateFrom    = document.getElementById('filter-date-from');
    var dateTo      = document.getElementById('filter-date-to');
    var noResults   = document.getElementById('no-filter-results');
    var loadMoreBtn = document.getElementById('btn-load-more');
    if (!fg || !tb) return;

    var PAGE_SIZE = 50;
    var limit     = PAGE_SIZE;

    function applyFilters(resetLimit) {
        if (resetLimit !== false) limit = PAGE_SIZE;

        var level = fg.querySelector('input:checked').value;
        var term  = search.value.toLowerCase().trim();
        var from  = dateFrom.value;
        var to    = dateTo.value;
        var rows  = tb.querySelectorAll('tr[data-level]');
        var matched = [];

        rows.forEach(function (r) {
            var show = (!level || r.dataset.level === level)
                    && (!term  || r.dataset.message.indexOf(term) !== -1)
                    && (!from  || r.dataset.date >= from)
                    && (!to    || r.dataset.date <= to);
            if (show) matched.push(r);
            r.style.display = 'none';
        });

        matched.slice(0, limit).forEach(function (r) { r.style.display = ''; });

        var vis = Math.min(limit, matched.length);
        span.textContent = (level || term || from || to) ? vis + ' de ' + rows.length + ' entradas' : '';
        if (noResults)   noResults.style.display   = matched.length === 0 ? '' : 'none';
        if (loadMoreBtn) loadMoreBtn.style.display = matched.length > limit ? '' : 'none';
    }

    fg.addEventListener('change', function () { applyFilters(); });
    search.addEventListener('input', function () { applyFilters(); });
    dateFrom.addEventListener('change', function () { applyFilters(); });
    dateTo.addEventListener('change', function () { applyFilters(); });
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function () {
            limit += PAGE_SIZE;
            applyFilters(false);
        });
    }

    applyFilters();
})();
</script>
</body>
</html>
