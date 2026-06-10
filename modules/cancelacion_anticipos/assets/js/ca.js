/* Cancelación de Anticipos — aplica anticipos contra comprobantes pendientes. */
const CA = {
    RO: window.CA_RO, FECHA: window.CA_FECHA || '', mode: 'idle', dt: null,
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    fmt(n) { return (parseFloat(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    num(v) { return parseFloat(String(v).replace(/,/g, '')) || 0; },

    init() {
        if (!this.RO) {
            this.el('btnNuevo').addEventListener('click', () => this.nuevo());
            this.el('btnGrabar').addEventListener('click', () => this.grabar());
            this.el('btnCancelar').addEventListener('click', () => this.cancelar());
        }
        this.el('btnBuscar').addEventListener('click', () => { new bootstrap.Modal(this.el('modalBuscar')).show(); });
        this.el('modalBuscar').addEventListener('shown.bs.modal', () => this.loadList());
        // autocomplete proveedor
        let t;
        const inp = this.el('f_prov');
        inp.addEventListener('input', () => { this.el('f_cue').value = ''; clearTimeout(t); const q = inp.value; t = setTimeout(() => this.provSearch(q), 280); });
        document.addEventListener('click', e => { if (!e.target.closest('.ac-wrap')) document.querySelectorAll('.ac-list').forEach(l => l.classList.remove('show')); });
        // recalc al tipear importes
        this.el('tbAnt').addEventListener('input', e => { if (e.target.classList.contains('imp')) this.recalc(); });
        this.el('tbRef').addEventListener('input', e => { if (e.target.classList.contains('imp')) this.recalc(); });
        this.setMode('idle');
    },

    async provSearch(q) {
        const list = this.el('f_prov').parentNode.querySelector('.ac-list');
        if (q.length < 1) { list.classList.remove('show'); return; }
        const r = await this.api('proveedores', { q });
        if (!r.ok || !r.data.length) { list.innerHTML = '<div class="ac-item text-muted">Sin resultados</div>'; list.classList.add('show'); return; }
        list.innerHTML = r.data.map(o => `<div class="ac-item" data-id="${o.id}" data-den="${this.esc(o.den)}">${o.cod ? '<span class="ac-code">' + this.esc(o.cod) + '</span>' : ''}${this.esc(o.den)}</div>`).join('');
        list.querySelectorAll('.ac-item[data-id]').forEach(it => it.addEventListener('click', () => { this.el('f_cue').value = it.dataset.id; this.el('f_prov').value = it.dataset.den; list.classList.remove('show'); this.cargarProv(); }));
        list.classList.add('show');
    },

    async cargarProv() {
        const cue = this.el('f_cue').value; if (!cue) return;
        const j = await this.api('datos', { codcue: cue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        const d = j.data;
        this.el('sAnt').textContent = d.sancue; this.el('sOper').textContent = d.sopcue;
        this.el('tbAnt').innerHTML = d.anticipos.map(a => `<tr data-num="${a.nummov}" data-saldo="${a.saldo}">
            <td>${this.esc(a.interno)}</td><td>${this.esc(a.externo)}</td><td class="small">${this.esc(a.detalle)}</td>
            <td class="text-end">${this.fmt(a.saldo)}</td>
            <td><input type="number" step="0.01" class="form-control form-control-sm text-end imp" value=""></td></tr>`).join('')
            || '<tr><td colspan="5" class="text-muted small p-2">Sin anticipos disponibles.</td></tr>';
        this.el('tbRef').innerHTML = d.comprobantes.map(c => `<tr data-num="${c.nummov}" data-fvx="${c.fvx}" data-saldo="${c.saldo}">
            <td>${this.esc(c.vencimiento)}</td><td>${this.esc(c.interno)}</td><td>${this.esc(c.externo)}</td><td class="small">${this.esc(c.detalle)}</td>
            <td class="text-end">${this.fmt(c.saldo)}</td>
            <td><input type="number" step="0.01" class="form-control form-control-sm text-end imp" value=""></td></tr>`).join('')
            || '<tr><td colspan="6" class="text-muted small p-2">Sin comprobantes pendientes.</td></tr>';
        this.recalc();
    },

    recalc() {
        let ta = 0, tr = 0;
        this.el('tbAnt').querySelectorAll('tr[data-num]').forEach(t => ta += this.num(t.querySelector('.imp').value));
        this.el('tbRef').querySelectorAll('tr[data-num]').forEach(t => tr += this.num(t.querySelector('.imp').value));
        ta = Math.round(ta * 100) / 100; tr = Math.round(tr * 100) / 100;
        this.el('totAnt').textContent = ta ? 'Total: ' + this.fmt(ta) : '';
        this.el('totRef').textContent = tr ? 'Total: ' + this.fmt(tr) : '';
        const ok = ta > 0 && Math.abs(ta - tr) < 0.005;
        const dif = Math.round((ta - tr) * 100) / 100;
        this.el('caBar').className = 'ca-bar ' + (ta === 0 && tr === 0 ? '' : (ok ? 'ok' : 'bad'));
        this.el('caBar').innerHTML = (ta === 0 && tr === 0) ? '' :
            `Anticipos <b>${this.fmt(ta)}</b> · Comprobantes <b>${this.fmt(tr)}</b> · ` + (ok ? '<b>cuadra ✓</b>' : `Diferencia <b>${this.fmt(dif)}</b>`);
        if (!this.RO) this.el('btnGrabar').disabled = !(ok && this.mode === 'create');
    },

    nuevo() { this.setMode('create'); this.el('f_cue').value = ''; this.el('f_prov').value = ''; this.el('f_fex').value = this.FECHA; this.el('f_det').value = ''; this.el('tbAnt').innerHTML = ''; this.el('tbRef').innerHTML = ''; this.el('sAnt').textContent = '—'; this.el('sOper').textContent = '—'; this.el('caBar').innerHTML = ''; this.el('formErr').textContent = ''; setTimeout(() => this.el('f_prov').focus(), 80); },
    cancelar() { this.setMode('idle'); this.el('tbAnt').innerHTML = ''; this.el('tbRef').innerHTML = ''; this.el('caBar').innerHTML = ''; this.el('formErr').textContent = ''; },

    async grabar() {
        this.el('formErr').textContent = '';
        if (!this.el('f_cue').value) { this.el('formErr').textContent = 'Elegí un proveedor'; return; }
        if (!this.el('f_fex').value) { this.el('formErr').textContent = 'Falta la fecha'; return; }
        const ants = [], refs = [];
        this.el('tbAnt').querySelectorAll('tr[data-num]').forEach(t => { const imp = this.num(t.querySelector('.imp').value); if (imp > 0) ants.push({ nummov: t.dataset.num, imp }); });
        this.el('tbRef').querySelectorAll('tr[data-num]').forEach(t => { const imp = this.num(t.querySelector('.imp').value); if (imp > 0) refs.push({ nummov: t.dataset.num, fvx: t.dataset.fvx, imp }); });
        if (!ants.length || !refs.length) { this.el('formErr').textContent = 'Cargá importes en anticipos y comprobantes'; return; }
        if (!await this.confirm('Grabar la cancelación por ' + this.el('caBar').textContent.split('·')[0].replace('Anticipos', '').trim() + '?')) return;
        const data = { codcue: this.el('f_cue').value, fexmov: this.el('f_fex').value, detmov: this.el('f_det').value, anticipos: ants, referencias: refs };
        const fd = new FormData(); fd.append('data', JSON.stringify(data));
        const j = await (await fetch('api.php?action=guardar', { method: 'POST', body: fd })).json();
        if (!j.ok) { this.el('formErr').textContent = j.error || 'Error'; this.toast(j.error || 'Error', 'danger'); return; }
        this.toast('Cancelación Nº ' + j.data.nummov + ' grabada (' + j.data.total + ')', 'success');
        this.cancelar();
    },

    async loadList() {
        const j = await this.api('listar', {}); if (!j.ok) return;
        const data = (j.data || []).map(o => [o.NUMMOV, o.FECHA, o.PROVEEDOR, o.TOTAL, o.ANULADO ? '<span class="badge bg-danger">ANUL</span>' : '']);
        const self = this;
        if (this.dt) this.dt.clear().rows.add(data).draw();
        else this.dt = $('#grdBuscar').DataTable({ data, pageLength: 25, order: [[0, 'desc']], columnDefs: [{ targets: 3, className: 'text-end' }], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
            createdRow: (row, d) => row.addEventListener('click', () => { self.ver(d[0]); bootstrap.Modal.getInstance(self.el('modalBuscar')).hide(); }) });
    },
    async ver(num) {
        const j = await this.api('detalle', { nummov: num }); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        const d = j.data;
        this.el('verTit').innerHTML = 'Cancelación Nº ' + d.NUMMOV + (d.ANULADO ? ' <span class="badge bg-danger">ANULADO</span>' : '');
        this.el('verBody').innerHTML = `<div class="mb-2"><b>${this.esc(d.PROVEEDOR)}</b> · ${this.esc(d.FECHA)} · Total <b>${d.TOTAL}</b>${d.DETALLE ? ' · ' + this.esc(d.DETALLE) : ''}</div>
          <div class="fw-bold small mt-2">Anticipos aplicados</div><table class="table table-sm"><tbody>${d.anticipos.map(a => `<tr><td>${this.esc(a.comp)}</td><td>${this.esc(a.fecha)}</td><td class="text-end">${a.imp}</td></tr>`).join('')}</tbody></table>
          <div class="fw-bold small mt-2">Comprobantes referenciados</div><table class="table table-sm"><tbody>${d.referencias.map(r => `<tr><td>${this.esc(r.comp)}</td><td>${this.esc(r.externo)}</td><td>${this.esc(r.vencimiento)}</td><td class="text-end">${r.imp}</td></tr>`).join('')}</tbody></table>`;
        new bootstrap.Modal(this.el('modalVer')).show();
    },

    setMode(mode) {
        this.mode = mode; const create = (mode === 'create') && !this.RO;
        ['f_prov', 'f_fex', 'f_det'].forEach(i => this.el(i).disabled = !create);
        if (!this.RO) { this.el('btnNuevo').disabled = create; this.el('btnCancelar').disabled = (mode === 'idle'); this.el('btnGrabar').disabled = true; }
        this.el('mainForm').className = 'mode-' + mode;
    },

    async api(action, params) { const url = new URL('api.php', location.href); url.searchParams.set('action', action); for (const k in (params || {})) if (params[k] != null && params[k] !== '') url.searchParams.set(k, params[k]); return await (await fetch(url)).json(); },
    toast(msg, type) { const t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: type === 'danger' ? 7000 : 3500 }).show(); },
    confirm(message) { return new Promise(resolve => { const me = this.el('modalConfirm'); this.el('confirmBody').textContent = message; const modal = bootstrap.Modal.getOrCreateInstance(me); let done = false; const ok = this.el('btnConfirmOk'); const clean = () => { ok.removeEventListener('click', okH); me.removeEventListener('hidden.bs.modal', hidH); }; const okH = () => { if (done) return; done = true; clean(); modal.hide(); resolve(true); }; const hidH = () => { if (done) return; done = true; clean(); resolve(false); }; ok.addEventListener('click', okH); me.addEventListener('hidden.bs.modal', hidH); modal.show(); }); },
};
document.addEventListener('DOMContentLoaded', () => CA.init());
