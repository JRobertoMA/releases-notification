<?php
session_start();

$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    header('Location: settings.php');
    exit;
}

// Busca el PHP CLI correcto (PHP_BINARY puede apuntar al módulo Apache/FPM en contexto web)
$php = '';
exec('which php 2>/dev/null', $which_out, $which_code);
if ($which_code === 0 && !empty($which_out[0])) {
    $php = trim($which_out[0]);
}
// Candidatos alternativos si 'which' falla
if (empty($php)) {
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php8', '/usr/bin/php82', '/usr/bin/php81', '/usr/bin/php80'] as $candidate) {
        if (is_executable($candidate)) {
            $php = $candidate;
            break;
        }
    }
}
if (empty($php)) {
    $_SESSION['message']      = 'No se encontró el binario de PHP CLI. Configura el cron job manualmente.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$script = escapeshellarg(__DIR__ . '/check_releases.php');
$output = [];
$code   = 0;

$id_arg = isset($_GET['id']) && ctype_digit($_GET['id'])
    ? ' ' . escapeshellarg('--id=' . $_GET['id'])
    : '';

exec(escapeshellarg($php) . ' ' . $script . $id_arg . ' 2>&1', $output, $code);

// Drop PHP startup warnings (missing extensions, etc.) — keep only our [level] log lines
$log_lines = array_values(array_filter($output, function ($line) {
    return isset($line[0]) && $line[0] === '[';
}));
$summary = implode(' | ', array_filter(array_slice($log_lines, -4)));
if ($code === 0) {
    $_SESSION['message']      = 'Verificación completada. ' . htmlspecialchars($summary);
    $_SESSION['message_type'] = 'success';
} else {
    $_SESSION['message']      = 'La verificación finalizó con errores. ' . htmlspecialchars($summary);
    $_SESSION['message_type'] = 'warning';
}

header('Location: index.php');
exit;
