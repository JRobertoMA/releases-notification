<?php
/**
 * Cabecera común. Variables que puede definir la página antes de incluirla:
 *   $page_title  título de la pestaña
 *   $active      clave de la navegación: repos | dashboard | history | logs | settings | users
 *   $head_extra  HTML extra para <head> (p. ej. Chart.js, solo en las páginas que lo usan)
 *   $minimal     true = solo marca y tema, sin navegación ni menú de usuario (login.php)
 */
$page_title ??= 'Notificador de Releases';
$active     ??= '';
$head_extra ??= '';
$minimal    ??= false;

$theme = (string) cfg('THEME');
if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
    $theme = 'auto';
}
$asset_version = static fn (string $f) => $f . '?v=' . (@filemtime(BASE_DIR . '/public/' . $f) ?: 0);
$header_user   = $minimal ? null : current_user();
$theme_icon    = ['auto' => 'circle-half', 'light' => 'sun-fill', 'dark' => 'moon-stars-fill'][$theme];

$nav = [
    'repos'     => ['index.php',     'Repositorios',  'collection'],
    'dashboard' => ['dashboard.php', 'Dashboard',     'bar-chart-line'],
    'history'   => ['history.php',   'Historial',     'clock-history'],
    'logs'      => ['logs.php',      'Logs',          'journal-text'],
    'settings'  => ['settings.php',  'Configuración', 'gear'],
];
?>
<!DOCTYPE html>
<html lang="es" data-theme-pref="<?= h($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($page_title) ?> · Notificador de Releases</title>
    <script>
    // Antes del primer pintado, para que no parpadee el tema
    (function () {
        var pref = <?= json_encode($theme) ?>;
        var mq   = window.matchMedia('(prefers-color-scheme: dark)');
        document.documentElement.setAttribute('data-bs-theme', pref === 'auto' ? (mq.matches ? 'dark' : 'light') : pref);
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= h($asset_version('assets/app.css')) ?>" rel="stylesheet">
    <?= $head_extra ?>
</head>
<body>
<a class="visually-hidden-focusable" href="#main">Saltar al contenido</a>
<nav class="navbar navbar-expand-lg border-bottom bg-body-tertiary sticky-top">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php"><?= icon('bell-fill', 'text-primary me-1') ?>Notificador</a>
        <div class="d-flex align-items-center gap-1 order-lg-last">
            <button type="button" id="theme-toggle" class="btn btn-sm btn-outline-secondary" aria-label="Cambiar tema">
                <i class="bi bi-<?= $theme_icon ?>" aria-hidden="true" id="theme-toggle-icon"></i>
            </button>
            <?php if ($header_user !== null): ?>
                <div class="dropdown">
                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"
                            aria-label="Menú de <?= h($header_user['username']) ?>">
                        <?= icon('person-circle') ?><span class="d-none d-sm-inline ms-1"><?= h($header_user['username']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item<?= $active === 'users' ? ' active' : '' ?>" href="users.php"><?= icon('people', 'me-2') ?>Usuarios</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button type="button" class="dropdown-item" data-post-action="logout.php">
                                <?= icon('box-arrow-right', 'me-2') ?>Cerrar sesión
                            </button>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (!$minimal): ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-nav"
                        aria-controls="main-nav" aria-expanded="false" aria-label="Mostrar navegación">
                    <span class="navbar-toggler-icon"></span>
                </button>
            <?php endif; ?>
        </div>
        <?php if (!$minimal): ?>
            <div class="collapse navbar-collapse" id="main-nav">
                <ul class="navbar-nav me-auto">
                    <?php foreach ($nav as $key => [$href, $label, $nav_icon]): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $active === $key ? ' active fw-semibold' : '' ?>" href="<?= $href ?>"
                               <?= $active === $key ? 'aria-current="page"' : '' ?>><?= icon($nav_icon, 'me-1') ?><?= h($label) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</nav>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toast-area" style="z-index:1080">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="toast align-items-center text-bg-<?= h($_SESSION['message_type'] ?? 'info') ?> border-0" role="alert"
             aria-live="assertive" data-bs-delay="6000" data-autoshow>
            <div class="d-flex">
                <div class="toast-body"><?= h($_SESSION['message']) ?></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
            </div>
        </div>
        <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
    <?php endif; ?>
</div>

<main id="main" class="container py-4">
