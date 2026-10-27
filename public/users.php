<?php

declare(strict_types=1);

/**
 * Gestión de usuarios (solo administradores). La propia contraseña y el propio 2FA se gestionan en «Mi cuenta».
 * Nunca puede quedar el sistema sin un administrador (Users::setRole/delete lo impiden).
 *
 * POST action=create     username, password, password_confirm, role
 * POST action=password   id, password, password_confirm   (de otro usuario; cierra sus sesiones)
 * POST action=role       id, role
 * POST action=2fa_reset  id   (móvil perdido: entrará solo con la contraseña; si es admin, tendrá que reactivarlo)
 * POST action=tg_unlink  id   (desvincula su cuenta de Telegram: los botones de los avisos dejan de actuar en su nombre)
 * POST action=delete     id
 */

require __DIR__ . '/../includes/bootstrap.php';

use App\Access;
use App\EventLog;
use App\TelegramLinks;
use App\Users;

require_permission('users');

$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action   = (string) ($_POST['action'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');
    $id       = (string) ($_POST['id'] ?? '');
    $back     = 'users.php';

    try {
        switch ($action) {
            case 'create':
                if ($password !== $confirm) {
                    throw new InvalidArgumentException('Las contraseñas no coinciden.');
                }
                $user = users()->create((string) ($_POST['username'] ?? ''), $password, (string) ($_POST['role'] ?? ''));
                redirect_with("Usuario «{$user['username']}» creado como " . Access::ROLES[$user['role']]
                    . (Access::needsTwoFactorSetup($user) ? '. Al entrar tendrá que activar la verificación en dos pasos.' : '.'), 'success', $back);

            case 'password':
                $target = users()->find($id) ?? throw new InvalidArgumentException('El usuario ya no existe.');
                if ($target['id'] === $me['id']) {
                    redirect('account.php');
                }
                if ($password !== $confirm) {
                    throw new InvalidArgumentException('Las contraseñas no coinciden.');
                }
                users()->setPassword($target['id'], $password);
                redirect_with("Contraseña de «{$target['username']}» cambiada; sus sesiones abiertas se han cerrado.", 'success', $back);

            case 'role':
                $target = users()->find($id) ?? throw new InvalidArgumentException('El usuario ya no existe.');
                $role   = (string) ($_POST['role'] ?? '');
                users()->setRole($target['id'], $role);
                if ($target['id'] === $me['id'] && $role !== 'admin') {
                    redirect_with('Ya no eres administrador.', 'info', 'index.php');
                }
                redirect_with("«{$target['username']}» ahora es " . Access::ROLES[$role]
                    . (Access::needsTwoFactorSetup(['role' => $role] + $target) ? '. En su próximo acceso tendrá que activar la verificación en dos pasos.' : '.'), 'success', $back);

            case '2fa_reset':
                $target = users()->find($id) ?? throw new InvalidArgumentException('El usuario ya no existe.');
                if ($target['id'] === $me['id']) {
                    throw new InvalidArgumentException('Tu propio 2FA se gestiona en «Mi cuenta».');
                }
                users()->disableTotp($target['id']);
                redirect_with("Verificación en dos pasos de «{$target['username']}» restablecida.", 'success', $back);

            case 'tg_unlink':
                $target = users()->find($id) ?? throw new InvalidArgumentException('El usuario ya no existe.');
                TelegramLinks::unlink($target['id']);
                EventLog::log('info', "{$me['username']} desvinculó la cuenta de Telegram de {$target['username']}.");
                redirect_with("Telegram de «{$target['username']}» desvinculado.", 'success', $back);

            case 'delete':
                $target = users()->find($id) ?? throw new InvalidArgumentException('El usuario ya no existe.');
                if ($target['id'] === $me['id']) {
                    throw new InvalidArgumentException('No puedes eliminar tu propio usuario.');
                }
                users()->delete($target['id']);
                redirect_with("Usuario «{$target['username']}» eliminado.", 'success', $back);

            default:
                throw new InvalidArgumentException('Acción no válida.');
        }
    } catch (InvalidArgumentException $e) {
        redirect_with($e->getMessage(), 'danger', $back);
    }
}

$all = array_map([Users::class, 'publicData'], users()->all());
usort($all, fn ($a, $b) => strcmp($a['username'], $b['username']));
$admins = count(array_filter($all, fn ($u) => $u['role'] === 'admin'));
$tg_links = TelegramLinks::all();

$page_title = 'Usuarios';
$active     = 'users';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><?= icon('people', 'me-1') ?> Usuarios</h1>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header"><?= count($all) ?> usuario(s)</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>2FA</th>
                            <th>Telegram</th>
                            <th class="d-none d-xl-table-cell">Alta</th>
                            <th>Último acceso</th>
                            <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all as $u):
                        $self       = $u['id'] === $me['id'];
                        $last_admin = $u['role'] === 'admin' && $admins <= 1;
                    ?>
                        <tr>
                            <td class="text-break">
                                <?= icon('person-circle', 'text-body-secondary me-1') ?><?= h($u['username']) ?>
                                <?php if ($self): ?><span class="badge text-bg-primary ms-1">tú</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm p-0 border-0 dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"
                                            data-bs-popper-config='{"strategy":"fixed"}' aria-label="Cambiar el rol de <?= h($u['username']) ?>">
                                        <?= Access::badge($u['role']) ?>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <?php foreach (Access::ROLES as $role => $label): ?>
                                            <li>
                                                <?php if ($role === $u['role']): ?>
                                                    <span class="dropdown-item active" aria-current="true"><?= h($label) ?></span>
                                                <?php elseif ($last_admin): ?>
                                                    <span class="dropdown-item disabled" title="Es el único administrador"><?= h($label) ?></span>
                                                <?php else: ?>
                                                    <button type="button" class="dropdown-item"
                                                            data-post-action="users.php" data-post-params="<?= h(json_encode(['action' => 'role', 'id' => $u['id'], 'role' => $role])) ?>"><?= h($label) ?></button>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                        <?php if ($last_admin): ?>
                                            <li><span class="dropdown-item-text small text-body-secondary">Único administrador: nombra antes a otro.</span></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($u['totp_enabled_at'])): ?>
                                    <span class="text-success" title="Activa desde <?= h($u['totp_enabled_at']) ?>"><?= icon('shield-check') ?><span class="visually-hidden">Activa</span></span>
                                <?php elseif (Access::needsTwoFactorSetup($u)): ?>
                                    <span class="badge text-bg-warning" title="Obligatoria para administradores: se le pedirá al entrar">Pendiente</span>
                                <?php else: ?>
                                    <span class="text-body-secondary" title="No activada"><?= icon('dash') ?><span class="visually-hidden">No activada</span></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($tg_links[$u['id']])): $tg = $tg_links[$u['id']]; ?>
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-sm p-0 border-0 link-primary" data-bs-toggle="dropdown" aria-expanded="false"
                                                data-bs-popper-config='{"strategy":"fixed"}' title="Vinculada <?= h($tg['linked_at']) ?>">
                                            <?= icon('telegram', 'me-1') ?><span class="small"><?= h(TelegramLinks::label($tg)) ?></span>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <button type="button" class="dropdown-item text-danger"
                                                        data-bs-toggle="modal" data-bs-target="#confirmModal"
                                                        data-action="users.php" data-params="<?= h(json_encode(['action' => 'tg_unlink', 'id' => $u['id']])) ?>"
                                                        data-title="Desvincular Telegram"
                                                        data-body="Los botones de los avisos dejarán de actuar en nombre de «<?= h($u['username']) ?>». Podrá volver a vincularla desde su «Mi cuenta»."
                                                        data-variant="danger" data-confirm-label="Desvincular"><?= icon('x-lg', 'me-2') ?>Desvincular</button>
                                            </li>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <span class="text-body-secondary" title="Sin vincular"><?= icon('dash') ?><span class="visually-hidden">Sin vincular</span></span>
                                <?php endif; ?>
                            </td>
                            <td class="meta d-none d-xl-table-cell"><?= time_tag($u['created_at'], '—', true) ?></td>
                            <td class="meta"><?= time_tag($u['last_login_at'], 'nunca') ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($self): ?>
                                    <a href="account.php" class="btn btn-sm btn-outline-secondary"><?= icon('person-gear') ?><span class="d-none d-sm-inline ms-1">Mi cuenta</span></a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal" data-bs-target="#passwordModal"
                                            data-user-id="<?= h($u['id']) ?>" data-username="<?= h($u['username']) ?>"
                                            aria-label="Cambiar la contraseña de <?= h($u['username']) ?>">
                                        <?= icon('key') ?><span class="d-none d-sm-inline ms-1">Contraseña</span>
                                    </button>
                                <?php endif; ?>
                                <?php if (!$self && !empty($u['totp_enabled_at'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-warning"
                                            data-bs-toggle="modal" data-bs-target="#confirmModal"
                                            data-action="users.php" data-params="<?= h(json_encode(['action' => '2fa_reset', 'id' => $u['id']])) ?>"
                                            data-title="Restablecer verificación en dos pasos"
                                            data-body="¿Quitar el 2FA de «<?= h($u['username']) ?>»? Úsalo si perdió el móvil y sus códigos de recuperación. Podrá entrar solo con la contraseña<?= Access::twoFactorMandatory($u) ? ' y tendrá que volver a activarlo' : '' ?>."
                                            data-variant="warning" data-confirm-label="Restablecer"
                                            aria-label="Restablecer el 2FA de <?= h($u['username']) ?>">
                                        <?= icon('shield-x') ?>
                                    </button>
                                <?php endif; ?>
                                <?php if (!$self && count($all) > 1 && !$last_admin): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal" data-bs-target="#confirmModal"
                                            data-action="users.php" data-params="<?= h(json_encode(['action' => 'delete', 'id' => $u['id']])) ?>"
                                            data-title="Eliminar usuario"
                                            data-body="¿Eliminar a «<?= h($u['username']) ?>»? Sus sesiones abiertas se cerrarán."
                                            data-variant="danger" data-confirm-label="Eliminar"
                                            aria-label="Eliminar a <?= h($u['username']) ?>">
                                        <?= icon('trash') ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <form method="post" action="users.php" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="card-header"><?= icon('person-plus', 'me-1') ?> Añadir usuario</div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="new_username" class="form-label">Usuario</label>
                    <input type="text" class="form-control" id="new_username" name="username" required
                           pattern="[a-zA-Z0-9._\-]{3,32}" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="new_username_help">
                    <div id="new_username_help" class="form-text">De 3 a 32 caracteres: letras, números, punto, guion o guion bajo.</div>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label">Contraseña</label>
                    <input type="password" class="form-control" id="new_password" name="password" required minlength="10" autocomplete="new-password" aria-describedby="new_password_help">
                    <div id="new_password_help" class="form-text">Al menos 10 caracteres.</div>
                </div>
                <div class="mb-3">
                    <label for="new_password_confirm" class="form-label">Repite la contraseña</label>
                    <input type="password" class="form-control" id="new_password_confirm" name="password_confirm" required minlength="10" autocomplete="new-password">
                </div>
                <div class="mb-3">
                    <label for="new_role" class="form-label">Rol</label>
                    <select class="form-select" id="new_role" name="role" aria-describedby="new_role_help">
                        <?php foreach (Access::ROLES as $role => $label): ?>
                            <option value="<?= $role ?>" <?= $role === 'viewer' ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="new_role_help" class="form-text">
                        <strong>Lector</strong>: solo ver. <strong>Editor</strong>: además añadir, editar, verificar y borrar repos.
                        <strong>Administrador</strong>: además usuarios y Configuración; 2FA obligatorio.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><?= icon('person-plus', 'me-1') ?> Crear usuario</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="passwordModal" tabindex="-1" aria-labelledby="passwordModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="users.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="id" id="pw_user_id">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="passwordModalTitle">Cambiar contraseña</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="pw_new" class="form-label">Nueva contraseña</label>
                    <input type="password" class="form-control" id="pw_new" name="password" required minlength="10" autocomplete="new-password">
                    <div class="form-text">Al menos 10 caracteres. Se cerrarán todas sus sesiones abiertas.</div>
                </div>
                <div class="mb-1">
                    <label for="pw_confirm" class="form-label">Repite la nueva contraseña</label>
                    <input type="password" class="form-control" id="pw_confirm" name="password_confirm" required minlength="10" autocomplete="new-password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><?= icon('key', 'me-1') ?> Cambiar</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    var modal = document.getElementById('passwordModal');
    modal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        document.getElementById('pw_user_id').value = btn.dataset.userId;
        document.getElementById('passwordModalTitle').textContent = 'Cambiar la contraseña de «' + btn.dataset.username + '»';
        modal.querySelectorAll('input[type=password]').forEach(function (i) { i.value = ''; });
    });
    modal.addEventListener('shown.bs.modal', function () { document.getElementById('pw_new').focus(); });
})();
</script>
</body>
</html>
