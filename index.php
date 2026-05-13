<?php
session_start();

// Load configuration
$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    header('Location: settings.php');
    exit;
}
$config = json_decode(file_get_contents($config_file), true);
$repositories_file = __DIR__ . '/' . $config['REPOSITORIES_FILE'];

function get_repositories($file) {
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    return json_decode($json, true) ?? [];
}

$repos = get_repositories($repositories_file);

$stats = ['total' => count($repos), 'ok' => 0, 'new' => 0, 'error' => 0];
$all_tags = [];
foreach ($repos as $r) {
    $cs = $r['check_status'] ?? null;
    if (isset($stats[$cs])) $stats[$cs]++;
    foreach ($r['tags'] ?? [] as $tag) {
        $all_tags[$tag] = true;
    }
}
ksort($all_tags);

$status_badge = [
    'ok'    => ['class' => 'bg-success',          'label' => 'Al día'],
    'new'   => ['class' => 'bg-primary',           'label' => 'Nuevo'],
    'error' => ['class' => 'bg-danger',            'label' => 'Error'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificador de Releases</title>
    <script>(function(){document.documentElement.setAttribute('data-bs-theme',localStorage.getItem('theme')||'light');})();</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Notificador de Releases</h1>
            <div class="d-flex gap-2">
                <a href="history.php" class="btn btn-outline-primary">Historial</a>
                <a href="logs.php" class="btn btn-outline-secondary">Logs</a>
                <a href="settings.php" class="btn btn-secondary">Configuración</a>
                <button id="btn-theme" class="btn btn-outline-secondary btn-sm" title="Cambiar tema"></button>
            </div>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                Agregar Repositorio
            </div>
            <div class="card-body">
                <form action="add_repo.php" method="POST">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="repo_name" class="form-label">Repositorio</label>
                                <input type="text" class="form-control" id="repo_name" name="repo_name" required placeholder="owner/repo">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="repo_type" class="form-label">Tipo</label>
                                <select class="form-select" id="repo_type" name="repo_type">
                                    <option value="github">GitHub</option>
                                    <option value="gitlab">GitLab</option>
                                    <option value="docker">Docker Hub</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-8">
                            <label class="form-label">Etiquetas <small class="text-muted">(opcional, separadas por coma)</small></label>
                            <input type="text" class="form-control" name="repo_tags" placeholder="media, gaming, self-hosted">
                        </div>
                    </div>

                    <div id="docker-extra" class="mt-2" style="display:none">
                        <div class="row">
                            <div class="col-md-5">
                                <label class="form-label">Patrón de tag</label>
                                <input type="text" class="form-control font-monospace" id="tag_pattern" name="tag_pattern" placeholder="#.#-apache, alpine, latest, #.#-fpm">
                                <div class="form-text"><code>#</code> actúa como comodín de versión: <code>#.#-apache</code> encuentra <code>8.5-apache</code>, <code>8.6-apache</code>, etc.</div>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="button" id="btn-load-variants" class="btn btn-outline-info w-100">Cargar variantes</button>
                            </div>
                        </div>
                        <div id="variants-container" class="mt-2" style="display:none">
                            <div id="variants-loading" class="text-muted small" style="display:none">Cargando tags…</div>
                            <div id="variants-error" class="text-danger small" style="display:none"></div>
                            <div id="variants-list" class="d-flex flex-wrap gap-1 mt-1" style="max-height:150px;overflow-y:auto"></div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Agregar</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-sm-3">
                <div class="card text-center border-0 bg-body-secondary">
                    <div class="card-body py-3">
                        <div class="fs-2 fw-bold"><?php echo $stats['total']; ?></div>
                        <div class="text-muted small">Total</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-3">
                <div class="card text-center border-0 bg-success-subtle">
                    <div class="card-body py-3">
                        <div class="fs-2 fw-bold text-success-emphasis"><?php echo $stats['ok']; ?></div>
                        <div class="text-muted small">Al día</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-3">
                <div class="card text-center border-0 bg-primary-subtle">
                    <div class="card-body py-3">
                        <div class="fs-2 fw-bold text-primary-emphasis"><?php echo $stats['new']; ?></div>
                        <div class="text-muted small">Nuevo</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-3">
                <div class="card text-center border-0 bg-danger-subtle">
                    <div class="card-body py-3">
                        <div class="fs-2 fw-bold text-danger-emphasis"><?php echo $stats['error']; ?></div>
                        <div class="text-muted small">Con error</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-12 col-md-5">
                <input type="text" id="filter-search" class="form-control"
                       placeholder="Buscar por nombre..." autocomplete="off">
            </div>
            <div class="col-4 col-md-2">
                <select id="filter-type" class="form-select">
                    <option value="">Todos los tipos</option>
                    <option value="github">GitHub</option>
                    <option value="gitlab">GitLab</option>
                    <option value="docker">Docker</option>
                </select>
            </div>
            <div class="col-4 col-md-2">
                <select id="filter-status" class="form-select">
                    <option value="">Todos los estados</option>
                    <option value="ok">Al día</option>
                    <option value="new">Nuevo</option>
                    <option value="error">Error</option>
                </select>
            </div>
            <div class="col-4 col-md-3">
                <select id="filter-tag" class="form-select">
                    <option value="">Todas las etiquetas</option>
                    <?php foreach (array_keys($all_tags) as $tag): ?>
                        <option value="<?php echo htmlspecialchars($tag); ?>"><?php echo htmlspecialchars($tag); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex align-items-center">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="filter-outdated">
                    <label class="form-check-label text-nowrap" for="filter-outdated">
                        Solo con actualización pendiente
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Repositorios Monitoreados
                    <span id="filter-count" class="text-muted fw-normal small ms-2"></span>
                </span>
                <a href="run_check.php" class="btn btn-sm btn-success"
                   onclick="return confirm('¿Ejecutar verificación manual ahora? Puede tardar unos segundos.');">
                    Verificar ahora
                </a>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Repositorio</th>
                            <th>Tipo</th>
                            <th>Último Release</th>
                            <th>Estado</th>
                            <th>Última verificación</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody id="repos-tbody">
                        <?php if (empty($repos)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-3">No hay repositorios monitoreados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($repos as $index => $repo): ?>
                                <?php
                                    $row_outdated = false;
                                    if (!empty($repo['my_version']) && !empty($repo['last_seen_release'])) {
                                        if ($repo['my_version'] !== $repo['last_seen_release']) {
                                            // Tags distintos → desactualizado
                                            $row_outdated = true;
                                        } elseif (!empty($repo['my_updated_at']) && !empty($repo['last_release_date'])) {
                                            // Mismo tag (latest, stable…) → comparar fechas
                                            // Truncar a YYYY-MM-DD: evita que "2026-04-24T15:30:00Z" > "2026-04-24"
                                            // (comparación léxica daría true aunque sean el mismo día)
                                            $row_outdated = substr($repo['last_release_date'], 0, 10) > $repo['my_updated_at'];
                                        }
                                    }
                                ?>
                                <tr
                                    data-name="<?php echo strtolower(htmlspecialchars($repo['name'])); ?>"
                                    data-type="<?php echo htmlspecialchars($repo['type'] ?? 'github'); ?>"
                                    data-status="<?php echo htmlspecialchars($repo['check_status'] ?? 'unknown'); ?>"
                                    data-tags="<?php echo htmlspecialchars(implode('|', $repo['tags'] ?? [])); ?>"
                                    data-outdated="<?php echo $row_outdated ? '1' : '0'; ?>"
                                >
                                    <td>
                                        <?php
                                            $repo_url = null;
                                            switch ($repo['type'] ?? 'github') {
                                                case 'github':
                                                    $repo_url = 'https://github.com/' . $repo['name'];
                                                    break;
                                                case 'gitlab':
                                                    $repo_url = 'https://gitlab.com/' . $repo['name'];
                                                    break;
                                                case 'docker':
                                                    $dn = $repo['name'];
                                                    $repo_url = (strpos($dn, '/') === false || substr($dn, 0, 2) === '_/')
                                                        ? 'https://hub.docker.com/_/' . ltrim(str_replace('_/', '', $dn), '/')
                                                        : 'https://hub.docker.com/r/' . $dn;
                                                    break;
                                            }
                                        ?>
                                        <?php echo htmlspecialchars($repo['name']); ?>
                                        <?php if ($repo_url): ?>
                                            <a href="<?php echo htmlspecialchars($repo_url); ?>" target="_blank" rel="noopener"
                                               class="text-muted ms-1" style="font-size:.8em" title="Abrir repositorio">↗</a>
                                        <?php endif; ?>
                                        <?php foreach ($repo['tags'] ?? [] as $tag): ?>
                                            <span class="badge bg-secondary me-1 fw-normal"><?php echo htmlspecialchars($tag); ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $repo_type = $repo['type'] ?? 'github';
                                            $badge_class = 'text-bg-secondary';
                                            switch ($repo_type) {
                                                case 'github': $badge_class = 'text-bg-secondary'; break;
                                                case 'gitlab': $badge_class = 'text-bg-warning';   break;
                                                case 'docker': $badge_class = 'text-bg-info';       break;
                                            }
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($repo_type); ?></span>
                                        <?php if (!empty($repo['tag_pattern'])): ?>
                                            <br><small class="text-muted font-monospace"><?php echo htmlspecialchars($repo['tag_pattern']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $latest    = $repo['last_seen_release'] ?? null;
                                            $rel_date  = $repo['last_release_date']  ?? null;
                                            $my_ver    = $repo['my_version']          ?? null;
                                            $my_date   = $repo['my_updated_at']       ?? null;
                                            $outdated  = $my_ver && $latest && $my_ver !== $latest;
                                        ?>
                                        <?php if ($latest): ?>
                                            <span class="font-monospace small"><?php echo htmlspecialchars($latest); ?></span>
                                            <?php if ($rel_date): ?>
                                                <br><span class="text-muted" style="font-size:.75em"><?php echo htmlspecialchars(substr($rel_date, 0, 10)); ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                        <?php if ($my_ver): ?>
                                            <br><span class="text-muted" style="font-size:.75em">
                                                Mi versión: <span class="font-monospace <?php echo $outdated ? 'text-warning' : 'text-success'; ?>"><?php echo htmlspecialchars($my_ver); ?></span>
                                                <?php if ($outdated): ?><span title="Actualización disponible">⚠</span><?php endif; ?>
                                                <?php if ($my_date): ?>(<?php echo htmlspecialchars($my_date); ?>)<?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $cs = $repo['check_status'] ?? null;
                                            if ($cs && isset($status_badge[$cs])) {
                                                $sb = $status_badge[$cs];
                                                echo '<span class="badge ' . $sb['class'] . '">' . $sb['label'] . '</span>';
                                            } else {
                                                echo '<span class="text-muted small">—</span>';
                                            }
                                        ?>
                                    </td>
                                    <td class="text-muted small">
                                        <?php echo htmlspecialchars($repo['last_checked'] ?? '—'); ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <a href="run_check.php?id=<?php echo $index; ?>"
                                               class="btn btn-outline-success btn-sm"
                                               onclick="return confirm('¿Verificar solo <?php echo htmlspecialchars(addslashes($repo['name'])); ?>?');">
                                                Verificar
                                            </a>
                                            <a href="edit_repo.php?id=<?php echo $index; ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
                                            <a href="delete_repo.php?id=<?php echo $index; ?>"
                                               class="btn btn-danger btn-sm"
                                               onclick="return confirm('¿Estás seguro de que quieres eliminar este repositorio?');">
                                                Eliminar
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="no-filter-results" style="display:none">
                                <td colspan="6" class="text-center py-3 text-muted">
                                    No hay repos que coincidan con los filtros.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

         <footer class="mt-5 mb-3 text-center text-muted">
            <p>JRobertoMA | <a href="https://github.com/JRobertoMA">Github</a></p>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        // --- Filtros de tabla ---
        var searchInput   = document.getElementById('filter-search');
        var typeSelect    = document.getElementById('filter-type');
        var statusSelect  = document.getElementById('filter-status');
        var tagSelect     = document.getElementById('filter-tag');
        var outdatedCheck = document.getElementById('filter-outdated');
        var countSpan     = document.getElementById('filter-count');
        var tbody         = document.getElementById('repos-tbody');
        var noResults     = document.getElementById('no-filter-results');

        function applyFilters() {
            var term         = searchInput.value.toLowerCase().trim();
            var type         = typeSelect.value;
            var status       = statusSelect.value;
            var tag          = tagSelect.value;
            var outdatedOnly = outdatedCheck.checked;
            var rows         = tbody.querySelectorAll('tr[data-name]');
            var visible      = 0;

            rows.forEach(function (row) {
                // Tags use '|' as separator to support tags with spaces
                var rowTags = row.dataset.tags ? row.dataset.tags.split('|') : [];
                var show = (!term         || row.dataset.name.indexOf(term) !== -1)
                        && (!type         || row.dataset.type === type)
                        && (!status       || row.dataset.status === status)
                        && (!tag          || rowTags.indexOf(tag) !== -1)
                        && (!outdatedOnly || row.dataset.outdated === '1');
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
            countSpan.textContent = (term || type || status || tag || outdatedOnly)
                ? '(' + visible + ' de ' + rows.length + ')'
                : '';
        }

        searchInput.addEventListener('input', applyFilters);
        typeSelect.addEventListener('change', applyFilters);
        statusSelect.addEventListener('change', applyFilters);
        tagSelect.addEventListener('change', applyFilters);
        outdatedCheck.addEventListener('change', applyFilters);

        // --- Mostrar/ocultar bloque Docker en el form de agregar ---
        var repoTypeSelect = document.getElementById('repo_type');
        var dockerExtra    = document.getElementById('docker-extra');

        function toggleDockerExtra() {
            if (repoTypeSelect && dockerExtra) {
                dockerExtra.style.display = repoTypeSelect.value === 'docker' ? '' : 'none';
            }
        }

        if (repoTypeSelect) {
            repoTypeSelect.addEventListener('change', toggleDockerExtra);
            toggleDockerExtra();
        }

        // --- Scraper de variantes Docker Hub ---
        var btnLoad   = document.getElementById('btn-load-variants');
        if (btnLoad) {
            var varContainer = document.getElementById('variants-container');
            var varLoading   = document.getElementById('variants-loading');
            var varError     = document.getElementById('variants-error');
            var varList      = document.getElementById('variants-list');
            var patInput     = document.getElementById('tag_pattern');
            var repoNameInp  = document.getElementById('repo_name');

            btnLoad.addEventListener('click', function () {
                var repo = repoNameInp.value.trim();
                if (!repo) { alert('Escribe el nombre del repositorio primero.'); return; }

                varContainer.style.display = '';
                varLoading.style.display   = '';
                varError.style.display     = 'none';
                varList.innerHTML          = '';
                btnLoad.disabled           = true;

                fetch('docker_tags.php?repo=' + encodeURIComponent(repo))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        varLoading.style.display = 'none';
                        btnLoad.disabled         = false;
                        if (data.error) {
                            varError.textContent   = 'Error: ' + data.error;
                            varError.style.display = '';
                            return;
                        }
                        if (!data.tags || data.tags.length === 0) {
                            varError.textContent   = 'No se encontraron tags.';
                            varError.style.display = '';
                            return;
                        }
                        data.tags.forEach(function (t) {
                            var b = document.createElement('button');
                            b.type      = 'button';
                            b.className = 'btn btn-sm btn-outline-secondary font-monospace';
                            b.textContent = t.name;
                            b.addEventListener('click', function () {
                                patInput.value = t.name;
                                varList.querySelectorAll('button').forEach(function (x) {
                                    x.className = 'btn btn-sm btn-outline-secondary font-monospace';
                                });
                                b.classList.replace('btn-outline-secondary', 'btn-secondary');
                            });
                            varList.appendChild(b);
                        });
                    })
                    .catch(function () {
                        varLoading.style.display = 'none';
                        btnLoad.disabled         = false;
                        varError.textContent     = 'Error de red al contactar Docker Hub.';
                        varError.style.display   = '';
                    });
            });
        }
    })();
    </script>
    <script>
    (function () {
        var btn  = document.getElementById('btn-theme');
        var html = document.documentElement;
        function applyIcon() { btn.textContent = html.getAttribute('data-bs-theme') === 'dark' ? '☀️' : '🌙'; }
        applyIcon();
        btn.addEventListener('click', function () {
            var next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            localStorage.setItem('theme', next);
            applyIcon();
        });
    })();
    </script>
</body>
</html>
