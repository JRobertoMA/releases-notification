<?php
session_start();

// Load configuration
$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    die('Error: config.json no encontrado. Configure la aplicación en settings.php');
}
$config = json_decode(file_get_contents($config_file), true);
$repositories_file = __DIR__ . '/' . $config['REPOSITORIES_FILE'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $repo_name = trim($_POST['repo_name']);
    $repo_type = trim($_POST['repo_type']);

    // --- Validation ---
    if (empty($repo_name) || empty($repo_type)) {
        $_SESSION['message'] = 'El nombre del repositorio y el tipo son obligatorios.';
        $_SESSION['message_type'] = 'danger';
        header('Location: index.php');
        exit;
    }

    $repos = file_exists($repositories_file) ? json_decode(file_get_contents($repositories_file), true) : [];
    if ($repos === null) $repos = []; // Ensure $repos is an array if file is empty or corrupt

    // --- Check for duplicates ---
    foreach ($repos as $repo) {
        // A simple check on name and type is sufficient now
        if ($repo['name'] === $repo_name && $repo['type'] === $repo_type) {
            $_SESSION['message'] = "El repositorio '{$repo_name}' con el tipo '{$repo_type}' ya existe.";
            $_SESSION['message_type'] = 'warning';
            header('Location: index.php');
            exit;
        }
    }

    // --- Add new repo ---
    $new_repo = [
        'name' => $repo_name,
        'type' => $repo_type,
        'last_seen_release' => null
    ];

    $repos[] = $new_repo;

    file_put_contents($repositories_file, json_encode($repos, JSON_PRETTY_PRINT));

    $_SESSION['message'] = "Repositorio '{$repo_name}' agregado correctamente.";
    $_SESSION['message_type'] = 'success';
}

header('Location: index.php');
exit;
