<?php

declare(strict_types=1);

namespace App;

/**
 * Límite de intentos de login (htdocs/CLAUDE.md §5, SECURITY_AUDIT.md §11). Solo se registran los fallos;
 * un acierto limpia la red y el usuario.
 *
 * - Por red: 5 fallos en 15 min bloquean esa red hasta que el fallo más antiguo sale de la ventana.
 *   Las IPv6 se agrupan por su /64: quien tiene IPv6 suele disponer de un /64 entero (millones de
 *   direcciones) y, contando por dirección, podría cambiar de IP en cada intento. En IONOS los clientes
 *   llegan por IPv6.
 * - Por usuario: retardo creciente en vez de bloqueo largo (30 s, 60 s, 120 s… máx. 5 min desde el 5.º fallo).
 *   Un bloqueo de 15 min por usuario permitía a cualquiera dejar fuera a otro solo conociendo su nombre.
 *   Quien entra con «Recordarme» no pasa por aquí.
 * - Nunca se duerme el proceso: la respuesta dice cuánto esperar (la cuenta de IONOS tiene 42 procesos).
 */
final class LoginThrottle
{
    private const MAX_PER_NETWORK = 5;
    private const WINDOW          = 900;
    private const USER_FREE_FAILS = 4;      // a partir del 5.º fallo empieza el retardo
    private const USER_BASE_DELAY = 30;
    private const USER_MAX_DELAY  = 300;
    private const MAX_KEYS        = 2000;   // tope del fichero aunque lleguen miles de redes distintas

    public function __construct(private readonly string $file)
    {
    }

    public static function fromBase(string $baseDir): self
    {
        return new self($baseDir . '/data/login_attempts.json');
    }

    /** Red a la que se aplica el límite: la IPv4 tal cual, la IPv6 por su prefijo /64. */
    public static function networkKey(string $ip): string
    {
        $ip = trim($ip);
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return substr($ip, 7);   // IPv4 escrita como IPv6
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
        $data = JsonFile::read($this->file, []);
        $now  = time();
        $wait = 0;

        $net = self::recent($data['net:' . self::networkKey($ip)] ?? []);
        if (count($net) >= self::MAX_PER_NETWORK) {
            $wait = min($net) + self::WINDOW - $now;
        }

        if ($username !== '') {
            $user = self::recent($data['user:' . Users::normalizeUsername($username)] ?? []);
            $over = count($user) - self::USER_FREE_FAILS;
            if ($over > 0) {
                $delay = min(self::USER_BASE_DELAY * (2 ** ($over - 1)), self::USER_MAX_DELAY);
                $wait  = max($wait, max($user) + $delay - $now);
            }
        }
        return max(0, $wait);
    }

    public function fail(string $ip, string $username): void
    {
        $this->modify(function (array $data) use ($ip, $username) {
            foreach (self::keys($ip, $username) as $key) {
                $data[$key]   = self::recent($data[$key] ?? []);
                $data[$key][] = time();
            }
            return $data;
        });
    }

    public function clear(string $ip, string $username): void
    {
        $this->modify(function (array $data) use ($ip, $username) {
            foreach (self::keys($ip, $username) as $key) {
                unset($data[$key]);
            }
            return $data;
        });
    }

    private static function keys(string $ip, string $username): array
    {
        $keys = ['net:' . self::networkKey($ip)];
        if ($username !== '') {
            $keys[] = 'user:' . Users::normalizeUsername($username);
        }
        return $keys;
    }

    private static function recent(array $times): array
    {
        return array_values(array_filter($times, fn ($t) => $t > time() - self::WINDOW));
    }

    /** Aplica $fn, descarta lo caducado y, si hay demasiadas claves, conserva las más recientes. */
    private function modify(callable $fn): void
    {
        JsonFile::ensureDir(dirname($this->file));
        JsonFile::modify($this->file, function ($data) use ($fn) {
            $data = $fn(is_array($data) ? $data : []);
            foreach ($data as $key => $times) {
                $data[$key] = self::recent((array) $times);
                if ($data[$key] === []) {
                    unset($data[$key]);
                }
            }
            if (count($data) > self::MAX_KEYS) {
                uasort($data, fn ($a, $b) => max($b) <=> max($a));
                $data = array_slice($data, 0, self::MAX_KEYS, true);
            }
            return $data === [] ? new \stdClass() : $data;
        });
    }
}
