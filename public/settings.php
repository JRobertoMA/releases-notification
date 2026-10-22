<?php

declare(strict_types=1);

/**
 * Configuración.
 *
 * - Secretos y entorno (token del bot, GitHub Token, base de datos, PHP de consola, zona horaria, HTTPS) viven en el
 *   .env, que la web SOLO LEE: aquí se muestra su estado («termina en …abcd») y se pueden probar, pero cambiarlos
 *   es editar el .env por SSH. Una sesión web robada no puede leer ni cambiar secretos.
 * - Lo ajustable desde la web (chat de Telegram, intervalo del cron, registros OCI extra, tema por defecto) va a la
 *   tabla `settings` (App\AppConfig::SETTINGS). Cualquier otro campo enviado se ignora.
 * - Solo administradores (require_permission('settings')).
 * - La línea de crontab se recalcula en el navegador al cambiar el intervalo (cronSchedule() en JS replica
 *   cron_schedule() de aquí: si se cambia una, cambiar la otra).
 */

require __DIR__ . '/../includes/bootstrap.php';

use App\AppConfig;
use App\Database;
use App\Env;
use App\OciRegistry;

require_permission('settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $errors = [];

    $chat_id = trim((string) ($_POST['chat_id'] ?? ''));
    if ($chat_id !== '' && !preg_match('/^-?\d+$|^@\w{5,}$/', $chat_id)) {
        $errors[] = 'El chat debe ser un ID numérico o @canal.';
    }

    $registries = [];
    foreach (preg_split('/[\s,]+/', strtolower((string) ($_POST['oci_registries'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $host) {
        if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+(:\d+)?$/', $host)) {
            $errors[] = "«{$host}» no es un nombre de host válido.";
        } elseif (!in_array($host, OciRegistry::DEFAULT_ALLOWED_HOSTS, true)) {
            $registries[] = $host;
        }
    }

    if ($errors !== []) {
        redirect_with(implode(' ', $errors), 'danger', 'settings.php');
    }

    $theme = (string) ($_POST['theme'] ?? 'auto');
    AppConfig::setSettings([
        'TELEGRAM_CHAT_ID'     => $chat_id,
        'CHECK_INTERVAL_HOURS' => max(1, min(168, (int) ($_POST['check_interval_hours'] ?? 6))),
        'OCI_REGISTRIES'       => array_values(array_unique($registries)),
        'THEME'                => in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'auto',
    ]);
    redirect_with('Configuración guardada.', 'success', 'settings.php');
}

// Estado de lo que viene del .env (nunca se imprime un secreto: solo cómo termina)
$php_detected = php_cli_binary();
$php_env      = (string) cfg('PHP_CLI');
$force_https  = AppConfig::forceHttps();
$db_version   = (string) Database::value('SELECT VERSION()');
$env_rows = [
    ['TELEGRAM_BOT_TOKEN', 'Token del bot de Telegram', AppConfig::secretHint('TELEGRAM_BOT_TOKEN'), true, 'bot'],
    ['GITHUB_TOKEN', 'GitHub Token (opcional)', AppConfig::secretHint('GITHUB_TOKEN'), false, 'github'],
];

/**
 * Expresión de cron para «cada N horas». Nunca más espaciada que N (la portada avisa a las 2N h sin verificar):
 * menos de 24 h → cada N horas en punto; 168 → semanal; resto → cada floor(N/24) días.
 */
function cron_schedule(int $hours): string
{
    return match (true) {
        $hours <= 1    => '0 * * * *',
        $hours < 24    => "0 */{$hours} * * *",
        $hours === 168 => '0 0 * * 0',
        $hours < 48    => '0 0 * * *',
        default        => '0 0 */' . intdiv($hours, 24) . ' * *',
    };
}

// Línea de crontab lista para copiar. En el Docker local el cron vive en el host (docker exec).
$interval     = max(1, min(168, (int) cfg('CHECK_INTERVAL_HOURS')));
$schedule     = cron_schedule($interval);
$in_docker    = is_file('/.dockerenv');
$cron_php     = $php_detected ?? '/usr/bin/php8.4-cli';
$cron_command = $in_docker
    ? 'docker exec -u www-data php-apache php ' . BASE_DIR . '/check_releases.php > /dev/null 2>&1'
    : $cron_php . ' -f ' . BASE_DIR . '/check_releases.php > /dev/null';
$cron_line    = $schedule . ' ' . $cron_command;
$theme        = (string) cfg('THEME');

$page_title = 'Configuración';
$active     = 'settings';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3">Configuración</h1>

<section class="card mb-3" aria-labelledby="sec-env">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="h6 mb-0" id="sec-env"><?= icon('shield-lock', 'me-1') ?>Secretos y entorno <span class="fw-normal text-body-secondary">(fichero <code>.env</code>, solo lectura)</span></h2>
    </div>
    <div class="card-body">
        <p class="small text-body-secondary">
            Por seguridad, la web nunca muestra ni cambia estos valores: están en el fichero <code>.env</code> de la carpeta de la app
            (fuera de <code>public/</code>). Para cambiarlos, edítalo por SSH; en IONOS con <code>nano ~/notificador/.env</code>.
            La plantilla con todas las claves es <code>.env.example</code>.
        </p>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                <?php foreach ($env_rows as [$key, $label, $hint, $required, $check]): ?>
                    <tr>
                        <th scope="row" class="fw-normal"><?= h($label) ?><div class="meta font-monospace"><?= h($key) ?></div></th>
                        <td>
                            <?php if ($hint !== null): ?>
                                <span class="text-success"><?= icon('check-circle-fill', 'me-1') ?>Definido</span> <span class="meta">· termina en <?= h($hint) ?></span>
                            <?php elseif ($required): ?>
                                <span class="text-danger"><?= icon('x-circle-fill', 'me-1') ?>Falta en el .env</span>
                            <?php else: ?>
                                <span class="text-body-secondary">No definido</span>
                            <?php endif; ?>
                            <div id="<?= $check ?>_result" class="form-text" aria-live="polite"></div>
                        </td>
                        <td class="text-end">
                            <?php if ($hint !== null): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-check="<?= $check ?>"><?= icon('check2-circle', 'me-1') ?><?= $check === 'bot' ? 'Probar' : 'Comprobar' ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr>
                        <th scope="row" class="fw-normal">PHP de consola<div class="meta font-monospace">PHP_CLI</div></th>
                        <td>
                            <code><?= h($php_env !== '' ? $php_env : ($php_detected ?? '—')) ?></code>
                            <span class="meta"><?= $php_env !== '' ? '' : '· autodetectado (PHP_CLI vacío)' ?></span>
                            <div id="php_result" class="form-text" aria-live="polite">En IONOS debe ser <code>/usr/bin/php8.4-cli</code>: <code>php</code> a secas es PHP 4.4.</div>
                        </td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" data-check="php"><?= icon('check2-circle', 'me-1') ?>Comprobar</button></td>
                    </tr>
                    <tr>
                        <th scope="row" class="fw-normal">Zona horaria<div class="meta font-monospace">APP_TIMEZONE</div></th>
                        <td><code><?= h(AppConfig::timezone()) ?></code> <span class="meta">· ahora: <?= h(date('Y-m-d H:i')) ?></span></td>
                        <td></td>
                    </tr>
                    <tr>
                        <th scope="row" class="fw-normal">HTTPS<div class="meta font-monospace">FORCE_HTTPS</div></th>
                        <td><?= $force_https === null ? 'Automático <span class="meta">· se fuerza fuera de localhost/IPs privadas</span>' : ($force_https ? 'Siempre' : 'Desactivado') ?></td>
                        <td></td>
                    </tr>
                    <tr>
                        <th scope="row" class="fw-normal">2FA de administradores<div class="meta font-monospace">REQUIRE_ADMIN_2FA</div></th>
                        <td>
                            <?php if (AppConfig::adminTwoFactorRequired()): ?>
                                Obligatorio
                            <?php else: ?>
                                <span class="text-warning-emphasis"><?= icon('exclamation-triangle-fill', 'me-1') ?>Opcional</span>
                                <span class="meta">· solo para el Docker local; en IONOS quita REQUIRE_ADMIN_2FA del .env</span>
                            <?php endif; ?>
                        </td>
                        <td></td>
                    </tr>
                    <tr>
                        <th scope="row" class="fw-normal">Base de datos<div class="meta font-monospace">DB_*</div></th>
                        <td><span class="text-success"><?= icon('check-circle-fill', 'me-1') ?>Conectada</span> <span class="meta">· <?= h(Env::get('DB_NAME')) ?> en <?= h(Env::get('DB_HOST')) ?> · <?= h(preg_replace('/-.*/', '', $db_version)) ?></span></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<form method="post" action="settings.php" id="settings-form" autocomplete="off">
    <?= csrf_field() ?>

    <section class="card mb-3" aria-labelledby="sec-telegram">
        <div class="card-header"><h2 class="h6 mb-0" id="sec-telegram">Chat de Telegram</h2></div>
        <div class="card-body">
            <label for="chat_id" class="form-label">Chat donde avisar</label>
            <div class="input-group">
                <input type="text" class="form-control font-monospace" id="chat_id" name="chat_id"
                       value="<?= h(cfg('TELEGRAM_CHAT_ID')) ?>" autocomplete="off" aria-describedby="chat_result">
                <button type="button" class="btn btn-outline-primary" data-check="chats"><?= icon('search', 'me-1') ?>Detectar chat</button>
                <button type="button" class="btn btn-outline-success" data-check="test"><?= icon('send', 'me-1') ?>Enviar prueba</button>
            </div>
            <div id="chat_result" class="form-text" aria-live="polite">
                Escribe <code>/start</code> a tu bot (o añádelo al grupo) y pulsa <strong>Detectar chat</strong> para elegirlo sin copiar IDs.
            </div>
            <div id="chat_list" class="list-group mt-2" hidden></div>
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="sec-cron">
        <div class="card-header"><h2 class="h6 mb-0" id="sec-cron">Verificación automática (cron)</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="check_interval_hours" class="form-label">Intervalo del cron (horas)</label>
                    <input type="number" class="form-control" id="check_interval_hours" name="check_interval_hours"
                           min="1" max="168" value="<?= $interval ?>" data-saved="<?= $interval ?>" aria-describedby="interval_help">
                    <div id="interval_help" class="form-text">La portada avisa si pasan más de 2 intervalos sin una verificación completa.</div>
                </div>
                <div class="col-md-8">
                    <label for="cron_line" class="form-label">Línea para tu crontab <span class="fw-normal text-body-secondary">(<code>crontab -e</code><?= $in_docker ? ', en el host' : ', por SSH' ?>)</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace small" id="cron_line" value="<?= h($cron_line) ?>"
                               data-command="<?= h($cron_command) ?>" readonly aria-describedby="cron_desc cron_unsaved">
                        <button type="button" class="btn btn-outline-secondary" id="btn-copy-cron"><?= icon('clipboard', 'me-1') ?>Copiar</button>
                    </div>
                    <div id="cron_desc" class="form-text" aria-live="polite"></div>
                    <div id="cron_unsaved" class="form-text text-warning-emphasis" hidden>
                        <?= icon('exclamation-triangle-fill', 'me-1') ?>Intervalo sin guardar: pulsa <strong>Guardar configuración</strong> y sustituye la línea de tu crontab por esta.
                    </div>
                    <div class="form-text">Añade <code>MAILTO=tu@correo</code> en la primera línea del crontab para recibir los errores. Las horas son las del servidor.</div>
                </div>
            </div>
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="sec-oci">
        <div class="card-header"><h2 class="h6 mb-0" id="sec-oci">Registros OCI permitidos</h2></div>
        <div class="card-body">
            <p class="small mb-2">
                Por seguridad, la app solo consulta registros conocidos:
                <?php foreach (OciRegistry::DEFAULT_ALLOWED_HOSTS as $host): ?><code class="me-1"><?= h($host) ?></code><?php endforeach; ?>
            </p>
            <label for="oci_registries" class="form-label">Registros adicionales <span class="fw-normal text-body-secondary">(separados por coma)</span></label>
            <input type="text" class="form-control font-monospace" id="oci_registries" name="oci_registries"
                   value="<?= h(implode(', ', (array) cfg('OCI_REGISTRIES'))) ?>" placeholder="registry.ejemplo.com" spellcheck="false">
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="sec-look">
        <div class="card-header"><h2 class="h6 mb-0" id="sec-look">Apariencia</h2></div>
        <div class="card-body">
            <label for="theme" class="form-label">Tema por defecto</label>
            <select class="form-select" id="theme" name="theme" style="max-width:300px">
                <?php foreach (['auto' => 'Automático (según el sistema)', 'light' => 'Claro', 'dark' => 'Oscuro'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $theme === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Para quien aún no haya elegido el suyo: cada usuario cambia su tema con el botón <?= icon('circle-half') ?> de la barra superior.</div>
        </div>
    </section>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary"><?= icon('check2', 'me-1') ?>Guardar configuración</button>
        <a href="index.php" class="btn btn-outline-secondary">Volver</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    'use strict';
    var $ = function (id) { return document.getElementById(id); };

    function result(el, ok, html) {
        el.innerHTML = ok ? App.ok(html) : App.fail(html);
    }

    // Asistente (api/settings_check.php): los tokens los lee del .env en el servidor; aquí no viajan
    var OUT = { github: 'github_result', bot: 'bot_result', php: 'php_result' };
    document.querySelectorAll('[data-check]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.dataset.check;
            var out = $(OUT[action] || 'chat_result');
            var original = btn.innerHTML, label = btn.textContent.trim();
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + App.esc(label);
            App.post('api/settings_check.php', { action: action, chat_id: $('chat_id').value.trim() }).then(function (d) {
                if (action === 'bot') {
                    result(out, true, 'Token válido: <strong>@' + App.esc(d.username) + '</strong> (' + App.esc(d.name) + ')');
                } else if (action === 'test') {
                    result(out, true, 'Mensaje de prueba enviado. Revisa Telegram.');
                } else if (action === 'github') {
                    result(out, true, (d.remaining != null ? d.remaining + ' de ' + d.limit + ' peticiones disponibles' + (d.reset ? ' (se renueva a las ' + App.esc(d.reset) + ')' : '') : 'Token válido') +
                        ' · ghcr.io: ' + (d.packages ? '<span class="text-success">con read:packages</span>' : '<span class="text-warning-emphasis">sin read:packages (usará el registro anónimo)</span>'));
                } else if (action === 'php') {
                    result(out, true, '<code>' + App.esc(d.binary) + '</code> · ' + App.esc(d.version) + (d.autodetected ? ' (autodetectado)' : ''));
                } else if (action === 'chats') {
                    renderChats(d, out);
                }
            }).catch(function (err) {
                result(out, false, App.esc(err.message));
            }).then(function () {
                btn.disabled = false;
                btn.innerHTML = original;
            });
        });
    });

    var TYPES = { private: 'privado', group: 'grupo', supergroup: 'grupo', channel: 'canal' };
    function renderChats(chats, out) {
        var list = $('chat_list');
        list.innerHTML = '';
        if (!chats.length) {
            list.hidden = true;
            result(out, false, 'El bot no tiene mensajes recientes. Escríbele <code>/start</code> (o añádelo al grupo) y vuelve a pulsar.');
            return;
        }
        result(out, true, 'Elige el chat donde quieres los avisos:');
        chats.forEach(function (c) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            b.innerHTML = '<span><strong></strong> <span class="text-body-secondary small"></span></span><code></code>';
            b.querySelector('strong').textContent = c.title;
            b.querySelector('.small').textContent = (TYPES[c.type] || c.type) + (c.username ? ' · @' + c.username : '');
            b.querySelector('code').textContent = c.id;
            if (c.id === $('chat_id').value.trim()) { b.classList.add('active'); }
            b.addEventListener('click', function () {
                $('chat_id').value = c.id;
                list.querySelectorAll('.active').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                result(out, true, 'Chat «' + App.esc(c.title) + '» elegido. Pulsa <strong>Enviar prueba</strong> y luego <strong>Guardar</strong>.');
            });
            list.appendChild(b);
        });
        list.hidden = false;
    }

    // --- Línea de crontab en vivo: misma regla que cron_schedule() en PHP ---
    function cronSchedule(h) {
        if (h <= 1) { return '0 * * * *'; }
        if (h < 24) { return '0 */' + h + ' * * *'; }
        if (h === 168) { return '0 0 * * 0'; }
        if (h < 48) { return '0 0 * * *'; }
        return '0 0 */' + Math.floor(h / 24) + ' * *';
    }
    function pad(n) { return (n < 10 ? '0' : '') + n + ':00'; }
    function cronDescription(h) {
        if (h <= 1) { return 'Cada hora, en punto.'; }
        if (h < 24) {
            var hours = [];
            for (var x = 0; x < 24; x += h) { hours.push(pad(x)); }
            var text = 'Cada ' + h + ' h, en punto';
            if (hours.length <= 8) { text += ': a las ' + hours.slice(0, -1).join(', ') + ' y ' + hours[hours.length - 1]; }
            text += '.';
            if (24 % h !== 0) { text += ' Como 24 no es múltiplo de ' + h + ', el último intervalo del día es más corto (el cron vuelve a empezar a medianoche).'; }
            return text;
        }
        if (h === 168) { return 'Una vez a la semana: el domingo a las 00:00.'; }
        if (h < 48) { return 'Una vez al día, a las 00:00' + (h > 24 ? ' (con ' + h + ' h el cron no admite otro intervalo: se verifica a diario).' : '.'); }
        var d = Math.floor(h / 24);
        return 'Cada ' + d + ' días a las 00:00 (los días 1, ' + (1 + d) + ', ' + (1 + 2 * d) + '… de cada mes)' +
            (h % 24 ? '; ' + h + ' h se redondea a ' + d + ' días para no verificar menos de lo pedido.' : '.');
    }
    var intervalInput = $('check_interval_hours'), cronInput = $('cron_line');
    function updateCron() {
        var h = parseInt(intervalInput.value, 10);
        var valid = h >= 1 && h <= 168;
        intervalInput.classList.toggle('is-invalid', !valid && intervalInput.value !== '');
        if (!valid) {
            $('cron_desc').textContent = 'Escribe un número de horas entre 1 y 168.';
            $('cron_unsaved').hidden = true;
            return;
        }
        cronInput.value = cronSchedule(h) + ' ' + cronInput.dataset.command;
        $('cron_desc').textContent = cronDescription(h);
        $('cron_unsaved').hidden = String(h) === intervalInput.dataset.saved;
    }
    intervalInput.addEventListener('input', updateCron);
    updateCron();

    $('btn-copy-cron').addEventListener('click', function () {
        navigator.clipboard.writeText($('cron_line').value).then(function () { App.toast('Línea copiada.', 'success'); });
    });
})();
</script>
</body>
</html>
