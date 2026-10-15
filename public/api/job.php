<?php

declare(strict_types=1);

/**
 * GET api/job.php?id=<job>    estado de una verificación en segundo plano
 * GET api/job.php?current=1   la que esté en curso (o null)
 */

require __DIR__ . '/../../includes/bootstrap.php';

use App\Jobs;

$jobs = new Jobs();

if (!empty($_GET['current'])) {
    json_out($jobs->running());
}

$job = $jobs->get((string) ($_GET['id'] ?? ''));
$job === null ? json_out(null, 404, 'Verificación no encontrada') : json_out($job);
