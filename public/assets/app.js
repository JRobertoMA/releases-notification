/* Notificador de Releases: comportamiento común (sin dependencias salvo Bootstrap 5). */
(function () {
    'use strict';

    var App = window.App = {};
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    App.csrf = csrfMeta ? csrfMeta.content : '';

    // --- Utilidades ---------------------------------------------------------------------------

    /** <i class="bi bi-NAME"> decorativo (Bootstrap Icons), equivalente al icon() de PHP. */
    App.icon = function (name, cls) {
        return '<i class="bi bi-' + name + (cls ? ' ' + cls : '') + '" aria-hidden="true"></i>';
    };
    App.ok   = function (html) { return App.icon('check-circle-fill', 'text-success me-1') + html; };
    App.fail = function (html) { return App.icon('x-circle-fill', 'text-danger me-1') + html; };

    App.esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    /** fetch → sobre {data, error, status} de api/. Resuelve con data o rechaza con Error(error). */
    App.request = function (url, options) {
        options = options || {};
        options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
        return fetch(url, options).then(function (r) {
            if (r.status === 401) {
                // Sesión caducada: al login, volviendo después a esta página
                location.href = 'login.php?next=' + encodeURIComponent(location.pathname.split('/').pop() + location.search);
                return new Promise(function () {});
            }
            return r.json().catch(function () { return { error: 'Respuesta no válida (HTTP ' + r.status + ')' }; });
        }).then(function (body) {
            if (body.error) { throw new Error(body.error); }
            return body.data;
        });
    };

    App.post = function (url, data) {
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) { v.forEach(function (x) { body.append(k + '[]', x); }); }
            else if (v != null) { body.append(k, v); }
        });
        return App.request(url, { method: 'POST', body: body, headers: { 'X-CSRF-Token': App.csrf } });
    };

    App.toast = function (message, type) {
        var area = document.getElementById('toast-area');
        if (!area || !window.bootstrap) { return; }
        var el = document.createElement('div');
        el.className = 'toast align-items-center border-0 text-bg-' + (type || 'info');
        el.setAttribute('role', 'alert');
        el.setAttribute('aria-live', 'assertive');
        el.innerHTML = '<div class="d-flex"><div class="toast-body"></div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button></div>';
        el.querySelector('.toast-body').textContent = message;
        area.appendChild(el);
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        new bootstrap.Toast(el, { delay: 6000 }).show();
    };

    App.loading = function (text) {
        var overlay = document.getElementById('loading-overlay');
        if (!overlay) { return; }
        if (text === false) { overlay.hidden = true; return; }
        document.getElementById('loading-overlay-text').textContent = text || 'Procesando…';
        overlay.hidden = false;
    };

    /** Envía un POST de formulario normal (navega) con CSRF; arrays como name[]. */
    App.submit = function (action, params, loadingText) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = action;
        form.hidden = true;
        var add = function (name, value) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = name; i.value = value;
            form.appendChild(i);
        };
        add('csrf_token', App.csrf);
        Object.keys(params || {}).forEach(function (k) {
            if (Array.isArray(params[k])) { params[k].forEach(function (v) { add(k + '[]', v); }); }
            else { add(k, params[k]); }
        });
        document.body.appendChild(form);
        if (loadingText) { App.loading(loadingText); }
        form.submit();
    };

    App.debounce = function (fn, ms) {
        var t;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    };

    // --- Tema (PROPUESTA_UX §3.3) -------------------------------------------------------------

    var THEMES = { auto: ['circle-half', 'automático'], light: ['sun-fill', 'claro'], dark: ['moon-stars-fill', 'oscuro'] };
    var NEXT   = { auto: 'light', light: 'dark', dark: 'auto' };
    var mq     = window.matchMedia('(prefers-color-scheme: dark)');

    function applyTheme(pref) {
        document.documentElement.setAttribute('data-theme-pref', pref);
        document.documentElement.setAttribute('data-bs-theme', pref === 'auto' ? (mq.matches ? 'dark' : 'light') : pref);
        var btn = document.getElementById('theme-toggle');
        if (btn) {
            document.getElementById('theme-toggle-icon').className = 'bi bi-' + THEMES[pref][0];
            btn.setAttribute('aria-label', 'Tema ' + THEMES[pref][1] + '. Cambiar a ' + THEMES[NEXT[pref]][1]);
            btn.title = 'Tema: ' + THEMES[pref][1];
        }
    }

    mq.addEventListener('change', function () {
        if (document.documentElement.getAttribute('data-theme-pref') === 'auto') { applyTheme('auto'); }
    });

    // --- Fechas relativas (PROPUESTA_UX §2.3) -------------------------------------------------

    var rtf   = window.Intl && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat('es', { numeric: 'auto' }) : null;
    var UNITS = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];

    App.relative = function (date, dateOnly) {
        if (!rtf || isNaN(date)) { return null; }
        var diff = (date.getTime() - Date.now()) / 1000;
        if (dateOnly) {
            var today = new Date(); today.setHours(0, 0, 0, 0);
            var d = new Date(date); d.setHours(0, 0, 0, 0);
            var days = Math.round((d - today) / 86400000);
            if (Math.abs(days) < 7) { return rtf.format(days, 'day'); }
        }
        if (Math.abs(diff) < 45) { return 'ahora mismo'; }
        for (var i = 0; i < UNITS.length; i++) {
            if (Math.abs(diff) >= UNITS[i][1] || UNITS[i][0] === 'minute') {
                return rtf.format(Math.round(diff / UNITS[i][1]), UNITS[i][0]);
            }
        }
        return null;
    };

    App.refreshTimes = function (root) {
        (root || document).querySelectorAll('time[data-rel]').forEach(function (el) {
            var text = App.relative(new Date(el.getAttribute('datetime')), el.hasAttribute('data-rel-date'));
            if (text) { el.textContent = text; }
        });
    };

    // --- Detección de tipo a partir de una URL (mismas reglas que src/RepoInput.php) ----------

    App.detectRepo = function (input) {
        var raw = String(input || '').trim();
        var s = raw.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '').replace(/^www\./i, '').replace(/[?#].*$/, '').replace(/\/+$/, '');
        var m;
        if ((m = s.match(/^github\.com\/([\w.-]+)\/([\w.-]+)/i))) {
            return { type: 'github', name: m[1] + '/' + m[2].replace(/\.git$/, '') };
        }
        if ((m = s.match(/^gitlab\.com\/(.+)$/i))) {
            var p = m[1].replace(/\/-\/.*$/, '').replace(/\.git$/, '');
            return p.indexOf('/') > 0 ? { type: 'gitlab', name: p } : null;
        }
        if ((m = s.match(/^hub\.docker\.com\/_\/([a-z0-9._-]+)/i))) {
            return { type: 'docker', name: '_/' + m[1].toLowerCase() };
        }
        if ((m = s.match(/^hub\.docker\.com\/(?:r|repository\/docker)\/([a-z0-9._-]+)\/([a-z0-9._-]+)/i))) {
            return { type: 'docker', name: (m[1] + '/' + m[2]).toLowerCase() };
        }
        if ((m = s.match(/^(?:registry-1\.)?docker\.io\/(.+)$/i))) {
            return { type: 'docker', name: m[1].replace(/^library\//, '_/').replace(/[:@].*$/, '').toLowerCase() };
        }
        if (/^(github\.com|gitlab\.com|hub\.docker\.com)(\/|$)/i.test(s)) { return null; }
        if (/^[a-z0-9-]+(\.[a-z0-9-]+)+(:\d+)?\//i.test(s)) {
            return { type: 'oci', name: s.toLowerCase().replace(/@sha256:[0-9a-f]+$/, '').replace(/(\/[^\/:]+):[^\/]+$/, '$1') };
        }
        return null;
    };

    // --- Widget de patrón de tag (PROPUESTA_UX §1.3 y §1.4) -----------------------------------
    //
    // source = { type: fn → 'docker'|'oci'|…, name: fn → string }. La vista previa la calcula
    // api/tags.php con las mismas clases que la verificación real (DockerHub / OciRegistry).

    App.patternWidget = function (root, source) {
        var input  = root.querySelector('input[name="tag_pattern"]');
        var status = root.querySelector('.pw-status');
        var chips  = root.querySelector('.pw-chips');
        var seq = 0, lastKey = '';

        function setPattern(value) {
            input.value = value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            load();
        }

        function load() {
            var type = source.type(), name = source.name().trim();
            if (!name || (type !== 'docker' && type !== 'oci')) {
                status.innerHTML = ''; chips.innerHTML = ''; lastKey = '';
                return;
            }
            var pattern = input.value.trim();
            var key = type + '|' + name + '|' + pattern;
            if (key === lastKey) { return; }
            lastKey = key;
            var my = ++seq;
            status.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Consultando el registro…';
            var qs = new URLSearchParams({ type: type, repo: name, pattern: pattern });
            App.request('api/tags.php?' + qs.toString()).then(function (d) {
                if (my === seq) { render(d, pattern); }
            }).catch(function (err) {
                if (my !== seq) { return; }
                lastKey = '';
                status.innerHTML = App.fail('<span class="text-danger">' + App.esc(err.message) + '</span>');
                chips.innerHTML = '';
            });
        }

        function render(d, pattern) {
            var selected = d.selected ? '<code>' + App.esc(d.selected.tag) + '</code>' : null;
            var approx   = d.approximate ? ' <span class="text-body-secondary">(estimado con la primera página de tags)</span>' : '';
            var html;
            if (pattern === '') {
                html = selected
                    ? 'Sin patrón se seguirá ' + selected + ' — ' + App.esc(d.rule) + '.' + approx
                    : App.icon('exclamation-triangle-fill', 'text-warning me-1') + '<span class="text-warning-emphasis">Sin patrón no hay ningún tag que seguir. Elige uno abajo.</span>';
            } else if (d.matches.length) {
                var sample = d.matches.slice(0, 4).map(function (t) { return '<code>' + App.esc(t) + '</code>'; }).join(' · ');
                html = App.icon('check-circle-fill', 'text-success me-1') + 'Coincide con <strong>' + d.matches.length + '</strong> de ' + d.tags.length +
                    ' tags: ' + sample + (d.matches.length > 4 ? ' …' : '') +
                    (selected ? '<br>→ Se seguirá ' + selected + ' (' + App.esc(d.rule) + ').' : '') + approx;
            } else {
                html = App.icon('x-circle-fill', 'text-danger me-1') + 'No coincide con ninguno de los ' + d.tags.length + ' tags consultados.';
                if (d.suggestion) {
                    html += ' ¿Querías <button type="button" class="btn btn-link btn-sm p-0 align-baseline font-monospace" data-suggest>' +
                        App.esc(d.suggestion.pattern) + '</button>? (' + d.suggestion.count + ' coincidencias)';
                }
            }
            status.innerHTML = html;
            var sugg = status.querySelector('[data-suggest]');
            if (sugg) { sugg.addEventListener('click', function () { setPattern(d.suggestion.pattern); }); }

            chips.innerHTML = '';
            var matched = {};
            d.matches.forEach(function (t) { matched[t] = true; });
            d.tags.forEach(function (t) {
                var group = document.createElement('div');
                group.className = 'btn-group btn-group-sm';
                group.setAttribute('role', 'group');
                var isSelected = d.selected && d.selected.tag === t.name;
                var exact = document.createElement('button');
                exact.type = 'button';
                exact.className = 'btn ' + (isSelected ? 'btn-success' : matched[t.name] ? 'btn-outline-success' : 'btn-outline-secondary');
                exact.textContent = t.name;
                exact.title = 'Seguir exactamente «' + t.name + '»' + (t.updated ? ' · actualizado ' + t.updated.slice(0, 10) : '');
                exact.addEventListener('click', function () { setPattern(t.name); });
                group.appendChild(exact);
                var asPattern = t.name.replace(/\d+/g, '#');
                if (asPattern !== t.name) {
                    var gen = document.createElement('button');
                    gen.type = 'button';
                    gen.className = 'btn btn-outline-secondary';
                    gen.innerHTML = App.icon('hash');
                    gen.title = 'Usar como patrón: ' + asPattern;
                    gen.setAttribute('aria-label', 'Usar como patrón ' + asPattern);
                    gen.addEventListener('click', function () { setPattern(asPattern); });
                    group.appendChild(gen);
                }
                chips.appendChild(group);
            });
        }

        var schedule = App.debounce(load, 600);
        input.addEventListener('input', function (e) { if (e.isTrusted) { schedule(); } });
        return { refresh: function () { lastKey = ''; load(); }, schedule: schedule };
    };

    // --- Progreso de una verificación en segundo plano (PROPUESTA_UX §2.4) --------------------

    App.watchJob = function (jobId, box) {
        var bar  = box.querySelector('.progress-bar');
        var text = box.querySelector('.job-text');
        box.hidden = false;

        function tick() {
            App.request('api/job.php?id=' + encodeURIComponent(jobId)).then(function (job) {
                if (!job) { box.hidden = true; return; }
                var pct = job.total ? Math.round(job.done / job.total * 100) : 0;
                bar.style.width = pct + '%';
                bar.parentNode.setAttribute('aria-valuenow', pct);
                if (job.status === 'done') {
                    bar.classList.remove('progress-bar-animated');
                    var msg = 'Verificación completada: ' + job.new + ' nuevo(s), ' + job.errors + ' con error.';
                    text.textContent = msg + ' Recargando…';
                    App.toast(msg, job.errors ? 'warning' : 'success');
                    setTimeout(function () { location.reload(); }, 1500);
                    return;
                }
                if (job.status === 'failed' || job.status === 'busy') {
                    bar.classList.remove('progress-bar-animated');
                    bar.classList.add('bg-danger');
                    text.textContent = job.message || 'La verificación no pudo completarse.';
                    return;
                }
                text.textContent = job.status === 'queued'
                    ? 'Verificación en cola…'
                    : 'Verificando ' + job.done + ' de ' + job.total + (job.current ? ' · ' + job.current : '') + '…';
                setTimeout(tick, 1500);
            }).catch(function () { setTimeout(tick, 4000); });
        }
        tick();
    };

    // --- Arranque -----------------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        // Tema
        applyTheme(document.documentElement.getAttribute('data-theme-pref') || 'auto');
        var themeBtn = document.getElementById('theme-toggle');
        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                var next = NEXT[document.documentElement.getAttribute('data-theme-pref') || 'auto'];
                applyTheme(next);
                App.post('api/theme.php', { theme: next }).catch(function (err) { App.toast(err.message, 'danger'); });
            });
        }

        // Fechas relativas, refrescadas cada minuto
        App.refreshTimes();
        setInterval(App.refreshTimes, 60000);

        // Mensajes flash del servidor
        document.querySelectorAll('.toast[data-autoshow]').forEach(function (el) { new bootstrap.Toast(el).show(); });

        // Modal de confirmación: rellena título, texto y parámetros desde el botón que lo abre
        var modal = document.getElementById('confirmModal');
        if (modal) {
            var form = document.getElementById('confirmForm');
            var loadingText = '';
            modal.addEventListener('show.bs.modal', function (e) {
                var btn = e.relatedTarget;
                if (!btn) { return; }
                var variant = btn.getAttribute('data-variant') || 'danger';
                document.getElementById('confirmModalTitle').textContent = btn.getAttribute('data-title') || 'Confirmar';
                document.getElementById('confirmModalBody').textContent  = btn.getAttribute('data-body') || '';
                var action = document.getElementById('confirmModalAction');
                action.className   = 'btn btn-' + variant;
                action.textContent = btn.getAttribute('data-confirm-label') || 'Confirmar';
                form.action = btn.getAttribute('data-action') || '';
                loadingText = btn.getAttribute('data-loading-text') || '';
                var holder = document.getElementById('confirmParams');
                holder.innerHTML = '';
                var params = {};
                try { params = JSON.parse(btn.getAttribute('data-params') || '{}'); } catch (err) { params = {}; }
                Object.keys(params).forEach(function (k) {
                    [].concat(params[k]).forEach(function (v) {
                        var i = document.createElement('input');
                        i.type = 'hidden';
                        i.name = Array.isArray(params[k]) ? k + '[]' : k;
                        i.value = v;
                        holder.appendChild(i);
                    });
                });
            });
            form.addEventListener('submit', function () {
                if (loadingText) { App.loading(loadingText); }
            });
        }

        // Botones que envían un POST directo (sin modal): data-post-action + data-post-params (JSON)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-post-action]');
            if (!btn) { return; }
            e.preventDefault();
            var params = {};
            try { params = JSON.parse(btn.getAttribute('data-post-params') || '{}'); } catch (err) { params = {}; }
            App.submit(btn.getAttribute('data-post-action'), params, btn.getAttribute('data-loading-text'));
        });

        // Formularios con overlay de carga
        document.querySelectorAll('form[data-loading-text]').forEach(function (f) {
            f.addEventListener('submit', function () { App.loading(f.getAttribute('data-loading-text')); });
        });

        // Al volver con el botón «atrás» desde una página con overlay, que no se quede pegado
        window.addEventListener('pageshow', function () { App.loading(false); });
    });
})();
