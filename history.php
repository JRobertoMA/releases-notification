<?php
session_start();

$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    header('Location: settings.php');
    exit;
}

$history_file = __DIR__ . '/release_history.json';
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true) ?? [];
    $history = array_reverse($history); // Más recientes primero
}

$type_badges = [
    'github' => 'text-bg-secondary',
    'gitlab' => 'text-bg-warning',
    'docker' => 'text-bg-info',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Releases - Notificador</title>
    <script>(function(){document.documentElement.setAttribute('data-bs-theme',localStorage.getItem('theme')||'light');})();</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Historial de Releases</h1>
            <div>
                <button id="btn-theme" class="btn btn-outline-secondary btn-sm me-2" title="Cambiar tema"></button>
                <a href="logs.php" class="btn btn-outline-secondary me-2">Ver Logs</a>
                <a href="index.php" class="btn btn-secondary">Volver</a>
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
            <div class="card">
                <div class="card-body p-0">
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
                                <tr data-type="<?php echo htmlspecialchars($entry['type'] ?? 'github'); ?>">
                                    <td class="text-muted small"><?php echo htmlspecialchars($entry['timestamp']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['repo']); ?></td>
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
                        </tbody>
                    </table>
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
        var fg   = document.getElementById('history-type-filter');
        var tb   = document.getElementById('history-tbody');
        var span = document.getElementById('history-count');
        if (!fg || !tb) return;
        fg.addEventListener('change', function () {
            var type = fg.querySelector('input:checked').value;
            var rows = tb.querySelectorAll('tr[data-type]');
            var vis  = 0;
            rows.forEach(function (r) {
                var show = !type || r.dataset.type === type;
                r.style.display = show ? '' : 'none';
                if (show) vis++;
            });
            span.textContent = type ? vis + ' de ' + rows.length + ' releases' : '';
        });
    })();
    (function () {
        var btn  = document.getElementById('btn-theme');
        var html = document.documentElement;
        function applyIcon() { btn.textContent = html.getAttribute('data-bs-theme') === 'dark' ? '☀️' : '🌙'; }
        applyIcon();
        btn.addEventListener('click', function () {
            var next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            localStorage.setItem('theme', next);
            applyIcon();
        });
    })();
    </script>
</body>
</html>
