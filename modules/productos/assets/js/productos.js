/* Productos y Servicios — maestro central. Form + grillas editables (equiv/prov) + stock + precios. */
const PR = {
    RO: window.PR_RO, LK: window.PR_LK, mode: 'idle', cur: null, dt: null, data: null,
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },

    init() {
        this.cats = this.LK.catcli || []; this.curPunrub = 0; this.curPunsub = 0;
        this.opts('f_codcat', this.LK.cat); this.opts('f_codrub', this.LK.rub);
        this.opts('f_codlin', this.LK.lin); this.opts('f_codudm', this.LK.udm); this.opts('f_codmon', this.LK.mon);
        this.fillSub('');
        // eventos de cálculo (porta los AfterUpdate del Frm SI Productos)
        this.el('f_codrub').addEventListener('change', () => {
            const r = this.LK.rub.find(x => String(x.id) === this.el('f_codrub').value);
            this.curPunrub = r ? (parseFloat(r.pun) || 0) : 0; this.curPunsub = 0;
            this.fillSub(this.el('f_codrub').value); this.el('f_codsub').value = '';
        });
        this.el('f_codsub').addEventListener('change', () => {
            const s = this.LK.sub.find(x => String(x.id) === this.el('f_codsub').value);
            this.curPunsub = s ? (parseFloat(s.pun) || 0) : 0;
        });
        this.el('f_codmon').addEventListener('change', () => {
            const m = this.LK.mon.find(x => String(x.id) === this.el('f_codmon').value);
            if (m) this.el('f_cot').value = (m.cot != null ? m.cot : 1);
            this.applyEnabled();
        });
        this.el('f_cos').addEventListener('change', () => { this.el('f_flt').value = '0'; this.el('f_plc').value = this.el('f_cos').value; this.updatePLV(); });
        this.el('f_flt').addEventListener('change', () => { if (this.mode === 'create') this.el('f_plc').value = (parseFloat(this.el('f_cos').value) || 0) + (parseFloat(this.el('f_flt').value) || 0); this.updatePLV(); });
        this.el('f_plc').addEventListener('change', () => this.updatePLV());
        this.el('f_plv').addEventListener('change', () => this.recomputePrecios());
        this.bind();
        this.setMode('idle');
    },

    // PLVPRO = PLCPRO × (1 + utilidad%/100); utilidad = PUNSUB (subrubro) o PUNRUB (rubro)
    updatePLV() {
        const pun = (this.curPunsub && this.curPunsub != 0) ? this.curPunsub : (this.curPunrub || 0);
        const plc = parseFloat(this.el('f_plc').value) || 0;
        this.el('f_plv').value = Math.round(plc * (1 + pun / 100) * 10000) / 10000;
        this.recomputePrecios();
    },
    // precios de venta por categoría de cliente (NETO = PLV − PLV×LDPCAT/100; %UTIL vs PLCPRO)
    recomputePrecios() {
        const plv = parseFloat(this.el('f_plv').value) || 0, plc = parseFloat(this.el('f_plc').value) || 0;
        const fmt = (n, d) => n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
        this.el('tbPrecios').innerHTML = this.cats.map(c => {
            const ldp = parseFloat(c.ldp) || 0, net = plv - plv * ldp / 100, gan = plc == 0 ? 0 : (net * 100 / plc) - 100;
            return `<tr><td>${this.esc(c.den)}</td><td class="text-end">${fmt(ldp, 2)}</td><td class="text-end">${fmt(net, 4)}</td><td class="text-end">${fmt(gan, 2)}</td></tr>`;
        }).join('') || '<tr><td colspan="4" class="text-muted small">Sin categorías.</td></tr>';
    },
    opts(id, rows, sel) {
        this.el(id).innerHTML = '<option value="">—</option>' + (rows || []).map(r => `<option value="${this.esc(r.id)}"${String(r.id) === String(sel) ? ' selected' : ''}>${this.esc(r.den)}</option>`).join('');
    },
    fillSub(rub) {
        const subs = (this.LK.sub || []).filter(s => !rub || String(s.rub) === String(rub));
        this.el('f_codsub').innerHTML = '<option value="">—</option>' + subs.map(s => `<option value="${this.esc(s.id)}">${this.esc(s.den)}</option>`).join('');
    },

    bind() {
        // colapso manual de paneles (el botón + no debe dispararlo)
        document.querySelectorAll('.collapse-hd').forEach(h => {
            const tgt = document.querySelector(h.getAttribute('data-target')); if (!tgt) return;
            h.addEventListener('click', e => { if (e.target.closest('.prod-add')) return; bootstrap.Collapse.getOrCreateInstance(tgt).toggle(); });
            tgt.addEventListener('shown.bs.collapse', () => h.classList.remove('collapsed'));
            tgt.addEventListener('hidden.bs.collapse', () => h.classList.add('collapsed'));
        });
        if (!this.RO) {
            this.el('btnNuevo').addEventListener('click', () => this.nuevo());
            this.el('btnGuardar').addEventListener('click', () => this.guardar());
            this.el('btnCancelar').addEventListener('click', () => this.cancelar());
            this.el('btnEditar').addEventListener('click', () => this.editar());
            this.el('btnEliminar').addEventListener('click', () => this.eliminar());
            document.querySelectorAll('.prod-add').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); b.dataset.grid === 'equiv' ? this.addEquiv({ factor: 1 }) : this.addProv({}); }));
        }
        this.el('btnBuscar').addEventListener('click', () => new bootstrap.Modal(this.el('modalBuscar')).show());
        this.el('modalBuscar').addEventListener('shown.bs.modal', () => this.loadList());
        // autocomplete proveedores (delegado)
        let t;
        this.el('tbProv').addEventListener('input', e => {
            const inp = e.target.closest('.g-prov-ac'); if (!inp) return;
            const tr = inp.closest('tr'); tr.querySelector('.g-cue').value = '';
            clearTimeout(t); const q = inp.value; t = setTimeout(() => this.provSearch(q, inp), 300);
        });
        document.addEventListener('click', e => { if (!e.target.closest('.prov-cue')) document.querySelectorAll('#tbProv .ac-list').forEach(l => l.classList.remove('show')); });
    },

    // ---------- Buscar ----------
    async loadList() {
        const j = await this.api('list'); if (!j.ok) return;
        const data = (j.data || []).map(o => [o.cod, o.den, o.cat, o.rub]);
        const self = this;
        if (this.dt) this.dt.clear().rows.add(data).draw();
        else this.dt = $('#grdBuscar').DataTable({ data: data, pageLength: 25, order: [[0, 'asc']],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
            createdRow: function (row, d) { row.addEventListener('click', () => { self.cargar(d[0]); bootstrap.Modal.getInstance(self.el('modalBuscar')).hide(); }); } });
        setTimeout(() => $('#modalBuscar .dataTables_filter input').trigger('focus'), 150);
    },

    // ---------- cargar / poblar ----------
    async cargar(cod) {
        const j = await this.api('get', { cod }); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.cur = cod; this.data = j.data; this.populate(j.data); this.setMode('view');
    },
    populate(d) {
        this.el('fCod').textContent = d.cod;
        this.opts('f_codcat', this.LK.cat, d.codcat);
        this.opts('f_codrub', this.LK.rub, d.codrub); this.fillSub(d.codrub); this.el('f_codsub').value = d.codsub || '';
        { const r = this.LK.rub.find(x => String(x.id) === String(d.codrub)); this.curPunrub = r ? (parseFloat(r.pun) || 0) : 0;
          const s = this.LK.sub.find(x => String(x.id) === String(d.codsub)); this.curPunsub = s ? (parseFloat(s.pun) || 0) : 0; }
        this.opts('f_codlin', this.LK.lin, d.codlin); this.opts('f_codudm', this.LK.udm, d.codudm);
        this.el('f_den').value = d.den; this.el('f_dec').value = d.dec; this.el('f_ubi').value = d.ubi;
        this.el('f_plv').value = (d.plv != null ? d.plv : ''); this.el('f_obs').value = d.obs; this.el('f_dis').checked = !!d.dis;
        // última compra (inputs)
        this.el('f_fuc').value = d.fuc || ''; this.opts('f_codmon', this.LK.mon, d.codmon);
        this.el('f_cot').value = (d.cot != null ? d.cot : ''); this.el('f_cos').value = (d.cos != null ? d.cos : '');
        this.el('f_flt').value = (d.flt != null ? d.flt : ''); this.el('f_plc').value = (d.plc != null ? d.plc : '');
        // precios (recalculados en JS desde PLV/PLC + descuentos de categoría)
        this.recomputePrecios();
        // stock
        this.el('tbStock').innerHTML = d.stock.map(s => `<tr data-suc="${s.suc}">
            <td>${this.esc(s.sucDen)}</td>
            <td><input class="g-min form-control form-control-sm text-end" type="number" step="any" value="${this.esc((s.min || '').replace(/,/g, ''))}"></td>
            <td><input class="g-max form-control form-control-sm text-end" type="number" step="any" value="${this.esc((s.max || '').replace(/,/g, ''))}"></td>
            <td class="text-end">${this.esc(s.ini)}</td><td class="text-end">${this.esc(s.exi)}</td><td class="text-end">${this.esc(s.rmc)}</td><td class="text-end">${this.esc(s.rmv)}</td><td class="text-end fw-bold">${this.esc(s.dsp)}</td></tr>`).join('') || '<tr><td colspan="8" class="text-muted small">Sin stock (servicio).</td></tr>';
        // equiv / prov
        this.el('tbEquiv').innerHTML = ''; d.equiv.forEach(x => this.addEquiv(x));
        this.el('tbProv').innerHTML = ''; d.provs.forEach(x => this.addProv(x));
        this.el('formErr').textContent = '';
        this.applyEnabled();
    },
    clearForm() {
        this.el('fCod').textContent = '(nuevo)';
        ['f_den', 'f_dec', 'f_ubi', 'f_plv', 'f_obs', 'f_fuc', 'f_cos', 'f_flt', 'f_plc'].forEach(i => this.el(i).value = '');
        ['f_codcat', 'f_codrub', 'f_codsub', 'f_codlin', 'f_codudm'].forEach(i => this.el(i).value = '');
        this.el('f_codmon').value = 'P'; this.el('f_cot').value = '1'; this.el('f_dis').checked = false;
        this.curPunrub = 0; this.curPunsub = 0;
        this.el('tbPrecios').innerHTML = ''; this.el('tbStock').innerHTML = ''; this.el('tbEquiv').innerHTML = ''; this.el('tbProv').innerHTML = '';
        this.el('formErr').textContent = '';
    },

    // ---------- grillas ----------
    addEquiv(x) {
        const opts = '<option value="">—</option>' + this.LK.udm.map(u => `<option value="${u.id}"${String(u.id) === String(x.udm || '') ? ' selected' : ''}>${this.esc(u.den)}</option>`).join('');
        const tr = document.createElement('tr');
        tr.innerHTML = `<td><select class="g-udm form-select form-select-sm">${opts}</select></td>
            <td><input class="g-factor form-control form-control-sm text-end" type="number" step="any" value="${this.esc(x.factor || '')}"></td>
            <td><button type="button" class="btn btn-outline-danger btn-sm g-del">&times;</button></td>`;
        this.el('tbEquiv').appendChild(tr);
        tr.querySelector('.g-del').addEventListener('click', () => {
            const base = this.el('f_codudm').value;
            if (base !== '' && tr.querySelector('.g-udm').value === base) { this.toast('No se puede borrar la unidad base del producto.', 'warning'); return; }
            tr.remove();
        });
        this.applyEnabled();
    },
    addProv(x) {
        const tr = document.createElement('tr');
        const den = x.cueDen || '';
        tr.innerHTML = `<td><div class="prov-cue"><input type="hidden" class="g-cue" value="${this.esc(x.cue || '')}">
              <input class="g-prov-ac form-control form-control-sm" autocomplete="off" placeholder="Buscar proveedor…" value="${this.esc(den)}"><div class="ac-list"></div></div></td>
            <td><input class="g-ext form-control form-control-sm" value="${this.esc(x.ext || '')}"></td>
            <td class="small text-muted">${this.esc(x.fecha || '')}${x.moneda ? ' · ' + this.esc(x.moneda) : ''}</td>
            <td class="text-end">${this.esc(x.costo || '')}</td>
            <td><button type="button" class="btn btn-outline-danger btn-sm g-del">&times;</button></td>`;
        this.el('tbProv').appendChild(tr);
        tr.querySelector('.g-del').addEventListener('click', () => tr.remove());
        this.applyEnabled();
    },
    async provSearch(q, inp) {
        const list = inp.parentNode.querySelector('.ac-list');
        if (q.length < 2) { list.classList.remove('show'); return; }
        const r = await this.api('buscar_prov', { q });
        if (!r.ok || !r.data.length) { list.innerHTML = ''; list.classList.remove('show'); return; }
        list.innerHTML = r.data.map(o => `<div class="ac-item" data-id="${o.id}" data-den="${this.esc(o.den)}">${o.cod ? `<span class="ac-code">${this.esc(o.cod)}</span>` : ''}${this.esc(o.den)}</div>`).join('');
        list.querySelectorAll('.ac-item').forEach(it => it.addEventListener('click', () => {
            inp.closest('tr').querySelector('.g-cue').value = it.dataset.id; inp.value = it.dataset.den; list.classList.remove('show');
        }));
        list.classList.add('show');
    },

    // ---------- acciones ----------
    nuevo() { this.cur = null; this.data = null; this.clearForm(); this.setMode('create'); setTimeout(() => this.el('f_codcat').focus(), 80); },
    editar() { if (this.cur) this.setMode('edit'); },
    cancelar() { if (this.cur) this.cargar(this.cur); else { this.clearForm(); this.setMode('idle'); } },

    async guardar() {
        this.el('formErr').textContent = '';
        if (this.mode === 'create') {
            const cod = prompt('Código del producto (ej. MAT0001):'); if (cod === null || cod.trim() === '') { this.el('formErr').textContent = 'Falta el código'; return; }
            this.newCod = cod.trim();
        }
        const equiv = Array.from(this.el('tbEquiv').children).map(tr => ({ udm: tr.querySelector('.g-udm').value, factor: tr.querySelector('.g-factor').value })).filter(x => x.udm);
        const provs = Array.from(this.el('tbProv').children).map(tr => ({ cue: tr.querySelector('.g-cue').value, ext: tr.querySelector('.g-ext').value })).filter(x => x.cue);
        const stock = Array.from(this.el('tbStock').children).filter(tr => tr.dataset.suc).map(tr => ({ suc: tr.dataset.suc, min: tr.querySelector('.g-min').value, max: tr.querySelector('.g-max').value }));
        // validaciones de subforms (como el legacy): sin unidades ni proveedores duplicados
        const udms = equiv.map(x => x.udm); if (new Set(udms).size !== udms.length) { this.el('formErr').textContent = 'Hay unidades de equivalencia duplicadas.'; this.toast('Unidades de equivalencia duplicadas', 'danger'); return; }
        const cues = provs.map(x => x.cue); if (new Set(cues).size !== cues.length) { this.el('formErr').textContent = 'Hay proveedores duplicados.'; this.toast('Proveedores duplicados', 'danger'); return; }
        const fd = new FormData();
        fd.append('__nuevo', this.mode === 'create' ? '1' : '0');
        fd.append('cod', this.mode === 'create' ? this.newCod : this.cur);
        fd.append('codcat', this.el('f_codcat').value); fd.append('den', this.el('f_den').value);
        fd.append('codrub', this.el('f_codrub').value); fd.append('codsub', this.el('f_codsub').value);
        fd.append('codlin', this.el('f_codlin').value); fd.append('codudm', this.el('f_codudm').value);
        fd.append('dec', this.el('f_dec').value || '0'); fd.append('ubi', this.el('f_ubi').value);
        fd.append('plv', this.el('f_plv').value); fd.append('plc', this.el('f_plc').value); fd.append('obs', this.el('f_obs').value);
        fd.append('dis', this.el('f_dis').checked ? '1' : '');
        // última compra (el server solo usa estos en alta, salvo PLC que va siempre)
        fd.append('fuc', this.el('f_fuc').value); fd.append('codmon', this.el('f_codmon').value);
        fd.append('cot', this.el('f_cot').value); fd.append('cos', this.el('f_cos').value); fd.append('flt', this.el('f_flt').value);
        fd.append('equiv', JSON.stringify(equiv)); fd.append('provs', JSON.stringify(provs)); fd.append('stock', JSON.stringify(stock));
        const j = await (await fetch('api.php?action=save', { method: 'POST', body: fd })).json();
        if (!j.ok) { this.el('formErr').textContent = j.error || 'Error al guardar'; this.toast(j.error || 'Error', 'danger'); return; }
        this.toast('Guardado', 'success'); this.cargar(j.data.cod);
    },

    async eliminar() {
        if (!this.cur) return;
        if (!await this.confirm('¿Eliminar el producto ' + this.cur + '?')) return;
        const fd = new FormData(); fd.append('cod', this.cur);
        const j = await (await fetch('api.php?action=delete', { method: 'POST', body: fd })).json();
        if (!j.ok) { this.toast(j.error || 'No se pudo eliminar', 'danger'); return; }
        this.toast('Eliminado', 'success'); this.cur = null; this.clearForm(); this.setMode('idle');
    },

    // ---------- modo ----------
    setMode(mode) {
        this.mode = mode;
        const editing = (mode === 'create' || mode === 'edit');
        if (!this.RO) {
            this.el('btnNuevo').disabled = editing; this.el('btnGuardar').disabled = !editing;
            this.el('btnCancelar').disabled = (mode === 'idle');
            this.el('btnEditar').disabled = (mode !== 'view'); this.el('btnEliminar').disabled = (mode !== 'view');
        }
        this.el('mainForm').classList.toggle('mode-view', !editing);
        this.applyEnabled();
    },
    applyEnabled() {
        const editing = (this.mode === 'create' || this.mode === 'edit') && !this.RO;
        const create = (this.mode === 'create') && !this.RO;
        // categoría solo editable al crear
        this.el('f_codcat').disabled = !create;
        ['f_den', 'f_codrub', 'f_codsub', 'f_codlin', 'f_codudm', 'f_dec', 'f_ubi', 'f_plv', 'f_obs', 'f_dis'].forEach(i => this.el(i).disabled = !editing);
        // última compra: editable solo al ALTA; PLC (compra) y PLV (venta) también en edición; cotización off si pesos
        ['f_fuc', 'f_codmon', 'f_cos', 'f_flt'].forEach(i => this.el(i).disabled = !create);
        this.el('f_cot').disabled = !create || this.el('f_codmon').value === 'P';
        this.el('f_plc').disabled = !editing;
        document.querySelectorAll('.prod-add').forEach(b => b.disabled = !editing);
        document.querySelectorAll('#tbEquiv .g-udm, #tbEquiv .g-factor, #tbProv .g-ext, #tbStock .g-min, #tbStock .g-max').forEach(x => x.disabled = !editing);
        // proveedor autocomplete: solo en filas nuevas (sin cue ya fijado); las existentes quedan fijas
        document.querySelectorAll('#tbProv tr').forEach(tr => { const ac = tr.querySelector('.g-prov-ac'); if (ac) ac.disabled = !editing || tr.querySelector('.g-cue').value !== ''; });
        document.querySelectorAll('.g-del').forEach(x => x.style.display = editing ? '' : 'none');
    },

    // ---------- utils ----------
    async api(action, params = {}) { const url = new URL('api.php', window.location.href); url.searchParams.set('action', action); for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v); return await (await fetch(url)).json(); },
    toast(msg, type = 'info') { const t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + type; bootstrap.Toast.getOrCreateInstance(t, { delay: type === 'danger' ? 7000 : 3000 }).show(); },
    confirm(message) { return new Promise(resolve => { const me = this.el('modalConfirm'); this.el('confirmBody').textContent = message; const modal = bootstrap.Modal.getOrCreateInstance(me); let done = false; const ok = this.el('btnConfirmOk'); const clean = () => { ok.removeEventListener('click', okH); me.removeEventListener('hidden.bs.modal', hidH); }; const okH = () => { if (done) return; done = true; clean(); modal.hide(); resolve(true); }; const hidH = () => { if (done) return; done = true; clean(); resolve(false); }; ok.addEventListener('click', okH); me.addEventListener('hidden.bs.modal', hidH); modal.show(); }); },
};
document.addEventListener('DOMContentLoaded', () => PR.init());
