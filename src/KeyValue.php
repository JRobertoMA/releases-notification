<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Tabla clave → valor JSON. Dos usos:
 *   new KeyValue('settings')   ajustes editables desde la web (chat de Telegram, intervalo, registros OCI, tema)
 *   new KeyValue('app_state')  estado interno (última verificación completa)
 */
final class KeyValue
{
    public function __construct(private readonly string $table)
    {
        if (!in_array($table, ['settings', 'app_state'], true)) {
            throw new InvalidArgumentException("Tabla no permitida: {$table}");
        }
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $raw = Database::value("SELECT value FROM {$this->table} WHERE name = ?", [$name]);
        return $raw === null ? $default : Database::decodeJson((string) $raw, $default);
    }

    public function set(string $name, mixed $value): void
    {
        Database::run(
            "INSERT INTO {$this->table} (name, value, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)",
            [$name, Database::encodeJson($value), Database::now()]
        );
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        $out = [];
        foreach (Database::all("SELECT name, value FROM {$this->table}") as $row) {
            $out[$row['name']] = Database::decodeJson($row['value']);
        }
        return $out;
    }
}
