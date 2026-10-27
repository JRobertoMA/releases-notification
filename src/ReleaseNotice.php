<?php

declare(strict_types=1);

namespace App;

/**
 * El aviso de Telegram de un release nuevo: texto HTML y teclado (PROPUESTA_BOT_TELEGRAM.md §6.1).
 *
 *   🔔 Nuevo Release Detectado · 🐋 linuxserver/jellyfin · 📦 10.11.2 · 📅 2026-10-26
 *   [✅ Ya actualicé] [🔕 Ignorar] [↗ Ver]
 *   [📋 docker pull lscr.io/linuxserver/jellyfin:10.11.2]
 *
 * Los botones solo van con el backend del bot (TelegramClient en modo «bot»): sin él nadie recibe los callbacks.
 * Entonces el enlace y el `docker pull` se quedan en el texto, como hasta v1.3.0.
 * callback_data: «rel:<acción>:<id>» (≤ 64 bytes; el id son 6 hex). Lo atiende App\BotEvents.
 */
final class ReleaseNotice
{
    private const TYPE_EMOJI = ['github' => '🐙', 'gitlab' => '🦊', 'docker' => '🐋', 'oci' => '📦'];

    /**
     * @param array $outcome ['tag', 'tag_changed', 'date', 'url'] del resultado de check_releases.php
     */
    public static function text(array $repo, array $outcome, bool $withButtons): string
    {
        $type    = $repo['type'] ?? 'github';
        $date_ts = !empty($outcome['date']) ? strtotime($outcome['date']) : false;
        // En APP_TIMEZONE, como Repo::isOutdated(): el valor de la API está en UTC
        $date    = $date_ts !== false ? "\n📅 " . date('Y-m-d', $date_ts) : '';

        $message = sprintf(
            "🔔 <b>Nuevo Release Detectado</b>\n──────────────────────\n%s <b>%s</b>\n📦 <b>%s</b>%s",
            self::TYPE_EMOJI[$type] ?? '📦',
            htmlspecialchars($repo['name']),
            htmlspecialchars($outcome['tag']),
            $date
        );
        if (!$outcome['tag_changed']) {
            $message .= "\n<i>Misma etiqueta, contenido actualizado</i>";
        }
        if ($withButtons) {
            return $message;
        }
        if (($type === 'github' || $type === 'gitlab') && !empty($outcome['url'])) {
            $message .= sprintf("\n\n<a href=\"%s\">→ Ver Release</a>", htmlspecialchars($outcome['url']));
        }
        if ($type === 'oci' && ($package_url = OciRegistry::webUrl($repo['name']))) {
            $message .= sprintf("\n\n<a href=\"%s\">→ Ver paquete</a>", htmlspecialchars($package_url));
        }
        if (in_array($type, ['docker', 'oci'], true)) {
            $message .= "\n\n<code>docker pull " . htmlspecialchars(Repo::pullName($repo) . ':' . $outcome['tag']) . '</code>';
        }
        return $message;
    }

    /** Teclado del aviso. */
    public static function buttons(array $repo, string $tag, ?string $releaseUrl = null): array
    {
        $rows = [[
            ['text' => '✅ Ya actualicé', 'data' => 'rel:sync:' . $repo['id']],
            ['text' => '🔕 Ignorar', 'data' => 'rel:ignore:' . $repo['id']],
        ]];
        if (($url = self::viewUrl($repo, $releaseUrl)) !== null) {
            $rows[0][] = ['text' => '↗ Ver', 'url' => $url];
        }
        if (in_array($repo['type'] ?? '', ['docker', 'oci'], true)) {
            $pull = 'docker pull ' . Repo::pullName($repo) . ':' . $tag;
            if (mb_strlen($pull) <= 256) {
                $rows[] = [['text' => '📋 ' . $pull, 'copy' => $pull]];
            }
        }
        return $rows;
    }

    /**
     * Teclado tras la acción: un botón que deja constancia («✅ 10.11.2 instalada · ana») y el de ver.
     * Pulsarlo de nuevo (rel:done) solo muestra un aviso. Es un botón normal, no DisabledButton (Bot API 10.3), para
     * que funcione en cualquier versión de la app.
     */
    public static function doneButtons(array $repo, string $label): array
    {
        $rows = [[['text' => $label, 'data' => 'rel:done:' . $repo['id']]]];
        if (($url = self::viewUrl($repo, null)) !== null) {
            $rows[0][] = ['text' => '↗ Ver', 'url' => $url];
        }
        return $rows;
    }

    /** Release concreto (GitHub/GitLab) si se conoce; si no, la página del repo o la imagen. */
    private static function viewUrl(array $repo, ?string $releaseUrl): ?string
    {
        $url = $releaseUrl ?: Repo::webUrl($repo);
        return $url !== null && preg_match('#^https://#', $url) ? $url : null;
    }
}
