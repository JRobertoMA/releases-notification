<?php

declare(strict_types=1);

/**
 * Gestión de usuarios. Todos tienen los mismos permisos: cualquiera puede añadir usuarios, cambiar
 * contraseñas o eliminar a otro (nunca a sí mismo ni al último). Cambiar la propia contraseña exige la actual.
 *
 * POST action=create   username, password, password_confirm
 * POST action=password id, password, password_confirm [, current_password si es la propia]
 * POST action=delete   id
 */

require __DIR__ . '/../includes/bootstrap.php';

use App\Users;

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
                $user = users()->create((string) ($_POST['username'] ?? ''), $password);
                redirect_with("Usuario «{$user['username']}» creado.", 'success', $back);

            case 'password':
                $target = users()->find($id) ?? throw new InvalidArgumentException('El usuario ya no existe.');
                if ($password !== $confirm) {
                    throw new InvalidArgumentException('Las contraseñas no coinciden.');
                }
                $self = $target['id'] === $me['id'];
                if ($self && users()->verify($me['username'], (string) ($_POST['current_password'] ?? '')) === null) {
                    throw new InvalidArgumentException('La contraseña actual no es correcta.');
                }
                users()->setPassword($target['id'], $password);
                if ($self) {
                    // La huella de la contraseña cambió: se renueva la sesión propia para no cerrarla
                    auth()->login(users()->find($target['id']), false);
                }
                redirect_with($self
                    ? 'Contraseña cambiada. Se cerraron las sesiones «Recordarme» de otros navegadores.'
                    : "Contraseña de «{$target['username']}» cambiada; sus sesiones abiertas se han cerrado.", 'success', $back);

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

$page_title = 'Usuarios';
$active     = 'users';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><?= icon('people', 'me-1') ?> Usuarios</h1>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header"><?= count($all) ?> usuario(s) · todos con los mismos permisos</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Alta</th>
                            <th>Último acceso</th>
                            <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all as $u): $self = $u['id'] === $me['id']; ?>
                        <tr>
                            <td class="text-break">
                                <?= icon('person-circle', 'text-body-secondary me-1') ?><?= h($u['username']) ?>
                                <?php if ($self): ?><span class="badge text-bg-primary ms-1">tú</span><?php endif; ?>
                            </td>
                            <td class="meta"><?= time_tag($u['created_at'], '—', true) ?></td>
                            <td class="meta"><?= time_tag($u['last_login_at'], 'nunca') ?></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal" data-bs-target="#passwordModal"
                                        data-user-id="<?= h($u['id']) ?>" data-username="<?= h($u['username']) ?>" data-self="<?= $self ? '1' : '0' ?>">
                                    <?= icon('key') ?><span class="d-none d-sm-inline ms-1">Contraseña</span>
                                </button>
                                <?php if (!$self && count($all) > 1): ?>
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
                <div class="mb-3" id="pw_current_block">
                    <label for="pw_current" class="form-label">Contraseña actual</label>
                    <input type="password" class="form-control" id="pw_current" name="current_password" autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label for="pw_new" class="form-label">Nueva contraseña</label>
                    <input type="password" class="form-control" id="pw_new" name="password" required minlength="10" autocomplete="new-password">
                    <div class="form-text">Al menos 10 caracteres. Se cerrarán las sesiones «Recordarme» de ese usuario.</div>
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
        var btn  = e.relatedTarget;
        var self = btn.dataset.self === '1';
        document.getElementById('pw_user_id').value = btn.dataset.userId;
        document.getElementById('passwordModalTitle').textContent = self
            ? 'Cambiar mi contraseña'
            : 'Cambiar la contraseña de «' + btn.dataset.username + '»';
        document.getElementById('pw_current_block').hidden = !self;
        document.getElementById('pw_current').required = self;
        modal.querySelectorAll('input[type=password]').forEach(function (i) { i.value = ''; });
    });
    modal.addEventListener('shown.bs.modal', function () {
        var self = !document.getElementById('pw_current_block').hidden;
        document.getElementById(self ? 'pw_current' : 'pw_new').focus();
    });
})();
</script>
</body>
</html>
