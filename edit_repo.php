<?php
session_start();

$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    header('Location: settings.php');
    exit;
}
$config            = json_decode(file_get_contents($config_file), true);
$repositories_file = __DIR__ . '/' . $config['REPOSITORIES_FILE'];

$repos = file_exists($repositories_file)
    ? (json_decode(file_get_contents($repositories_file), true) ?? [])
    : [];

$id = isset($_GET['id']) ? (int)$_GET['id'] : -1;
if (!isset($repos[$id])) {
    $_SESSION['message']      = 'Repositorio no encontrado.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tags_input  = trim($_POST['repo_tags'] ?? '');
    $repo_tags   = $tags_input
        ? array_values(array_filter(array_map('trim', explode(',', $tags_input))))
        : [];
    $tag_pattern       = trim($_POST['tag_pattern'] ?? '');
    $my_version        = trim($_POST['my_version'] ?? '');
    $my_updated_at     = trim($_POST['my_updated_at'] ?? '');
    $last_seen_release = trim($_POST['last_seen_release'] ?? '');

    $repos[$id]['tags'] = $repo_tags;

    if ($repos[$id]['type'] === 'docker') {
        if ($tag_pattern !== '') {
            $repos[$id]['tag_pattern'] = $tag_pattern;
        } else {
            unset($repos[$id]['tag_pattern']);
        }
    }

    if ($my_version !== '') {
        $repos[$id]['my_version'] = $my_version;
    } else {
        unset($repos[$id]['my_version']);
    }
    if ($my_updated_at !== '') {
        $repos[$id]['my_updated_at'] = $my_updated_at;
    } else {
        unset($repos[$id]['my_updated_at']);
    }

    // last_seen_release editable — clear digest so next check refreshes it
    if (isset($_POST['last_seen_release'])) {
        $repos[$id]['last_seen_release'] = $last_seen_release !== '' ? $last_seen_release : null;
        unset($repos[$id]['last_seen_digest']);
    }

    file_put_contents($repositories_file, json_encode($repos, JSON_PRETTY_PRINT));

    $_SESSION['message']      = "Repositorio '{$repos[$id]['name']}' actualizado correctamente.";
    $_SESSION['message_type'] = 'success';
    header('Location: index.php');
    exit;
}

$repo              = $repos[$id];
$is_docker         = ($repo['type'] === 'docker');
$current_tags      = implode(', ', $repo['tags'] ?? []);
$tag_pattern       = $repo['tag_pattern'] ?? '';
$my_version        = $repo['my_version'] ?? '';
$my_updated_at     = $repo['my_updated_at'] ?? '';
$last_seen_release = $repo['last_seen_release'] ?? '';
$last_release_date = $repo['last_release_date'] ?? null;

// URL al listado de tags/releases para verificación manual
$tags_url   = null;
$tags_label = 'Ver releases';
switch ($repo['type']) {
    case 'github':
        $tags_url   = 'https://github.com/' . $repo['name'] . '/releases';
        $tags_label = 'Releases en GitHub ↗';
        break;
    case 'gitlab':
        $tags_url   = 'https://gitlab.com/' . $repo['name'] . '/-/releases';
        $tags_label = 'Releases en GitLab ↗';
        break;
    case 'docker':
        $name     = $repo['name'];
        $ns_image = preg_replace('/^_\//', 'library/', $name);
        if (strpos($ns_image, '/') === false) $ns_image = 'library/' . $ns_image;

        if (!empty($last_seen_release) && !empty($repo['last_seen_digest'])) {
            $digest_slug = str_replace('sha256:', 'sha256-', $repo['last_seen_digest']);
            $tags_url    = "https://hub.docker.com/layers/{$ns_image}/{$last_seen_release}/images/{$digest_slug}";
            $tags_label  = 'Ver capa exacta en Docker Hub ↗';
        } else {
            if (strpos($name, '/') === false || substr($name, 0, 2) === '_/') {
                $image    = ltrim(str_replace('_/', '', $name), '/');
                $tags_url = 'https://hub.docker.com/_/' . $image . '/tags';
            } else {
                $tags_url = 'https://hub.docker.com/r/' . $name . '/tags';
            }
            $tags_label = 'Tags en Docker Hub ↗';
        }
        break;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Repositorio - Notificador</title>
    <script>(function(){document.documentElement.setAttribute('data-bs-theme',localStorage.getItem('theme')||'light');})();</script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5" style="max-width:700px">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">Editar Repositorio</h1>
            <div class="d-flex gap-2">
                <button id="btn-theme" class="btn btn-outline-secondary btn-sm" title="Cambiar tema"></button>
                <a href="index.php" class="btn btn-secondary">Volver</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <strong><?php echo htmlspecialchars($repo['name']); ?></strong>
                <span class="badge bg-<?php echo $is_docker ? 'info text-dark' : ($repo['type'] === 'gitlab' ? 'warning text-dark' : 'dark'); ?> ms-2">
                    <?php echo htmlspecialchars($repo['type']); ?>
                </span>
            </div>
            <div class="card-body">
                <form action="edit_repo.php?id=<?php echo $id; ?>" method="POST">
                    <div class="mb-3">
                        <label class="form-label">Etiquetas <small class="text-muted">(separadas por coma)</small></label>
                        <input type="text" class="form-control" name="repo_tags"
                               value="<?php echo htmlspecialchars($current_tags); ?>"
                               placeholder="media, gaming, self-hosted">
                    </div>

                    <?php if ($is_docker): ?>
                    <div class="mb-3">
                        <label class="form-label">Patrón de tag</label>
                        <input type="text" class="form-control font-monospace" id="tag_pattern" name="tag_pattern"
                               value="<?php echo htmlspecialchars($tag_pattern); ?>"
                               placeholder="#.#-apache, alpine, latest, #.#-fpm">
                        <div class="form-text">
                            <code>#</code> es comodín de versión: <code>#.#-apache</code> encuentra <code>8.5-apache</code>, <code>8.6-apache</code>, <code>9.0-apache</code>, etc.<br>
                            Sin <code>#</code> busca por subcadena. <code>latest</code> rastrea cambios por digest aunque el nombre no cambie.
                        </div>
                    </div>

                    <div class="mb-3">
                        <button type="button" id="btn-load-variants" class="btn btn-outline-info btn-sm">
                            Cargar variantes disponibles en Docker Hub
                        </button>
                        <div id="variants-container" class="mt-2" style="display:none">
                            <div id="variants-loading" class="text-muted small" style="display:none">Cargando tags…</div>
                            <div id="variants-error" class="text-danger small" style="display:none"></div>
                            <div id="variants-list" class="d-flex flex-wrap gap-1 mt-1"
                                 style="max-height:160px;overflow-y:auto"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <hr>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <h6 class="text-muted mb-0">Último release conocido</h6>
                        <?php if ($tags_url): ?>
                            <a href="<?php echo htmlspecialchars($tags_url); ?>" target="_blank" rel="noopener"
                               class="btn btn-outline-secondary btn-sm"><?php echo htmlspecialchars($tags_label); ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="row">
                        <div class="col-md-5">
                            <div class="mb-3">
                                <label class="form-label">Versión registrada</label>
                                <input type="text" class="form-control font-monospace" name="last_seen_release"
                                       value="<?php echo htmlspecialchars($last_seen_release); ?>"
                                       placeholder="v1.2.3">
                                <div class="form-text">Corrige esto si la verificación automática detectó una versión incorrecta. Al guardar se borra el digest para que la próxima comprobación lo actualice.</div>
                            </div>
                        </div>
                        <?php if ($last_release_date): ?>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Fecha del release</label>
                                <p class="form-control-plaintext text-muted"><?php echo htmlspecialchars(substr($last_release_date, 0, 10)); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <hr>
                    <h6 class="text-muted">Versión instalada <small>(registro personal, no afecta la verificación)</small></h6>
                    <div class="row">
                        <div class="col-md-5">
                            <div class="mb-3">
                                <label class="form-label">Versión instalada</label>
                                <input type="text" class="form-control font-monospace" name="my_version"
                                       value="<?php echo htmlspecialchars($my_version); ?>"
                                       placeholder="v1.2.3">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Fecha de actualización</label>
                                <input type="date" class="form-control" name="my_updated_at"
                                       value="<?php echo htmlspecialchars($my_updated_at); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Guardar cambios</button>
                        <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>

        <footer class="mt-5 mb-3 text-center text-muted">
            <p>JRobertoMA | <a href="https://github.com/JRobertoMA">Github</a></p>
        </footer>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
    <?php if ($is_docker): ?>
    <script>
    (function () {
        var btn       = document.getElementById('btn-load-variants');
        var container = document.getElementById('variants-container');
        var loading   = document.getElementById('variants-loading');
        var errDiv    = document.getElementById('variants-error');
        var list      = document.getElementById('variants-list');
        var patInput  = document.getElementById('tag_pattern');
        var repoName  = <?php echo json_encode($repo['name']); ?>;

        btn.addEventListener('click', function () {
            container.style.display = '';
            loading.style.display   = '';
            errDiv.style.display    = 'none';
            list.innerHTML          = '';

            fetch('docker_tags.php?repo=' + encodeURIComponent(repoName))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    loading.style.display = 'none';
                    if (data.error) {
                        errDiv.textContent    = 'Error: ' + data.error;
                        errDiv.style.display  = '';
                        return;
                    }
                    if (!data.tags || data.tags.length === 0) {
                        errDiv.textContent   = 'No se encontraron tags.';
                        errDiv.style.display = '';
                        return;
                    }
                    data.tags.forEach(function (t) {
                        var b = document.createElement('button');
                        b.type      = 'button';
                        b.className = 'btn btn-sm btn-outline-secondary font-monospace';
                        if (patInput.value === t.name) b.classList.replace('btn-outline-secondary', 'btn-secondary');
                        b.textContent = t.name;
                        b.addEventListener('click', function () {
                            patInput.value = t.name;
                            list.querySelectorAll('button').forEach(function (x) {
                                x.className = 'btn btn-sm btn-outline-secondary font-monospace';
                            });
                            b.classList.replace('btn-outline-secondary', 'btn-secondary');
                        });
                        list.appendChild(b);
                    });
                })
                .catch(function () {
                    loading.style.display = 'none';
                    errDiv.textContent    = 'Error de red al contactar Docker Hub.';
                    errDiv.style.display  = '';
                });
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
