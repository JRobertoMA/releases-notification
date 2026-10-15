<?php

declare(strict_types=1);

namespace App;

/**
 * Repos vigilados, en la tabla `repos` (antes repositories.json).
 *
 * El contrato es el mismo que con el JSON, para que las páginas y check_releases.php no cambien:
 * - all()/find() devuelven arrays con la forma de siempre (claves ausentes = columna NULL).
 * - update(fn(array &$repos)) lee el estado ACTUAL dentro de un bloqueo (GET_LOCK 'repos') y una transacción,
 *   deja que $fn lo modifique y guarda solo las diferencias. La web, el cron y el worker se turnan, así que una
 *   edición hecha durante una verificación no se pierde.
 * - Cada repo tiene `id` (6 hex) estable: las acciones nunca usan la posición.
 */
final class RepoStore
{
    /** Columnas de datos (además de id y position). */
    private const COLUMNS = [
        'name', 'type', 'tags', 'tag_pattern', 'last_seen_release', 'last_seen_digest', 'last_release_date',
        'last_checked', 'check_status', 'my_version', 'my_updated_at',
    ];

    /** @return list<array> */
    public function all(): array
    {
        return array_map([self::class, 'fromRow'], Database::all('SELECT * FROM repos ORDER BY position, id'));
    }

    public function find(string $id): ?array
    {
        $row = Database::one('SELECT * FROM repos WHERE id = ?', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Lee el estado actual, deja que $fn lo modifique (recibe el array por referencia) y guarda lo que cambió.
     * Devuelve lo que devuelva $fn.
     */
    public function update(callable $fn): mixed
    {
        return Database::withLock('repos', fn () => Database::transaction(function () use ($fn) {
            $before = $this->all();
            $repos  = $before;
            $result = $fn($repos);
            $this->persist($before, array_values($repos));
            return $result;
        }));
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
        } while (isset($taken[$id]) || Database::value('SELECT 1 FROM repos WHERE id = ?', [$id]));
        return $id;
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{6}$/', $id) === 1;
    }

    /** Inserta un repo tal cual (importación desde JSON). */
    public static function insertRow(array $repo, int $position): void
    {
        $row = self::toRow($repo);
        Database::run(
            'INSERT INTO repos (id, position, ' . implode(', ', self::COLUMNS) . ') VALUES (?, ?, ' . rtrim(str_repeat('?, ', count(self::COLUMNS)), ', ') . ')',
            array_merge([$repo['id'], $position], array_values($row))
        );
    }

    // --- Persistencia ---------------------------------------------------------------------------

    private function persist(array $before, array $after): void
    {
        $old = [];
        foreach ($before as $pos => $r) {
            $old[$r['id']] = ['row' => self::toRow($r), 'pos' => $pos];
        }
        $seen = [];
        foreach ($after as $pos => $repo) {
            $repo['id'] ??= self::newId($after);
            $id   = $repo['id'];
            $row  = self::toRow($repo);
            $seen[$id] = true;
            if (!isset($old[$id])) {
                self::insertRow($repo, $pos);
            } elseif ($old[$id]['row'] !== $row || $old[$id]['pos'] !== $pos) {
                $set = implode(', ', array_map(fn ($c) => "{$c} = ?", self::COLUMNS));
                Database::run("UPDATE repos SET position = ?, {$set} WHERE id = ?", array_merge([$pos], array_values($row), [$id]));
            }
        }
        foreach (array_keys($old) as $id) {
            if (!isset($seen[$id])) {
                Database::run('DELETE FROM repos WHERE id = ?', [$id]);
            }
        }
    }

    /** Array del repo → valores de columna (lo que falta, NULL). */
    private static function toRow(array $repo): array
    {
        $row = [];
        foreach (self::COLUMNS as $col) {
            $v = $repo[$col] ?? null;
            if ($col === 'tags') {
                $v = Database::encodeJson(array_values(is_array($v) ? $v : []));
            } elseif ($v === '' && in_array($col, ['my_updated_at', 'last_checked'], true)) {
                $v = null;
            } elseif ($v !== null && !is_string($v)) {
                $v = (string) $v;
            }
            $row[$col] = $v;
        }
        return $row;
    }

    /** Fila → array con la forma de siempre: `id` primero y sin las claves que valen NULL. */
    private static function fromRow(array $row): array
    {
        $repo = ['id' => $row['id']];
        foreach (self::COLUMNS as $col) {
            if ($row[$col] === null) {
                continue;
            }
            $repo[$col] = $col === 'tags' ? Database::decodeJson($row[$col], []) : $row[$col];
        }
        $repo += ['tags' => [], 'last_seen_release' => null];
        return $repo;
    }
}
