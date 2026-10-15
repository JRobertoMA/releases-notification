<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDOException;

/**
 * Usuarios de la interfaz web (tablas `users` y `user_tokens`; antes data/users.json).
 *
 * Los usuarios solo se crean por consola (cli/user.php) o desde la página Usuarios ya con sesión iniciada.
 * Los tokens «Recordarme» se guardan como selector + sha256(validator): la cookie no se puede reconstruir
 * aunque alguien lea la base de datos.
 */
final class Users
{
    public const TOKEN_TTL = 30 * 86400;
    private const MIN_PASSWORD = 10;

    /** @return list<array> */
    public function all(): array
    {
        return Database::all('SELECT * FROM users ORDER BY username');
    }

    public function count(): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM users');
    }

    public function find(string $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByUsername(string $username): ?array
    {
        return Database::one('SELECT * FROM users WHERE username = ?', [self::normalizeUsername($username)]);
    }

    /** Datos seguros para mostrar: sin hash. */
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
     * @param bool $onlyIfEmpty  falla si ya existe algún usuario
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
            'created_at'    => Database::now(),
            'last_login_at' => null,
        ];
        Database::withLock('users', function () use ($user, $onlyIfEmpty) {
            if ($onlyIfEmpty && $this->count() > 0) {
                throw new InvalidArgumentException('Ya existe un usuario.');
            }
            try {
                self::insertRow($user);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new InvalidArgumentException("El usuario «{$user['username']}» ya existe.");
                }
                throw $e;
            }
        });
        return $user;
    }

    /** Inserta una fila de usuario tal cual (también la usa la importación desde JSON). */
    public static function insertRow(array $user): void
    {
        Database::run(
            'INSERT INTO users (id, username, password_hash, created_at, last_login_at) VALUES (?, ?, ?, ?, ?)',
            [$user['id'], $user['username'], $user['password_hash'], $user['created_at'], $user['last_login_at'] ?? null]
        );
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
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        return $user;
    }

    /** Cambia la contraseña y revoca todos sus tokens «Recordarme». */
    public function setPassword(string $id, string $password): void
    {
        if (($e = self::passwordError($password)) !== null) {
            throw new InvalidArgumentException($e);
        }
        Database::transaction(function () use ($id, $password) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            Database::run('DELETE FROM user_tokens WHERE user_id = ?', [$id]);
        });
    }

    public function delete(string $id): void
    {
        Database::withLock('users', function () use ($id) {
            if ($this->count() <= 1) {
                throw new InvalidArgumentException('No se puede eliminar el último usuario.');
            }
            Database::run('DELETE FROM users WHERE id = ?', [$id]);   // sus tokens caen en cascada
        });
    }

    public function touchLogin(string $id): void
    {
        Database::run('UPDATE users SET last_login_at = ? WHERE id = ?', [Database::now(), $id]);
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
        Database::run('DELETE FROM user_tokens WHERE expires_at < ?', [time()]);
        self::insertToken($userId, $selector, hash('sha256', $validator), time() + self::TOKEN_TTL);
        return $selector . ':' . $validator;
    }

    public static function insertToken(string $userId, string $selector, string $validatorHash, int $expiresAt): void
    {
        Database::run(
            'INSERT INTO user_tokens (selector, user_id, validator_hash, expires_at) VALUES (?, ?, ?, ?)',
            [$selector, $userId, $validatorHash, $expiresAt]
        );
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

        return Database::transaction(function () use ($selector, $validator) {
            $token = Database::one('SELECT * FROM user_tokens WHERE selector = ? FOR UPDATE', [$selector]);
            if ($token === null || (int) $token['expires_at'] < time()
                || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
                return null;
            }
            $user = $this->find($token['user_id']);
            if ($user === null) {
                return null;
            }
            $newValidator = bin2hex(random_bytes(32));
            Database::run(
                'UPDATE user_tokens SET validator_hash = ?, expires_at = ? WHERE selector = ?',
                [hash('sha256', $newValidator), time() + self::TOKEN_TTL, $selector]
            );
            return ['user' => $user, 'cookie' => $selector . ':' . $newValidator];
        });
    }

    public function revokeToken(string $cookie): void
    {
        Database::run('DELETE FROM user_tokens WHERE selector = ?', [explode(':', $cookie, 2)[0]]);
    }

    public function tokenCount(string $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND expires_at >= ?', [$userId, time()]);
    }
}
