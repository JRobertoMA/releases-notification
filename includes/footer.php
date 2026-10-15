<?php
/**
 * Pie común: modal de confirmación (envía un POST con CSRF), overlay de carga y scripts.
 * La página cierra </body></html> después de sus propios <script>.
 *
 * Botón que abre el modal:
 *   data-bs-toggle="modal" data-bs-target="#confirmModal"
 *   data-action="delete_repo.php" data-params='{"id":"a1b2c3"}'
 *   data-title="…" data-body="…" data-variant="danger" data-confirm-label="Eliminar"
 *   data-loading-text="…"   (opcional: muestra el overlay al confirmar)
 */
$asset_version ??= static fn (string $f) => $f . '?v=' . (@filemtime(BASE_DIR . '/public/' . $f) ?: 0);
?>
</main>

<?php $app_version = trim((string) @file_get_contents(BASE_DIR . '/VERSION')); ?>
<footer class="container py-4 text-center text-body-secondary small border-top">
    <?php if ($app_version !== ''): ?><span title="Versión instalada (fichero VERSION)">v<?= h($app_version) ?></span> · <?php endif; ?>
    JRobertoMA · <a href="https://github.com/JRobertoMA" class="link-secondary">GitHub</a>
</footer>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="confirmForm" method="post">
            <?= csrf_field() ?>
            <div id="confirmParams"></div>
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="confirmModalTitle">Confirmar</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" id="confirmModalAction" class="btn btn-danger">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<div id="loading-overlay" role="status" aria-live="polite" hidden>
    <div class="spinner-border mb-3" aria-hidden="true"></div>
    <div id="loading-overlay-text">Procesando…</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= h($asset_version('assets/app.js')) ?>"></script>
