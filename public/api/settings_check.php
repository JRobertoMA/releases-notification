<?php

declare(strict_types=1);

/**
 * POST api/settings_check.php — asistente de settings.php (PROPUESTA_UX §2.7).
 *
 *   action=bot     getMe: valida el token del bot y devuelve su @usuario
 *   action=chats   getUpdates: chats que han escrito al bot o donde lo añadieron (sin confirmar updates)
 *   action=test    sendMessage de prueba al chat indicado
 *   action=github  límite restante del token y si puede leer ghcr.io (read:packages)
 *
 * Los tokens salen SOLO del .env (la web no puede leerlos ni cambiarlos; lo que llegue en la petición se ignora).
 * El chat de prueba sí puede venir del formulario (aún sin guardar); si no, el guardado en Configuración.
 * action=php comprueba el PHP_CLI del .env (o el autodetectado si está vacío).
 * Cuando exista el backend de bot compartido (PROPUESTA_BOT_TELEGRAM.md), «chats» debe leer su registro:
 * con un webhook o un poller activos getUpdates deja de estar disponible.
 */

require __DIR__ . '/../../includes/bootstrap.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

require_post_csrf();

$action   = (string) ($_POST['action'] ?? '');
$botToken = (string) cfg('TELEGRAM_BOT_TOKEN');
$client   = new Client(['timeout' => 15, 'http_errors' => false]);

function telegram(Client $client, string $token, string $method, array $params = []): array
{
    if (!preg_match('/^\d+:[\w-]{30,}$/', $token)) {
        json_out(null, 422, 'Falta TELEGRAM_BOT_TOKEN en el .env o no tiene el formato esperado (123456:ABC…).');
    }
    try {
        $r = $client->post("https://api.telegram.org/bot{$token}/{$method}", ['json' => $params]);
    } catch (GuzzleException $e) {
        json_out(null, 502, 'No se pudo contactar con Telegram.');
    }
    $body = json_decode((string) $r->getBody(), true) ?: [];
    if (empty($body['ok'])) {
        $desc = $body['description'] ?? ('HTTP ' . $r->getStatusCode());
        $msg  = match (true) {
            $r->getStatusCode() === 401 => 'Telegram rechazó el token (401). Revísalo en @BotFather.',
            $r->getStatusCode() === 409 => 'El bot tiene un webhook activo u otro proceso leyendo sus mensajes; no se puede usar getUpdates.',
            default                     => 'Telegram: ' . $desc,
        };
        json_out(null, 422, $msg);
    }
    return $body['result'];
}

switch ($action) {
    case 'bot':
        $me = telegram($client, $botToken, 'getMe');
        json_out(['username' => $me['username'] ?? '', 'name' => $me['first_name'] ?? '']);

    case 'chats':
        $updates = telegram($client, $botToken, 'getUpdates', ['limit' => 100, 'timeout' => 0]);
        $chats   = [];
        foreach ($updates as $u) {
            foreach (['message', 'edited_message', 'channel_post', 'my_chat_member', 'chat_member'] as $k) {
                $chat = $u[$k]['chat'] ?? null;
                if ($chat === null && $k === 'message') {
                    $chat = $u['callback_query']['message']['chat'] ?? null;
                }
                if (is_array($chat) && isset($chat['id'])) {
                    $title = $chat['title']
                        ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))
                        ?: ($chat['username'] ?? (string) $chat['id']);
                    $chats[(string) $chat['id']] = [
                        'id'       => (string) $chat['id'],
                        'type'     => $chat['type'] ?? '',
                        'title'    => $title,
                        'username' => $chat['username'] ?? null,
                    ];
                }
            }
        }
        json_out(array_values($chats));

    case 'test':
        $chatId = trim((string) ($_POST['chat_id'] ?? '')) ?: (string) cfg('TELEGRAM_CHAT_ID');
        if (!preg_match('/^-?\d+$|^@\w{5,}$/', $chatId)) {
            json_out(null, 422, 'Indica un ID de chat válido (número, o @canal).');
        }
        telegram($client, $botToken, 'sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "✅ <b>Mensaje de prueba</b>\nEl Notificador de Releases puede escribir en este chat.",
            'parse_mode' => 'HTML',
        ]);
        json_out(['sent' => true]);

    case 'github':
        $token = (string) cfg('GITHUB_TOKEN');
        if ($token === '') {
            json_out(null, 422, 'No hay GITHUB_TOKEN en el .env: el límite de GitHub es de 60 peticiones/hora y ghcr.io usará el registro anónimo.');
        }
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
