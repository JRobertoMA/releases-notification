<?php

declare(strict_types=1);

namespace App;

/**
 * ¿Sigue corriendo el cron? (PROPUESTA_UX §1.5)
 *
 * check_releases.php escribe data/last_run.json al terminar cada verificación completa (cron, web o HTTP).
 * Hasta que exista, se deduce de app_log.json: el último «Verificación completada» precedido de
 * «Iniciando verificación (todos los repos)».
 */
final class RunHealth
{
    public static function lastFullRun(string $baseDir): ?array
    {
        $last = JsonFile::read($baseDir . '/data/last_run.json', null);
        if (is_array($last) && !empty($last['finished_at'])) {
            return $last;
        }

        $logs = JsonFile::read($baseDir . '/app_log.json', []);
        for ($i = count($logs) - 1; $i >= 0; $i--) {
            if (strpos($logs[$i]['message'] ?? '', 'Verificación completada') !== 0) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; $j--) {
                $msg = $logs[$j]['message'] ?? '';
                if (strpos($msg, 'Iniciando verificación') === 0) {
                    if (strpos($msg, 'todos los repos') !== false) {
                        return ['finished_at' => $logs[$i]['timestamp'], 'source' => 'log'];
                    }
                    break;
                }
            }
        }
        return null;
    }

    /**
     * @return array{level:string, age:?int, threshold:int}  level: ok | late | never
     */
    public static function status(?array $last, int $intervalHours): array
    {
        $threshold = max(1, $intervalHours) * 2 * 3600;
        if ($last === null) {
            return ['level' => 'never', 'age' => null, 'threshold' => $threshold];
        }
        $age = time() - (int) strtotime($last['finished_at']);
        return ['level' => $age > $threshold ? 'late' : 'ok', 'age' => $age, 'threshold' => $threshold];
    }
}
