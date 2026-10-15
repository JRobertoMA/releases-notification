<?php

declare(strict_types=1);

namespace App;

/**
 * ¿Sigue corriendo el cron? (PROPUESTA_UX §1.5)
 *
 * check_releases.php guarda `last_run` en app_state al terminar cada verificación completa. Si aún no existe
 * (p. ej. justo tras importar), se deduce de app_log: el último «Verificación completada» precedido de
 * «Iniciando verificación (todos los repos)».
 */
final class RunHealth
{
    public static function lastFullRun(): ?array
    {
        $last = (new KeyValue('app_state'))->get('last_run');
        if (is_array($last) && !empty($last['finished_at'])) {
            return $last;
        }

        // Las verificaciones de un solo repo también escriben «Verificación completada»: hay que partir de los
        // inicios de verificaciones COMPLETAS y buscar el primer «completada» posterior (el último puede estar
        // aún en curso, por eso se miran varios).
        $starts = Database::all(
            "SELECT id FROM app_log WHERE message LIKE 'Iniciando verificación (todos los repos)%' ORDER BY id DESC LIMIT 5"
        );
        foreach ($starts as $start) {
            $done = Database::value(
                "SELECT created_at FROM app_log WHERE id > ? AND message LIKE 'Verificación completada%' ORDER BY id LIMIT 1",
                [$start['id']]
            );
            if ($done !== null) {
                return ['finished_at' => $done, 'source' => 'log'];
            }
        }
        return null;
    }

    public static function record(array $run): void
    {
        (new KeyValue('app_state'))->set('last_run', $run);
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
