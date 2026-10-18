<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDOException;

/**
 * Usuarios de la interfaz web (tablas `users`, `user_tokens` y `user_recovery_codes`).
 *
 * Los usuarios solo se crean por consola (cli/user.php) o desde la página Usuarios (solo admin).
 * Los tokens «Recordarme» se guardan como selector + sha256(validator): la cookie no se puede reconstruir
 * aunque alguien lea la base de datos.
 *
 * Roles (v1.2.0, App\Access): nunca puede quedar el sistema sin un admin (setRole/delete lo impiden).
 * 2FA: secreto TOTP cifrado con APP_KEY (App\Crypto), último paso usado (anti-repetición) y 10 códigos de
 * recuperación de un solo uso guardados como sha256.
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

    /** Datos seguros para mostrar: sin hash ni secreto 2FA. */
    public static function publicData(array $user): array
    {
        return [
            'id'              => $user['id'],
            'username'        => $user['username'],
            'role'            => $user['role'] ?? 'viewer',
            'totp_enabled_at' => $user['totp_enabled_at'] ?? null,
            'created_at'      => $user['created_at'] ?? null,
            'last_login_at'   => $user['last_login_at'] ?? null,
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
     * El primer usuario siempre es admin, se pida el rol que se pida.
     *
     * @throws InvalidArgumentException con un mensaje apto para mostrar
     */
    public function create(string $username, string $password, string $role = 'viewer'): array
    {
        $username = self::normalizeUsername($username);
        if (($e = self::usernameError($username)) !== null || ($e = self::passwordError($password)) !== null) {
            throw new InvalidArgumentException($e);
        }
        if (!Access::isValidRole($role)) {
            throw new InvalidArgumentException('Rol no válido.');
        }
        $user = [
            'id'            => bin2hex(random_bytes(4)),
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'created_at'    => Database::now(),
            'last_login_at' => null,
        ];
        return Database::withLock('users', function () use ($user) {
            if ($this->count() === 0) {
                $user['role'] = 'admin';
            }
            try {
                self::insertRow($user);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new InvalidArgumentException("El usuario «{$user['username']}» ya existe.");
                }
                throw $e;
            }
            return $user;
        });
    }

    /** Inserta una fila de usuario tal cual (también la usa la importación desde JSON). */
    public static function insertRow(array $user): void
    {
        Database::run(
            'INSERT INTO users (id, username, password_hash, role, created_at, last_login_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$user['id'], $user['username'], $user['password_hash'], $user['role'] ?? 'viewer', $user['created_at'], $user['last_login_at'] ?? null]
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
            $this->assertKeepsAnAdmin($id);
            Database::run('DELETE FROM users WHERE id = ?', [$id]);   // tokens y códigos caen en cascada
        });
    }

    public function adminCount(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    }

    /** Cambia el rol. Nunca deja el sistema sin admin (ni siquiera quitándoselo uno mismo). */
    public function setRole(string $id, string $role): void
    {
        if (!Access::isValidRole($role)) {
            throw new InvalidArgumentException('Rol no válido.');
        }
        Database::withLock('users', function () use ($id, $role) {
            if ($role !== 'admin') {
                $this->assertKeepsAnAdmin($id);
            }
            Database::run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        });
    }

    /** Falla si $id es el único admin (llamar dentro de withLock('users')). */
    private function assertKeepsAnAdmin(string $id): void
    {
        $user = $this->find($id);
        if (($user['role'] ?? '') === 'admin' && $this->adminCount() <= 1) {
            throw new InvalidArgumentException('Es el único administrador: nombra antes a otro.');
        }
    }

    /** Tema propio (auto/light/dark), o null para usar el de Configuración. */
    public function setTheme(string $id, ?string $theme): void
    {
        Database::run('UPDATE users SET theme = ? WHERE id = ?', [in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : null, $id]);
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

    // --- Verificación en dos pasos (TOTP) ----------------------------------------------------

    public const RECOVERY_CODES = 10;

    public static function hasTotp(array $user): bool
    {
        return !empty($user['totp_enabled_at']) && !empty($user['totp_secret']);
    }

    /**
     * Activa el 2FA con un secreto ya confirmado (el usuario introdujo un código válido en $step).
     * Revoca los «Recordarme»: los emitidos antes del 2FA no lo pidieron.
     *
     * @return list<string> códigos de recuperación en claro (se muestran una sola vez)
     */
    public function enableTotp(string $id, string $secret, int $step): array
    {
        return Database::transaction(function () use ($id, $secret, $step) {
            Database::run(
                'UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = ? WHERE id = ?',
                [Crypto::encrypt($secret), Database::now(), $step, $id]
            );
            Database::run('DELETE FROM user_tokens WHERE user_id = ?', [$id]);
            return $this->regenerateRecoveryCodes($id);
        });
    }

    /** Quita el 2FA y sus códigos de recuperación (desactivar, o restablecer si se perdió el móvil). */
    public function disableTotp(string $id): void
    {
        Database::transaction(function () use ($id) {
            Database::run('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?', [$id]);
            Database::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$id]);
        });
    }

    /**
     * Comprueba un código de la app. Bloquea la fila para que dos peticiones simultáneas no acepten el mismo
     * código y guarda el paso usado (un código ya aceptado no vuelve a servir).
     */
    public function verifyTotp(string $id, string $code): bool
    {
        return Database::transaction(function () use ($id, $code) {
            $user = Database::one('SELECT totp_secret, totp_last_step FROM users WHERE id = ? FOR UPDATE', [$id]);
            $secret = $user !== null && $user['totp_secret'] !== null ? Crypto::decrypt($user['totp_secret']) : null;
            if ($secret === null) {
                return false;
            }
            $step = Totp::verify($secret, $code, $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null);
            if ($step === null) {
                return false;
            }
            Database::run('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $id]);
            return true;
        });
    }

    /** Gasta un código de recuperación (un solo uso). */
    public function useRecoveryCode(string $id, string $code): bool
    {
        $hash = self::recoveryHash($code);
        return $hash !== null && Database::run(
            'UPDATE user_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1',
            [Database::now(), $id, $hash]
        ) === 1;
    }

    public function recoveryCodesLeft(string $id): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$id]);
    }

    /** @return list<string> códigos nuevos en claro; los anteriores dejan de valer */
    public function regenerateRecoveryCodes(string $id): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';   // sin 0/o, 1/l/i
        $codes    = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        Database::transaction(function () use ($id, $codes) {
            Database::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$id]);
            foreach ($codes as $c) {
                Database::run('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)', [$id, self::recoveryHash($c)]);
            }
        });
        return $codes;
    }

    /** sha256 del código normalizado (sin guiones ni espacios, en minúsculas); null si no tiene el formato. */
    private static function recoveryHash(string $code): ?string
    {
        $code = strtolower(preg_replace('/[\s-]+/', '', $code));
        return preg_match('/^[a-z0-9]{10}$/', $code) ? hash('sha256', $code) : null;
    }
}
