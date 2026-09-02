<?php
session_start();

// Load configuration
$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    die('Error: config.json no encontrado. Configure la aplicación en settings.php');
}
$config = json_decode(file_get_contents($config_file), true);
$repositories_file = __DIR__ . '/' . $config['REPOSITORIES_FILE'];

// Check if an ID is provided and is a numeric value
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $repo_id = (int)$_GET['id'];

    $repos = file_exists($repositories_file) ? json_decode(file_get_contents($repositories_file), true) : [];
    if ($repos === null) $repos = [];

    if (isset($repos[$repo_id])) {
        $repo_display_name = $repos[$repo_id]['name'];
        $latest_release    = $repos[$repo_id]['last_seen_release'] ?? '';

        if ($latest_release === '') {
            $_SESSION['message']      = "No hay una versión detectada para sincronizar en '{$repo_display_name}'.";
            $_SESSION['message_type'] = 'warning';
        } else {
            $repos[$repo_id]['my_version']    = $latest_release;
            $repos[$repo_id]['my_updated_at'] = date('Y-m-d');

            file_put_contents($repositories_file, json_encode($repos, JSON_PRETTY_PRINT));

            $_SESSION['message']      = "Versión instalada de '{$repo_display_name}' sincronizada a {$latest_release}.";
            $_SESSION['message_type'] = 'success';
        }
    } else {
        $_SESSION['message']      = 'Error: El repositorio no existe.';
        $_SESSION['message_type'] = 'danger';
    }
} else {
    $_SESSION['message']      = 'Error: No se proporcionó un ID válido para sincronizar.';
    $_SESSION['message_type'] = 'danger';
}

header('Location: index.php');
exit;
