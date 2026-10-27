<?php

declare(strict_types=1);

namespace App;

/**
 * Punto único para leer la configuración. Cada clave vive en un sitio:
 *
 *   .env (solo lectura para la web; se edita por SSH)   → secretos y lo propio de cada entorno
 *   tabla `settings` (Configuración, solo admin)        → lo ajustable desde la web
 *
 * config.json ya no existe (cli/import_json.php lo reparte entre ambos).
 */
final class AppConfig
{
    /** Claves que vienen del .env. */
    public const ENV_KEYS = ['BOT_BACKEND_URL', 'BOT_PROJECT', 'BOT_PROJECT_KEY', 'TELEGRAM_BOT_TOKEN', 'GITHUB_TOKEN', 'PHP_CLI',
        'APP_TIMEZONE', 'FORCE_HTTPS', 'REQUIRE_ADMIN_2FA'];

    /** Claves editables desde la web, con su valor por defecto. */
    public const SETTINGS = [
        'TELEGRAM_CHAT_ID'     => '',
        'CHECK_INTERVAL_HOURS' => 6,
        'OCI_REGISTRIES'       => [],
        'THEME'                => 'auto',
    ];

    private static ?array $settings = null;

    public static function get(string $key): mixed
    {
        if (in_array($key, self::ENV_KEYS, true)) {
            return Env::get($key, '');
        }
        if (array_key_exists($key, self::SETTINGS)) {
            self::$settings ??= (new KeyValue('settings'))->all();
            return self::$settings[$key] ?? self::SETTINGS[$key];
        }
        return null;
    }

    /** Guarda ajustes web (solo claves de SETTINGS). */
    public static function setSettings(array $values): void
    {
        $kv = new KeyValue('settings');
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::SETTINGS)) {
                $kv->set($key, $value);
            }
        }
        self::$settings = null;
    }

    /** Zona horaria de la app (APP_TIMEZONE), validada; por defecto America/Mexico_City. */
    public static function timezone(): string
    {
        $tz = (string) Env::get('APP_TIMEZONE', '');
        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'America/Mexico_City';
    }

    /** FORCE_HTTPS del .env: null = automático (ver must_force_https() en bootstrap). */
    public static function forceHttps(): ?bool
    {
        $v = Env::get('FORCE_HTTPS');
        if ($v === null) {
            return null;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * REQUIRE_ADMIN_2FA del .env: el 2FA es obligatorio para administradores salvo REQUIRE_ADMIN_2FA=false
     * (pensado solo para el Docker local). Es una clave explícita y no una detección de «localhost», porque la
     * cabecera Host la manda el cliente. Si el valor no es un booleano válido, sigue siendo obligatorio.
     */
    public static function adminTwoFactorRequired(): bool
    {
        $v = Env::get('REQUIRE_ADMIN_2FA');
        return $v === null || filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /** «termina en …abcd» para mostrar que un secreto existe sin revelarlo. */
    public static function secretHint(string $key): ?string
    {
        $v = (string) Env::get($key, '');
        return $v === '' ? null : '…' . substr($v, -4);
    }
}
