<?php

declare(strict_types=1);

/**
 * Configuración (config.json).
 *
 * Los secretos (token del bot, GitHub Token) nunca se devuelven al navegador: el campo va vacío y se muestra
 * solo cómo termina el valor guardado. Un campo vacío al guardar conserva el valor (SECURITY_AUDIT.md §7).
 * El cron por HTTP y su CRON_SECRET se eliminaron: la verificación automática es un crontab por SSH (§8).
 */

$allow_missing_config = true;   // primera ejecución: aquí se crea config.json
require __DIR__ . '/../includes/bootstrap.php';

use App\JsonFile;
use App\OciRegistry;

$config_exists = is_file($config_file);
$config += [
    'TELEGRAM_BOT_TOKEN'   => '',
    'TELEGRAM_CHAT_ID'     => '',
    'GITHUB_TOKEN'         => '',
    'REPOSITORIES_FILE'    => 'repositories.json',
    'THEME'                => 'auto',
    'CHECK_INTERVAL_HOURS' => 6,
    'PHP_CLI'              => '',
    'APP_TIMEZONE'         => app_timezone($config),
    'OCI_REGISTRIES'       => [],
];

/** «termina en …a1b2» para mostrar que hay un secreto guardado sin revelarlo. */
function secret_hint(string $secret): string
{
    return $secret === '' ? '' : 'Guardado · termina en …' . substr($secret, -4);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $errors = [];

    $bot_token = trim((string) ($_POST['bot_token'] ?? ''));
    if ($bot_token === '' && $config['TELEGRAM_BOT_TOKEN'] === '') {
        $errors[] = 'Falta el token del bot.';
    }

    $github_token = trim((string) ($_POST['github_token'] ?? ''));
    $remove_github = !empty($_POST['github_token_remove']);

    $php_cli = trim((string) ($_POST['php_cli'] ?? ''));
    if ($php_cli !== '' && (!preg_match('#^/[\w./-]+$#', $php_cli) || php_cli_version($php_cli) < 8)) {
        $errors[] = "«{$php_cli}» no es un PHP 8 ejecutable. En IONOS usa /usr/bin/php8.4-cli; déjalo vacío para autodetectar.";
    }

    $timezone = trim((string) ($_POST['app_timezone'] ?? ''));
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        $errors[] = "«{$timezone}» no es una zona horaria válida (p. ej. America/Mexico_City).";
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

    $theme    = (string) ($_POST['theme'] ?? 'auto');
    $interval = (int) ($_POST['check_interval_hours'] ?? 6);

    JsonFile::modify($config_file, function ($current) use ($bot_token, $github_token, $remove_github, $php_cli, $timezone, $registries, $theme, $interval) {
        $current = is_array($current) ? $current : [];
        $current += ['REPOSITORIES_FILE' => 'repositories.json'];
        if ($bot_token !== '') {
            $current['TELEGRAM_BOT_TOKEN'] = $bot_token;
        }
        if ($remove_github) {
            $current['GITHUB_TOKEN'] = '';
        } elseif ($github_token !== '') {
            $current['GITHUB_TOKEN'] = $github_token;
        }
        $current['TELEGRAM_CHAT_ID']     = trim((string) ($_POST['chat_id'] ?? ''));
        $current['PHP_CLI']              = $php_cli;
        $current['APP_TIMEZONE']         = $timezone;
        $current['OCI_REGISTRIES']       = array_values(array_unique($registries));
        $current['THEME']                = in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'auto';
        $current['CHECK_INTERVAL_HOURS'] = max(1, min(168, $interval));
        unset($current['CRON_SECRET']);   // del cron por HTTP, ya eliminado
        return $current;
    });
    redirect_with('Configuración guardada.', 'success', 'settings.php');
}

// Línea de crontab lista para copiar. En el Docker local el cron vive en el host (docker exec).
$interval     = max(1, min(168, (int) $config['CHECK_INTERVAL_HOURS']));
$schedule     = $interval < 24 ? "0 */{$interval} * * *" : '0 0 * * *';
$in_docker    = is_file('/.dockerenv');
$cron_php     = php_cli_binary() ?? '/usr/bin/php8.4-cli';
$cron_command = $in_docker
    ? 'docker exec -u www-data php-apache php ' . BASE_DIR . '/check_releases.php > /dev/null 2>&1'
    : $cron_php . ' -f ' . BASE_DIR . '/check_releases.php > /dev/null';
$cron_line    = $schedule . ' ' . $cron_command;

$page_title = 'Configuración';
$active     = 'settings';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3">Configuración</h1>

<?php if (!$config_exists): ?>
    <div class="alert alert-info">
        Primera configuración: crea un bot con <a href="https://t.me/BotFather" target="_blank" rel="noopener" class="alert-link">@BotFather</a>,
        pega su token, escríbele <code>/start</code> y pulsa <strong>Detectar chat</strong>.
    </div>
<?php endif; ?>

<form method="post" action="settings.php" id="settings-form" autocomplete="off">
    <?= csrf_field() ?>

    <section class="card mb-3" aria-labelledby="sec-telegram">
        <div class="card-header"><h2 class="h6 mb-0" id="sec-telegram">Telegram</h2></div>
        <div class="card-body">
            <div class="mb-3">
                <label for="bot_token" class="form-label">Token del bot</label>
                <div class="input-group">
                    <input type="password" class="form-control font-monospace" id="bot_token" name="bot_token" value=""
                           placeholder="<?= h($config['TELEGRAM_BOT_TOKEN'] !== '' ? secret_hint($config['TELEGRAM_BOT_TOKEN']) . ' · escribe uno nuevo para cambiarlo' : '123456:ABC…') ?>"
                           <?= $config['TELEGRAM_BOT_TOKEN'] === '' ? 'required' : '' ?> autocomplete="new-password" spellcheck="false"
                           aria-describedby="bot_result">
                    <button type="button" class="btn btn-outline-secondary" data-reveal="bot_token" aria-label="Mostrar u ocultar el token"><?= icon('eye') ?></button>
                    <button type="button" class="btn btn-outline-primary" data-check="bot"><?= icon('check2-circle', 'me-1') ?>Probar</button>
                </div>
                <div id="bot_result" class="form-text" aria-live="polite">
                    Lo da @BotFather al crear el bot. Por seguridad no se muestra el guardado; «Probar» usa el guardado si el campo está vacío.
                </div>
            </div>

            <div class="mb-1">
                <label for="chat_id" class="form-label">Chat donde avisar</label>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="chat_id" name="chat_id"
                           value="<?= h($config['TELEGRAM_CHAT_ID']) ?>" required autocomplete="off" aria-describedby="chat_result">
                    <button type="button" class="btn btn-outline-primary" data-check="chats"><?= icon('search', 'me-1') ?>Detectar chat</button>
                    <button type="button" class="btn btn-outline-success" data-check="test"><?= icon('send', 'me-1') ?>Enviar prueba</button>
                </div>
                <div id="chat_result" class="form-text" aria-live="polite">
                    Escribe <code>/start</code> a tu bot (o añádelo al grupo) y pulsa <strong>Detectar chat</strong> para elegirlo sin copiar IDs.
                </div>
                <div id="chat_list" class="list-group mt-2" hidden></div>
            </div>
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="sec-github">
        <div class="card-header"><h2 class="h6 mb-0" id="sec-github">GitHub <span class="fw-normal text-body-secondary">(opcional)</span></h2></div>
        <div class="card-body">
            <label for="github_token" class="form-label">GitHub Token</label>
            <div class="input-group">
                <input type="password" class="form-control font-monospace" id="github_token" name="github_token" value=""
                       placeholder="<?= h($config['GITHUB_TOKEN'] !== '' ? secret_hint($config['GITHUB_TOKEN']) . ' · escribe uno nuevo para cambiarlo' : 'ghp_xxxxxxxxxxxx') ?>"
                       autocomplete="new-password" spellcheck="false" aria-describedby="github_result github_help">
                <button type="button" class="btn btn-outline-secondary" data-reveal="github_token" aria-label="Mostrar u ocultar el token"><?= icon('eye') ?></button>
                <button type="button" class="btn btn-outline-primary" data-check="github"><?= icon('check2-circle', 'me-1') ?>Comprobar</button>
            </div>
            <?php if ($config['GITHUB_TOKEN'] !== ''): ?>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="github_token_remove" name="github_token_remove" value="1">
                    <label class="form-check-label" for="github_token_remove">Quitar el GitHub Token guardado</label>
                </div>
            <?php endif; ?>
            <div id="github_result" class="form-text" aria-live="polite"></div>
            <div id="github_help" class="form-text">
                Sube el límite de GitHub de 60 a 5 000 peticiones/hora. Con un token clásico con <code>read:packages</code>
                las imágenes de <strong>ghcr.io</strong> se consultan con fecha y en una sola petición.
                <a href="https://github.com/settings/tokens" target="_blank" rel="noopener">Crear token <?= icon('box-arrow-up-right') ?></a>
            </div>
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="sec-cron">
        <div class="card-header"><h2 class="h6 mb-0" id="sec-cron">Servidor y verificación automática</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="php_cli" class="form-label">PHP de consola</label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace" id="php_cli" name="php_cli"
                               value="<?= h($config['PHP_CLI']) ?>" placeholder="vacío = autodetectar" spellcheck="false" aria-describedby="php_result">
                        <button type="button" class="btn btn-outline-primary" data-check="php"><?= icon('check2-circle', 'me-1') ?>Comprobar</button>
                    </div>
                    <div id="php_result" class="form-text" aria-live="polite">
                        Para «Verificar» y el cron. En IONOS: <code>/usr/bin/php8.4-cli</code> (el comando <code>php</code> a secas es PHP 4.4).
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="app_timezone" class="form-label">Zona horaria</label>
                    <input type="text" class="form-control" id="app_timezone" name="app_timezone" list="tz-list" required
                           value="<?= h($config['APP_TIMEZONE']) ?>" spellcheck="false" aria-describedby="tz_help">
                    <datalist id="tz-list">
                        <?php foreach (timezone_identifiers_list() as $tz): ?>
                            <option value="<?= h($tz) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div id="tz_help" class="form-text">La de las fechas de la app. El servidor de IONOS está en America/New_York.</div>
                </div>
                <div class="col-md-4">
                    <label for="check_interval_hours" class="form-label">Intervalo del cron (horas)</label>
                    <input type="number" class="form-control" id="check_interval_hours" name="check_interval_hours"
                           min="1" max="168" value="<?= (int) $config['CHECK_INTERVAL_HOURS'] ?>" aria-describedby="interval_help">
                    <div id="interval_help" class="form-text">La portada avisa si pasan más de 2 intervalos sin una verificación completa.</div>
                </div>
                <div class="col-md-8">
                    <label for="cron_line" class="form-label">Línea para tu crontab <span class="fw-normal text-body-secondary">(<code>crontab -e</code><?= $in_docker ? ', en el host' : ', por SSH' ?>)</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace small" id="cron_line" value="<?= h($cron_line) ?>" readonly>
                        <button type="button" class="btn btn-outline-secondary" id="btn-copy-cron"><?= icon('clipboard', 'me-1') ?>Copiar</button>
                    </div>
                    <div class="form-text">Se actualiza al guardar. Añade <code>MAILTO=tu@correo</code> en la primera línea del crontab para recibir los errores.</div>
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
                   value="<?= h(implode(', ', (array) $config['OCI_REGISTRIES'])) ?>" placeholder="registry.ejemplo.com" spellcheck="false">
        </div>
    </section>

    <section class="card mb-3" aria-labelledby="sec-look">
        <div class="card-header"><h2 class="h6 mb-0" id="sec-look">Apariencia</h2></div>
        <div class="card-body">
            <label for="theme" class="form-label">Tema</label>
            <select class="form-select" id="theme" name="theme" style="max-width:300px">
                <?php foreach (['auto' => 'Automático (según el sistema)', 'light' => 'Claro', 'dark' => 'Oscuro'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $config['THEME'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">También se cambia con el botón <?= icon('circle-half') ?> de la barra superior.</div>
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

    // Mostrar/ocultar lo que se está escribiendo (lo guardado nunca llega al navegador)
    document.querySelectorAll('[data-reveal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = $(btn.dataset.reveal);
            input.type = input.type === 'password' ? 'text' : 'password';
            btn.innerHTML = App.icon(input.type === 'password' ? 'eye' : 'eye-slash');
        });
    });

    function result(el, ok, html) {
        el.innerHTML = ok ? App.ok(html) : App.fail(html);
    }

    // Asistente (api/settings_check.php): usa lo escrito o, si el campo está vacío, lo guardado
    var OUT = { github: 'github_result', bot: 'bot_result', php: 'php_result' };
    document.querySelectorAll('[data-check]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.dataset.check;
            var out = $(OUT[action] || 'chat_result');
            var original = btn.innerHTML, label = btn.textContent.trim();
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + App.esc(label);
            App.post('api/settings_check.php', {
                action: action,
                bot_token: $('bot_token').value.trim(),
                chat_id: $('chat_id').value.trim(),
                github_token: $('github_token').value.trim(),
                php_cli: $('php_cli').value.trim()
            }).then(function (d) {
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

    $('btn-copy-cron').addEventListener('click', function () {
        navigator.clipboard.writeText($('cron_line').value).then(function () { App.toast('Línea copiada.', 'success'); });
    });
})();
</script>
</body>
</html>
