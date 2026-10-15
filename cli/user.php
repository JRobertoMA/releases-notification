<?php

declare(strict_types=1);

/**
 * Gestión de usuarios por consola. Es la única forma de crear el primer usuario: la web ya no ofrece
 * «Crear administrador», porque en internet cualquiera podría llegar antes (SECURITY_AUDIT.md §2).
 *
 *   php cli/user.php create <usuario> [--password-stdin]
 *   php cli/user.php password <usuario> [--password-stdin]
 *   php cli/user.php delete <usuario>
 *   php cli/user.php list
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

// Todo lo que cree PHP (config.json, data/, sesiones, JSON de datos) nace solo para el propietario: 600/700.
// En IONOS el checkout queda legible por «otros» y el servidor es compartido (SECURITY_AUDIT.md, v1.0.1).
umask(0077);

$base = dirname(__DIR__);
require_once $base . '/vendor/autoload.php';

use App\Users;

$config = is_file($base . '/config.json') ? (json_decode((string) file_get_contents($base . '/config.json'), true) ?? []) : [];
$tz     = (string) ($config['APP_TIMEZONE'] ?? '');
date_default_timezone_set(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'America/Mexico_City');

$args      = array_slice($argv, 1);
$fromStdin = in_array('--password-stdin', $args, true);
$args      = array_values(array_filter($args, fn ($a) => $a !== '--password-stdin'));
[$command, $username] = [$args[0] ?? '', $args[1] ?? ''];

$users = Users::fromBase($base);

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
        try {
            $user = $users->create($username, new_password($fromStdin));
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        echo "Usuario «{$user['username']}» creado. Ya puedes iniciar sesión en la web.\n";
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
            printf("%-24s alta %s · último acceso %s\n", $u['username'], $u['created_at'] ?? '—', $u['last_login_at'] ?? 'nunca');
        }
        break;

    default:
        fwrite(STDERR, "Uso: php cli/user.php create|password|delete <usuario> [--password-stdin]\n     php cli/user.php list\n");
        exit(1);
}
