<?php

declare(strict_types=1);

/**
 * POST api/settings_check.php — asistente de settings.php (PROPUESTA_UX §2.7).
 *
 *   action=bot     comprueba la conexión con el backend del bot y devuelve el @usuario del bot
 *   action=chats   chats que han escrito al bot o donde lo añadieron
 *   action=test    mensaje de prueba al chat indicado
 *   action=github  límite restante del token y si puede leer ghcr.io (read:packages)
 *
 * Telegram va por App\TelegramClient, a través del backend del bot (BOT_BACKEND_URL + BOT_PROJECT_KEY): «chats» lee
 * el registro de chats del bot. Los tokens y la clave salen SOLO del .env (la web no puede leerlos ni cambiarlos; lo que llegue en la petición se
 * ignora). El chat de prueba sí puede venir del formulario (aún sin guardar); si no, el guardado en Configuración.
 * action=php comprueba el PHP_CLI del .env (o el autodetectado si está vacío).
 */

require __DIR__ . '/../../includes/bootstrap.php';

use App\TelegramClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

require_permission('settings');
require_post_csrf();

$action = (string) ($_POST['action'] ?? '');

/** data de una llamada de TelegramClient, o 422 con su error. */
function telegram_result(array $r): mixed
{
    if (!$r['ok']) {
        json_out(null, 422, $r['error']);
    }
    return $r['data'];
}

switch ($action) {
    case 'bot':
        json_out(telegram_result(TelegramClient::me()));

    case 'chats':
        json_out(telegram_result(TelegramClient::chats()));

    case 'test':
        $chatId = trim((string) ($_POST['chat_id'] ?? '')) ?: (string) cfg('TELEGRAM_CHAT_ID');
        if (!preg_match('/^-?\d+$/', $chatId)) {
            json_out(null, 422, 'Indica un ID de chat numérico (usa «Detectar chat»).');
        }
        telegram_result(TelegramClient::test($chatId));
        json_out(['sent' => true]);

    case 'github':
        $token = (string) cfg('GITHUB_TOKEN');
        if ($token === '') {
            json_out(null, 422, 'No hay GITHUB_TOKEN en el .env: el límite de GitHub es de 60 peticiones/hora y ghcr.io usará el registro anónimo.');
        }
        $client  = new Client(['timeout' => 15, 'http_errors' => false]);
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'telegram-notifier'];
        try {
            $rate = $client->get('https://api.github.com/rate_limit', ['headers' => $headers]);
            if ($rate->getStatusCode() === 401) {
                json_out(null, 422, 'GitHub rechazó el token (401): está mal copiado o caducó.');
            }
            $core = (json_decode((string) $rate->getBody(), true) ?: [])['resources']['core'] ?? [];
            // Comprobación real de read:packages (vale también para tokens fine-grained, que no exponen scopes)
            $pkg = $client->get('https://api.github.com/users/linuxserver/packages/container/jellyfin/versions?per_page=1', ['headers' => $headers]);
        } catch (GuzzleException $e) {
            json_out(null, 502, 'No se pudo contactar con GitHub.');
        }
        json_out([
            'remaining' => $core['remaining'] ?? null,
            'limit'     => $core['limit'] ?? null,
            'reset'     => isset($core['reset']) ? date('H:i', (int) $core['reset']) : null,
            'scopes'    => $rate->getHeaderLine('X-OAuth-Scopes') ?: null,
            'packages'  => $pkg->getStatusCode() === 200,
        ]);

    case 'php':
        // PHP de consola para «Verificar» y el cron. Vacío = el que autodetecta php_cli_binary().
        $binary = trim((string) cfg('PHP_CLI'));
        $auto   = $binary === '';
        if ($auto) {
            $binary = php_cli_binary() ?? '';
            if ($binary === '') {
                json_out(null, 422, 'No se encontró ningún PHP 8 de consola. Pon PHP_CLI en el .env (IONOS: /usr/bin/php8.4-cli).');
            }
        } elseif (!preg_match('#^/[\w./-]+$#', $binary)) {
            json_out(null, 422, 'PHP_CLI del .env debe ser una ruta absoluta, p. ej. /usr/bin/php8.4-cli.');
        }
        $major = php_cli_version($binary);
        if ($major < 8) {
            json_out(null, 422, $major > 0
                ? "«{$binary}» es PHP {$major}: hace falta PHP 8 o superior."
                : "«{$binary}» no existe o no responde como PHP de consola.");
        }
        exec(escapeshellarg($binary) . ' -r ' . escapeshellarg('echo PHP_VERSION;') . ' 2>/dev/null', $out);
        json_out(['binary' => $binary, 'version' => 'PHP ' . trim($out[0] ?? ''), 'autodetected' => $auto]);

    default:
        json_out(null, 422, 'Acción no válida');
}
