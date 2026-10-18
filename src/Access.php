<?php

declare(strict_types=1);

namespace App;

/**
 * Roles y permisos (v1.2.0). La decisión es siempre del servidor (require_permission() en bootstrap.php);
 * las páginas solo ocultan los controles que el usuario no puede usar.
 *
 *   admin   todo: usuarios, Configuración y repos. 2FA obligatorio.
 *   editor  alta, edición, borrado, sincronizar, etiquetar y verificar repos
 *   viewer  solo ver (repos, historial, logs, dashboard) y su propia cuenta
 */
final class Access
{
    public const ROLES = [
        'admin'  => 'Administrador',
        'editor' => 'Editor',
        'viewer' => 'Lector',
    ];

    public const ROLE_BADGE = [
        'admin'  => 'text-bg-danger',
        'editor' => 'text-bg-primary',
        'viewer' => 'text-bg-secondary',
    ];

    private const PERMISSIONS = [
        'users'       => ['admin'],
        'settings'    => ['admin'],
        'repos.write' => ['admin', 'editor'],   // alta, edición, borrado, sincronizar, etiquetar
        'repos.check' => ['admin', 'editor'],   // verificar y vista previa de tags (consultan APIs externas)
    ];

    public static function can(?array $user, string $permission): bool
    {
        return $user !== null && in_array($user['role'] ?? '', self::PERMISSIONS[$permission] ?? [], true);
    }

    public static function isValidRole(mixed $role): bool
    {
        return is_string($role) && isset(self::ROLES[$role]);
    }

    /** Un admin sin 2FA solo puede entrar en «Mi cuenta» hasta activarlo. */
    public static function needsTwoFactorSetup(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin' && empty($user['totp_enabled_at']);
    }

    public static function badge(string $role): string
    {
        return sprintf('<span class="badge %s">%s</span>', self::ROLE_BADGE[$role] ?? 'text-bg-light', htmlspecialchars(self::ROLES[$role] ?? $role));
    }
}
