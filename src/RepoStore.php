<?php

declare(strict_types=1);

namespace App;

/**
 * repositories.json con bloqueo e id estable por repo.
 *
 * - Toda lectura toma LOCK_SH y toda escritura LOCK_EX sobre data/repositories.lock, así que la web, el worker
 *   y el cron ya no se pisan: cada cambio relee el fichero dentro del bloqueo antes de escribir.
 * - Cada repo tiene `id` (6 hex) que no cambia aunque se borren otros. Las acciones (editar, eliminar,
 *   sincronizar, verificar) usan ese id, nunca la posición en el array.
 * - Los repos sin id (datos anteriores a este cambio) lo reciben en la primera lectura.
 */
final class RepoStore
{
    public function __construct(
        private readonly string $file,
        private readonly string $lockFile
    ) {
    }

    public static function fromConfig(array $config, string $baseDir): self
    {
        return new self(
            $baseDir . '/' . ($config['REPOSITORIES_FILE'] ?? 'repositories.json'),
            $baseDir . '/data/repositories.lock'
        );
    }

    /** @return list<array> */
    public function all(): array
    {
        $repos = $this->withLock(LOCK_SH, fn () => $this->readRaw());
        foreach ($repos as $r) {
            if (empty($r['id'])) {
                return $this->update(fn (array &$all) => $all);   // asigna ids y los guarda
            }
        }
        return $repos;
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $r) {
            if (($r['id'] ?? null) === $id) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Lee el estado actual, deja que $fn lo modifique (recibe el array por referencia) y lo guarda si cambió.
     * Devuelve lo que devuelva $fn.
     */
    public function update(callable $fn): mixed
    {
        return $this->withLock(LOCK_EX, function () use ($fn) {
            $repos  = $this->readRaw();
            $before = json_encode($repos);
            self::assignIds($repos);
            $result = $fn($repos);
            $repos  = array_values($repos);
            if (json_encode($repos) !== $before) {
                // En el sitio, no temporal+rename: conserva el propietario del fichero (ver JsonFile).
                $created = !is_file($this->file);
                file_put_contents($this->file, json_encode($repos, JSON_PRETTY_PRINT));
                if ($created) {
                    JsonFile::claimNew($this->file);
                }
            }
            return $result;
        });
    }

    public static function indexOf(array $repos, string $id): ?int
    {
        foreach ($repos as $i => $r) {
            if (($r['id'] ?? null) === $id) {
                return $i;
            }
        }
        return null;
    }

    public static function newId(array $repos): string
    {
        $taken = array_flip(array_filter(array_column($repos, 'id')));
        do {
            $id = bin2hex(random_bytes(3));
        } while (isset($taken[$id]));
        return $id;
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{6}$/', $id) === 1;
    }

    private static function assignIds(array &$repos): void
    {
        foreach ($repos as $i => $r) {
            if (empty($r['id'])) {
                $repos[$i] = ['id' => self::newId($repos)] + $r;   // id como primera clave, más legible
            }
        }
    }

    private function readRaw(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        return is_array($data) ? array_values($data) : [];
    }

    private function withLock(int $operation, callable $fn): mixed
    {
        $h = JsonFile::openLock($this->lockFile);
        try {
            flock($h, $operation);
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}
