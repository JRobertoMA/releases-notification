<?php

declare(strict_types=1);

namespace App;

/**
 * Verificaciones en segundo plano (tabla `check_jobs`; antes data/jobs/*.json).
 *
 * El worker (check_releases.php --job=<id>) actualiza el progreso tras cada repo y la página lo consulta por
 * api/job.php. Un job en queued/running que no se actualiza en STALE segundos se da por muerto.
 * La forma del array devuelto es la de siempre (`new` = repos con release nuevo).
 */
final class Jobs
{
    private const STALE = 600;
    private const KEEP  = 50;

    /** Campos del array del job → columna. */
    private const MAP = [
        'status' => 'status', 'source' => 'source', 'total' => 'total', 'done' => 'done', 'new' => 'new_count',
        'errors' => 'errors', 'current' => 'current', 'message' => 'message', 'started_at' => 'started_at',
        'finished_at' => 'finished_at', 'repo_ids' => 'repo_ids',
    ];

    /** @param list<string>|null $repoIds null = todos */
    public function create(string $source, ?array $repoIds, int $total): string
    {
        $id  = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $now = Database::now();
        Database::run(
            'INSERT INTO check_jobs (id, status, source, repo_ids, total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, 'queued', $source, $repoIds === null ? null : Database::encodeJson(array_values($repoIds)), $total, $now, $now]
        );
        // Conservar solo los últimos KEEP
        Database::run('DELETE FROM check_jobs WHERE id NOT IN (SELECT id FROM (SELECT id FROM check_jobs ORDER BY id DESC LIMIT ' . self::KEEP . ') AS keep)');
        return $id;
    }

    public function update(string $id, array $fields): void
    {
        $set    = ['updated_at = ?'];
        $params = [Database::now()];
        foreach ($fields as $key => $value) {
            if (!isset(self::MAP[$key])) {
                continue;
            }
            if ($key === 'repo_ids') {
                $value = $value === null ? null : Database::encodeJson($value);
            } elseif (in_array($key, ['started_at', 'finished_at'], true) && $value !== null) {
                $value = date('Y-m-d H:i:s', (int) strtotime((string) $value));
            }
            $set[]    = self::MAP[$key] . ' = ?';
            $params[] = $value;
        }
        $params[] = $id;
        Database::run('UPDATE check_jobs SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
    }

    public function get(string $id): ?array
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{4}$/', $id)) {
            return null;
        }
        $row = Database::one('SELECT * FROM check_jobs WHERE id = ?', [$id]);
        return $row === null ? null : $this->withStale(self::fromRow($row));
    }

    /** El job en curso más reciente, si hay alguno vivo. */
    public function running(): ?array
    {
        foreach (Database::all("SELECT * FROM check_jobs WHERE status IN ('queued', 'running') ORDER BY id DESC LIMIT 5") as $row) {
            $job = $this->withStale(self::fromRow($row));
            if (in_array($job['status'], ['queued', 'running'], true)) {
                return $job;
            }
        }
        return null;
    }

    private function withStale(array $job): array
    {
        if (in_array($job['status'], ['queued', 'running'], true)
            && time() - (int) strtotime((string) $job['updated_at']) > self::STALE) {
            $job['status']  = 'failed';
            $job['message'] = 'El proceso dejó de responder.';
        }
        return $job;
    }

    private static function fromRow(array $row): array
    {
        return [
            'id'          => $row['id'],
            'status'      => $row['status'],
            'source'      => $row['source'],
            'repo_ids'    => Database::decodeJson($row['repo_ids']),
            'total'       => (int) $row['total'],
            'done'        => (int) $row['done'],
            'new'         => (int) $row['new_count'],
            'errors'      => (int) $row['errors'],
            'current'     => $row['current'],
            'message'     => $row['message'],
            'created_at'  => $row['created_at'],
            'started_at'  => $row['started_at'],
            'updated_at'  => $row['updated_at'],
            'finished_at' => $row['finished_at'],
        ];
    }
}
