<?php

declare(strict_types=1);

$allow_without_2fa = true;
require __DIR__ . '/../includes/bootstrap.php';

require_post_csrf();
auth()->logout();
redirect_with('Sesión cerrada.', 'info', 'login.php');
