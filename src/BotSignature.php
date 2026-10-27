<?php

declare(strict_types=1);

namespace App;

/**
 * Firma de las peticiones entre el notificador y el backend del bot (telegram-bot), en los dos sentidos. Copia de
 * App\Signature del bot (PROPUESTA_BOT_TELEGRAM.md §4): si cambia allí, cambia aquí.
 *
 *   X-Bot-Project:   nombre del proyecto en el bot (BOT_PROJECT, por defecto «releases»)
 *   X-Bot-Timestamp: hora Unix
 *   X-Bot-Signature: hex(hmac_sha256(BOT_PROJECT_KEY, timestamp + "." + cuerpo))
 *
 * Se rechaza si la hora se desvía más de WINDOW segundos o si la firma no coincide (hash_equals).
 */
final class BotSignature
{
    public const WINDOW = 300;

    public static function sign(string $key, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $key);
    }

    /** @return array<string,string> cabeceras listas para una petición saliente */
    public static function headers(string $project, string $key, string $body): array
    {
        $ts = time();
        return [
            'X-Bot-Project'   => $project,
            'X-Bot-Timestamp' => (string) $ts,
            'X-Bot-Signature' => self::sign($key, $ts, $body),
        ];
    }

    public static function verify(string $key, string $timestamp, string $signature, string $body): bool
    {
        if ($key === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WINDOW) {
            return false;
        }
        return preg_match('/^[0-9a-f]{64}$/', $signature) === 1
            && hash_equals(self::sign($key, (int) $timestamp, $body), $signature);
    }
}
