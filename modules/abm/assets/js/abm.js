/* ABM genérico — form desplegado + Buscar, máquina de estados (estilo RDN/cuentas). */
const App = {
    M: window.ABM_M, RO: window.ABM_RO,
    DEF: null, mode: 'idle', currentId: null, dt: null,

    async init() {
        const d = await this.api('defs');
        if (!d.ok) { this.toast('Error: ' + d.error, 'danger'); return; }
        this.DEF = d.data;
        this.buildForm();
        this.buildHijos();
        this.buildBuscarHead();
        this.bind();
        this.setMode('idle');
    },

    // ---------- construcción del form ----------
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },

    ctrl(c, idAttr) {
        const id = idAttr ? `id="${idAttr}"` : '';
        const numCls = (c.tipo === 'decimal') ? ' fc-num' : '';   // importes alineados a la derecha
        if (c.ro) return `<input type="text" ${id} class="form-control hb-in${numCls}" data-col="${c.col}" disabled>`;
        if (c.tipo === 'memo') { const mxm = c.size ? ` maxlength="${c.size}"` : ''; return `<textarea ${id}${mxm} class="form-control hb-in" data-col="${c.col}" rows="2"></textarea>`; }
        if (c.tipo === 'bool') return `<div class="form-check"><input type="checkbox" ${id} class="form-check-input hb-in" data-col="${c.col}" value="1"></div>`;
        if (c.tipo === 'date') return `<input type="date" ${id} class="form-control hb-in" data-col="${c.col}">`;
        if (c.tipo === 'select' && c.big) {
            // lookup grande (ej. Localidades): autocomplete server-side. Hidden = id; text = búsqueda.
            return `<div class="ac-wrap">
                <input type="hidden" ${id} class="hb-in" data-col="${c.col}">
                <input type="text" class="form-control ac-input" data-accol="${c.col}" autocomplete="off" placeholder="Buscar…">
                <div class="ac-list"></div></div>`;
        }
        if (c.tipo === 'select') {
            const opts = '<option value="">—</option>' + (c.options || []).map(o => `<option value="${this.esc(o.id)}">${this.esc(o.den)}</option>`).join('');
            return `<select ${id} class="form-select hb-in" data-col="${c.col}">${opts}</select>`;
        }
        const t = (c.tipo === 'number' || c.tipo === 'decimal') ? 'number' : 'text';
        const step = (c.tipo === 'decimal') ? ' step="0.01"' : '';
        const mx = c.size ? ` maxlength="${c.size}"` : '';
        return `<input type="${t}"${step}${mx} ${id} class="form-control hb-in${numCls}" data-col="${c.col}">`;
    },

    // Fila: label a la izquierda + campo. El contenedor las reparte en 2 columnas
    // (flujo column-major: primero toda la columna izquierda, después la derecha).
    frow(labelHtml, ctlHtml, w) {
        return `<div class="fc-frow">
            <label class="fc-flabel">${labelHtml}</label>
            <div class="fc-field fc-w-${w}">${ctlHtml}</div></div>`;
    },

    buildForm() {
        const rows = [this.frow(this.esc(this.DEF.codlabel || 'Código'), '<input type="text" id="f__cod" class="form-control" disabled>', 'narrow')];
        this.DEF.campos.forEach(c => {
            const req = c.req ? ' <span class="text-danger">*</span>' : '';
            let ctl = this.ctrl(c, 'f_' + c.col);
            if (c.suffix) ctl = `<div class="input-group">${ctl}<span class="input-group-text">${this.esc(c.suffix)}</span></div>`;
            const w = c.ancho ? c.ancho   // override por campo: 'narrow' | 'mid' | 'wide'
                : (c.tipo === 'memo') ? 'wide'
                : (c.tipo === 'date' || c.tipo === 'number' || c.tipo === 'decimal' || c.tipo === 'bool') ? 'narrow' : 'mid';
            rows.push(this.frow(this.esc(c.label) + req, ctl, w));
        });
        this.el('formFields').innerHTML = rows.join('');
        // 2 columnas sólo en forms grandes (override por def 'cols'); chicos = 1 columna (vertical)
        const twoCol = (this.DEF.cols === 2) || (this.DEF.cols !== 1 && this.DEF.campos.length >= 12);
        this.el('formFields').classList.toggle('fc-2col', twoCol);
        this.bindAC();
    },

    acInput(col) { return this.el('formFields').querySelector('.ac-input[data-accol="' + col + '"]'); },
    acHidden(inp) { const w = inp.closest('.ac-wrap'); return w ? w.querySelector('input[data-col]') : null; },   // hidden de ESTE ac-wrap (sirve para form y grilla)

    // Autocomplete 'big': delegación en un contenedor (formFields o hijosCont). Server-side.
    bindAC(container) {
        const ff = container || this.el('formFields'); let timer;
        ff.addEventListener('input', e => {
            const inp = e.target.closest('.ac-input'); if (!inp) return;
            const hid = this.acHidden(inp); if (hid) hid.value = '';   // al tipear se pierde la selección
            clearTimeout(timer); const q = inp.value; const col = inp.dataset.accol;
            timer = setTimeout(() => this.acSearch(col, q, inp), 300);
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('.ac-wrap')) document.querySelectorAll('.ac-list').forEach(l => l.classList.remove('show'));
        });
    },

    async acSearch(col, q, inp) {
        const list = inp.parentNode.querySelector('.ac-list');
        if (q.length < 2) { list.classList.remove('show'); return; }
        const r = await this.api('lookup', { col, q });
        if (!r.ok || !r.data.length) { list.innerHTML = ''; list.classList.remove('show'); return; }
        list.innerHTML = r.data.map(o =>
            `<div class="ac-item" data-id="${this.esc(o.id)}" data-den="${this.esc(o.den)}">` +
            (o.cod != null && o.cod !== '' ? `<span class="ac-code">${this.esc(o.cod)}</span>` : '') +
            `${this.esc(o.den)}</div>`).join('');
        list.querySelectorAll('.ac-item').forEach(it => it.addEventListener('click', () => {
            const hid = this.acHidden(inp); if (hid) hid.value = it.dataset.id;
            inp.value = it.dataset.den;
            list.classList.remove('show');
        }));
        list.classList.add('show');
    },

    buildHijos() {
        this.el('hijosCont').innerHTML = (this.DEF.hijos || []).map(h => {
            const cols = this.hijoCols(h);
            const head = cols.map(c => `<th>${this.esc(c.label)}</th>`).join('') + '<th style="width:2.5rem"></th>';
            return `<div class="card fc-card"><div class="card-header collapsed" data-bs-toggle="collapse" data-bs-target="#c_${h.key}">
                <span><i class="bi bi-list-ul me-1"></i>${this.esc(h.titulo)} <span class="badge bg-secondary ms-1" id="badge_${h.key}">0</span></span>
                <i class="bi bi-chevron-down collapse-icon"></i></div>
              <div id="c_${h.key}" class="collapse"><div class="card-body">
                <table class="fc-grid"><thead><tr>${head}</tr></thead><tbody id="tb_${h.key}"></tbody></table>
                <button type="button" class="btn btn-outline-primary btn-sm mt-2 hb-add" data-key="${h.key}"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
              </div></div></div>`;
        }).join('');
        this.bindAC(this.el('hijosCont'));   // autocomplete 'big' en las grillas de hijos
    },

    hijoCols(h) {
        const cols = [];
        if (h.clave.tipo === 'select') cols.push({ col: h.clave.col, label: h.clave.label, tipo: 'select', options: h.clave.options });
        h.campos.forEach(c => cols.push(c));
        return cols;
    },

    rowHtml(h, r) {
        // 'entity': llevo la PK propia (CODSUB) en data-key para distinguir existentes (update) de nuevos (insert)
        const dk = (h.clave.tipo === 'entity' && r && r.__key != null && r.__key !== '') ? ` data-key="${this.esc(r.__key)}"` : '';
        const cells = this.hijoCols(h).map(c => `<td>${this.ctrl(c)}</td>`).join('');
        return `<tr${dk}>${cells}<td><button type="button" class="btn btn-outline-danger btn-sm btn-remove-row">&times;</button></td></tr>`;
    },

    renderHijo(h, rows) {
        rows = rows || [];
        const tb = this.el('tb_' + h.key);
        tb.innerHTML = rows.map(r => this.rowHtml(h, r)).join('');
        Array.from(tb.children).forEach((tr, i) => this.fillRow(tr, rows[i], h));
        this.bindRow(tb, h);
        this.el('badge_' + h.key).textContent = rows.length;
    },

    fillRow(tr, r, h) {
        const claveCol = (h.clave.tipo === 'select') ? h.clave.col : null;
        tr.querySelectorAll('.hb-in').forEach(el => {
            const col = el.dataset.col;
            const v = (col === claveCol) ? r.__key : r[col];
            if (el.type === 'checkbox') el.checked = !!v;
            else el.value = (v === null || v === undefined) ? '' : v;
            // big: poblar el texto del autocomplete con la denominación
            if (el.type === 'hidden') { const w = el.closest('.ac-wrap'); if (w) { const ai = w.querySelector('.ac-input'); if (ai) ai.value = (r[col + '__den'] != null ? r[col + '__den'] : ''); } }
        });
    },

    bindRow(tb, h) {
        tb.querySelectorAll('.btn-remove-row').forEach(b => b.addEventListener('click', () => { b.closest('tr').remove(); this.el('badge_' + h.key).textContent = tb.children.length; }));
    },

    buildBuscarHead() {
        const cols = ['Cód'].concat(this.DEF.campos.filter(c => c.list).map(c => c.label));
        this.el('grdBuscarHead').innerHTML = cols.map(c => `<th>${this.esc(c)}</th>`).join('');
    },

    bind() {
        if (!this.RO) {
            this.el('btnNuevo').addEventListener('click', () => this.nuevo());
            this.el('btnGuardar').addEventListener('click', () => this.guardar());
            this.el('btnCancelar').addEventListener('click', () => this.cancelar());
            this.el('btnEditar').addEventListener('click', () => this.editar());
            this.el('btnEliminar').addEventListener('click', () => this.eliminar());
        }
        this.el('btnBuscar').addEventListener('click', () => new bootstrap.Modal(this.el('modalBuscar')).show());
        this.el('modalBuscar').addEventListener('shown.bs.modal', () => this.loadList());
        // delegación para botón "Agregar" de hijos
        this.el('hijosCont').addEventListener('click', e => {
            const b = e.target.closest('.hb-add'); if (!b) return;
            const h = this.DEF.hijos.find(x => x.key === b.dataset.key);
            const tb = this.el('tb_' + h.key);
            tb.insertAdjacentHTML('beforeend', this.rowHtml(h, {}));
            this.bindRow(tb, h);
            this.el('badge_' + h.key).textContent = tb.children.length;
        });
    },

    // ---------- listado (Buscar) ----------
    async loadList(q) {
        const j = await this.api('list', this.DEF.buscable ? { q: (q || '') } : {});
        if (!j.ok) return;
        const rows = j.data || [];
        const listCols = this.DEF.campos.filter(c => c.list);
        const data = rows.map(r => {
            const arr = [r[this.DEF.pk]];
            listCols.forEach(c => arr.push(c.tipo === 'bool' ? (r[c.col] ? 'Sí' : 'No') : (r[c.col] != null ? r[c.col] : '')));
            return arr;
        });
        const self = this;
        if (this.dt) { this.dt.clear().rows.add(data).draw(); }
        else {
            this.dt = $('#grdBuscar').DataTable({
                data: data, pageLength: 25, order: [[1, 'asc']],
                // en modo buscable el filtrado es server-side → desactivo el buscador cliente de DataTables
                searching: !this.DEF.buscable,
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
                createdRow: function (row, d) {
                    row.addEventListener('click', () => {
                        self.cargar(d[0]);
                        bootstrap.Modal.getInstance(self.el('modalBuscar')).hide();
                    });
                }
            });
            if (this.DEF.buscable) this.mountServerSearch();
        }
        if (!this.DEF.buscable) setTimeout(() => $('#modalBuscar .dataTables_filter input').trigger('focus'), 150);
    },

    // Buscador server-side para maestros 'buscable': un input propio arriba de la grilla que recarga del servidor.
    mountServerSearch() {
        const self = this; let t;
        const wrap = document.createElement('div');
        wrap.className = 'mb-2';
        wrap.innerHTML = '<input type="search" class="form-control form-control-sm" id="srvSearch" placeholder="Buscar (código postal, denominación, código)…" autocomplete="off">';
        const cont = this.el('grdBuscar').closest('.modal-body') || this.el('grdBuscar').parentNode;
        cont.insertBefore(wrap, cont.firstChild);
        const inp = wrap.querySelector('#srvSearch');
        inp.addEventListener('input', function () { clearTimeout(t); const v = this.value; t = setTimeout(() => self.loadList(v), 300); });
        setTimeout(() => inp.focus(), 150);
    },

    // ---------- cargar / poblar ----------
    async cargar(id) {
        const j = await this.api('get', { id });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.currentId = id;
        this.populate(j.data);
        this.setMode('view');
    },

    populate(d) {
        const cod = this.el('f__cod'); if (cod) cod.value = (d[this.DEF.pk] != null ? d[this.DEF.pk] : '');
        this.DEF.campos.forEach(c => {
            const el = this.el('f_' + c.col); if (!el) return;
            if (c.tipo === 'bool') el.checked = !!d[c.col];
            else el.value = (d[c.col] === null || d[c.col] === undefined) ? '' : d[c.col];
            if (c.tipo === 'select' && c.big) { const inp = this.acInput(c.col); if (inp) inp.value = (d[c.col + '__den'] != null ? d[c.col + '__den'] : ''); }
        });
        (this.DEF.hijos || []).forEach(h => this.renderHijo(h, (d.__hijos && d.__hijos[h.key]) || []));
    },

    clearForm() {
        const cod = this.el('f__cod'); if (cod) cod.value = '';
        this.DEF.campos.forEach(c => { const el = this.el('f_' + c.col); if (!el) return; if (c.tipo === 'bool') el.checked = false; else el.value = ''; if (c.tipo === 'select' && c.big) { const inp = this.acInput(c.col); if (inp) inp.value = ''; } });
        (this.DEF.hijos || []).forEach(h => this.renderHijo(h, []));
        this.el('formErr').textContent = '';
    },

    // ---------- acciones ----------
    nuevo() { this.currentId = null; this.clearForm(); this.setMode('create'); setTimeout(() => { const f = this.DEF.strpk ? this.el('f__cod') : this.el('f_' + this.DEF.campos[0].col); if (f) f.focus(); }, 100); },
    editar() { if (this.currentId) this.setMode('edit'); },
    cancelar() { if (this.currentId) this.cargar(this.currentId); else { this.clearForm(); this.setMode('idle'); } },

    collectHijos() {
        const out = {};
        (this.DEF.hijos || []).forEach(h => {
            const rows = [];
            this.el('tb_' + h.key).querySelectorAll('tr').forEach(tr => {
                const o = {};
                tr.querySelectorAll('.hb-in').forEach(el => { o[el.dataset.col] = (el.type === 'checkbox') ? (el.checked ? '1' : '') : el.value; });
                if (h.clave.tipo === 'entity' && tr.dataset.key) o.__key = tr.dataset.key;
                rows.push(o);
            });
            out[h.key] = rows;
        });
        return out;
    },

    async guardar() {
        this.el('formErr').textContent = '';
        const fd = new FormData();
        fd.append('__id', this.currentId || '');
        if (this.DEF.strpk && !this.currentId) fd.append('__newpk', (this.el('f__cod').value || '').trim());
        this.DEF.campos.forEach(c => { const el = this.el('f_' + c.col); if (!el) return; fd.append(c.col, (c.tipo === 'bool') ? (el.checked ? '1' : '') : el.value); });
        fd.append('__hijos', JSON.stringify(this.collectHijos()));
        const j = await (await fetch('api.php?action=save&m=' + encodeURIComponent(this.M), { method: 'POST', body: fd })).json();
        if (!j.ok) { this.el('formErr').textContent = j.error || 'Error al guardar'; this.toast(j.error || 'Error', 'danger'); return; }
        this.toast('Guardado', 'success');
        this.cargar(j.data.id);
    },

    async eliminar() {
        if (!this.currentId) return;
        if (!await this.confirm('¿Eliminar el registro ' + this.currentId + '?')) return;
        const fd = new FormData(); fd.append('__id', this.currentId);
        const j = await (await fetch('api.php?action=delete&m=' + encodeURIComponent(this.M), { method: 'POST', body: fd })).json();
        if (!j.ok) { this.toast(j.error || 'No se pudo eliminar', 'danger'); return; }
        this.toast('Eliminado', 'success');
        this.currentId = null; this.clearForm(); this.setMode('idle');
    },

    // ---------- modo ----------
    setMode(mode) {
        this.mode = mode;
        const editing = (mode === 'create' || mode === 'edit');
        if (!this.RO) {
            this.el('btnNuevo').disabled = editing;
            this.el('btnGuardar').disabled = !editing;
            this.el('btnCancelar').disabled = (mode === 'idle');
            this.el('btnEditar').disabled = (mode !== 'view');
            this.el('btnEliminar').disabled = (mode !== 'view');
        }
        this.el('mainForm').classList.toggle('mode-view', !editing);
        const cod = this.el('f__cod');   // strpk: el código se edita sólo al crear
        if (cod && this.DEF.strpk) cod.disabled = (mode !== 'create');
    },

    // ---------- utilidades ----------
    async api(action, params = {}) {
        const url = new URL('api.php', window.location.href);
        url.searchParams.set('action', action); url.searchParams.set('m', this.M);
        for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
        return await (await fetch(url)).json();
    },
    toast(msg, type = 'info') {
        const t = this.el('toastMsg'); this.el('toastBody').textContent = msg;
        t.className = 'toast align-items-center border-0 text-bg-' + type;
        bootstrap.Toast.getOrCreateInstance(t, { delay: type === 'danger' ? 7000 : 3000 }).show();
    },
    confirm(message) {
        return new Promise(resolve => {
            const me = this.el('modalConfirm'); this.el('confirmBody').textContent = message;
            let modal = bootstrap.Modal.getOrCreateInstance(me); let done = false;
            const ok = this.el('btnConfirmOk');
            const clean = () => { ok.removeEventListener('click', okH); me.removeEventListener('hidden.bs.modal', hidH); };
            const okH = () => { if (done) return; done = true; clean(); modal.hide(); resolve(true); };
            const hidH = () => { if (done) return; done = true; clean(); resolve(false); };
            ok.addEventListener('click', okH); me.addEventListener('hidden.bs.modal', hidH); modal.show();
        });
    },
};
document.addEventListener('DOMContentLoaded', () => App.init());
