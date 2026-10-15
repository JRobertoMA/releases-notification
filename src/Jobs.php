<?php

declare(strict_types=1);

namespace App;

/**
 * Verificaciones en segundo plano: un fichero JSON por job en data/jobs/.
 *
 * El worker (check_releases.php --job=<id>) actualiza el progreso tras cada repo y la página lo consulta por
 * api/job.php. Un job en queued/running que no se actualiza en STALE segundos se da por muerto.
 */
final class Jobs
{
    private const STALE = 600;
    private const KEEP  = 20;

    public function __construct(private readonly string $dir)
    {
    }

    public static function fromBase(string $baseDir): self
    {
        return new self($baseDir . '/data/jobs');
    }

    /** @param list<string>|null $repoIds null = todos */
    public function create(string $source, ?array $repoIds, int $total): string
    {
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        JsonFile::writeAtomic($this->path($id), [
            'id'          => $id,
            'status'      => 'queued',
            'source'      => $source,
            'repo_ids'    => $repoIds,
            'total'       => $total,
            'done'        => 0,
            'new'         => 0,
            'errors'      => 0,
            'current'     => null,
            'message'     => null,
            'created_at'  => date('c'),
            'updated_at'  => date('c'),
            'finished_at' => null,
        ]);
        $this->cleanup();
        return $id;
    }

    public function update(string $id, array $fields): void
    {
        $job = JsonFile::read($this->path($id), null);
        if (!is_array($job)) {
            return;
        }
        JsonFile::writeAtomic($this->path($id), array_merge($job, $fields, ['updated_at' => date('c')]));
    }

    public function get(string $id): ?array
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{4}$/', $id)) {
            return null;
        }
        $job = JsonFile::read($this->path($id), null);
        return is_array($job) ? $this->withStale($job) : null;
    }

    /** El job en curso más reciente, si hay alguno vivo. */
    public function running(): ?array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        rsort($files);
        foreach (array_slice($files, 0, 5) as $file) {
            $job = JsonFile::read($file, null);
            if (is_array($job)) {
                $job = $this->withStale($job);
                if (in_array($job['status'], ['queued', 'running'], true)) {
                    return $job;
                }
            }
        }
        return null;
    }

    private function withStale(array $job): array
    {
        if (in_array($job['status'] ?? '', ['queued', 'running'], true)
            && time() - (int) strtotime($job['updated_at'] ?? 'now') > self::STALE) {
            $job['status']  = 'failed';
            $job['message'] = 'El proceso dejó de responder.';
        }
        return $job;
    }

    private function cleanup(): void
    {
        $files = glob($this->dir . '/*.json') ?: [];
        rsort($files);
        foreach (array_slice($files, self::KEEP) as $old) {
            @unlink($old);
        }
    }

    private function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }
}
