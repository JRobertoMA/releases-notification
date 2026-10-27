<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Eventos que reenvía el backend del bot a public/api/bot.php (PROPUESTA_BOT_TELEGRAM.md §4–§6.1). Devuelve lo que
 * debe hacer el bot (text, buttons, edit, toast, alert) o null para no hacer nada.
 *
 * - ping      autotest del bot (cli/selftest.php).
 * - link      /vincular REL-XXXXXXXX → App\TelegramLinks.
 * - callback  botones del aviso (App\ReleaseNotice): rel:sync:<id>, rel:ignore:<id>, rel:done:<id>.
 * - command   fase 4; de momento solo se contesta que no están disponibles.
 * - reaction  fase 3 (👍 = «Ya actualicé»); de momento se ignoran.
 *
 * Quién actúa: el usuario vinculado al from_id (TelegramLinks::userFor), y con el rol que tenga AHORA
 * (Access::can con 'repos.write'). Un admin que aún debe activar el 2FA no puede actuar, igual que en la web.
 * Nada de Usuarios ni Configuración se hace por el bot. Cada acción queda en app_log como «[bot] …».
 */
final class BotEvents
{
    public static function handle(array $event): ?array
    {
        return match ((string) ($event['type'] ?? '')) {
            'ping'     => ['pong' => true],
            'link'     => self::link($event),
            'callback' => self::callback($event),
            'command'  => ['text' => 'Los comandos del Notificador de Releases llegarán en una próxima versión. De momento usa los botones de los avisos.'],
            default    => null,
        };
    }

    private static function link(array $event): array
    {
        $fromId = (int) ($event['from_id'] ?? 0);
        if ($fromId <= 0) {
            return ['text' => 'No se pudo identificar tu cuenta de Telegram.'];
        }
        try {
            $user = TelegramLinks::redeem((string) ($event['code'] ?? ''), $fromId, (string) ($event['from_name'] ?? ''), $event['from_username'] ?? null);
        } catch (InvalidArgumentException $e) {
            return ['text' => '✖ ' . htmlspecialchars($e->getMessage()) . "\nGenera un código nuevo en la web: «Mi cuenta → Telegram»."];
        }
        EventLog::log('info', "[bot] {$user['username']} vinculó su cuenta de Telegram (" . self::who($event) . ').');
        $can = Access::can($user, 'repos.write');
        return ['text' => sprintf(
            "✅ Cuenta vinculada: <b>%s</b> (%s).\n%s",
            htmlspecialchars($user['username']),
            htmlspecialchars(Access::ROLES[$user['role']] ?? $user['role']),
            $can ? 'Ya puedes usar los botones «Ya actualicé» e «Ignorar» de los avisos.' : 'Tu rol es de solo lectura: los botones de los avisos no harán cambios.'
        )];
    }

    private static function callback(array $event): array
    {
        if (!preg_match('/^rel:(sync|ignore|done):([0-9a-f]{6})$/', (string) ($event['data'] ?? ''), $m)) {
            return ['toast' => 'Esta acción ya no está disponible.'];
        }
        [, $action, $id] = $m;
        if ($action === 'done') {
            return ['toast' => 'Ya quedó registrado.'];
        }

        $user = TelegramLinks::userFor((int) ($event['from_id'] ?? 0));
        if ($user === null) {
            return ['toast' => 'Vincula antes tu cuenta: en la web, «Mi cuenta → Telegram».', 'alert' => true];
        }
        if (Access::needsTwoFactorSetup($user)) {
            return ['toast' => 'Activa la verificación en dos pasos en la web antes de actuar desde Telegram.', 'alert' => true];
        }
        if (!Access::can($user, 'repos.write')) {
            return ['toast' => 'Tu rol (' . (Access::ROLES[$user['role']] ?? $user['role']) . ') no permite esta acción.', 'alert' => true];
        }

        $store = new RepoStore();
        try {
            if ($action === 'sync') {
                $r = RepoActions::sync($store, [$id]);
                $repo = $r['repos'][$id] ?? null;
                if ($repo === null) {
                    return $store->find($id) === null
                        ? ['toast' => 'El repositorio ya no existe.', 'alert' => true]
                        : ['toast' => 'No hay una versión detectada que marcar.', 'alert' => true];
                }
                EventLog::log('info', "[bot] {$user['username']} sincronizó {$repo['name']} → {$repo['my_version']}.");
                return [
                    'toast'   => "✅ {$repo['my_version']} marcada como instalada.",
                    'edit'    => true,
                    'buttons' => ReleaseNotice::doneButtons($repo, "✅ {$repo['my_version']} instalada · {$user['username']}"),
                ];
            }
            $repo = RepoActions::ignore($store, $id);
            EventLog::log('info', "[bot] {$user['username']} ignoró {$repo['name']} {$repo['ignored_release']}.");
            return [
                'toast'   => "🔕 {$repo['ignored_release']} ignorada hasta la próxima versión.",
                'edit'    => true,
                'buttons' => ReleaseNotice::doneButtons($repo, "🔕 {$repo['ignored_release']} ignorada · {$user['username']}"),
            ];
        } catch (InvalidArgumentException $e) {
            return ['toast' => $e->getMessage(), 'alert' => true];
        }
    }

    /** «@usuario» o el nombre, para el registro. */
    private static function who(array $event): string
    {
        if (!empty($event['from_username'])) {
            return '@' . $event['from_username'];
        }
        return trim((string) ($event['from_name'] ?? '')) ?: 'id ' . (int) ($event['from_id'] ?? 0);
    }
}
