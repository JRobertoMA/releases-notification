<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Acciones sobre repos que hacen igual la web y el bot (public/sync_version.php, public/ignore_version.php y
 * App\BotEvents). Los permisos los comprueba quien llama.
 */
final class RepoActions
{
    /**
     * «Ya actualicé»: my_version = last_seen_release, my_updated_at = hoy. Quita el «Ignorar» que hubiera.
     *
     * @param list<string> $ids
     * @return array{synced: list<string>, skipped: list<string>, repos: array<string,array>}
     *         synced «nombre → versión», skipped los que no tienen versión detectada, repos los sincronizados por id
     */
    public static function sync(RepoStore $store, array $ids): array
    {
        return $store->update(function (array &$repos) use ($ids) {
            $out = ['synced' => [], 'skipped' => [], 'repos' => []];
            foreach ($repos as &$repo) {
                if (!in_array($repo['id'], $ids, true)) {
                    continue;
                }
                if (empty($repo['last_seen_release'])) {
                    $out['skipped'][] = $repo['name'];
                    continue;
                }
                $repo['my_version']    = $repo['last_seen_release'];
                $repo['my_updated_at'] = date('Y-m-d');
                unset($repo['ignored_release']);
                $out['synced'][] = $repo['name'] . ' → ' . $repo['last_seen_release'];
                $out['repos'][$repo['id']] = $repo;
            }
            unset($repo);
            return $out;
        });
    }

    /**
     * «Ignorar»: la versión detectada deja de contar como pendiente hasta que salga otra. $ignore = false lo deshace.
     *
     * @return array el repo tal como queda
     * @throws InvalidArgumentException si el repo ya no existe o no tiene versión detectada
     */
    public static function ignore(RepoStore $store, string $id, bool $ignore = true): array
    {
        return $store->update(function (array &$repos) use ($id, $ignore) {
            $i = RepoStore::indexOf($repos, $id);
            if ($i === null) {
                throw new InvalidArgumentException('El repositorio ya no existe.');
            }
            if ($ignore) {
                if (empty($repos[$i]['last_seen_release'])) {
                    throw new InvalidArgumentException('«' . $repos[$i]['name'] . '» no tiene una versión detectada que ignorar.');
                }
                $repos[$i]['ignored_release'] = $repos[$i]['last_seen_release'];
            } else {
                unset($repos[$i]['ignored_release']);
            }
            return $repos[$i];
        });
    }
}
