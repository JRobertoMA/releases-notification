<?php
session_start();

$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    header('Location: settings.php');
    exit;
}

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
    <script>(function(){document.documentElement.setAttribute('data-bs-theme',localStorage.getItem('theme')||'light');})();</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Logs de Ejecución</h1>
            <div>
                <button id="btn-theme" class="btn btn-outline-secondary btn-sm me-2" title="Cambiar tema"></button>
                <a href="history.php" class="btn btn-outline-primary me-2">Historial de Releases</a>
                <a href="index.php" class="btn btn-secondary">Volver</a>
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
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width:170px">Timestamp</th>
                                <th style="width:90px">Nivel</th>
                                <th>Mensaje</th>
                            </tr>
                        </thead>
                        <tbody id="logs-tbody">
                            <?php foreach ($logs as $entry): ?>
                                <tr data-level="<?php echo htmlspecialchars($entry['level'] ?? 'info'); ?>">
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
    var fg   = document.getElementById('log-level-filter');
    var tb   = document.getElementById('logs-tbody');
    var span = document.getElementById('log-count');
    if (!fg || !tb) return;
    fg.addEventListener('change', function () {
        var level = fg.querySelector('input:checked').value;
        var rows  = tb.querySelectorAll('tr[data-level]');
        var vis   = 0;
        rows.forEach(function (r) {
            var show = !level || r.dataset.level === level;
            r.style.display = show ? '' : 'none';
            if (show) vis++;
        });
        span.textContent = level ? vis + ' de ' + rows.length + ' entradas' : '';
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
