<?php

declare(strict_types=1);

/**
 * Segundo paso del inicio de sesión: código de la app de autenticación o un código de recuperación.
 * Solo se llega aquí con la contraseña ya comprobada (Auth::beginSecondFactor, válido 5 min).
 * Comparte el límite de intentos de login.php (mismas claves de red y de usuario en LoginThrottle).
 */

$public_page = true;
require __DIR__ . '/../includes/bootstrap.php';

use App\LoginThrottle;

if (current_user() !== null) {
    redirect('index.php');
}
if (isset($_GET['cancel'])) {
    auth()->cancelSecondFactor();
    redirect('login.php');
}

$pending = auth()->pendingSecondFactor();
if ($pending === null) {
    redirect_with('El inicio de sesión caducó. Vuelve a introducir tu contraseña.', 'warning', 'login.php');
}
$user  = $pending['user'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $code     = trim((string) ($_POST['code'] ?? ''));
    $throttle = new LoginThrottle();
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $wait     = $throttle->blockedFor($ip, $user['username']);
    $digits   = preg_replace('/\s+/', '', $code);

    if ($wait > 0) {
        $error = 'Demasiados intentos fallidos. Espera ' . ($wait < 60 ? $wait . ' s' : (int) ceil($wait / 60) . ' min') . ' y vuelve a intentarlo.';
    } elseif (preg_match('/^\d{6}$/', $digits) ? users()->verifyTotp($user['id'], $digits) : users()->useRecoveryCode($user['id'], $code)) {
        $throttle->clear($ip, $user['username']);
        auth()->login($user, $pending['remember']);
        if (!preg_match('/^\d{6}$/', $digits)) {
            $left = users()->recoveryCodesLeft($user['id']);
            flash("Has usado un código de recuperación; te quedan {$left}. Genera otros nuevos en «Mi cuenta» si te quedan pocos.", $left <= 3 ? 'warning' : 'info');
        }
        redirect(safe_next($pending['next']));
    } else {
        $throttle->fail($ip, $user['username']);
        $error = 'Código incorrecto o ya usado. Si tu reloj va desfasado, espera al siguiente código.';
    }
}

$page_title = 'Verificación en dos pasos';
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
                        <?= icon('phone') ?>
                    </span>
                    <h1 class="h4 mb-1">Verificación en dos pasos</h1>
                    <p class="text-body-secondary small mb-0">Hola, <?= h($user['username']) ?>. Abre tu app de autenticación.</p>
                </div>

                <?php if ($error !== null): ?>
                    <div class="alert alert-danger d-flex gap-2 align-items-start py-2" role="alert">
                        <?= icon('x-circle-fill', 'mt-1') ?><span><?= h($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="login_2fa.php" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="code" class="form-label">Código de 6 dígitos</label>
                        <input type="text" class="form-control form-control-lg text-center font-monospace" id="code" name="code"
                               required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="11"
                               spellcheck="false" autocapitalize="none" aria-describedby="code_help">
                        <div id="code_help" class="form-text">
                            ¿No tienes el móvil? Escribe uno de tus <strong>códigos de recuperación</strong> (formato <code>abcde-fghij</code>).
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?= icon('shield-check', 'me-1') ?>Verificar</button>
                </form>
                <div class="text-center mt-3">
                    <a href="login_2fa.php?cancel=1" class="small link-secondary">Cancelar y volver al inicio de sesión</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
// Al escribir un código de recuperación (letras), quitar el teclado numérico
document.getElementById('code').addEventListener('input', function (e) {
    e.target.inputMode = /[a-z]/i.test(e.target.value) ? 'text' : 'numeric';
});
</script>
</body>
</html>
