<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Vínculo entre un usuario de la web y una cuenta de Telegram (PROPUESTA_BOT_TELEGRAM.md §5). Tablas
 * `telegram_links` y `telegram_link_codes`.
 *
 * - «Mi cuenta → Vincular Telegram» genera un código REL-XXXXXXXX de un solo uso, válido CODE_TTL segundos. Solo se
 *   guarda su sha256 y solo hay uno por usuario: generar otro anula el anterior.
 * - El usuario lo envía al bot (/vincular REL-…). El bot lo reenvía a api/bot.php con su from.id, que es lo que se
 *   vincula: la persona, no el chat, así que también vale en grupos.
 * - Una cuenta de Telegram solo puede estar vinculada a un usuario. El rol no se guarda aquí: se vuelve a leer en
 *   cada acción (App\Access).
 */
final class TelegramLinks
{
    public const CODE_PREFIX = 'REL';
    public const CODE_TTL    = 600;
    // Sin 0/O, 1/I/L: el código se copia a mano en el móvil
    private const ALPHABET    = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 8;

    /** Código nuevo para el usuario (anula el que tuviera). @return array{code:string, expires_at:int} */
    public static function createCode(string $userId): array
    {
        $code = self::CODE_PREFIX . '-';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $expires = time() + self::CODE_TTL;
        Database::transaction(function () use ($userId, $code, $expires) {
            Database::run('DELETE FROM telegram_link_codes WHERE user_id = ? OR expires_at < ?', [$userId, time()]);
            Database::run('INSERT INTO telegram_link_codes (code_hash, user_id, expires_at) VALUES (?, ?, ?)', [self::hash($code), $userId, $expires]);
        });
        return ['code' => $code, 'expires_at' => $expires];
    }

    /**
     * Gasta el código y vincula esa cuenta de Telegram a su usuario. Si el usuario ya tenía otra, se sustituye.
     *
     * @return array el usuario vinculado
     * @throws InvalidArgumentException código no válido, caducado, o cuenta de Telegram vinculada a otro usuario
     */
    public static function redeem(string $code, int $telegramUserId, string $name, ?string $username): array
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^' . self::CODE_PREFIX . '-[' . self::ALPHABET . ']{' . self::CODE_LENGTH . '}$/', $code)) {
            throw new InvalidArgumentException('Ese código no es válido.');
        }
        return Database::transaction(function () use ($code, $telegramUserId, $name, $username) {
            $row = Database::one('SELECT user_id, expires_at FROM telegram_link_codes WHERE code_hash = ? FOR UPDATE', [self::hash($code)]);
            if ($row === null || (int) $row['expires_at'] < time()) {
                throw new InvalidArgumentException('Ese código no es válido o ya caducó.');
            }
            $user = Database::one('SELECT * FROM users WHERE id = ?', [$row['user_id']])
                ?? throw new InvalidArgumentException('Ese código no es válido o ya caducó.');
            $other = Database::value('SELECT user_id FROM telegram_links WHERE telegram_user_id = ? AND user_id <> ?', [$telegramUserId, $user['id']]);
            if ($other !== null) {
                throw new InvalidArgumentException('Esta cuenta de Telegram ya está vinculada a otro usuario. Desvincúlala antes desde su «Mi cuenta» o desde Usuarios.');
            }
            Database::run('DELETE FROM telegram_link_codes WHERE code_hash = ?', [self::hash($code)]);
            Database::run(
                'INSERT INTO telegram_links (user_id, telegram_user_id, telegram_name, telegram_username, linked_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE telegram_user_id = VALUES(telegram_user_id), telegram_name = VALUES(telegram_name),
                                         telegram_username = VALUES(telegram_username), linked_at = VALUES(linked_at)',
                [$user['id'], $telegramUserId, mb_substr($name, 0, 255), $username !== null ? mb_substr($username, 0, 64) : null, Database::now()]
            );
            return $user;
        });
    }

    /** Anula el código pendiente del usuario, si lo hay. */
    public static function discardCode(string $userId): void
    {
        Database::run('DELETE FROM telegram_link_codes WHERE user_id = ?', [$userId]);
    }

    public static function unlink(string $userId): void
    {
        Database::run('DELETE FROM telegram_links WHERE user_id = ?', [$userId]);
        Database::run('DELETE FROM telegram_link_codes WHERE user_id = ?', [$userId]);
    }

    public static function forUser(string $userId): ?array
    {
        return Database::one('SELECT * FROM telegram_links WHERE user_id = ?', [$userId]);
    }

    /** Usuario (fila completa de `users`) vinculado a esa cuenta de Telegram, o null. */
    public static function userFor(int $telegramUserId): ?array
    {
        return Database::one(
            'SELECT u.* FROM telegram_links l JOIN users u ON u.id = l.user_id WHERE l.telegram_user_id = ?',
            [$telegramUserId]
        );
    }

    /** @return array<string,array> vínculos por user_id (página Usuarios) */
    public static function all(): array
    {
        $out = [];
        foreach (Database::all('SELECT * FROM telegram_links') as $row) {
            $out[$row['user_id']] = $row;
        }
        return $out;
    }

    /** «@usuario» o el nombre de la cuenta de Telegram. */
    public static function label(array $link): string
    {
        return !empty($link['telegram_username']) ? '@' . $link['telegram_username'] : ($link['telegram_name'] ?: (string) $link['telegram_user_id']);
    }

    private static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
