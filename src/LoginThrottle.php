<?php

declare(strict_types=1);

namespace App;

/**
 * Límite de intentos de login (htdocs/CLAUDE.md §5, SECURITY_AUDIT.md §11), en la tabla `login_attempts`.
 * Solo se registran los fallos; un acierto limpia la red y el usuario.
 *
 * - Por red: 5 fallos en 15 min bloquean esa red hasta que el fallo más antiguo sale de la ventana.
 *   Las IPv6 se agrupan por su /64: quien tiene IPv6 suele disponer de un /64 entero y, contando por
 *   dirección, podría cambiar de IP en cada intento. En IONOS los clientes llegan por IPv6.
 * - Por usuario: retardo creciente en vez de bloqueo largo (30 s, 60 s, 120 s… máx. 5 min desde el 5.º fallo),
 *   para que nadie pueda dejar fuera a otro solo conociendo su nombre. «Recordarme» no pasa por aquí.
 * - Nunca se duerme el proceso: la respuesta dice cuánto esperar (la cuenta de IONOS tiene 42 procesos).
 * - Horas en epoch (INT): no dependen de la zona horaria de nadie.
 */
final class LoginThrottle
{
    private const MAX_PER_NETWORK = 5;
    private const WINDOW          = 900;
    private const USER_FREE_FAILS = 4;
    private const USER_BASE_DELAY = 30;
    private const USER_MAX_DELAY  = 300;

    /** Red a la que se aplica el límite: la IPv4 tal cual, la IPv6 por su prefijo /64. */
    public static function networkKey(string $ip): string
    {
        $ip = trim($ip);
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return $ip;
    }

    /** Segundos que faltan para poder reintentar; 0 si no hay que esperar. */
    public function blockedFor(string $ip, string $username): int
    {
        $now   = time();
        $since = $now - self::WINDOW;
        $wait  = 0;

        $net = Database::one(
            'SELECT COUNT(*) AS n, MIN(attempted_at) AS first FROM login_attempts WHERE key_name = ? AND attempted_at > ?',
            ['net:' . self::networkKey($ip), $since]
        );
        if ((int) $net['n'] >= self::MAX_PER_NETWORK) {
            $wait = (int) $net['first'] + self::WINDOW - $now;
        }

        if ($username !== '') {
            $user = Database::one(
                'SELECT COUNT(*) AS n, MAX(attempted_at) AS last FROM login_attempts WHERE key_name = ? AND attempted_at > ?',
                ['user:' . Users::normalizeUsername($username), $since]
            );
            $over = (int) $user['n'] - self::USER_FREE_FAILS;
            if ($over > 0) {
                $delay = min(self::USER_BASE_DELAY * (2 ** ($over - 1)), self::USER_MAX_DELAY);
                $wait  = max($wait, (int) $user['last'] + $delay - $now);
            }
        }
        return max(0, $wait);
    }

    public function fail(string $ip, string $username): void
    {
        $now = time();
        Database::run('DELETE FROM login_attempts WHERE attempted_at <= ?', [$now - self::WINDOW]);
        foreach (self::keys($ip, $username) as $key) {
            Database::run('INSERT INTO login_attempts (key_name, attempted_at) VALUES (?, ?)', [$key, $now]);
        }
    }

    public function clear(string $ip, string $username): void
    {
        foreach (self::keys($ip, $username) as $key) {
            Database::run('DELETE FROM login_attempts WHERE key_name = ?', [$key]);
        }
    }

    private static function keys(string $ip, string $username): array
    {
        $keys = ['net:' . self::networkKey($ip)];
        if ($username !== '') {
            $keys[] = 'user:' . mb_substr(Users::normalizeUsername($username), 0, 90);
        }
        return $keys;
    }
}
