/* inforemp-web-kit — JS compartido: tema + logout. */
(function () {
    // Tema persistente
    var saved = localStorage.getItem('iwk-theme') || document.documentElement.getAttribute('data-bs-theme') || 'dark';
    document.documentElement.setAttribute('data-bs-theme', saved);
    var sw = document.getElementById('themeSwitch');
    if (sw) {
        sw.checked = (saved === 'dark');
        sw.addEventListener('change', function (e) {
            var t = e.target.checked ? 'dark' : 'light';
            document.documentElement.setAttribute('data-bs-theme', t);
            localStorage.setItem('iwk-theme', t);
        });
    }
    // Logout
    var base = (window.IWK_BASE || '');
    var btn = document.getElementById('btnLogout');
    if (btn) {
        btn.addEventListener('click', async function () {
            var fd = new FormData();
            fd.append('action', 'logout');
            try { await fetch(base + '/api/auth.php', { method: 'POST', body: fd }); } catch (e) {}
            window.location.href = base + '/app/login.php';
        });
    }
    // Toggle de modo doble-libro: clic en el badge (solo si es clickeable: S/A) → /api/auth.php
    var mb = document.getElementById('iwkModoBadge');
    if (mb && mb.getAttribute('role') === 'button') {
        mb.addEventListener('click', async function () {
            var next = (mb.dataset.modo === 'capacitacion') ? 'operador' : 'capacitacion';
            var fd = new FormData();
            fd.append('action', 'set_modo');
            fd.append('modo', next);
            try { await fetch(base + '/api/auth.php', { method: 'POST', body: fd }); } catch (e) {}
            window.location.reload();
        });
    }
})();

/* ---------------------------------------------------------------------------
 * IWK.keynav — navegación de teclado estilo Access (para los usuarios que
 * vienen del legacy de escritorio). Se activa en cualquier form con [data-keynav].
 *
 *   - Enter avanza al campo siguiente (como Enter=Tab en Access).
 *   - Shift+Enter retrocede al campo anterior.
 *   - En <textarea> Enter mantiene su comportamiento (salto de línea), igual
 *     que los campos memo del legacy (EnterKeyBehavior=NotDefault).
 *   - Al enfocar un input de texto/número se selecciona todo (escribir reemplaza).
 *   - Tras el último campo, si el form define data-keynav-submit="#btnX", el
 *     foco salta a ese botón (un Enter más lo dispara) — sin guardar de sorpresa.
 *
 * Marcar el form:  <div class="fc-form" data-keynav data-keynav-submit="#btnGuardar">
 * ------------------------------------------------------------------------- */
(function () {
    var SEL = 'input, select, textarea';

    function focusable(el) {
        if (!el) return false;
        if (el.disabled || el.readOnly) return false;
        if (el.type === 'hidden') return false;
        if (el.offsetParent === null) return false; // no visible (incluye selects potenciados por combo)
        return true;
    }

    function fields(form) {
        return Array.prototype.filter.call(form.querySelectorAll(SEL), focusable);
    }

    /** Inserta un salto de línea en el caret de un textarea (Ctrl+Enter en memos). */
    function insertNewline(ta) {
        var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
        ta.value = v.slice(0, s) + '\n' + v.slice(e);
        ta.selectionStart = ta.selectionEnd = s + 1;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /** Mueve el foco al campo siguiente (dir=1) o anterior (dir=-1) desde `from`. */
    function move(form, from, dir) {
        var list = fields(form);
        var i = list.indexOf(from);
        if (i === -1) return false;
        var next = list[i + dir];
        if (next) { next.focus(); return true; }
        if (dir === 1) {
            var sel = form.getAttribute('data-keynav-submit');
            var btn = sel ? document.querySelector(sel) : null;
            if (btn && !btn.disabled && btn.offsetParent !== null) { btn.focus(); return true; }
        }
        return false;
    }

    function selectIfText(el) {
        var t = (el.type || '').toLowerCase();
        if (el.tagName === 'INPUT' && (t === 'text' || t === 'number' || t === 'search' || t === 'tel' || t === '')) {
            // setTimeout: algunos navegadores reposicionan el caret tras el focus de number
            setTimeout(function () { try { el.select(); } catch (e) {} }, 0);
        }
    }

    function enable(form) {
        // Seleccionar contenido al enfocar (sensación Access).
        form.addEventListener('focusin', function (e) {
            if (e.target && (e.target.tagName === 'INPUT')) selectIfText(e.target);
        });

        form.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var t = e.target;
            if (!t || t.tagName === 'BUTTON' || t.type === 'submit') return; // que el botón haga lo suyo

            // En memo (textarea): Enter avanza igual que el resto; Ctrl+Enter = salto de línea.
            if (t.tagName === 'TEXTAREA' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                insertNewline(t);
                return;
            }
            if (e.altKey || e.ctrlKey || e.metaKey) return; // no pisar atajos del navegador

            e.preventDefault();
            move(form, t, e.shiftKey ? -1 : 1);
        });
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-keynav]'), enable);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    window.IWK = window.IWK || {};
    window.IWK.keynav = { enable: enable, move: move, fields: fields };
})();

/* ---------------------------------------------------------------------------
 * IWK.combo — combo buscable estilo Access. Potencia un <select> nativo:
 * escribís y filtra la lista por subcadena (sin acentos), flechas navegan,
 * Enter elige y avanza (vía keynav). El <select> queda como fuente de verdad
 * (se mantiene .value y se disparan eventos 'change'), así el resto del código
 * del módulo no se entera del cambio.
 *
 * Se aplica solo: marcar el form con [data-keynav] potencia todos sus <select>
 * (excepto los que tengan [data-nocombo]).
 * ------------------------------------------------------------------------- */
(function () {
    function norm(s) {
        return String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    function enhance(sel) {
        if (sel.dataset.iwkCombo) return;          // ya potenciado
        if (sel.hasAttribute('data-nocombo')) return;
        sel.dataset.iwkCombo = '1';

        var wrap = document.createElement('div');
        wrap.className = 'iwk-combo';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control iwk-combo-input';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        var list = document.createElement('div');
        list.className = 'iwk-combo-list';

        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        wrap.appendChild(input);
        wrap.appendChild(list);
        sel.classList.add('iwk-combo-src');         // ocultado por CSS (no display:none, sigue medible)

        var open = false, hi = -1, matches = [];

        function curOpt() {
            var o = sel.options[sel.selectedIndex];
            return o || null;
        }
        function placeholderText() {
            // si la opción seleccionada es la vacía (value=''), usarla como placeholder
            var o = curOpt();
            return (o && o.value === '') ? o.textContent : '';
        }
        function syncInput() {
            var o = curOpt();
            input.value = (o && o.value !== '') ? o.textContent : '';
            input.placeholder = placeholderText();
            input.disabled = sel.disabled;
        }
        function options() {
            return Array.prototype.map.call(sel.options, function (o, i) {
                return { i: i, v: o.value, t: o.textContent, n: norm(o.textContent) };
            });
        }
        function render(q) {
            var qn = norm(q);
            matches = options().filter(function (o) { return !qn || o.n.indexOf(qn) !== -1; }).slice(0, 60);
            list.innerHTML = matches.map(function (o, k) {
                return '<div class="iwk-combo-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' +
                    o.t.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }) + '</div>';
            }).join('') || '<div class="iwk-combo-empty">sin coincidencias</div>';
        }
        function openList(q) {
            render(q == null ? '' : q);
            // resaltar la opción actual si no se está filtrando
            if (hi < 0) { for (var k = 0; k < matches.length; k++) if (matches[k].i === sel.selectedIndex) { hi = k; break; } }
            paint();
            list.classList.add('show'); open = true;
        }
        function closeList() { list.classList.remove('show'); open = false; hi = -1; }
        function paint() {
            var els = list.querySelectorAll('.iwk-combo-opt');
            for (var k = 0; k < els.length; k++) els[k].classList.toggle('active', k === hi);
            var act = els[hi]; if (act) act.scrollIntoView({ block: 'nearest' });
        }
        function pick(k) {
            var m = matches[k]; if (!m) return;
            if (sel.selectedIndex !== m.i) {
                sel.selectedIndex = m.i;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncInput();
            closeList();
        }

        // --- eventos del input ---
        input.addEventListener('focus', function () { input.select(); });
        input.addEventListener('input', function () { hi = -1; openList(input.value); if (matches.length) hi = 0; paint(); });
        input.addEventListener('blur', function () { setTimeout(function () { closeList(); syncInput(); }, 120); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault(); e.stopPropagation();
                if (!open) { openList(''); return; }
                hi = Math.min(hi + 1, matches.length - 1); paint();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault(); e.stopPropagation();
                if (!open) { openList(''); return; }
                hi = Math.max(hi - 1, 0); paint();
            } else if (e.key === 'Enter') {
                if (open && hi >= 0) { pick(hi); }   // elegir; el Enter burbujea a keynav y avanza
                // si está cerrado, no hago nada y keynav avanza
            } else if (e.key === 'Escape') {
                if (open) { e.stopPropagation(); closeList(); syncInput(); }
            } else if (e.key === 'Tab') {
                if (open && hi >= 0) pick(hi); else closeList();
            }
        });

        // clicks en la lista
        list.addEventListener('mousedown', function (e) {
            var opt = e.target.closest('.iwk-combo-opt');
            if (opt) { e.preventDefault(); pick(parseInt(opt.dataset.k, 10)); input.focus(); }
        });

        // --- mantener input sincronizado con cambios del <select> ---
        sel.addEventListener('change', syncInput);
        // interceptar asignaciones programáticas: el.value = x
        try {
            var desc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
            Object.defineProperty(sel, 'value', {
                configurable: true,
                get: function () { return desc.get.call(this); },
                set: function (v) { desc.set.call(this, v); syncInput(); }
            });
        } catch (e) {}
        // reemplazo de opciones (fillSel hace innerHTML=...) y cambios de disabled
        new MutationObserver(function () { if (open) render(input.value); syncInput(); })
            .observe(sel, { childList: true, attributes: true, attributeFilter: ['disabled'] });

        syncInput();
    }

    function enhanceForm(form) {
        Array.prototype.forEach.call(form.querySelectorAll('select'), enhance);
        // Potenciar también los <select> que se agreguen después (grids, hijos inline).
        if (form.dataset.iwkComboObs) return;
        form.dataset.iwkComboObs = '1';
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var added = muts[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var n = added[j];
                    if (n.nodeType !== 1) continue;
                    if (n.tagName === 'SELECT') enhance(n);
                    else if (n.querySelectorAll) Array.prototype.forEach.call(n.querySelectorAll('select'), enhance);
                }
            }
        }).observe(form, { childList: true, subtree: true });
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-keynav]'), enhanceForm);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    window.IWK = window.IWK || {};
    window.IWK.combo = { enhance: enhance, enhanceForm: enhanceForm };
})();
