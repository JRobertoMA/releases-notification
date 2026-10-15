<?php

declare(strict_types=1);

/** POST api/theme.php  theme=auto|light|dark — guarda el tema (ajuste THEME de la tabla settings). */

require __DIR__ . '/../../includes/bootstrap.php';

use App\AppConfig;

require_post_csrf();

$theme = (string) ($_POST['theme'] ?? '');
if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
    json_out(null, 422, 'Tema no válido');
}

AppConfig::setSettings(['THEME' => $theme]);

json_out(['theme' => $theme]);
