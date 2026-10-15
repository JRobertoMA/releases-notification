<?php

declare(strict_types=1);

/** POST api/theme.php  theme=auto|light|dark — guarda solo THEME en config.json (botón de la navbar). */

require __DIR__ . '/../../includes/bootstrap.php';

use App\JsonFile;

require_post_csrf();

$theme = (string) ($_POST['theme'] ?? '');
if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
    json_out(null, 422, 'Tema no válido');
}

// En el sitio y con bloqueo: conserva el propietario de config.json
JsonFile::modify($config_file, function ($cfg) use ($theme) {
    $cfg          = is_array($cfg) ? $cfg : [];
    $cfg['THEME'] = $theme;
    return $cfg;
});

json_out(['theme' => $theme]);
