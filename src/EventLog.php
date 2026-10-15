<?php

declare(strict_types=1);

namespace App;

/**
 * Log de ejecución (app_log) e historial de releases detectados (release_history).
 * Antes: app_log.json (300 entradas) y release_history.json (500). Ahora sin ese tope; el log se poda a 90 días.
 */
final class EventLog
{
    private const LOG_DAYS = 90;

    public static function log(string $level, string $message): void
    {
        Database::run(
            'INSERT INTO app_log (level, message, created_at) VALUES (?, ?, ?)',
            [$level, mb_substr($message, 0, 2000), Database::now()]
        );
    }

    public static function release(string $repoName, string $type, string $version, ?string $url): void
    {
        Database::run(
            'INSERT INTO release_history (repo_name, type, version, url, detected_at) VALUES (?, ?, ?, ?, ?)',
            [$repoName, $type, $version, $url, Database::now()]
        );
    }

    public static function prune(): void
    {
        Database::run('DELETE FROM app_log WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime('-' . self::LOG_DAYS . ' days'))]);
    }

    /** Más recientes primero, con el formato de los antiguos JSON (timestamp, level, message). */
    public static function recentLogs(int $limit = 1000): array
    {
        return Database::all(
            'SELECT created_at AS timestamp, level, message FROM app_log ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
    }

    /** Más recientes primero (timestamp, repo, type, version, url). Con $repo, todo el historial de ese repo. */
    public static function recentReleases(int $limit = 2000, ?string $repo = null): array
    {
        $cols = 'detected_at AS timestamp, repo_name AS repo, type, version, url';
        return $repo === null
            ? Database::all("SELECT {$cols} FROM release_history ORDER BY id DESC LIMIT " . max(1, $limit))
            : Database::all("SELECT {$cols} FROM release_history WHERE repo_name = ? ORDER BY id DESC", [$repo]);
    }

    /** @return array<string,int> 'YYYY-MM' => releases, en orden cronológico */
    public static function monthlyCounts(): array
    {
        $out = [];
        foreach (Database::all("SELECT DATE_FORMAT(detected_at, '%Y-%m') AS month, COUNT(*) AS n FROM release_history GROUP BY month ORDER BY month") as $r) {
            $out[$r['month']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<string,int> repo => releases, los $limit más activos */
    public static function topRepos(int $limit = 10): array
    {
        $out = [];
        foreach (Database::all('SELECT repo_name, COUNT(*) AS n FROM release_history GROUP BY repo_name ORDER BY n DESC, repo_name LIMIT ' . max(1, $limit)) as $r) {
            $out[$r['repo_name']] = (int) $r['n'];
        }
        return $out;
    }
}
