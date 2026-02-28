<?php
session_start();

// Load configuration
$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    header('Location: settings.php');
    exit;
}
$config = json_decode(file_get_contents($config_file), true);
$repositories_file = __DIR__ . '/' . $config['REPOSITORIES_FILE'];

function get_repositories($file) {
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    return json_decode($json, true) ?? [];
}

$repos = get_repositories($repositories_file);

$status_badge = [
    'ok'    => ['class' => 'bg-success',          'label' => 'Al día'],
    'new'   => ['class' => 'bg-primary',           'label' => 'Nuevo'],
    'error' => ['class' => 'bg-danger',            'label' => 'Error'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificador de Releases</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Notificador de Releases</h1>
            <div class="d-flex gap-2">
                <a href="history.php" class="btn btn-outline-primary">Historial</a>
                <a href="logs.php" class="btn btn-outline-secondary">Logs</a>
                <a href="settings.php" class="btn btn-secondary">Configuración</a>
            </div>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                Agregar Repositorio
            </div>
            <div class="card-body">
                <form action="add_repo.php" method="POST">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="repo_name" class="form-label">Repositorio</label>
                                <input type="text" class="form-control" id="repo_name" name="repo_name" required placeholder="owner/repo">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="repo_type" class="form-label">Tipo</label>
                                <select class="form-select" id="repo_type" name="repo_type">
                                    <option value="github">GitHub</option>
                                    <option value="gitlab">GitLab</option>
                                    <option value="docker">Docker Hub</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Agregar</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Repositorios Monitoreados</span>
                <a href="run_check.php" class="btn btn-sm btn-success"
                   onclick="return confirm('¿Ejecutar verificación manual ahora? Puede tardar unos segundos.');">
                    Verificar ahora
                </a>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Repositorio</th>
                            <th>Tipo</th>
                            <th>Último Release</th>
                            <th>Estado</th>
                            <th>Última verificación</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($repos)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-3">No hay repositorios monitoreados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($repos as $index => $repo): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($repo['name']); ?></td>
                                    <td>
                                        <?php
                                            $repo_type = $repo['type'] ?? 'github';
                                            $badge_class = 'bg-secondary';
                                            switch ($repo_type) {
                                                case 'github': $badge_class = 'bg-dark'; break;
                                                case 'gitlab': $badge_class = 'bg-warning text-dark'; break;
                                                case 'docker': $badge_class = 'bg-info text-dark'; break;
                                            }
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($repo_type); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($repo['last_seen_release'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                            $cs = $repo['check_status'] ?? null;
                                            if ($cs && isset($status_badge[$cs])) {
                                                $sb = $status_badge[$cs];
                                                echo '<span class="badge ' . $sb['class'] . '">' . $sb['label'] . '</span>';
                                            } else {
                                                echo '<span class="text-muted small">—</span>';
                                            }
                                        ?>
                                    </td>
                                    <td class="text-muted small">
                                        <?php echo htmlspecialchars($repo['last_checked'] ?? '—'); ?>
                                    </td>
                                    <td>
                                        <a href="delete_repo.php?id=<?php echo $index; ?>"
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('¿Estás seguro de que quieres eliminar este repositorio?');">
                                            Eliminar
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

         <footer class="mt-5 mb-3 text-center text-muted">
            <p>JRobertoMA | <a href="https://github.com/JRobertoMA">Github</a></p>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
