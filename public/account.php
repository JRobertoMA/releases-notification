<?php

declare(strict_types=1);

/**
 * Mi cuenta (cualquier rol): cambiar la propia contraseña y gestionar la verificación en dos pasos.
 * Es la única página a la que puede entrar un admin sin 2FA, hasta activarlo.
 *
 * POST action=password     current_password, password, password_confirm
 * POST action=2fa_start    genera un secreto nuevo en la sesión (si ya hay 2FA, exige code: cambio de móvil)
 * POST action=2fa_enable   code — confirma el secreto de la sesión y lo activa; muestra los códigos de recuperación
 * POST action=2fa_cancel   descarta el alta en curso
 * POST action=2fa_codes    code — genera códigos de recuperación nuevos (los anteriores dejan de valer)
 * POST action=2fa_disable  code — quita el 2FA (no permitido a administradores: es obligatorio)
 * POST action=tg_code      genera un código REL-XXXXXXXX para vincular Telegram (10 min; anula el anterior)
 * POST action=tg_cancel    anula el código pendiente
 * POST action=tg_unlink    desvincula la cuenta de Telegram
 *
 * Las acciones sobre un 2FA ya activo piden un código actual (o de recuperación) y usan el límite de intentos del
 * login: una sesión robada no basta para quitar o sustituir el segundo factor.
 */

$allow_without_2fa = true;
require __DIR__ . '/../includes/bootstrap.php';

use App\Access;
use App\Crypto;
use App\EventLog;
use App\LoginThrottle;
use App\TelegramClient;
use App\TelegramLinks;
use App\Totp;
use App\Users;

const TOTP_SETUP_TTL = 900;
const TOTP_ISSUER    = 'Notificador';

$me = current_user();

/** Código de la app o de recuperación del usuario actual, con el límite de intentos del login. */
function check_current_factor(array $me, string $code): void
{
    $throttle = new LoginThrottle();
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $wait     = $throttle->blockedFor($ip, $me['username']);
    if ($wait > 0) {
        throw new InvalidArgumentException('Demasiados intentos fallidos. Espera ' . ($wait < 60 ? $wait . ' s' : (int) ceil($wait / 60) . ' min') . '.');
    }
    $digits = preg_replace('/\s+/', '', $code);
    $ok     = preg_match('/^\d{6}$/', $digits) ? users()->verifyTotp($me['id'], $digits) : users()->useRecoveryCode($me['id'], $code);
    if (!$ok) {
        $throttle->fail($ip, $me['username']);
        throw new InvalidArgumentException('Código incorrecto o ya usado.');
    }
    $throttle->clear($ip, $me['username']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $code   = trim((string) ($_POST['code'] ?? ''));
    $back   = 'account.php';

    try {
        switch ($action) {
            case 'password':
                $password = (string) ($_POST['password'] ?? '');
                if ($password !== (string) ($_POST['password_confirm'] ?? '')) {
                    throw new InvalidArgumentException('Las contraseñas no coinciden.');
                }
                if (users()->verify($me['username'], (string) ($_POST['current_password'] ?? '')) === null) {
                    throw new InvalidArgumentException('La contraseña actual no es correcta.');
                }
                users()->setPassword($me['id'], $password);
                // La huella de la contraseña cambió: se renueva la sesión propia para no cerrarla
                auth()->login(users()->find($me['id']), false);
                redirect_with('Contraseña cambiada. Se cerraron las sesiones «Recordarme» de otros navegadores.', 'success', $back);

            case '2fa_start':
                if (!Crypto::available()) {
                    throw new InvalidArgumentException('Falta APP_KEY en el .env: ejecuta cli/migrate.php en el servidor.');
                }
                if (Users::hasTotp($me)) {
                    check_current_factor($me, $code);
                }
                $_SESSION['totp_setup'] = ['secret' => Totp::generateSecret(), 'started' => time()];
                redirect($back . '#twofa');

            case '2fa_cancel':
                unset($_SESSION['totp_setup']);
                redirect_with('Alta de la verificación en dos pasos cancelada.', 'info', $back);

            case '2fa_enable':
                $setup = $_SESSION['totp_setup'] ?? null;
                if (!is_array($setup) || time() - (int) $setup['started'] > TOTP_SETUP_TTL) {
                    unset($_SESSION['totp_setup']);
                    throw new InvalidArgumentException('El alta caducó. Vuelve a empezar.');
                }
                $step = Totp::verify($setup['secret'], $code);
                if ($step === null) {
                    redirect_with('El código no coincide. Comprueba que escaneaste el QR de esta página y que la hora del móvil es correcta.', 'danger', $back . '#twofa');
                }
                $_SESSION['recovery_once'] = users()->enableTotp($me['id'], $setup['secret'], $step);
                unset($_SESSION['totp_setup']);
                redirect_with('Verificación en dos pasos activada. Guarda ahora tus códigos de recuperación.', 'success', $back . '#twofa');

            case '2fa_codes':
                if (!Users::hasTotp($me)) {
                    throw new InvalidArgumentException('No tienes activada la verificación en dos pasos.');
                }
                check_current_factor($me, $code);
                $_SESSION['recovery_once'] = users()->regenerateRecoveryCodes($me['id']);
                redirect_with('Códigos de recuperación nuevos generados; los anteriores ya no valen.', 'success', $back . '#twofa');

            case '2fa_disable':
                if (Access::twoFactorMandatory($me)) {
                    throw new InvalidArgumentException('Los administradores no pueden desactivarla: es obligatoria. Si cambiaste de móvil, usa «Configurar en otro móvil».');
                }
                check_current_factor($me, $code);
                users()->disableTotp($me['id']);
                redirect_with('Verificación en dos pasos desactivada.', 'warning', $back);

            case 'tg_code':
                if (!TelegramClient::configured()) {
                    throw new InvalidArgumentException('Para vincular Telegram hace falta el backend del bot (BOT_BACKEND_URL y BOT_PROJECT_KEY en el .env).');
                }
                if (Access::needsTwoFactorSetup($me)) {
                    throw new InvalidArgumentException('Activa antes la verificación en dos pasos.');
                }
                $_SESSION['tg_code'] = TelegramLinks::createCode($me['id']);
                redirect($back . '#telegram');

            case 'tg_cancel':
                TelegramLinks::discardCode($me['id']);
                unset($_SESSION['tg_code']);
                redirect($back . '#telegram');

            case 'tg_unlink':
                TelegramLinks::unlink($me['id']);
                unset($_SESSION['tg_code']);
                EventLog::log('info', "{$me['username']} desvinculó su cuenta de Telegram.");
                redirect_with('Cuenta de Telegram desvinculada: los botones de los avisos ya no actuarán en tu nombre.', 'success', $back . '#telegram');

            default:
                throw new InvalidArgumentException('Acción no válida.');
        }
    } catch (InvalidArgumentException $e) {
        redirect_with($e->getMessage(), 'danger', $back);
    }
}

// --- Vista ------------------------------------------------------------------------------------

$has_totp = Users::hasTotp($me);
$setup    = $_SESSION['totp_setup'] ?? null;
if (is_array($setup) && time() - (int) $setup['started'] > TOTP_SETUP_TTL) {
    unset($_SESSION['totp_setup']);
    $setup = null;
}
$recovery_once = $_SESSION['recovery_once'] ?? null;   // se muestran una sola vez
unset($_SESSION['recovery_once']);

$codes_left   = $has_totp ? users()->recoveryCodesLeft($me['id']) : 0;
$crypto_ok    = Crypto::available();
$must_enable  = Access::needsTwoFactorSetup($me);
$setup_uri    = $setup !== null ? Totp::uri($setup['secret'], $me['username'], TOTP_ISSUER) : null;

// Telegram: vínculo actual, o el código pendiente (se muestra mientras no caduque ni se haya usado)
$tg_link = TelegramLinks::forUser($me['id']);
$tg_code = $_SESSION['tg_code'] ?? null;
if ($tg_link !== null || !is_array($tg_code) || (int) $tg_code['expires_at'] < time()) {
    unset($_SESSION['tg_code']);
    $tg_code = null;
}
$tg_bot = null;
if ($tg_code !== null) {
    $_SESSION['tg_bot'] ??= (TelegramClient::me()['data']['username'] ?? null);
    $tg_bot = $_SESSION['tg_bot'];
}

$page_title = 'Mi cuenta';
$active     = 'account';
$head_extra = $setup !== null
    ? '<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js" integrity="sha384-8FWZA6BGMXhsfO+BLtrJK0We6gg5o1JyO8xQm6peWDEUs17ACA5ziE/NIAkl9z2k" crossorigin="anonymous"></script>'
    : '';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><?= icon('person-gear', 'me-1') ?> Mi cuenta</h1>

<?php if ($must_enable): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert">
        <?= icon('shield-exclamation', 'mt-1') ?>
        <div>
            <strong>Activa la verificación en dos pasos para continuar.</strong>
            Es obligatoria para los administradores; hasta entonces solo puedes usar esta página.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <section class="card" id="twofa" aria-labelledby="sec-2fa">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0" id="sec-2fa"><?= icon('shield-lock', 'me-1') ?>Verificación en dos pasos</h2>
                <?php if ($has_totp): ?>
                    <span class="badge text-bg-success"><?= icon('check-circle-fill', 'me-1') ?>Activa</span>
                <?php else: ?>
                    <span class="badge <?= $must_enable ? 'text-bg-warning' : 'text-bg-secondary' ?>">Inactiva</span>
                <?php endif; ?>
            </div>
            <div class="card-body">

            <?php if ($recovery_once !== null): ?>
                <div class="alert alert-info">
                    <h3 class="h6"><?= icon('key-fill', 'me-1') ?>Tus códigos de recuperación</h3>
                    <p class="small mb-2">
                        Cada uno sirve <strong>una sola vez</strong> para entrar si pierdes el móvil. Guárdalos en tu gestor de contraseñas
                        o imprímelos: <strong>no se volverán a mostrar</strong>.
                    </p>
                    <div class="row row-cols-2 g-1 font-monospace mb-2" id="recovery-codes">
                        <?php foreach ($recovery_once as $c): ?><div class="col"><code><?= h($c) ?></code></div><?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-copy-codes"><?= icon('clipboard', 'me-1') ?>Copiar</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-download-codes"><?= icon('download', 'me-1') ?>Descargar .txt</button>
                </div>
            <?php endif; ?>

            <?php if (!$crypto_ok): ?>
                <div class="alert alert-danger mb-0">
                    Falta <code>APP_KEY</code> en el <code>.env</code>, la clave que cifra los secretos 2FA.
                    Ejecuta <code>cli/migrate.php</code> en el servidor (la genera sola) y recarga esta página.
                </div>

            <?php elseif ($setup !== null): ?>
                <ol class="small ps-3">
                    <li>Abre tu app de autenticación (Google Authenticator, Aegis, 2FAS, Bitwarden, 1Password…) y añade una cuenta escaneando el QR.</li>
                    <li>Escribe el código de 6 dígitos que muestra la app.</li>
                </ol>
                <div class="d-flex flex-wrap gap-3 align-items-start mb-3">
                    <div id="qr" class="bg-white p-2 rounded border" style="width:200px;height:200px" role="img"
                         aria-label="Código QR para la app de autenticación"></div>
                    <div class="small" style="max-width:20rem">
                        <div class="text-body-secondary">¿No puedes escanear? Introduce esta clave a mano (tipo «basada en tiempo»):</div>
                        <code class="d-block fs-6 my-1 text-break user-select-all"><?= h(trim(chunk_split($setup['secret'], 4, ' '))) ?></code>
                        <div class="text-body-secondary">Cuenta: <?= h(TOTP_ISSUER) ?> (<?= h($me['username']) ?>)</div>
                    </div>
                </div>
                <form method="post" action="account.php" class="d-flex flex-wrap gap-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="2fa_enable">
                    <div>
                        <label for="enable_code" class="form-label">Código de la app</label>
                        <input type="text" class="form-control font-monospace" id="enable_code" name="code" required autofocus
                               inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" style="max-width:10rem">
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('shield-check', 'me-1') ?>Activar</button>
                    <button type="submit" class="btn btn-outline-secondary" form="cancel-setup">Cancelar</button>
                </form>
                <form method="post" action="account.php" id="cancel-setup">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="2fa_cancel">
                </form>
                <?php if ($has_totp): ?>
                    <p class="small text-body-secondary mt-3 mb-0">Hasta que confirmes, sigue valiendo la app que tienes ahora.</p>
                <?php endif; ?>

            <?php elseif (!$has_totp): ?>
                <p>
                    Además de la contraseña, al entrar se te pedirá un código de 6 dígitos que genera una app en tu móvil.
                    Así, aunque alguien averigüe tu contraseña, no podrá entrar sin el móvil.
                </p>
                <form method="post" action="account.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="2fa_start">
                    <button type="submit" class="btn btn-primary"><?= icon('qr-code', 'me-1') ?>Activar verificación en dos pasos</button>
                </form>

            <?php else: ?>
                <p class="mb-2">
                    Activa desde <?= time_tag($me['totp_enabled_at']) ?>.
                    Códigos de recuperación sin usar: <strong class="<?= $codes_left <= 3 ? 'text-danger' : '' ?>"><?= $codes_left ?></strong> de <?= Users::RECOVERY_CODES ?>.
                </p>
                <p class="small text-body-secondary">Para cambiar algo, escribe un código actual de la app (o uno de recuperación) y elige la acción.</p>
                <form method="post" action="account.php" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="col-12 col-sm-5">
                        <label for="factor_code" class="form-label">Código actual</label>
                        <input type="text" class="form-control font-monospace" id="factor_code" name="code" required
                               autocomplete="one-time-code" maxlength="11" spellcheck="false" autocapitalize="none">
                    </div>
                    <div class="col-12 col-sm-7 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-outline-primary" name="action" value="2fa_codes"><?= icon('arrow-clockwise', 'me-1') ?>Nuevos códigos</button>
                        <button type="submit" class="btn btn-outline-primary" name="action" value="2fa_start"><?= icon('phone', 'me-1') ?>Configurar en otro móvil</button>
                        <?php if (!Access::twoFactorMandatory($me)): ?>
                            <button type="submit" class="btn btn-outline-danger" name="action" value="2fa_disable"><?= icon('shield-x', 'me-1') ?>Desactivar</button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if (Access::twoFactorMandatory($me)): ?>
                    <p class="small text-body-secondary mt-3 mb-0">Como administrador no puedes desactivarla. Si pierdes el móvil y los códigos, otro administrador puede restablecerla en Usuarios, o por consola: <code>cli/user.php 2fa-reset <?= h($me['username']) ?></code>.</p>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-12 col-lg-5">
        <section class="card mb-3" aria-labelledby="sec-me">
            <div class="card-header"><h2 class="h6 mb-0" id="sec-me"><?= icon('person-circle', 'me-1') ?><?= h($me['username']) ?></h2></div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between">Rol <?= Access::badge($me['role']) ?></li>
                <li class="list-group-item d-flex justify-content-between">Alta <?= time_tag($me['created_at'] ?? null, '—', true) ?></li>
                <li class="list-group-item d-flex justify-content-between">Último acceso <?= time_tag($me['last_login_at'] ?? null, 'nunca') ?></li>
            </ul>
        </section>

        <section class="card mb-3" id="telegram" aria-labelledby="sec-tg">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0" id="sec-tg"><?= icon('telegram', 'me-1') ?>Telegram</h2>
                <?php if ($tg_link !== null): ?>
                    <span class="badge text-bg-success"><?= icon('link-45deg', 'me-1') ?>Vinculada</span>
                <?php endif; ?>
            </div>
            <div class="card-body small">
            <?php if ($tg_link !== null): ?>
                <p class="mb-2">
                    Vinculada con <strong><?= h(TelegramLinks::label($tg_link)) ?></strong>
                    <?php if (!empty($tg_link['telegram_username']) && $tg_link['telegram_name'] !== ''): ?><span class="text-body-secondary">(<?= h($tg_link['telegram_name']) ?>)</span><?php endif; ?>
                    · <?= time_tag($tg_link['linked_at']) ?>.
                </p>
                <p class="text-body-secondary">
                    <?= can('repos.write')
                        ? 'Los botones «Ya actualicé» e «Ignorar» de los avisos actúan en tu nombre, con tu rol.'
                        : 'Tu rol es de solo lectura: los botones de los avisos no harán cambios.' ?>
                </p>
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#confirmModal"
                        data-action="account.php" data-params="<?= h(json_encode(['action' => 'tg_unlink'])) ?>"
                        data-title="Desvincular Telegram"
                        data-body="Los botones de los avisos dejarán de actuar en tu nombre. Podrás volver a vincularla cuando quieras."
                        data-variant="danger" data-confirm-label="Desvincular"><?= icon('x-lg', 'me-1') ?>Desvincular</button>

            <?php elseif (!TelegramClient::configured()): ?>
                <p class="mb-0 text-body-secondary">Para vincular tu cuenta hace falta el backend del bot: <code>BOT_BACKEND_URL</code> y <code>BOT_PROJECT_KEY</code> en el <code>.env</code>.</p>

            <?php elseif ($tg_code !== null): ?>
                <p class="mb-2">Envía este mensaje al bot<?= $tg_bot ? ' <a href="https://t.me/' . h($tg_bot) . '" target="_blank" rel="noopener">@' . h($tg_bot) . '</a>' : '' ?> desde tu Telegram:</p>
                <div class="input-group mb-2">
                    <input type="text" class="form-control font-monospace" id="tg-command" value="/vincular <?= h($tg_code['code']) ?>" readonly aria-label="Mensaje para el bot">
                    <button type="button" class="btn btn-outline-primary" id="btn-copy-tg"><?= icon('clipboard', 'me-1') ?>Copiar</button>
                </div>
                <p class="text-body-secondary mb-2">Vale una sola vez, hasta las <?= h(date('H:i', (int) $tg_code['expires_at'])) ?>. Cuando el bot te confirme, recarga esta página.</p>
                <div class="d-flex gap-2">
                    <a href="account.php#telegram" class="btn btn-sm btn-primary"><?= icon('arrow-clockwise', 'me-1') ?>Ya lo envié</a>
                    <form method="post" action="account.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="tg_cancel">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Cancelar</button>
                    </form>
                </div>

            <?php elseif ($must_enable): ?>
                <p class="mb-0 text-body-secondary">Podrás vincular Telegram cuando actives la verificación en dos pasos.</p>

            <?php else: ?>
                <p>Vincula tu cuenta para usar los botones de los avisos («Ya actualicé», «Ignorar») desde Telegram, con los permisos de tu rol.</p>
                <form method="post" action="account.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="tg_code">
                    <button type="submit" class="btn btn-sm btn-primary"><?= icon('link-45deg', 'me-1') ?>Vincular Telegram</button>
                </form>
            <?php endif; ?>
            </div>
        </section>

        <form method="post" action="account.php" class="card" aria-labelledby="sec-pw">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <div class="card-header"><h2 class="h6 mb-0" id="sec-pw"><?= icon('key', 'me-1') ?>Cambiar contraseña</h2></div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="pw_current" class="form-label">Contraseña actual</label>
                    <input type="password" class="form-control" id="pw_current" name="current_password" required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label for="pw_new" class="form-label">Nueva contraseña</label>
                    <input type="password" class="form-control" id="pw_new" name="password" required minlength="10" autocomplete="new-password" aria-describedby="pw_help">
                    <div id="pw_help" class="form-text">Al menos 10 caracteres. Se cerrarán tus sesiones «Recordarme» en otros navegadores.</div>
                </div>
                <div class="mb-3">
                    <label for="pw_confirm" class="form-label">Repite la nueva contraseña</label>
                    <input type="password" class="form-control" id="pw_confirm" name="password_confirm" required minlength="10" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary"><?= icon('key', 'me-1') ?>Cambiar contraseña</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    'use strict';
    <?php if ($setup_uri !== null): ?>
    // QR generado en el navegador: el secreto no sale hacia ningún servicio externo
    var box = document.getElementById('qr');
    if (window.qrcode) {
        var qr = qrcode(0, 'M');
        qr.addData(<?= js($setup_uri) ?>);
        qr.make();
        box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    } else {
        box.textContent = 'No se pudo cargar el generador de QR: usa la clave de texto.';
        box.classList.add('small', 'text-dark');
    }
    <?php endif; ?>

    <?php if ($tg_code !== null): ?>
    document.getElementById('btn-copy-tg').addEventListener('click', function () {
        navigator.clipboard.writeText(document.getElementById('tg-command').value).then(function () { App.toast('Copiado. Pégalo en el chat del bot.', 'success'); });
    });
    <?php endif; ?>

    <?php if ($recovery_once !== null): ?>
    var text = <?= js("Códigos de recuperación · Notificador · " . $me['username'] . "\n" . implode("\n", $recovery_once) . "\n") ?>;
    document.getElementById('btn-copy-codes').addEventListener('click', function () {
        navigator.clipboard.writeText(text).then(function () { App.toast('Códigos copiados.', 'success'); });
    });
    document.getElementById('btn-download-codes').addEventListener('click', function () {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
        a.download = 'notificador-codigos-recuperacion.txt';
        a.click();
        URL.revokeObjectURL(a.href);
    });
    <?php endif; ?>
})();
</script>
</body>
</html>
