<?php
session_start();

$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    header('Location: settings.php');
    exit;
}
$config = json_decode(file_get_contents($config_file), true);

$history_file = __DIR__ . '/release_history.json';
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true) ?? [];
    $history = array_reverse($history); // Más recientes primero
}

$filter_repo = trim($_GET['repo'] ?? '');
if ($filter_repo !== '') {
    $history = array_values(array_filter($history, function ($e) use ($filter_repo) {
        return ($e['repo'] ?? '') === $filter_repo;
    }));
}

$type_badges = [
    'github' => 'text-bg-secondary',
    'gitlab' => 'text-bg-warning',
    'docker' => 'text-bg-info',
];

$status_badges = [
    'ok'    => 'bg-success',
    'new'   => 'bg-primary',
    'error' => 'bg-danger',
];

function is_repo_outdated(array $repo): bool {
    if (empty($repo['my_version']) || empty($repo['last_seen_release'])) {
        return false;
    }
    if ($repo['my_version'] !== $repo['last_seen_release']) {
        // Tags distintos → desactualizado
        return true;
    }
    if (!empty($repo['my_updated_at']) && !empty($repo['last_release_date'])) {
        // Mismo tag (latest, stable…) → comparar fechas
        return substr($repo['last_release_date'], 0, 10) > $repo['my_updated_at'];
    }
    return false;
}

// Ficha de datos del repo (solo cuando se filtra por uno específico)
$matched_repo  = null;
$matched_index = null;
if ($filter_repo !== '') {
    $repositories_file  = __DIR__ . '/' . $config['REPOSITORIES_FILE'];
    $repos              = file_exists($repositories_file) ? (json_decode(file_get_contents($repositories_file), true) ?? []) : [];
    foreach ($repos as $i => $r) {
        if (($r['name'] ?? '') === $filter_repo) {
            $matched_repo  = $r;
            $matched_index = $i;
            break;
        }
    }
}

// Datos para el gráfico de línea de tiempo (solo cuando se filtra por un repo)
$chart_points     = [];
$chart_categories = [];
if ($filter_repo !== '' && !empty($history)) {
    $chronological = array_reverse($history); // más antiguo primero, para el eje X
    foreach ($chronological as $entry) {
        $chart_points[] = [
            'x'     => str_replace(' ', 'T', $entry['timestamp']),
            'label' => $entry['version'] ?? '',
            'url'   => $entry['url'] ?? null,
        ];
    }
    $chart_categories = array_values(array_unique(array_column($chronological, 'version')));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Releases - Notificador</title>
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
    <?php if ($filter_repo !== '' && !empty($chart_points)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <?php endif; ?>
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1><?php echo $filter_repo !== '' ? 'Historial de ' . htmlspecialchars($filter_repo) : 'Historial de Releases'; ?></h1>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($filter_repo !== ''): ?>
                    <a href="history.php" class="btn btn-outline-warning">Ver todos</a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn btn-outline-info">Dashboard</a>
                <a href="logs.php" class="btn btn-outline-secondary">Ver Logs</a>
                <a href="index.php" class="btn btn-secondary">Volver</a>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-12 col-md-5">
                <input type="text" id="filter-search" class="form-control"
                       placeholder="Buscar por repositorio o versión..." autocomplete="off">
            </div>
            <div class="col-6 col-md-3">
                <input type="date" id="filter-date-from" class="form-control" title="Desde">
            </div>
            <div class="col-6 col-md-3">
                <input type="date" id="filter-date-to" class="form-control" title="Hasta">
            </div>
        </div>

        <div class="mb-3 d-flex align-items-center gap-3 flex-wrap">
            <span class="text-muted small">Filtrar por tipo:</span>
            <div class="btn-group btn-group-sm" role="group" id="history-type-filter">
                <input type="radio" class="btn-check" name="hist-type" id="hf-all"    value=""       checked>
                <label class="btn btn-outline-secondary" for="hf-all">Todos</label>
                <input type="radio" class="btn-check" name="hist-type" id="hf-github" value="github">
                <label class="btn btn-outline-secondary"  for="hf-github">GitHub</label>
                <input type="radio" class="btn-check" name="hist-type" id="hf-gitlab" value="gitlab">
                <label class="btn btn-outline-warning"   for="hf-gitlab">GitLab</label>
                <input type="radio" class="btn-check" name="hist-type" id="hf-docker" value="docker">
                <label class="btn btn-outline-info"      for="hf-docker">Docker</label>
            </div>
            <span id="history-count" class="text-muted small"></span>
        </div>

        <?php if (empty($history)): ?>
            <div class="alert alert-info">No hay releases registrados aún. Se guardarán automáticamente cuando se detecten nuevas versiones.</div>
        <?php else: ?>
            <p class="text-muted">Mostrando los últimos <?php echo count($history); ?> releases detectados (más recientes primero).</p>

            <?php if ($matched_repo !== null): ?>
                <div class="card mb-3">
                    <div class="card-header">Datos del repositorio</div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                            <?php
                                $mtype  = $matched_repo['type'] ?? 'github';
                                $mbadge = $type_badges[$mtype] ?? 'bg-secondary';
                                $mstatus = $matched_repo['check_status'] ?? 'unknown';
                                $sbadge  = $status_badges[$mstatus] ?? 'bg-secondary';
                            ?>
                            <span class="badge <?php echo $mbadge; ?>"><?php echo htmlspecialchars($mtype); ?></span>
                            <span class="badge <?php echo $sbadge; ?>"><?php echo htmlspecialchars($mstatus); ?></span>
                            <?php foreach ($matched_repo['tags'] ?? [] as $tag): ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis"><?php echo htmlspecialchars($tag); ?></span>
                            <?php endforeach; ?>
                            <?php if ($mtype === 'docker' && !empty($matched_repo['tag_pattern'])): ?>
                                <span class="text-muted small">Patrón: <code><?php echo htmlspecialchars($matched_repo['tag_pattern']); ?></code></span>
                            <?php endif; ?>
                            <?php if ($matched_index !== null): ?>
                                <a href="edit_repo.php?id=<?php echo $matched_index; ?>" class="btn btn-outline-secondary btn-sm ms-auto">Editar</a>
                            <?php endif; ?>
                        </div>
                        <div class="row">
                            <div class="col-sm-4">
                                <div class="text-muted small">Última verificación</div>
                                <div><?php echo htmlspecialchars($matched_repo['last_checked'] ?? '—'); ?></div>
                            </div>
                            <div class="col-sm-4">
                                <div class="text-muted small">Última versión detectada</div>
                                <div class="font-monospace"><?php echo htmlspecialchars($matched_repo['last_seen_release'] ?? '—'); ?></div>
                            </div>
                            <div class="col-sm-4">
                                <div class="text-muted small">Versión instalada</div>
                                <div class="font-monospace <?php echo is_repo_outdated($matched_repo) ? 'text-warning' : 'text-success'; ?>">
                                    <?php echo htmlspecialchars($matched_repo['my_version'] ?? '—'); ?>
                                    <?php if (is_repo_outdated($matched_repo)): ?><span title="Actualización disponible">⚠</span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($filter_repo !== '' && !empty($chart_points)): ?>
                <div class="card mb-3">
                    <div class="card-header">Línea de tiempo de versiones</div>
                    <div class="card-body">
                        <div style="position:relative; height:300px">
                            <canvas id="repo-timeline-chart"></canvas>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th style="width:170px">Fecha</th>
                                <th>Repositorio</th>
                                <th style="width:100px">Tipo</th>
                                <th style="width:130px">Versión</th>
                                <th style="width:80px">Enlace</th>
                            </tr>
                        </thead>
                        <tbody id="history-tbody">
                            <?php foreach ($history as $entry): ?>
                                <tr data-type="<?php echo htmlspecialchars($entry['type'] ?? 'github'); ?>"
                                    data-repo="<?php echo strtolower(htmlspecialchars($entry['repo'] ?? '')); ?>"
                                    data-version="<?php echo strtolower(htmlspecialchars($entry['version'] ?? '')); ?>"
                                    data-date="<?php echo substr($entry['timestamp'] ?? '', 0, 10); ?>">
                                    <td class="text-muted small"><?php echo htmlspecialchars($entry['timestamp']); ?></td>
                                    <td>
                                        <?php if ($filter_repo === ''): ?>
                                            <a href="history.php?repo=<?php echo urlencode($entry['repo']); ?>"><?php echo htmlspecialchars($entry['repo']); ?></a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($entry['repo']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $type  = $entry['type'] ?? 'github';
                                            $badge = $type_badges[$type] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($type); ?></span>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($entry['version']); ?></code></td>
                                    <td>
                                        <?php if (!empty($entry['url'])): ?>
                                            <a href="<?php echo htmlspecialchars($entry['url']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">Ver</a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="no-filter-results" style="display:none">
                                <td colspan="5" class="text-center py-3 text-muted">No hay resultados que coincidan con los filtros.</td>
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
    <?php if ($filter_repo !== '' && !empty($chart_points)): ?>
    <script>
    (function () {
        var canvas = document.getElementById('repo-timeline-chart');
        if (!canvas || typeof Chart === 'undefined') return;

        var points     = <?php echo json_encode($chart_points); ?>;
        var categories = <?php echo json_encode($chart_categories); ?>;
        var isDark     = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        var textColor  = isDark ? '#e9ecef' : '#212529';
        var gridColor  = isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)';

        Chart.defaults.color = textColor;

        new Chart(canvas.getContext('2d'), {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Versión detectada',
                    data: points.map(function (p) { return { x: p.x, y: p.label }; }),
                    backgroundColor: '#0d6efd',
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'time',
                        time: { tooltipFormat: 'dd/MM/yyyy HH:mm' },
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    },
                    y: {
                        type: 'category',
                        labels: categories,
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var p = points[ctx.dataIndex];
                                return 'Versión: ' + p.label;
                            }
                        }
                    }
                }
            }
        });
    })();
    </script>
    <?php endif; ?>
    <script>
    (function () {
        var fg          = document.getElementById('history-type-filter');
        var tb          = document.getElementById('history-tbody');
        var span        = document.getElementById('history-count');
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

            var type = fg.querySelector('input:checked').value;
            var term = search.value.toLowerCase().trim();
            var from = dateFrom.value;
            var to   = dateTo.value;
            var rows = tb.querySelectorAll('tr[data-type]');
            var matched = [];

            rows.forEach(function (r) {
                var show = (!type || r.dataset.type === type)
                        && (!term || r.dataset.repo.indexOf(term) !== -1 || r.dataset.version.indexOf(term) !== -1)
                        && (!from || r.dataset.date >= from)
                        && (!to   || r.dataset.date <= to);
                if (show) matched.push(r);
                r.style.display = 'none';
            });

            matched.slice(0, limit).forEach(function (r) { r.style.display = ''; });

            var vis = Math.min(limit, matched.length);
            span.textContent = (type || term || from || to) ? vis + ' de ' + rows.length + ' releases' : '';
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
