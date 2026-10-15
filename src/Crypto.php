<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Cifrado simétrico de secretos guardados en la base de datos (hoy: el secreto TOTP de cada usuario).
 * sodium_crypto_secretbox con APP_KEY del .env: quien lea un volcado de la base no obtiene los secretos 2FA.
 *
 * APP_KEY la genera cli/migrate.php («base64:» + 32 bytes). Si se pierde o se cambia, los secretos 2FA dejan de
 * poder descifrarse: hay que restablecer el 2FA de cada usuario (cli/user.php 2fa-reset). Los códigos de
 * recuperación no dependen de ella (son hashes).
 */
final class Crypto
{
    private const PREFIX = 'v1:';

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox') && Env::get('APP_KEY') !== null;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** null si no se puede descifrar (APP_KEY distinta o dato manipulado). */
    public static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key()
        );
        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') {
            throw new RuntimeException('Falta APP_KEY en el .env: ejecuta cli/migrate.php para generarla.');
        }
        if (str_starts_with($key, 'base64:')) {
            $bin = base64_decode(substr($key, 7), true);
            if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $bin;
            }
        }
        // Clave escrita a mano: se deriva una de 32 bytes
        return sodium_crypto_generichash($key, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
