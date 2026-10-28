<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Todo lo que el notificador hace con Telegram: avisos, «Probar», «Detectar chat» y «Enviar prueba».
 *
 * Va siempre a través del backend compartido del bot (telegram-bot): BOT_BACKEND_URL + BOT_PROJECT_KEY en el .env,
 * peticiones firmadas con App\BotSignature. El bot tiene el webhook, así que los chats salen de su registro
 * (api/chats.php). El notificador ya no tiene el token del bot (el envío directo se quitó en v1.5.0).
 *
 * Todas las llamadas devuelven ['ok' => bool, 'data' => mixed, 'error' => ?string]. Los errores son para mostrar
 * al usuario y nunca contienen la clave.
 */
final class TelegramClient
{
    public const DEFAULT_PROJECT = 'releases';
    private const TIMEOUT = 15;

    public static function configured(): bool
    {
        return self::backendUrl() !== '' && (string) Env::get('BOT_PROJECT_KEY', '') !== '';
    }

    public static function backendUrl(): string
    {
        return rtrim((string) Env::get('BOT_BACKEND_URL', ''), '/');
    }

    /** Nombre del proyecto en el bot (cli/project.php add <nombre>). */
    public static function project(): string
    {
        $name = (string) Env::get('BOT_PROJECT', '');
        return $name !== '' ? $name : self::DEFAULT_PROJECT;
    }

    /**
     * Aviso en HTML. $ref (id del repo) queda en el bot para las reacciones y ediciones posteriores.
     * $buttons: filas de {text, data|url|copy} (App\ReleaseNotice).
     */
    public static function send(string $chat, string $html, ?string $ref = null, ?array $buttons = null): array
    {
        if (($id = self::numericChat($chat)) === null) {
            return self::fail('El chat tiene que ser un ID numérico.');
        }
        return self::bot('POST', 'api/send.php', array_filter(['chat' => $id, 'text' => $html, 'parse_mode' => 'HTML', 'ref' => $ref, 'buttons' => $buttons]));
    }

    /** @return array data = ['username' => …, 'name' => …] */
    public static function me(): array
    {
        $r = self::bot('GET', 'api/me.php');
        if ($r['ok']) {
            $r['data'] = ['username' => (string) ($r['data']['username'] ?? ''), 'name' => (string) ($r['data']['name'] ?? '')];
        }
        return $r;
    }

    /** @return array data = [['id' => '123', 'type' => 'private', 'title' => …, 'username' => …], …] */
    public static function chats(): array
    {
        $r = self::bot('GET', 'api/chats.php?days=30');
        if ($r['ok']) {
            $r['data'] = array_map(fn (array $c) => [
                'id'       => (string) $c['id'],
                'type'     => (string) ($c['type'] ?? ''),
                'title'    => (string) ($c['title'] ?? $c['username'] ?? $c['id']),
                'username' => $c['username'] ?? null,
            ], is_array($r['data']) ? $r['data'] : []);
        }
        return $r;
    }

    public static function test(string $chat): array
    {
        if (($id = self::numericChat($chat)) === null) {
            return self::fail('El chat tiene que ser un ID numérico.');
        }
        return self::bot('POST', 'api/test.php', ['chat' => $id]);
    }

    // --- Transporte ------------------------------------------------------------------------------

    private static function bot(string $method, string $path, ?array $payload = null): array
    {
        if (!self::configured()) {
            return self::fail('Faltan BOT_BACKEND_URL y BOT_PROJECT_KEY en el .env.');
        }
        $body    = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = BotSignature::headers(self::project(), (string) Env::get('BOT_PROJECT_KEY', ''), $body)
            + ['Accept' => 'application/json'] + ($payload === null ? [] : ['Content-Type' => 'application/json']);
        try {
            $res = self::http()->request($method, self::backendUrl() . '/' . $path, ['headers' => $headers, 'body' => $body]);
        } catch (GuzzleException $e) {
            error_log('Notificador: el backend del bot no responde: ' . $e->getMessage());
            return self::fail('El backend del bot no responde (' . self::backendUrl() . ').');
        }
        $status = $res->getStatusCode();
        $json   = json_decode((string) $res->getBody(), true);
        if ($status === 200 && is_array($json) && array_key_exists('data', $json)) {
            return ['ok' => true, 'data' => $json['data'], 'error' => null];
        }
        $detail = is_array($json) && !empty($json['error']) ? (string) $json['error'] : 'HTTP ' . $status;
        return self::fail(match ($status) {
            401     => 'El bot rechazó la firma: revisa BOT_PROJECT_KEY y BOT_PROJECT en el .env, y que la hora del servidor sea correcta.',
            301, 302, 307, 308 => 'BOT_BACKEND_URL redirige a otra dirección: usa la URL final (con https://).',
            404     => 'BOT_BACKEND_URL no apunta al bot: ' . self::backendUrl() . '/' . strtok($path, '?') . ' no existe (HTTP 404). Debe ser la URL raíz del bot, sin /public ni /api.',
            default => 'Bot: ' . $detail,
        });
    }

    private static function http(): Client
    {
        return new Client(['timeout' => self::TIMEOUT, 'connect_timeout' => 5, 'http_errors' => false, 'allow_redirects' => false]);
    }

    private static function numericChat(string $chat): ?int
    {
        return preg_match('/^-?\d+$/', $chat) ? (int) $chat : null;
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'data' => null, 'error' => $error];
    }
}
