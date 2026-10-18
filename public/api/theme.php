<?php

declare(strict_types=1);

/** POST api/theme.php  theme=auto|light|dark — guarda el tema del usuario actual (cualquier rol). */

$allow_without_2fa = true;
require __DIR__ . '/../../includes/bootstrap.php';

require_post_csrf();

$theme = (string) ($_POST['theme'] ?? '');
if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
    json_out(null, 422, 'Tema no válido');
}

users()->setTheme(current_user()['id'], $theme);

json_out(['theme' => $theme]);
