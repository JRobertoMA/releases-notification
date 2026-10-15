<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Usuarios de la interfaz web en data/users.json (sin base de datos, como el resto del proyecto).
 *
 * Todos los usuarios tienen los mismos permisos. El primero se crea desde login.php cuando no existe ninguno
 * (createFirst comprueba que siga vacío dentro del bloqueo). Los tokens «Recordarme» se guardan como
 * selector + sha256(validator): la cookie no sirve de nada si alguien lee el fichero.
 */
final class Users
{
    public const TOKEN_TTL = 30 * 86400;
    private const MIN_PASSWORD = 10;

    public function __construct(private readonly string $file)
    {
    }

    public static function fromBase(string $baseDir): self
    {
        return new self($baseDir . '/data/users.json');
    }

    /** @return list<array> */
    public function all(): array
    {
        $users = JsonFile::read($this->file, []);
        return is_array($users) ? array_values($users) : [];
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $u) {
            if (($u['id'] ?? null) === $id) {
                return $u;
            }
        }
        return null;
    }

    public function findByUsername(string $username): ?array
    {
        $username = self::normalizeUsername($username);
        foreach ($this->all() as $u) {
            if (($u['username'] ?? null) === $username) {
                return $u;
            }
        }
        return null;
    }

    /** Datos seguros para mostrar: sin hash ni tokens. */
    public static function publicData(array $user): array
    {
        return [
            'id'            => $user['id'],
            'username'      => $user['username'],
            'created_at'    => $user['created_at'] ?? null,
            'last_login_at' => $user['last_login_at'] ?? null,
        ];
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    /** Mensaje de error, o null si es válido. */
    public static function usernameError(string $username): ?string
    {
        return preg_match('/^[a-z0-9._-]{3,32}$/', self::normalizeUsername($username))
            ? null
            : 'El usuario debe tener de 3 a 32 caracteres: letras, números, punto, guion o guion bajo.';
    }

    public static function passwordError(string $password): ?string
    {
        return mb_strlen($password) >= self::MIN_PASSWORD
            ? null
            : 'La contraseña debe tener al menos ' . self::MIN_PASSWORD . ' caracteres.';
    }

    /**
     * @param bool $onlyIfEmpty  alta inicial: falla si ya existe algún usuario
     * @throws InvalidArgumentException con un mensaje apto para mostrar
     */
    public function create(string $username, string $password, bool $onlyIfEmpty = false): array
    {
        $username = self::normalizeUsername($username);
        if (($e = self::usernameError($username)) !== null || ($e = self::passwordError($password)) !== null) {
            throw new InvalidArgumentException($e);
        }
        $user = [
            'id'            => bin2hex(random_bytes(4)),
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at'    => date('Y-m-d H:i:s'),
            'last_login_at' => null,
            'tokens'        => [],
        ];
        $this->modify(function (array $users) use ($user, $onlyIfEmpty) {
            if ($onlyIfEmpty && $users !== []) {
                throw new InvalidArgumentException('Ya existe un administrador. Inicia sesión.');
            }
            foreach ($users as $u) {
                if ($u['username'] === $user['username']) {
                    throw new InvalidArgumentException("El usuario «{$user['username']}» ya existe.");
                }
            }
            $users[] = $user;
            return $users;
        });
        return $user;
    }

    /** Usuario si la contraseña es correcta. Tarda lo mismo exista o no el usuario. */
    public function verify(string $username, string $password): ?array
    {
        $user = $this->findByUsername($username);
        $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || $user === null) {
            return null;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->update($user['id'], fn (array $u) => array_merge($u, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]));
        }
        return $user;
    }

    /** Cambia la contraseña y revoca todos sus tokens «Recordarme». */
    public function setPassword(string $id, string $password): void
    {
        if (($e = self::passwordError($password)) !== null) {
            throw new InvalidArgumentException($e);
        }
        $this->update($id, fn (array $u) => array_merge($u, [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'tokens'        => [],
        ]));
    }

    public function delete(string $id): void
    {
        $this->modify(function (array $users) use ($id) {
            if (count($users) <= 1) {
                throw new InvalidArgumentException('No se puede eliminar el último usuario.');
            }
            return array_values(array_filter($users, fn ($u) => $u['id'] !== $id));
        });
    }

    public function touchLogin(string $id): void
    {
        $this->update($id, fn (array $u) => array_merge($u, ['last_login_at' => date('Y-m-d H:i:s')]));
    }

    /** Huella de la contraseña actual: la sesión deja de valer si la contraseña cambia en otro sitio. */
    public static function passwordFingerprint(array $user): string
    {
        return substr(hash('sha256', $user['password_hash'] ?? ''), 0, 16);
    }

    // --- Tokens «Recordarme» ------------------------------------------------------------------

    /** Crea un token y devuelve el valor de la cookie: selector:validator. */
    public function issueToken(string $userId): string
    {
        $selector  = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $this->update($userId, function (array $u) use ($selector, $validator) {
            $u['tokens']   = self::liveTokens($u['tokens'] ?? []);
            $u['tokens'][] = [
                'selector'   => $selector,
                'hash'       => hash('sha256', $validator),
                'expires_at' => time() + self::TOKEN_TTL,
            ];
            return $u;
        });
        return $selector . ':' . $validator;
    }

    /**
     * Valida la cookie y rota el validator (un token robado deja de servir en cuanto el dueño lo usa).
     *
     * @return array{user:array, cookie:string}|null
     */
    public function consumeToken(string $cookie): ?array
    {
        if (!preg_match('/^([0-9a-f]{18}):([0-9a-f]{64})$/', $cookie, $m)) {
            return null;
        }
        [, $selector, $validator] = $m;
        $newValidator = bin2hex(random_bytes(32));
        $found        = null;

        $this->modify(function (array $users) use ($selector, $validator, $newValidator, &$found) {
            foreach ($users as &$u) {
                $u['tokens'] = self::liveTokens($u['tokens'] ?? []);
                foreach ($u['tokens'] as &$t) {
                    if ($t['selector'] === $selector && hash_equals($t['hash'], hash('sha256', $validator))) {
                        $t['hash']       = hash('sha256', $newValidator);
                        $t['expires_at'] = time() + self::TOKEN_TTL;
                        $found           = $u;
                    }
                }
                unset($t);
            }
            unset($u);
            return $users;
        });

        return $found === null ? null : ['user' => $found, 'cookie' => $selector . ':' . $newValidator];
    }

    public function revokeToken(string $cookie): void
    {
        $selector = explode(':', $cookie, 2)[0];
        $this->modify(function (array $users) use ($selector) {
            foreach ($users as &$u) {
                $u['tokens'] = array_values(array_filter(
                    self::liveTokens($u['tokens'] ?? []),
                    fn ($t) => $t['selector'] !== $selector
                ));
            }
            unset($u);
            return $users;
        });
    }

    private static function liveTokens(array $tokens): array
    {
        return array_values(array_filter($tokens, fn ($t) => ($t['expires_at'] ?? 0) > time()));
    }

    // --- Persistencia ---------------------------------------------------------------------------

    private function update(string $id, callable $fn): void
    {
        $this->modify(function (array $users) use ($id, $fn) {
            foreach ($users as $i => $u) {
                if ($u['id'] === $id) {
                    $users[$i] = $fn($u);
                }
            }
            return $users;
        });
    }

    /** Bajo LOCK_EX. Si $fn lanza una excepción el fichero no se toca (JsonFile trunca después de $fn). */
    private function modify(callable $fn): void
    {
        JsonFile::ensureDir(dirname($this->file));
        JsonFile::modify($this->file, fn ($users) => array_values($fn(is_array($users) ? array_values($users) : [])));
    }
}
