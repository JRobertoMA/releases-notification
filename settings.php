<?php
session_start();

$config_file = __DIR__ . '/config.json';

// Load current config or start with defaults for first-time setup
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file), true) ?? [];
} else {
    $config = [
        'TELEGRAM_BOT_TOKEN' => '',
        'TELEGRAM_CHAT_ID' => '',
        'GITHUB_TOKEN' => '',
        'REPOSITORIES_FILE' => 'repositories.json',
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['TELEGRAM_BOT_TOKEN'] = trim($_POST['bot_token']);
    $config['TELEGRAM_CHAT_ID'] = trim($_POST['chat_id']);
    $config['GITHUB_TOKEN'] = trim($_POST['github_token']);
    if (!isset($config['REPOSITORIES_FILE'])) {
        $config['REPOSITORIES_FILE'] = 'repositories.json';
    }

    // Save updated config
    file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));

    $_SESSION['message'] = 'Configuración guardada correctamente.';
    $_SESSION['message_type'] = 'success';

    // Redirect to the same page to show the message and prevent form resubmission
    header('Location: settings.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Notificador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1 class="mb-4">Configuración</h1>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                Ajustes de Notificación (Telegram)
            </div>
            <div class="card-body">
                <form action="settings.php" method="POST">
                    <div class="mb-3">
                        <label for="bot_token" class="form-label">Token del Bot de Telegram</label>
                        <input type="text" class="form-control" id="bot_token" name="bot_token" value="<?php echo htmlspecialchars($config['TELEGRAM_BOT_TOKEN'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="chat_id" class="form-label">ID del Chat de Telegram</label>
                        <input type="text" class="form-control" id="chat_id" name="chat_id" value="<?php echo htmlspecialchars($config['TELEGRAM_CHAT_ID'] ?? ''); ?>" required>
                    </div>
                    <hr>
                    <h6 class="text-muted">GitHub API (opcional)</h6>
                    <div class="mb-3">
                        <label for="github_token" class="form-label">GitHub Token</label>
                        <input type="password" class="form-control" id="github_token" name="github_token" value="<?php echo htmlspecialchars($config['GITHUB_TOKEN'] ?? ''); ?>" placeholder="ghp_xxxxxxxxxxxx">
                        <div class="form-text">Sube el límite de peticiones de 60 a 5.000/hora. Genera uno en <a href="https://github.com/settings/tokens" target="_blank">GitHub Settings → Tokens</a> (sin permisos especiales para repos públicos).</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Guardar Configuración</button>
                    <a href="index.php" class="btn btn-secondary">Volver a la página principal</a>
                </form>
            </div>
        </div>

         <footer class="mt-5 mb-3 text-center text-muted">
            <p>JRobertoMA | <a href="https://github.com/JRobertoMA">Github</a></p>
        </footer>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
