<?php

declare(strict_types=1);

/**
 * Verificación lanzada desde la web.
 *
 *   id=<id>        un repo: síncrono (1–2 s), con overlay en la página
 *   ids[]=…        varios repos (acción masiva) → segundo plano
 *   all=1          todos → segundo plano
 *
 * En segundo plano se crea un job en data/jobs/ y se lanza check_releases.php --job=<id> con setsid nohup:
 * no ocupa un worker de Apache (mpm_prefork) mientras dura. La página sigue el progreso con api/job.php.
 */

require __DIR__ . '/../includes/bootstrap.php';

use App\Jobs;

require_permission('repos.check');
require_post_csrf();

$php = php_cli_binary();
if ($php === null) {
    redirect_with('No se encontró un PHP 8 de consola. Indica su ruta en Configuración → «PHP de consola» (en IONOS: /usr/bin/php8.4-cli).', 'danger');
}
$script = escapeshellarg(BASE_DIR . '/check_releases.php');
$ids    = post_ids();
$all    = !empty($_POST['all']);

// --- Un solo repo: síncrono ---
if (!$all && count($ids) === 1) {
    $output = [];
    exec(escapeshellarg($php) . ' ' . $script . ' ' . escapeshellarg('--repo=' . $ids[0]) . ' 2>&1', $output, $code);
    // Solo nuestras líneas [nivel]; fuera avisos de arranque de PHP
    $lines   = array_values(array_filter($output, fn ($l) => isset($l[0]) && $l[0] === '['));
    $detail  = array_values(array_filter($lines, fn ($l) => !str_contains($l, 'Iniciando verificación') && !str_contains($l, 'Verificación completada')));
    $summary = preg_replace('/^\[\w+\]\s*/', '', $detail[0] ?? ($lines[count($lines) - 1] ?? ''));
    $level   = match (true) {
        $code !== 0                           => 'warning',
        str_starts_with($detail[0] ?? '', '[error]')   => 'danger',
        str_starts_with($detail[0] ?? '', '[warning]') => 'warning',
        str_starts_with($detail[0] ?? '', '[success]') => 'primary',
        default                               => 'success',
    };
    redirect_with($summary !== '' ? $summary : 'Verificación terminada.', $level);
}

// --- Varios o todos: segundo plano ---
if (!$all && $ids === []) {
    redirect_with('No se seleccionó ningún repositorio.', 'warning');
}
$jobs = new Jobs();
if (($running = $jobs->running()) !== null) {
    redirect_with('Ya hay una verificación en curso (' . $running['done'] . '/' . $running['total'] . '). Espera a que termine.', 'warning');
}

$total  = $all ? count(repo_store()->all()) : count($ids);
$job_id = $jobs->create('web', $all ? null : $ids, $total);
exec(sprintf(
    'setsid nohup %s %s %s > /dev/null 2>&1 &',
    escapeshellarg($php),
    $script,
    escapeshellarg('--job=' . $job_id)
));

flash($all
    ? 'Verificación de todos los repositorios iniciada en segundo plano.'
    : "Verificación de {$total} repositorios iniciada en segundo plano.", 'info');
redirect('index.php?job=' . urlencode($job_id));
