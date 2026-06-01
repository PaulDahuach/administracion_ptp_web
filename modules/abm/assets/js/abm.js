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
        if (c.tipo === 'memo') return `<textarea ${id} class="form-control hb-in" data-col="${c.col}" rows="2"></textarea>`;
        if (c.tipo === 'bool') return `<div class="form-check"><input type="checkbox" ${id} class="form-check-input hb-in" data-col="${c.col}" value="1"></div>`;
        if (c.tipo === 'date') return `<input type="date" ${id} class="form-control hb-in" data-col="${c.col}">`;
        if (c.tipo === 'select') {
            const opts = '<option value="">—</option>' + (c.options || []).map(o => `<option value="${this.esc(o.id)}">${this.esc(o.den)}</option>`).join('');
            return `<select ${id} class="form-select hb-in" data-col="${c.col}">${opts}</select>`;
        }
        const t = (c.tipo === 'number' || c.tipo === 'decimal') ? 'number' : 'text';
        const step = (c.tipo === 'decimal') ? ' step="any"' : '';
        const mx = c.size ? ` maxlength="${c.size}"` : '';
        return `<input type="${t}"${step}${mx} ${id} class="form-control hb-in" data-col="${c.col}">`;
    },

    buildForm() {
        this.el('formFields').innerHTML = this.DEF.campos.map(c => {
            const wide = (c.tipo === 'memo') ? 'col-12' : 'col-md-4';
            const req = c.req ? ' <span class="text-danger">*</span>' : '';
            return `<div class="${wide}"><label class="form-label">${this.esc(c.label)}${req}</label>${this.ctrl(c, 'f_' + c.col)}</div>`;
        }).join('');
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
    },

    hijoCols(h) {
        const cols = [];
        if (h.clave.tipo === 'select') cols.push({ col: h.clave.col, label: h.clave.label, tipo: 'select', options: h.clave.options });
        h.campos.forEach(c => cols.push(c));
        return cols;
    },

    rowHtml(h, r) {
        const cells = this.hijoCols(h).map(c => `<td>${this.ctrl(c)}</td>`).join('');
        return `<tr>${cells}<td><button type="button" class="btn btn-outline-danger btn-sm btn-remove-row">&times;</button></td></tr>`;
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
    async loadList() {
        const j = await this.api('list');
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
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
                createdRow: function (row, d) {
                    row.addEventListener('click', () => {
                        self.cargar(d[0]);
                        bootstrap.Modal.getInstance(self.el('modalBuscar')).hide();
                    });
                }
            });
        }
        setTimeout(() => $('#modalBuscar .dataTables_filter input').trigger('focus'), 150);
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
        this.el('fCodigo').textContent = (d[this.DEF.pk] != null ? d[this.DEF.pk] : '—');
        this.DEF.campos.forEach(c => {
            const el = this.el('f_' + c.col); if (!el) return;
            if (c.tipo === 'bool') el.checked = !!d[c.col];
            else el.value = (d[c.col] === null || d[c.col] === undefined) ? '' : d[c.col];
        });
        (this.DEF.hijos || []).forEach(h => this.renderHijo(h, (d.__hijos && d.__hijos[h.key]) || []));
    },

    clearForm() {
        this.el('fCodigo').textContent = '(nuevo)';
        this.DEF.campos.forEach(c => { const el = this.el('f_' + c.col); if (!el) return; if (c.tipo === 'bool') el.checked = false; else el.value = ''; });
        (this.DEF.hijos || []).forEach(h => this.renderHijo(h, []));
        this.el('formErr').textContent = '';
    },

    // ---------- acciones ----------
    nuevo() { this.currentId = null; this.clearForm(); this.setMode('create'); setTimeout(() => { const f = this.el('f_' + this.DEF.campos[0].col); if (f) f.focus(); }, 100); },
    editar() { if (this.currentId) this.setMode('edit'); },
    cancelar() { if (this.currentId) this.cargar(this.currentId); else { this.clearForm(); this.setMode('idle'); } },

    collectHijos() {
        const out = {};
        (this.DEF.hijos || []).forEach(h => {
            const rows = [];
            this.el('tb_' + h.key).querySelectorAll('tr').forEach(tr => {
                const o = {};
                tr.querySelectorAll('.hb-in').forEach(el => { o[el.dataset.col] = (el.type === 'checkbox') ? (el.checked ? '1' : '') : el.value; });
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
