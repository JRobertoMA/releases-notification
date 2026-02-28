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
    $repo_id_to_delete = (int)$_GET['id'];

    $repos = file_exists($repositories_file) ? json_decode(file_get_contents($repositories_file), true) : [];
    if ($repos === null) $repos = [];

    // Check if the repository with that ID exists
    if (isset($repos[$repo_id_to_delete])) {
        $repo_to_delete = $repos[$repo_id_to_delete];
        $repo_display_name = $repo_to_delete['name'];

        // Remove the item from the array
        array_splice($repos, $repo_id_to_delete, 1);

        // Save the updated array back to the file
        file_put_contents($repositories_file, json_encode($repos, JSON_PRETTY_PRINT));

        $_SESSION['message'] = "Repositorio '{$repo_display_name}' eliminado correctamente.";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error: El repositorio no existe.';
        $_SESSION['message_type'] = 'danger';
    }
} else {
    $_SESSION['message'] = 'Error: No se proporcionó un ID válido para eliminar.';
    $_SESSION['message_type'] = 'danger';
}

header('Location: index.php');
exit;
