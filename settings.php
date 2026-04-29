<?php
session_start();

$config_file = __DIR__ . '/config.json';

// Load current config or start with defaults for first-time setup
$config_exists = file_exists($config_file);
if ($config_exists) {
    $config = json_decode(file_get_contents($config_file), true) ?? [];
} else {
    $config = [];
}

$config += [
    'TELEGRAM_BOT_TOKEN' => '',
    'TELEGRAM_CHAT_ID'   => '',
    'GITHUB_TOKEN'       => '',
    'REPOSITORIES_FILE'  => 'repositories.json',
];

// Auto-generate CRON_SECRET if missing.
// If config.json already exists, persist it immediately so the URL stays stable on every page load.
if (empty($config['CRON_SECRET'])) {
    $config['CRON_SECRET'] = bin2hex(random_bytes(16));
    if ($config_exists) {
        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['TELEGRAM_BOT_TOKEN'] = trim($_POST['bot_token']);
    $config['TELEGRAM_CHAT_ID'] = trim($_POST['chat_id']);
    $config['GITHUB_TOKEN'] = trim($_POST['github_token']);
    $config['CRON_SECRET']  = trim($_POST['cron_secret']);
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
    <script>(function(){document.documentElement.setAttribute('data-bs-theme',localStorage.getItem('theme')||'light');})();</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">Configuración</h1>
            <button id="btn-theme" class="btn btn-outline-secondary btn-sm" title="Cambiar tema"></button>
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
                    <hr>
                    <h6 class="text-muted">Cron via HTTP (opcional)</h6>
                    <div class="mb-3">
                        <label for="cron_secret" class="form-label">Token secreto para cron HTTP</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" id="cron_secret" name="cron_secret"
                                   value="<?php echo htmlspecialchars($config['CRON_SECRET'] ?? ''); ?>">
                            <button type="button" class="btn btn-outline-secondary" id="btn-regen-secret" title="Generar nuevo token">↻ Regenerar</button>
                        </div>
                        <?php
                            $base      = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio');
                            $path      = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
                            $cron_url  = $base . $path . '/check_releases.php?token=' . ($config['CRON_SECRET'] ?? '');
                        ?>
                        <div class="form-text mt-2">
                            URL para cron externo (curl, etc.):<br>
                            <div class="input-group input-group-sm mt-1" style="max-width:600px">
                                <input type="text" class="form-control font-monospace" id="cron_url"
                                       value="<?php echo htmlspecialchars($cron_url); ?>" readonly>
                                <button type="button" class="btn btn-outline-secondary" id="btn-copy-url">Copiar</button>
                            </div>
                            <span id="copy-feedback" class="text-success small" style="display:none">¡Copiado!</span>
                        </div>
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
<script>
(function () {
    // Dark mode toggle
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

    // Regenerar secret
    document.getElementById('btn-regen-secret').addEventListener('click', function () {
        var arr = new Uint8Array(16);
        crypto.getRandomValues(arr);
        var hex = Array.from(arr).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
        document.getElementById('cron_secret').value = hex;
        // Update the readonly URL field preview
        var urlInput = document.getElementById('cron_url');
        urlInput.value = urlInput.value.replace(/token=[^&]*/, 'token=' + hex);
    });

    // Copiar URL
    document.getElementById('btn-copy-url').addEventListener('click', function () {
        var url = document.getElementById('cron_url').value;
        navigator.clipboard.writeText(url).then(function () {
            var fb = document.getElementById('copy-feedback');
            fb.style.display = '';
            setTimeout(function () { fb.style.display = 'none'; }, 2000);
        });
    });
})();
</script>
</body>
</html>
