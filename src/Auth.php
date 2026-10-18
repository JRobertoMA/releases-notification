<?php

declare(strict_types=1);

namespace App;

/**
 * Sesión del usuario de la interfaz web.
 *
 * - La sesión caduca tras IDLE segundos sin actividad o al cerrar el navegador (cookie de sesión).
 * - «Recordarme» añade la cookie tn_remember (30 días), validada y rotada por Users::consumeToken().
 * - En cada petición se comprueba que el usuario sigue existiendo y que su contraseña no ha cambiado
 *   (huella guardada en la sesión): borrar un usuario o cambiarle la contraseña cierra sus sesiones abiertas.
 * - Con 2FA (v1.2.0) el login tiene dos pasos: la contraseña correcta deja una sesión «pendiente»
 *   ($_SESSION['auth_pending'], PENDING_TTL segundos) que no da acceso a nada; login_2fa.php pide el código y
 *   solo entonces llama a login(). «Recordarme» se emite al completar el segundo paso.
 */
final class Auth
{
    public const IDLE        = 7200;
    public const COOKIE      = 'tn_remember';
    public const PENDING_TTL = 300;

    public function __construct(
        private readonly Users $users,
        private readonly string $cookiePath
    ) {
    }

    /** Usuario de la sesión actual (validado contra la base de datos), o null. */
    public function user(): ?array
    {
        $session = $_SESSION['auth'] ?? null;
        if (!is_array($session)) {
            return null;
        }
        if (time() - (int) ($session['last_activity'] ?? 0) > self::IDLE) {
            unset($_SESSION['auth']);
            return null;
        }
        $user = $this->users->find((string) ($session['id'] ?? ''));
        if ($user === null || Users::passwordFingerprint($user) !== ($session['fp'] ?? null)) {
            unset($_SESSION['auth']);
            return null;
        }
        $_SESSION['auth']['last_activity'] = time();
        return $user;
    }

    /** Si no hay sesión pero sí cookie «Recordarme» válida, inicia sesión con ella. */
    public function fromRememberCookie(): ?array
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($cookie) || $cookie === '') {
            return null;
        }
        $result = $this->users->consumeToken($cookie);
        if ($result === null) {
            $this->setCookie('', time() - 3600);
            return null;
        }
        $this->setCookie($result['cookie'], time() + Users::TOKEN_TTL);
        $this->startSession($result['user']);
        return $result['user'];
    }

    public function login(array $user, bool $remember): void
    {
        $this->startSession($user);
        $this->users->touchLogin($user['id']);
        if ($remember) {
            $this->setCookie($this->users->issueToken($user['id']), time() + Users::TOKEN_TTL);
        }
    }

    /** Primer paso superado (contraseña correcta, 2FA activo): falta el código. */
    public function beginSecondFactor(array $user, bool $remember, string $next): void
    {
        session_regenerate_id(true);
        unset($_SESSION['auth']);
        $_SESSION['auth_pending'] = [
            'id'       => $user['id'],
            'fp'       => Users::passwordFingerprint($user),
            'remember' => $remember,
            'next'     => $next,
            'started'  => time(),
        ];
    }

    /**
     * Usuario con el segundo paso pendiente (y aún vigente), o null.
     *
     * @return array{user:array, remember:bool, next:string}|null
     */
    public function pendingSecondFactor(): ?array
    {
        $p = $_SESSION['auth_pending'] ?? null;
        if (!is_array($p) || time() - (int) ($p['started'] ?? 0) > self::PENDING_TTL) {
            unset($_SESSION['auth_pending']);
            return null;
        }
        $user = $this->users->find((string) ($p['id'] ?? ''));
        if ($user === null || Users::passwordFingerprint($user) !== ($p['fp'] ?? null) || !Users::hasTotp($user)) {
            unset($_SESSION['auth_pending']);
            return null;
        }
        return ['user' => $user, 'remember' => (bool) $p['remember'], 'next' => (string) $p['next']];
    }

    public function cancelSecondFactor(): void
    {
        unset($_SESSION['auth_pending']);
    }

    public function logout(): void
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($cookie) && $cookie !== '') {
            $this->users->revokeToken($cookie);
        }
        $this->setCookie('', time() - 3600);
        // Se vacía y se regenera en lugar de destruir: así el mensaje flash de despedida sigue funcionando
        $_SESSION = [];
        session_regenerate_id(true);
    }

    private function startSession(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['csrf_token'], $_SESSION['auth_pending']);   // token CSRF nuevo tras iniciar sesión
        $_SESSION['auth'] = [
            'id'            => $user['id'],
            'username'      => $user['username'],
            'fp'            => Users::passwordFingerprint($user),
            'last_activity' => time(),
        ];
    }

    private function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => $this->cookiePath,
            'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
