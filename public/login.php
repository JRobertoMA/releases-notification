<?php

declare(strict_types=1);

/**
 * Inicio de sesión. Los usuarios solo se crean por consola (cli/user.php): una alta desde la web dejaría que
 * cualquiera que encuentre el subdominio cree el primer administrador (SECURITY_AUDIT.md §2).
 *
 * Con 2FA activo, la contraseña correcta solo abre el segundo paso (login_2fa.php). Los intentos fallidos no se
 * limpian hasta completarlo: si no, reintroducir la contraseña reiniciaría el límite de intentos del código.
 */

$public_page = true;
require __DIR__ . '/../includes/bootstrap.php';

use App\LoginThrottle;
use App\Users;

$next     = safe_next($_POST['next'] ?? ($_GET['next'] ?? null));
$no_users = users()->count() === 0;

if (current_user() !== null) {
    redirect($next);
}

$error    = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$no_users) {
    require_post_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $throttle = new LoginThrottle();
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $wait     = $throttle->blockedFor($ip, $username);

    if ($wait > 0) {
        $error = 'Demasiados intentos fallidos. Espera ' . ($wait < 60 ? $wait . ' s' : (int) ceil($wait / 60) . ' min') . ' y vuelve a intentarlo.';
    } elseif (($user = users()->verify($username, $password)) === null) {
        $throttle->fail($ip, $username);
        $error = 'Usuario o contraseña incorrectos.';
    } elseif (Users::hasTotp($user)) {
        auth()->beginSecondFactor($user, !empty($_POST['remember']), $next);
        redirect('login_2fa.php');
    } else {
        $throttle->clear($ip, $username);
        auth()->login($user, !empty($_POST['remember']));
        redirect($next);
    }
}

$page_title = 'Iniciar sesión';
$minimal    = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center py-md-4">
    <div class="col-12 col-sm-10 col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary-emphasis mb-2"
                          style="width:3.5rem;height:3.5rem;font-size:1.6rem">
                        <?= icon($no_users ? 'terminal' : 'shield-lock') ?>
                    </span>
                    <h1 class="h4 mb-1"><?= $no_users ? 'Crea el primer usuario' : 'Iniciar sesión' ?></h1>
                    <p class="text-body-secondary small mb-0">Notificador de Releases</p>
                </div>

                <?php if ($no_users): ?>
                    <p>Aún no hay ningún usuario. Por seguridad, el primero se crea <strong>desde la consola del servidor</strong>, no desde la web:</p>
                    <p class="small fw-semibold mb-1">En IONOS (por SSH, dentro de la carpeta de la app):</p>
                    <pre class="bg-body-tertiary border rounded p-2 small mb-3"><code>/usr/bin/php8.4-cli cli/user.php create &lt;usuario&gt;</code></pre>
                    <p class="small fw-semibold mb-1">En Docker local:</p>
                    <pre class="bg-body-tertiary border rounded p-2 small mb-3"><code>docker exec -it -u www-data php-apache php /var/www/html/telegram-notification/cli/user.php create &lt;usuario&gt;</code></pre>
                    <p class="small text-body-secondary mb-0">
                        <?= icon('info-circle', 'me-1') ?>Usa la ruta completa de PHP 8: en IONOS el comando <code>php</code> a secas es PHP 4.4.
                        Después, recarga esta página.
                    </p>
                <?php else: ?>
                    <?php if ($error !== null): ?>
                        <div class="alert alert-danger d-flex gap-2 align-items-start py-2" role="alert">
                            <?= icon('x-circle-fill', 'mt-1') ?><span><?= h($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="login.php" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="next" value="<?= h($next) ?>">
                        <div class="mb-3">
                            <label for="username" class="form-label">Usuario</label>
                            <div class="input-group">
                                <span class="input-group-text"><?= icon('person-circle') ?></span>
                                <input type="text" class="form-control" id="username" name="username" value="<?= h($username) ?>"
                                       required autofocus autocomplete="username" autocapitalize="none" spellcheck="false">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Contraseña</label>
                            <div class="input-group">
                                <span class="input-group-text"><?= icon('key') ?></span>
                                <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                                <button type="button" class="btn btn-outline-secondary" data-reveal="password" aria-label="Mostrar u ocultar la contraseña">
                                    <?= icon('eye') ?>
                                </button>
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                            <label class="form-check-label" for="remember">Recordarme 30 días en este navegador</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><?= icon('box-arrow-in-right', 'me-1') ?>Entrar</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
document.querySelectorAll('[data-reveal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.dataset.reveal);
        var show  = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.innerHTML = App.icon(show ? 'eye-slash' : 'eye');
    });
});
</script>
</body>
</html>
