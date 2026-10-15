<?php
/**
 * Campo «Patrón de tag» con vista previa en vivo. Lo usan el alta (index.php) y edit_repo.php;
 * el comportamiento está en App.patternWidget() (assets/app.js).
 *
 *   $pattern_value  valor inicial
 */
$pattern_value ??= '';
?>
<div class="pattern-widget" data-pattern-widget>
    <label for="tag_pattern" class="form-label">Patrón de tag <span class="text-body-secondary fw-normal">(opcional)</span></label>
    <input type="text" class="form-control font-monospace" id="tag_pattern" name="tag_pattern"
           value="<?= h($pattern_value) ?>" placeholder="#.#-apache · latest · *-slim"
           autocomplete="off" spellcheck="false" aria-describedby="tag_pattern_help tag_pattern_status">
    <div id="tag_pattern_help" class="form-text">
        <code>#</code> = un número · <code>*</code> = cualquier texto · sin comodines = tag exacto · vacío = el más reciente.
        <details class="mt-1">
            <summary class="link-primary" style="cursor:pointer">Ver ejemplos</summary>
            <ul class="mb-0 mt-1 ps-3">
                <li><code>#.#-apache</code> → <code>8.5-apache</code>, <code>9.0-apache</code> (no <code>8.5.3-apache</code>: usa <code>#.#.#-apache</code>). Con <code>#</code> gana la versión más alta.</li>
                <li><code>stable*</code> → <code>stable</code>, <code>stable-fpm-alpine</code> · <code>*-slim</code> → <code>lts-trixie-slim</code>.</li>
                <li><code>latest</code> (o cualquier tag exacto) avisa cuando cambia su contenido aunque el nombre no cambie (digest).</li>
                <li>En la lista de abajo: pulsa un tag para seguirlo tal cual, o <kbd>#</kbd> para convertirlo en patrón.</li>
            </ul>
        </details>
    </div>
    <div id="tag_pattern_status" class="pw-status small mt-2" aria-live="polite"></div>
    <div class="pw-chips d-flex flex-wrap gap-1 mt-2"></div>
</div>
