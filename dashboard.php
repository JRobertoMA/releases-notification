<?php
session_start();

$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    header('Location: settings.php');
    exit;
}
$config = json_decode(file_get_contents($config_file), true);
$repositories_file = __DIR__ . '/' . $config['REPOSITORIES_FILE'];

$repos = [];
if (file_exists($repositories_file)) {
    $repos = json_decode(file_get_contents($repositories_file), true) ?? [];
}

$history_file = __DIR__ . '/release_history.json';
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true) ?? [];
}

// Distribución por tipo
$type_counts = ['github' => 0, 'gitlab' => 0, 'docker' => 0];
foreach ($repos as $r) {
    $t = $r['type'] ?? 'github';
    if (isset($type_counts[$t])) $type_counts[$t]++;
}

// Distribución por etiqueta
$tag_counts = [];
foreach ($repos as $r) {
    foreach ($r['tags'] ?? [] as $tag) {
        $tag_counts[$tag] = ($tag_counts[$tag] ?? 0) + 1;
    }
}
arsort($tag_counts);
$tag_counts = array_slice($tag_counts, 0, 10, true);

// Ranking de repos más activos (por cantidad de releases registrados)
$repo_activity = [];
foreach ($history as $entry) {
    $name = $entry['repo'] ?? '—';
    $repo_activity[$name] = ($repo_activity[$name] ?? 0) + 1;
}
arsort($repo_activity);
$repo_activity = array_slice($repo_activity, 0, 10, true);

// Actividad global por mes
$monthly_counts = [];
foreach ($history as $entry) {
    $month = substr($entry['timestamp'] ?? '', 0, 7); // YYYY-MM
    if ($month === '') continue;
    $monthly_counts[$month] = ($monthly_counts[$month] ?? 0) + 1;
}
ksort($monthly_counts);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Notificador de Releases</title>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1>Dashboard</h1>
            <div class="d-flex flex-wrap gap-2">
                <a href="history.php" class="btn btn-outline-primary">Historial</a>
                <a href="logs.php" class="btn btn-outline-secondary">Logs</a>
                <a href="index.php" class="btn btn-secondary">Volver</a>
            </div>
        </div>

        <?php if (empty($repos) && empty($history)): ?>
            <div class="alert alert-info">Aún no hay datos suficientes para mostrar el dashboard.</div>
        <?php else: ?>
            <div class="row g-3 mb-3">
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Distribución por tipo</div>
                        <div class="card-body">
                            <div style="position:relative; height:280px">
                                <canvas id="chart-type"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Distribución por etiqueta</div>
                        <div class="card-body">
                            <?php if (empty($tag_counts)): ?>
                                <p class="text-muted mb-0">No hay etiquetas registradas.</p>
                            <?php else: ?>
                                <div style="position:relative; height:280px">
                                    <canvas id="chart-tags"></canvas>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Actividad global (releases detectados por mes)</div>
                <div class="card-body">
                    <?php if (empty($monthly_counts)): ?>
                        <p class="text-muted mb-0">No hay releases registrados aún.</p>
                    <?php else: ?>
                        <div style="position:relative; height:300px">
                            <canvas id="chart-activity"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Repositorios más activos</div>
                <div class="card-body p-0">
                    <?php if (empty($repo_activity)): ?>
                        <p class="text-muted mb-0 p-3">No hay releases registrados aún.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Repositorio</th>
                                    <th style="width:160px">Releases detectados</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($repo_activity as $name => $count): ?>
                                    <tr>
                                        <td><a href="history.php?repo=<?php echo urlencode($name); ?>"><?php echo htmlspecialchars($name); ?></a></td>
                                        <td><?php echo $count; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

         <footer class="mt-5 mb-3 text-center text-muted">
            <p>JRobertoMA | <a href="https://github.com/JRobertoMA">Github</a></p>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        var isDark    = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        var textColor = isDark ? '#e9ecef' : '#212529';
        var gridColor = isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)';
        Chart.defaults.color = textColor;

        var typeEl = document.getElementById('chart-type');
        if (typeEl) {
            new Chart(typeEl.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['GitHub', 'GitLab', 'Docker Hub'],
                    datasets: [{
                        data: <?php echo json_encode(array_values($type_counts)); ?>,
                        backgroundColor: ['#6c757d', '#ffc107', '#0dcaf0']
                    }]
                },
                options: { maintainAspectRatio: false, animation: false, plugins: { legend: { position: 'bottom', labels: { color: textColor } } } }
            });
        }

        var tagsEl = document.getElementById('chart-tags');
        if (tagsEl) {
            new Chart(tagsEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_keys($tag_counts)); ?>,
                    datasets: [{
                        label: 'Repositorios',
                        data: <?php echo json_encode(array_values($tag_counts)); ?>,
                        backgroundColor: '#0d6efd'
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    animation: false,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: textColor, precision: 0 }, grid: { color: gridColor } },
                        y: { ticks: { color: textColor }, grid: { color: gridColor } }
                    }
                }
            });
        }

        var activityEl = document.getElementById('chart-activity');
        if (activityEl) {
            new Chart(activityEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_keys($monthly_counts)); ?>,
                    datasets: [{
                        label: 'Releases detectados',
                        data: <?php echo json_encode(array_values($monthly_counts)); ?>,
                        backgroundColor: '#198754'
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: textColor }, grid: { color: gridColor } },
                        y: { ticks: { color: textColor, precision: 0 }, grid: { color: gridColor } }
                    }
                }
            });
        }
    })();
    </script>
</body>
</html>
