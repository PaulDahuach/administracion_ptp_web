/* Cuentas Contables (Plan de Cuentas) — form + árbol. Porta Frm IC Cuentas Contables. */
const CC = {
    RO: window.CC_RO, mode: 'idle', cur: null, data: null,
    BOOLS: ['imp', 'ccc', 'dec', 'con', 'gas', 'gex', 'dis'],

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },

    init() {
        this.fillSelect('f_codcbx', window.CC_BANCARIAS, 'CODCBX', 'DENCBX');
        this.fillSelect('f_codhol', window.CC_HOLISTOR, 'CODHOL', 'DENHOL');
        this.bind();
        this.loadTree();
        this.setMode('idle');
    },

    fillSelect(id, rows, kc, dc) {
        this.el(id).innerHTML = '<option value="">—</option>' +
            (rows || []).map(r => `<option value="${this.esc(r[kc])}">${this.esc(r[dc])}</option>`).join('');
    },

    bind() {
        if (!this.RO) {
            this.el('btnNuevo').addEventListener('click', () => this.nuevo());
            this.el('btnGuardar').addEventListener('click', () => this.guardar());
            this.el('btnCancelar').addEventListener('click', () => this.cancelar());
            this.el('btnEliminar').addEventListener('click', () => this.eliminar());
        }
        // nivelación en vivo al tipear el código (alta)
        this.el('f_cod').addEventListener('input', e => {
            const v = e.target.value.replace(/\D/g, '').slice(0, 7); e.target.value = v;
            this.el('f_nivcue').textContent = v ? this.nivcue(v) : '—';
        });
        this.el('treeFilter').addEventListener('input', e => this.renderTree(e.target.value.trim()));
    },

    // ---------- nivelación / jerarquía ----------
    nivcue(s) {
        let o = s.substr(0, 1);
        if (s.length > 1) o += '.' + s.substr(1, 1);
        if (s.length > 2) o += '.' + s.substr(2, 1);
        if (s.length > 3) o += '.' + s.substr(3, 2);
        if (s.length > 5) o += '.' + s.substr(5, 2);
        return o;
    },
    parentCod(cod) { const L = { 2: 1, 3: 2, 5: 3, 7: 5 }[cod.length]; return L ? cod.substring(0, L) : null; },

    // ---------- árbol ----------
    async loadTree() {
        const j = await this.api('tree');
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.tree = j.data;
        this.renderTree('');
    },
    renderTree(filter) {
        const cont = this.el('tree');
        if (filter) {
            const f = filter.toLowerCase();
            const hits = this.tree.filter(it => it.nivcue.indexOf(f) >= 0 || it.den.toLowerCase().indexOf(f) >= 0);
            cont.innerHTML = hits.length
                ? hits.map(it => `<div class="cc-node cc-flat${it.imp ? ' cc-imp' : ''}" data-cod="${it.cod}"><span class="cc-code">${this.esc(it.nivcue)}</span> ${this.esc(it.den)}</div>`).join('')
                : '<div class="text-muted p-2 small">Sin coincidencias.</div>';
        } else {
            const byCod = {}; this.tree.forEach(it => byCod[it.cod] = Object.assign({}, it, { ch: [] }));
            const roots = [];
            this.tree.forEach(it => { const p = this.parentCod(it.cod); (p && byCod[p]) ? byCod[p].ch.push(byCod[it.cod]) : roots.push(byCod[it.cod]); });
            cont.innerHTML = roots.map(n => this.nodeHtml(n)).join('');
        }
        this.bindNodes();
    },
    nodeHtml(n) {
        const has = n.ch.length > 0;
        const kids = has ? `<div class="cc-children">${n.ch.map(c => this.nodeHtml(c)).join('')}</div>` : '';
        const tog = has ? '<i class="bi bi-caret-down-fill cc-tog"></i>' : '<span class="cc-tog-sp"></span>';
        return `<div class="cc-branch"><div class="cc-node${n.imp ? ' cc-imp' : ''}" data-cod="${n.cod}">${tog}<span class="cc-code">${this.esc(n.nivcue)}</span> ${this.esc(n.den)}</div>${kids}</div>`;
    },
    bindNodes() {
        const cont = this.el('tree');
        cont.querySelectorAll('.cc-node').forEach(nd => {
            nd.addEventListener('click', e => {
                if (e.target.classList.contains('cc-tog')) { // toggle expand/collapse
                    const br = nd.closest('.cc-branch'); br.classList.toggle('cc-collapsed');
                    e.stopPropagation(); return;
                }
                this.cargar(nd.dataset.cod);
                cont.querySelectorAll('.cc-node.sel').forEach(x => x.classList.remove('sel'));
                nd.classList.add('sel');
            });
        });
    },

    // ---------- cargar / poblar ----------
    async cargar(cod) {
        const j = await this.api('get', { cod });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.cur = cod; this.data = j.data; this.populate(j.data); this.setMode('view');
    },
    populate(d) {
        this.el('f_cod').value = d.cod;
        this.el('f_nivcue').textContent = d.nivcue;
        this.el('f_den').value = d.den;
        this.el('f_codcbx').value = d.codcbx || '';
        this.el('f_codhol').value = d.codhol || '';
        this.BOOLS.forEach(b => this.el('f_' + b).checked = !!d[b]);
        this.el('s_deb').textContent = d.debitos; this.el('s_cre').textContent = d.creditos;
        this.el('s_act').textContent = d.s_actual; this.el('s_ini').textContent = d.s_inicial;
        this.el('s_con').textContent = d.s_concil;
        this.el('formErr').textContent = '';
    },
    clearForm() {
        this.el('f_cod').value = ''; this.el('f_nivcue').textContent = '—'; this.el('f_den').value = '';
        this.el('f_codcbx').value = ''; this.el('f_codhol').value = '';
        this.BOOLS.forEach(b => this.el('f_' + b).checked = false);
        ['s_deb', 's_cre', 's_act', 's_ini', 's_con'].forEach(i => this.el(i).textContent = '0.00');
        this.el('formErr').textContent = '';
    },

    // ---------- acciones ----------
    nuevo() { this.cur = null; this.data = null; this.clearForm(); this.setMode('create'); setTimeout(() => this.el('f_cod').focus(), 80); },
    cancelar() { if (this.cur) this.cargar(this.cur); else { this.clearForm(); this.setMode('idle'); } },

    async guardar() {
        this.el('formErr').textContent = '';
        const nuevo = (this.mode === 'create');
        const fd = new FormData();
        fd.append('__nuevo', nuevo ? '1' : '0');
        fd.append('cod', this.el('f_cod').value);
        fd.append('den', this.el('f_den').value);
        fd.append('codcbx', this.el('f_codcbx').value);
        fd.append('codhol', this.el('f_codhol').value);
        this.BOOLS.forEach(b => fd.append(b, this.el('f_' + b).checked ? '1' : ''));
        const j = await (await fetch('api.php?action=save', { method: 'POST', body: fd })).json();
        if (!j.ok) { this.el('formErr').textContent = j.error || 'Error al guardar'; this.toast(j.error || 'Error', 'danger'); return; }
        this.toast('Guardado', 'success');
        await this.loadTree();
        this.cargar(j.data.cod);
    },

    async eliminar() {
        if (!this.cur) return;
        if (!await this.confirm('¿Eliminar la cuenta ' + this.el('f_nivcue').textContent + ' (' + this.el('f_den').value + ')?')) return;
        const fd = new FormData(); fd.append('cod', this.cur);
        const j = await (await fetch('api.php?action=delete', { method: 'POST', body: fd })).json();
        if (!j.ok) { this.toast(j.error || 'No se pudo eliminar', 'danger'); return; }
        this.toast('Eliminado', 'success');
        this.cur = null; this.clearForm(); this.setMode('idle'); this.loadTree();
    },

    // ---------- modo + bloqueos ----------
    setMode(mode) {
        this.mode = mode;
        const editing = (mode === 'create' || mode === 'edit'); // nota: no hay "edit" separado; en view se edita directo
        const d = this.data || {};
        if (!this.RO) {
            this.el('btnNuevo').disabled = (mode === 'create');
            this.el('btnGuardar').disabled = (mode === 'idle');
            this.el('btnCancelar').disabled = (mode === 'idle');
            this.el('btnEliminar').disabled = (mode !== 'view') || !!d.lock_del;
        }
        // Código: editable solo al crear.
        this.el('f_cod').disabled = (mode !== 'create');
        // En modo idle todo deshabilitado; en view/create editable salvo bloqueos.
        const off = (mode === 'idle') || this.RO;
        this.el('f_den').disabled = off || !!d.lock_den;
        this.el('f_imp').disabled = off || !!d.lock_imp;
        ['f_codcbx', 'f_codhol', 'f_ccc', 'f_dec', 'f_con', 'f_gas', 'f_gex', 'f_dis'].forEach(i => this.el(i).disabled = off);
        this.el('cardSaldos').style.display = (mode === 'view') ? '' : 'none';
    },

    // ---------- utilidades ----------
    async api(action, params = {}) {
        const url = new URL('api.php', window.location.href);
        url.searchParams.set('action', action);
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
            const modal = bootstrap.Modal.getOrCreateInstance(me); let done = false;
            const ok = this.el('btnConfirmOk');
            const clean = () => { ok.removeEventListener('click', okH); me.removeEventListener('hidden.bs.modal', hidH); };
            const okH = () => { if (done) return; done = true; clean(); modal.hide(); resolve(true); };
            const hidH = () => { if (done) return; done = true; clean(); resolve(false); };
            ok.addEventListener('click', okH); me.addEventListener('hidden.bs.modal', hidH); modal.show();
        });
    },
};
document.addEventListener('DOMContentLoaded', () => CC.init());
