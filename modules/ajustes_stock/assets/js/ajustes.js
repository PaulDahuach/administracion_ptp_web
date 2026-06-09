/* Ajustes de Stock — transaccional. Alta (mueve EXISTK) + buscar/ver/anular. */
const AJ = {
    RO: window.AJ_RO, CONCEPTOS: window.AJ_CONCEPTOS || [], FECHA: window.AJ_FECHA || '',
    mode: 'idle', cur: null, dt: null,
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    fmt(n, d) { return (parseFloat(n) || 0).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); },

    init() {
        this.el('f_codaux').innerHTML = '<option value="">—</option>' + this.CONCEPTOS.map(c => `<option value="${c.id}">${this.esc(c.den)}</option>`).join('');
        this.bind();
        this.setMode('idle');
    },
    bind() {
        if (!this.RO) {
            this.el('btnNuevo').addEventListener('click', () => this.nuevo());
            this.el('btnGuardar').addEventListener('click', () => this.guardar());
            this.el('btnCancelar').addEventListener('click', () => this.cancelar());
            this.el('btnAnular').addEventListener('click', () => this.anular());
            this.el('btnAddLinea').addEventListener('click', () => this.addLinea({}));
        }
        this.el('btnBuscar').addEventListener('click', () => { new bootstrap.Modal(this.el('modalBuscar')).show(); });
        this.el('modalBuscar').addEventListener('shown.bs.modal', () => this.loadList());
        this.el('bGo').addEventListener('click', () => this.loadList());
        // autocomplete de producto (delegado en el tbody)
        let t;
        this.el('tbLineas').addEventListener('input', e => {
            const inp = e.target.closest('.g-prod-ac'); if (!inp) return;
            const tr = inp.closest('tr'); tr.querySelector('.g-codpro').value = '';
            clearTimeout(t); const q = inp.value; t = setTimeout(() => this.prodSearch(q, inp), 280);
        });
        this.el('tbLineas').addEventListener('change', e => { if (e.target.closest('.g-udm')) this.recalcStock(e.target.closest('tr')); });
        document.addEventListener('click', e => { if (!e.target.closest('.ac-wrap')) document.querySelectorAll('#tbLineas .ac-list').forEach(l => l.classList.remove('show')); });
    },

    // ---------- alta ----------
    nuevo() {
        this.cur = null; this.el('fNum').textContent = '(nuevo)';
        this.el('f_fex').value = this.FECHA; this.el('f_codaux').value = ''; this.el('f_cot').value = '1'; this.el('f_det').value = '';
        this.el('tbLineas').innerHTML = ''; this.el('formErr').textContent = '';
        this.addLinea({});
        this.setMode('create');
        setTimeout(() => this.el('f_codaux').focus(), 80);
    },
    cancelar() { this.cur = null; this.el('tbLineas').innerHTML = ''; this.el('fNum').textContent = '—'; this.el('formErr').textContent = ''; this.setMode('idle'); },

    addLinea(x) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><div class="ac-wrap"><input type="hidden" class="g-codpro" value="${this.esc(x.codpro || '')}">
                <input class="g-prod-ac form-control form-control-sm" autocomplete="off" placeholder="Buscar producto…" value="${this.esc(x.denmov || '')}"><div class="ac-list"></div></div></td>
            <td><select class="g-udm form-select form-select-sm"></select></td>
            <td class="text-end g-stock">—</td>
            <td><input class="g-ing form-control form-control-sm text-end" type="number" step="any" value="${x.ing ? this.esc(x.ing) : ''}"></td>
            <td><input class="g-egr form-control form-control-sm text-end" type="number" step="any" value="${x.egr ? this.esc(x.egr) : ''}"></td>
            <td><button type="button" class="btn btn-outline-danger btn-sm g-del">&times;</button></td>`;
        this.el('tbLineas').appendChild(tr);
        tr._aj = { existk: 0, units: [] };
        tr.querySelector('.g-del').addEventListener('click', () => { tr.remove(); if (!this.el('tbLineas').children.length) this.addLinea({}); });
        this.applyEnabled();
        return tr;
    },

    async prodSearch(q, inp) {
        const list = inp.parentNode.querySelector('.ac-list');
        if (q.length < 1) { list.classList.remove('show'); return; }
        const r = await this.api('productos', { q });
        if (!r.ok || !r.data.length) { list.innerHTML = '<div class="ac-item text-muted">Sin resultados</div>'; list.classList.add('show'); return; }
        list.innerHTML = r.data.map(o => `<div class="ac-item" data-p='${this.esc(JSON.stringify(o))}'><span class="ac-code">${this.esc(o.CODPRO)}</span>${this.esc(o.DENPRO)}</div>`).join('');
        list.querySelectorAll('.ac-item[data-p]').forEach(it => it.addEventListener('click', () => this.pickProd(it.closest('tr'), JSON.parse(it.dataset.p), inp, list)));
        list.classList.add('show');
    },
    async pickProd(tr, p, inp, list) {
        tr.querySelector('.g-codpro').value = p.CODPRO; inp.value = p.DENPRO; list.classList.remove('show');
        tr._aj.existk = parseFloat(p.EXISTK) || 0; tr._aj.codudm = parseInt(p.CODUDM, 10) || 0;
        const u = await this.api('unidades', { codpro: p.CODPRO });
        const units = (u.ok ? u.data : []);
        tr._aj.units = units;
        const sel = tr.querySelector('.g-udm');
        sel.innerHTML = units.map(x => `<option value="${x.CODUDM}" data-fct="${x.FCTPUM}">${this.esc(x.DENUDM)}</option>`).join('');
        sel.value = String(tr._aj.codudm);
        this.recalcStock(tr);
        tr.querySelector('.g-ing').focus();
    },
    recalcStock(tr) {
        const opt = tr.querySelector('.g-udm').selectedOptions[0];
        const fct = opt ? (parseFloat(opt.dataset.fct) || 1) : 1;
        tr.querySelector('.g-stock').textContent = this.fmt((tr._aj.existk || 0) / (fct || 1), 4);
    },

    async guardar() {
        this.el('formErr').textContent = '';
        if (this.el('f_codaux').value === '') { this.el('formErr').textContent = 'Falta el concepto'; this.el('f_codaux').focus(); return; }
        if (this.el('f_fex').value === '') { this.el('formErr').textContent = 'Falta la fecha'; return; }
        const lineas = Array.from(this.el('tbLineas').children).map(tr => ({
            codpro: tr.querySelector('.g-codpro').value, codudm: tr.querySelector('.g-udm').value,
            ing: tr.querySelector('.g-ing').value, egr: tr.querySelector('.g-egr').value,
        })).filter(l => l.codpro && ((parseFloat(l.ing) || 0) > 0 || (parseFloat(l.egr) || 0) > 0));
        if (!lineas.length) { this.el('formErr').textContent = 'Cargá al menos un producto con ingreso o egreso.'; return; }
        const data = { codaux: this.el('f_codaux').value, fexmov: this.el('f_fex').value, cotmov: this.el('f_cot').value || '1', detmov: this.el('f_det').value, lineas: lineas };
        const fd = new FormData(); fd.append('data', JSON.stringify(data));
        const j = await (await fetch('api.php?action=guardar', { method: 'POST', body: fd })).json();
        if (!j.ok) { this.el('formErr').textContent = j.error || 'Error al grabar'; this.toast(j.error || 'Error', 'danger'); return; }
        this.toast('Ajuste Nº ' + j.data.ajuste + ' grabado', 'success');
        this.cargar(j.data.nummov);
    },

    // ---------- ver / buscar ----------
    async loadList() {
        const p = { q: this.el('bq').value, desde: this.el('bdesde').value, hasta: this.el('bhasta').value };
        const j = await this.api('listar', p); if (!j.ok) return;
        const data = (j.data || []).map(o => [o.NUMERO, o.FECHA, o.CONCEPTO, o.DETALLE, o.TOTAL, o.ANULADO ? '<span class="badge bg-danger">ANULADO</span>' : '', o.NUMMOV]);
        const self = this;
        if (this.dt) this.dt.clear().rows.add(data).draw();
        else this.dt = $('#grdBuscar').DataTable({
            data: data, pageLength: 25, order: [[0, 'desc']],
            columnDefs: [{ targets: 6, visible: false }, { targets: 4, className: 'text-end' }],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
            createdRow: function (row, d) { row.addEventListener('click', () => { self.cargar(d[6]); bootstrap.Modal.getInstance(self.el('modalBuscar')).hide(); }); },
        });
    },
    async cargar(nummov) {
        const j = await this.api('detalle', { nummov }); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        const d = j.data; this.cur = d;
        this.el('fNum').innerHTML = 'Nº ' + d.NUMERO + (d.ANULADO ? ' <span class="badge bg-danger">ANULADO</span>' : '');
        this.el('f_fex').value = d.FEXISO; this.el('f_codaux').value = d.CODAUX; this.el('f_cot').value = d.COTMOV; this.el('f_det').value = d.DETMOV;
        this.el('tbLineas').innerHTML = d.lineas.map(l => `<tr>
            <td><span class="ac-code">${this.esc(l.codpro)}</span> ${this.esc(l.denmov)}</td>
            <td>${this.esc(l.unidad)}</td>
            <td class="text-end">${this.fmt(l.eximov, 4)}</td>
            <td class="text-end">${l.ing ? this.fmt(l.ing, 4) : ''}</td>
            <td class="text-end">${l.egr ? this.fmt(l.egr, 4) : ''}</td><td></td></tr>`).join('');
        this.el('formErr').textContent = '';
        this.setMode('view');
    },

    async anular() {
        if (!this.cur || !this.cur.NUMMOV) return;
        if (!await this.confirm('¿Anular el ajuste Nº ' + this.cur.NUMERO + '? Se revertirá el stock movido.')) return;
        const fd = new FormData(); fd.append('nummov', this.cur.NUMMOV);
        const j = await (await fetch('api.php?action=anular', { method: 'POST', body: fd })).json();
        if (!j.ok) { this.toast(j.error || 'No se pudo anular', 'danger'); return; }
        this.toast('Ajuste anulado', 'success'); this.cargar(this.cur.NUMMOV);
    },

    // ---------- modo ----------
    setMode(mode) {
        this.mode = mode;
        const editing = (mode === 'create');
        if (!this.RO) {
            this.el('btnNuevo').disabled = editing;
            this.el('btnGuardar').disabled = !editing;
            this.el('btnCancelar').disabled = (mode === 'idle');
            this.el('btnAddLinea').disabled = !editing;
            this.el('btnAnular').disabled = !(mode === 'view' && this.cur && this.cur.ANULABLE);
        }
        this.el('mainForm').className = 'aj-form mode-' + mode;
        this.applyEnabled();
    },
    applyEnabled() {
        const editing = (this.mode === 'create') && !this.RO;
        ['f_fex', 'f_codaux', 'f_cot', 'f_det'].forEach(i => this.el(i).disabled = !editing);
        document.querySelectorAll('#tbLineas .g-prod-ac, #tbLineas .g-udm, #tbLineas .g-ing, #tbLineas .g-egr').forEach(x => x.disabled = !editing);
        document.querySelectorAll('#tbLineas .g-del').forEach(x => x.style.display = editing ? '' : 'none');
    },

    // ---------- utils ----------
    async api(action, params) { const url = new URL('api.php', window.location.href); url.searchParams.set('action', action); for (const k in (params || {})) if (params[k] != null && params[k] !== '') url.searchParams.set(k, params[k]); return await (await fetch(url)).json(); },
    toast(msg, type) { const t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: type === 'danger' ? 7000 : 3000 }).show(); },
    confirm(message) { return new Promise(resolve => { const me = this.el('modalConfirm'); this.el('confirmBody').textContent = message; const modal = bootstrap.Modal.getOrCreateInstance(me); let done = false; const ok = this.el('btnConfirmOk'); const clean = () => { ok.removeEventListener('click', okH); me.removeEventListener('hidden.bs.modal', hidH); }; const okH = () => { if (done) return; done = true; clean(); modal.hide(); resolve(true); }; const hidH = () => { if (done) return; done = true; clean(); resolve(false); }; ok.addEventListener('click', okH); me.addEventListener('hidden.bs.modal', hidH); modal.show(); }); },
};
document.addEventListener('DOMContentLoaded', () => AJ.init());
