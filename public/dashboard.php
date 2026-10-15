<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

use App\JsonFile;
use App\Repo;

$repos   = repo_store()->all();
$history = JsonFile::read(BASE_DIR . '/release_history.json', []);

// Distribución por tipo (mismos colores que los badges, Repo::TYPE_COLOR)
$type_counts = array_fill_keys(array_keys(Repo::TYPES), 0);
foreach ($repos as $r) {
    $t = $r['type'] ?? 'github';
    if (isset($type_counts[$t])) {
        $type_counts[$t]++;
    }
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

// Ranking de repos más activos
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
    $month = substr($entry['timestamp'] ?? '', 0, 7);
    if ($month !== '') {
        $monthly_counts[$month] = ($monthly_counts[$month] ?? 0) + 1;
    }
}
ksort($monthly_counts);

$page_title = 'Dashboard';
$active     = 'dashboard';
$head_extra = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3">Dashboard</h1>

<?php if ($repos === [] && $history === []): ?>
    <div class="alert alert-info">Aún no hay datos suficientes para el dashboard.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header">Distribución por tipo</div>
                <div class="card-body">
                    <div style="position:relative; height:280px">
                        <canvas id="chart-type" role="img" aria-label="Repositorios por tipo"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header">Distribución por etiqueta</div>
                <div class="card-body">
                    <?php if ($tag_counts === []): ?>
                        <p class="text-body-secondary mb-0">No hay etiquetas registradas.</p>
                    <?php else: ?>
                        <div style="position:relative; height:280px">
                            <canvas id="chart-tags" role="img" aria-label="Repositorios por etiqueta"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Actividad global (releases detectados por mes)</div>
        <div class="card-body">
            <?php if ($monthly_counts === []): ?>
                <p class="text-body-secondary mb-0">No hay releases registrados aún.</p>
            <?php else: ?>
                <div style="position:relative; height:300px">
                    <canvas id="chart-activity" role="img" aria-label="Releases detectados por mes"></canvas>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Repositorios más activos</div>
        <?php if ($repo_activity === []): ?>
            <p class="text-body-secondary mb-0 p-3">No hay releases registrados aún.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Repositorio</th>
                            <th style="width:10rem">Releases detectados</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($repo_activity as $name => $count): ?>
                            <tr>
                                <td class="text-break"><a href="history.php?repo=<?= urlencode((string) $name) ?>"><?= h($name) ?></a></td>
                                <td><?= (int) $count ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var isDark    = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var textColor = isDark ? '#e9ecef' : '#212529';
    var gridColor = isDark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)';
    Chart.defaults.color = textColor;

    var typeEl = document.getElementById('chart-type');
    if (typeEl) {
        new Chart(typeEl.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?= js(array_values(Repo::TYPES)) ?>,
                datasets: [{
                    data: <?= js(array_values($type_counts)) ?>,
                    backgroundColor: <?= js(array_values(Repo::TYPE_COLOR)) ?>
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
                labels: <?= js(array_map('strval', array_keys($tag_counts))) ?>,
                datasets: [{ label: 'Repositorios', data: <?= js(array_values($tag_counts)) ?>, backgroundColor: '#0d6efd' }]
            },
            options: {
                maintainAspectRatio: false, animation: false, indexAxis: 'y',
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
                labels: <?= js(array_keys($monthly_counts)) ?>,
                datasets: [{ label: 'Releases detectados', data: <?= js(array_values($monthly_counts)) ?>, backgroundColor: '#198754' }]
            },
            options: {
                maintainAspectRatio: false, animation: false,
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
