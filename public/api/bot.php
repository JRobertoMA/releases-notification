<?php

declare(strict_types=1);

/**
 * POST api/bot.php — eventos que reenvía el backend del bot (telegram-bot): comandos, botones, reacciones y
 * vinculación. Sin sesión: lo llama el bot, no un navegador. Firmado con HMAC (App\BotSignature, BOT_PROJECT_KEY).
 *
 * Evento: {"type": "ping|command|callback|reaction|link", "data": "rel:sync:a1b2c3", "ref": "a1b2c3",
 *          "from_id": 456, "chat_id": 123, "message_id": 789, …}
 * Respuesta: el sobre {data, error, status}; en data, qué debe hacer el bot (text, buttons, edit, toast…) o null.
 *
 * Qué se hace con cada evento: App\BotEvents (vincular, botones del aviso; comandos y reacciones, en fases
 * posteriores).
 */

$public_page = true;
$stateless   = true;
require __DIR__ . '/../../includes/bootstrap.php';

use App\BotEvents;
use App\BotSignature;
use App\Env;
use App\TelegramClient;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_out(null, 405, 'Método no permitido');
}

$body = (string) file_get_contents('php://input');
$ok   = hash_equals(TelegramClient::project(), (string) ($_SERVER['HTTP_X_BOT_PROJECT'] ?? ''))
    && BotSignature::verify(
        (string) Env::get('BOT_PROJECT_KEY', ''),
        (string) ($_SERVER['HTTP_X_BOT_TIMESTAMP'] ?? ''),
        (string) ($_SERVER['HTTP_X_BOT_SIGNATURE'] ?? ''),
        $body
    );
if (!$ok) {
    json_out(null, 401, 'Firma no válida');
}

$event = json_decode($body, true);
if (!is_array($event) || !is_string($event['type'] ?? null)) {
    json_out(null, 422, 'Evento no válido');
}

json_out(BotEvents::handle($event));
