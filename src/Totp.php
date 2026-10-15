<?php

declare(strict_types=1);

namespace App;

/**
 * Códigos de un solo uso por tiempo (TOTP, RFC 6238), compatibles con Google Authenticator, Aegis, 2FAS,
 * 1Password, Bitwarden…: HMAC-SHA1, 6 dígitos, pasos de 30 s. Sin librerías.
 *
 * verify() acepta el paso actual ±1 (relojes desfasados hasta 30 s) y devuelve el paso usado: quien llama lo
 * guarda en users.totp_last_step y lo pasa como $lastStep la próxima vez, para que un código ya usado
 * no sirva dos veces (anti-repetición).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const WINDOW = 1;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo: 160 bits en base32 (32 caracteres). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function currentStep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /** HOTP (RFC 4226) sobre la clave binaria. */
    public static function hotp(string $key, int $counter, int $digits = self::DIGITS): string
    {
        $hash   = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value  = ((ord($hash[$offset]) & 0x7f) << 24)
                | (ord($hash[$offset + 1]) << 16)
                | (ord($hash[$offset + 2]) << 8)
                | ord($hash[$offset + 3]);
        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function code(string $secret, ?int $time = null): string
    {
        return self::hotp(self::base32Decode($secret), self::currentStep($time));
    }

    /**
     * @return int|null  paso que coincide (posterior a $lastStep), o null si el código no vale
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $key  = self::base32Decode($secret);
        $now  = self::currentStep($time);
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::hotp($key, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** URI otpauth:// que leen las apps (la convierte en QR el navegador). */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32  = strtoupper(preg_replace('/[\s=-]+/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $i = strpos(self::BASE32, $c);
            if ($i !== false) {
                $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
