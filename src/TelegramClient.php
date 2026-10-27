<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Todo lo que el notificador hace con Telegram: avisos, «Probar», «Detectar chat» y «Enviar prueba».
 *
 * Dos modos, según el .env:
 *   bot     BOT_BACKEND_URL + BOT_PROJECT_KEY → a través del backend compartido (telegram-bot), firmado con
 *           App\BotSignature. Es el modo normal desde v1.3.0: el bot tiene el webhook, así que getUpdates ya no
 *           funciona (409) y los chats salen de su registro (api/chats.php).
 *   direct  solo TELEGRAM_BOT_TOKEN → Bot API directa, como hasta v1.2.x. Queda de respaldo mientras exista el token.
 *
 * Todas las llamadas devuelven ['ok' => bool, 'data' => mixed, 'error' => ?string]. Los errores son para mostrar
 * al usuario y nunca contienen el token ni la clave.
 */
final class TelegramClient
{
    public const DEFAULT_PROJECT = 'releases';
    private const TIMEOUT = 15;

    /** 'bot', 'direct' o null (sin configurar). */
    public static function mode(): ?string
    {
        if (self::backendUrl() !== '' && (string) Env::get('BOT_PROJECT_KEY', '') !== '') {
            return 'bot';
        }
        return (string) Env::get('TELEGRAM_BOT_TOKEN', '') !== '' ? 'direct' : null;
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

    /** Aviso en HTML. $ref (id del repo) queda en el bot para las reacciones y ediciones posteriores. */
    public static function send(string $chat, string $html, ?string $ref = null): array
    {
        if (self::mode() === 'bot') {
            if (($id = self::numericChat($chat)) === null) {
                return self::fail('Con el backend del bot, el chat tiene que ser un ID numérico.');
            }
            return self::bot('POST', 'api/send.php', array_filter(['chat' => $id, 'text' => $html, 'parse_mode' => 'HTML', 'ref' => $ref]));
        }
        return self::direct('sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML']);
    }

    /** @return array data = ['username' => …, 'name' => …] */
    public static function me(): array
    {
        $r = self::mode() === 'bot' ? self::bot('GET', 'api/me.php') : self::direct('getMe');
        if ($r['ok']) {
            $r['data'] = ['username' => (string) ($r['data']['username'] ?? ''), 'name' => (string) ($r['data']['name'] ?? $r['data']['first_name'] ?? '')];
        }
        return $r;
    }

    /** @return array data = [['id' => '123', 'type' => 'private', 'title' => …, 'username' => …], …] */
    public static function chats(): array
    {
        if (self::mode() === 'bot') {
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
        // Sin offset: no confirma los updates, así que no se pierden para nadie
        $r = self::direct('getUpdates', ['limit' => 100, 'timeout' => 0]);
        if (!$r['ok']) {
            return $r;
        }
        $chats = [];
        foreach ($r['data'] as $u) {
            foreach (['message', 'edited_message', 'channel_post', 'my_chat_member', 'chat_member'] as $k) {
                $chat = $u[$k]['chat'] ?? null;
                if ($chat === null && $k === 'message') {
                    $chat = $u['callback_query']['message']['chat'] ?? null;
                }
                if (is_array($chat) && isset($chat['id'])) {
                    $title = $chat['title']
                        ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))
                        ?: ($chat['username'] ?? (string) $chat['id']);
                    $chats[(string) $chat['id']] = ['id' => (string) $chat['id'], 'type' => $chat['type'] ?? '', 'title' => $title, 'username' => $chat['username'] ?? null];
                }
            }
        }
        return ['ok' => true, 'data' => array_values($chats), 'error' => null];
    }

    public static function test(string $chat): array
    {
        if (self::mode() === 'bot') {
            if (($id = self::numericChat($chat)) === null) {
                return self::fail('Con el backend del bot, el chat tiene que ser un ID numérico.');
            }
            return self::bot('POST', 'api/test.php', ['chat' => $id]);
        }
        return self::direct('sendMessage', [
            'chat_id'    => $chat,
            'text'       => "✅ <b>Mensaje de prueba</b>\nEl Notificador de Releases puede escribir en este chat.",
            'parse_mode' => 'HTML',
        ]);
    }

    // --- Transporte ------------------------------------------------------------------------------

    private static function bot(string $method, string $path, ?array $payload = null): array
    {
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
            default => 'Bot: ' . $detail,
        });
    }

    private static function direct(string $method, array $params = []): array
    {
        $token = (string) Env::get('TELEGRAM_BOT_TOKEN', '');
        if (!preg_match('/^\d+:[\w-]{30,}$/', $token)) {
            return self::fail('Falta TELEGRAM_BOT_TOKEN en el .env (o BOT_BACKEND_URL y BOT_PROJECT_KEY), o no tiene el formato esperado.');
        }
        try {
            $res = self::http()->post("https://api.telegram.org/bot{$token}/{$method}", ['json' => $params]);
        } catch (GuzzleException $e) {
            // El mensaje de Guzzle incluye la URL, y la URL lleva el token del bot (SECURITY_AUDIT.md §14)
            error_log('Telegram API Error: ' . str_replace($token, '<TOKEN>', $e->getMessage()));
            return self::fail('No se pudo contactar con Telegram.');
        }
        $body = json_decode((string) $res->getBody(), true) ?: [];
        if (!empty($body['ok'])) {
            return ['ok' => true, 'data' => $body['result'], 'error' => null];
        }
        return self::fail(match ($res->getStatusCode()) {
            401     => 'Telegram rechazó el token (401). Revísalo en @BotFather.',
            409     => 'El bot tiene un webhook activo (el backend compartido): configura BOT_BACKEND_URL y BOT_PROJECT_KEY en el .env.',
            default => 'Telegram: ' . ($body['description'] ?? 'HTTP ' . $res->getStatusCode()),
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
