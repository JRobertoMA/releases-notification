<?php

declare(strict_types=1);

/**
 * Gestión de usuarios por consola. Es la única forma de crear el primer usuario: la web ya no ofrece
 * «Crear administrador», porque en internet cualquiera podría llegar antes (SECURITY_AUDIT.md §2).
 *
 *   php cli/user.php create <usuario> [--role=admin|editor|viewer] [--password-stdin]
 *   php cli/user.php password <usuario> [--password-stdin]
 *   php cli/user.php role <usuario> <admin|editor|viewer>
 *   php cli/user.php 2fa-reset <usuario>      quita su 2FA (móvil perdido o APP_KEY cambiada)
 *   php cli/user.php delete <usuario>
 *   php cli/user.php list
 *
 * El primer usuario siempre es admin. Sin --role, los siguientes se crean como lector (viewer).
 * Un admin debe activar el 2FA en «Mi cuenta» en su primer acceso.
 *
 * IONOS:  /usr/bin/php8.4-cli cli/user.php create <usuario>          (nunca `php`: es PHP 4.4)
 * Docker: docker exec -it -u www-data php-apache php /var/www/html/telegram-notification/cli/user.php create <usuario>
 *
 * --password-stdin lee la contraseña de la entrada estándar (una línea), para usarlo sin terminal interactiva.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Todo lo que cree PHP (data/, sesiones, el lock del worker) nace solo para el propietario: 600/700.
// En IONOS el checkout queda legible por «otros» y el servidor es compartido (SECURITY_AUDIT.md, v1.0.1).
umask(0077);

$base = dirname(__DIR__);
require_once $base . '/vendor/autoload.php';

use App\Access;
use App\AppConfig;
use App\Database;
use App\Env;
use App\Users;

Env::load($base);
date_default_timezone_set(AppConfig::timezone());

try {
    if (!Database::isConfigured() || !Database::hasSchema()) {
        fwrite(STDERR, "Error: configura la base de datos en .env y ejecuta antes cli/migrate.php\n");
        exit(1);
    }
} catch (PDOException $e) {
    fwrite(STDERR, 'Error: no se pudo conectar a la base de datos: ' . $e->getMessage() . "\n");
    exit(1);
}

$args      = array_slice($argv, 1);
$fromStdin = in_array('--password-stdin', $args, true);
$roleOpt   = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--role=')) {
        $roleOpt = substr($a, 7);
    }
}
$args = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));
[$command, $username] = [$args[0] ?? '', $args[1] ?? ''];

$users = new Users();

function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

/** Lee la contraseña sin mostrarla (stty -echo) o, con --password-stdin, una línea de la entrada. */
function ask_password(string $prompt, bool $fromStdin): string
{
    if ($fromStdin) {
        return rtrim((string) fgets(STDIN), "\r\n");
    }
    fwrite(STDOUT, $prompt);
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
    }
    fwrite(STDOUT, "\n");
    return $value;
}

function new_password(bool $fromStdin): string
{
    $password = ask_password('Contraseña (mínimo 10 caracteres): ', $fromStdin);
    if (($e = Users::passwordError($password)) !== null) {
        fail($e);
    }
    if (!$fromStdin && $password !== ask_password('Repite la contraseña: ', false)) {
        fail('Las contraseñas no coinciden.');
    }
    return $password;
}

switch ($command) {
    case 'create':
        if ($username === '') {
            fail('Indica el usuario: php cli/user.php create <usuario>');
        }
        if (($e = Users::usernameError($username)) !== null) {
            fail($e);
        }
        if ($roleOpt !== null && !Access::isValidRole($roleOpt)) {
            fail('Rol no válido: usa admin, editor o viewer.');
        }
        try {
            $user = $users->create($username, new_password($fromStdin), $roleOpt ?? 'viewer');
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        echo "Usuario «{$user['username']}» creado como " . Access::ROLES[$user['role']] . ". Ya puede iniciar sesión en la web.\n";
        if (Access::needsTwoFactorSetup($user)) {
            echo "Al entrar se le pedirá activar la verificación en dos pasos (obligatoria para administradores).\n";
        }
        break;

    case 'role':
        $user = $users->findByUsername($username) ?? fail("No existe el usuario «{$username}».");
        $role = $args[2] ?? '';
        if (!Access::isValidRole($role)) {
            fail('Uso: php cli/user.php role <usuario> <admin|editor|viewer>');
        }
        try {
            $users->setRole($user['id'], $role);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        echo "«{$user['username']}» ahora es " . Access::ROLES[$role] . ".\n";
        break;

    case '2fa-reset':
        $user = $users->findByUsername($username) ?? fail("No existe el usuario «{$username}».");
        if (empty($user['totp_enabled_at'])) {
            echo "«{$user['username']}» no tiene la verificación en dos pasos activada.\n";
            break;
        }
        $users->disableTotp($user['id']);
        echo "Verificación en dos pasos de «{$user['username']}» restablecida. Entrará solo con la contraseña"
            . (Access::twoFactorMandatory($user) ? " y tendrá que volver a activarla (obligatoria para administradores).\n" : ".\n");
        break;

    case 'password':
        $user = $users->findByUsername($username) ?? fail("No existe el usuario «{$username}».");
        try {
            $users->setPassword($user['id'], new_password($fromStdin));
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        echo "Contraseña de «{$user['username']}» cambiada. Sus sesiones abiertas se han cerrado.\n";
        break;

    case 'delete':
        $user = $users->findByUsername($username) ?? fail("No existe el usuario «{$username}».");
        try {
            $users->delete($user['id']);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        echo "Usuario «{$user['username']}» eliminado.\n";
        break;

    case 'list':
        $all = $users->all();
        if ($all === []) {
            echo "No hay usuarios. Crea el primero con: php cli/user.php create <usuario>\n";
            break;
        }
        foreach ($all as $u) {
            printf("%-24s %-14s 2FA %-3s · alta %s · último acceso %s\n", $u['username'], Access::ROLES[$u['role']] ?? $u['role'],
                empty($u['totp_enabled_at']) ? 'no' : 'sí', $u['created_at'] ?? '—', $u['last_login_at'] ?? 'nunca');
        }
        break;

    default:
        fwrite(STDERR, "Uso: php cli/user.php create <usuario> [--role=admin|editor|viewer] [--password-stdin]\n"
            . "     php cli/user.php password <usuario> [--password-stdin]\n"
            . "     php cli/user.php role <usuario> <admin|editor|viewer>\n"
            . "     php cli/user.php 2fa-reset|delete <usuario>\n"
            . "     php cli/user.php list\n");
        exit(1);
}
