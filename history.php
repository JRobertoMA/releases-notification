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
    'github' => 'bg-dark',
    'gitlab' => 'bg-warning text-dark',
    'docker' => 'bg-info text-dark',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Releases - Notificador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Historial de Releases</h1>
            <div>
                <a href="logs.php" class="btn btn-outline-secondary me-2">Ver Logs</a>
                <a href="index.php" class="btn btn-secondary">Volver</a>
            </div>
        </div>

        <?php if (empty($history)): ?>
            <div class="alert alert-info">No hay releases registrados aún. Se guardarán automáticamente cuando se detecten nuevas versiones.</div>
        <?php else: ?>
            <p class="text-muted">Mostrando los últimos <?php echo count($history); ?> releases detectados (más recientes primero).</p>
            <div class="card">
                <div class="card-body p-0">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width:170px">Fecha</th>
                                <th>Repositorio</th>
                                <th style="width:100px">Tipo</th>
                                <th style="width:130px">Versión</th>
                                <th style="width:80px">Enlace</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $entry): ?>
                                <tr>
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
</body>
</html>
