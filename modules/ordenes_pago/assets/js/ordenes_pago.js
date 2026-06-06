/* Órdenes de Pago (acreedores) — carga. Espejo de recibos: proveedor + referencias + retención IIBB
   + cheques (cartera endosados / propios) + efectivo plug + totales. Importes punto-decimal. */
const OP = {
    modo: window.OP_MODO || 'operador',
    refs: [], chqs: [], regs: [], seq: 0, rix: 0, efe: 0, total: 0, viewNum: null,
    dt: null, dtPend: null, dtCart: null,

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    n(v) { var x = parseFloat(v); return isNaN(x) ? '0.00' : x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        return await (await fetch(url, opts || {})).json();
    },

    async init() {
        this.el('fexmov').value = new Date().toISOString().slice(0, 10);
        this.el('saldo').value = '0.00';
        var ops = await this.api('operaciones'); if (ops.ok) this.el('codaux').innerHTML = ops.data.map(function (o) { return '<option value="' + o.CODAUX + '">' + OP.esc(o.DENAUX) + '</option>'; }).join('');
        if (this.modo !== 'capacitacion') { var p = await this.api('pdvs'); if (p.ok && p.data.length) this.el('cipmov').value = p.data[0].CODPDV; }

        this.autocomplete(this.el('provQ'), this.el('provList'), 'buscar_proveedores', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { OP.pickProveedor(o.CODCUE); });
        this.el('btnAddRef').addEventListener('click', function () { OP.abrirPendientes(); });
        this.el('btnAddCart').addEventListener('click', function () { OP.abrirCartera(); });
        this.el('btnAddChq').addEventListener('click', function () { OP.addCheque(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnGuardar').addEventListener('click', function () { OP.guardar(); });
        this.el('rip').addEventListener('input', function () { OP.recalc(); });
        this.el('arb').addEventListener('input', function () { OP.recalc(); });
        this.el('aiamov').addEventListener('input', function () { OP.recalc(); });
        this.el('efectivo').addEventListener('input', function () { OP.recalc(); });
        this.el('codfdp').addEventListener('change', function () { OP.el('lblEfeOp').textContent = (this.value == '5') ? 'Importe' : 'Efectivo'; });   // interdepósito (como el legacy, solo relabel)
        this.el('siamov').addEventListener('change', function () { OP.onSia(); });
        this.el('btnBuscar').addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(OP.el('modalBuscar')).show(); });
        this.el('modalBuscar').addEventListener('shown.bs.modal', function () { OP.loadList(); });
        this.el('opBuscarGo').addEventListener('click', function () { OP.loadList(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (OP.viewNum) OP.anular(OP.viewNum); });
        this.el('btnImprimirHdr').addEventListener('click', function () { if (OP.viewNum) window.open('imprimir.php?nummov=' + OP.viewNum, '_blank'); });
    },

    async pickProveedor(codcue) {
        var j = await this.api('get_proveedor', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data;
        this.el('codcue').value = codcue; this.el('provQ').value = d.DENCUE;
        this.saldoNum = parseFloat(d.SALDO) || 0;
        this.el('saldo').value = this.n(d.SALDO);
        this.el('provInfo').textContent = [d.CITMOV || d.CITCUE, d.DOMICILIO, d.LOCALIDAD].filter(Boolean).join(' · ');
        // operación auto: saldo<0 (le debemos) → cancelación 342; ≥0 → anticipo 341
        this.el('codaux').value = (this.saldoNum < 0) ? '342' : '341';
        this.el('btnAddRef').disabled = (this.el('codaux').value != '342');
        // retención IIBB: régimen + alícuota del proveedor (el padrón ARBA la pisaría; pendiente)
        this.el('codrri').value = d.CODRRI || '';
        this.el('arb').value = d.SUJETO ? (d.ALIRRI || 0) : 0;
        this.el('retReg').textContent = d.SUJETO ? (d.DENRRI ? '(' + d.DENRRI + ')' : '') : '(no sujeto)';
        // alícuota IVA para netear (SIAMOV/AIAMOV) — de la categoría del proveedor
        this.aiaDefault = d.AIAMOV || 0; this.aiaEdit = !!d.AIAEDIT;
        this.el('siamov').checked = false; this.onSia();
        this.refs = []; this.el('refBody').innerHTML = ''; this.recalc();
    },

    // ---- Referencias ----
    async abrirPendientes() {
        var j = await this.api('pendientes', { codcue: this.el('codcue').value }); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var used = this.refs.map(function (r) { return r.refmov + '|' + r.fvxiso; });
        var rows = j.data.filter(function (p) { return used.indexOf(p.REFMOV + '|' + p.FVXISO) < 0; })
            .map(function (p) { return [OP.esc(p.COMP), OP.esc(p.FEXMOV), OP.esc(p.FVXMOV), '<span class="d-block text-end" data-order="' + p.SALDO + '">' + OP.n(p.SALDO) + '</span>', JSON.stringify(p)]; });
        if (!this.dtPend) this.dtPend = $('#grdPend').DataTable({ autoWidth: false, columns: [{ title: 'Comprobante' }, { title: 'Emisión' }, { title: 'Vencimiento' }, { title: 'Saldo', className: 'text-end' }, { visible: false }], pageLength: 10, order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }, createdRow: function (row, d) { row.addEventListener('click', function () { OP.addRef(JSON.parse(d[4])); bootstrap.Modal.getInstance(OP.el('modalPend')).hide(); }); } });
        this.dtPend.clear().rows.add(rows).draw();
        bootstrap.Modal.getOrCreateInstance(this.el('modalPend')).show();
    },
    addRef(p) {
        var rec = { refmov: p.REFMOV, fvxiso: p.FVXISO, comp: p.COMP, saldo: p.SALDO, imp: p.SALDO };
        this.refs.push(rec);
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + this.esc(p.COMP) + '</td><td>' + this.esc(p.FVXMOV) + '</td><td class="op-num">' + this.n(p.SALDO) + '</td>' +
            '<td><input type="number" step="0.01" class="form-control form-control-sm op-num r-imp" value="' + p.SALDO.toFixed(2) + '"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger r-del"><i class="bi bi-x"></i></button></td>';
        this.el('refBody').appendChild(tr);
        tr.querySelector('.r-imp').addEventListener('input', function () { rec.imp = parseFloat(this.value) || 0; OP.recalc(); });
        tr.querySelector('.r-del').addEventListener('click', function () { tr.remove(); OP.refs = OP.refs.filter(function (x) { return x !== rec; }); OP.recalc(); });
        this.recalc();
    },

    // ---- Cheques de cartera (endoso) ----
    async abrirCartera() {
        var j = await this.api('cartera'); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var used = this.chqs.filter(function (c) { return c.codchq; }).map(function (c) { return c.codchq; });
        var rows = j.data.filter(function (c) { return used.indexOf(c.CODCHQ) < 0; })
            .map(function (c) { return [OP.esc(c.BANCO), OP.esc(c.SYN), OP.esc(c.FAX), OP.esc(c.LIB), '<span class="d-block text-end" data-order="' + c.IMP + '">' + OP.n(c.IMP) + '</span>', JSON.stringify(c)]; });
        if (!this.dtCart) this.dtCart = $('#grdCart').DataTable({ autoWidth: false, columns: [{ title: 'Banco' }, { title: 'Serie-Nº' }, { title: 'Acred.' }, { title: 'Librador' }, { title: 'Importe', className: 'text-end' }, { visible: false }], pageLength: 10, order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }, createdRow: function (row, d) { row.addEventListener('click', function () { OP.addCartera(JSON.parse(d[5])); bootstrap.Modal.getInstance(OP.el('modalCart')).hide(); }); } });
        this.dtCart.clear().rows.add(rows).draw();
        bootstrap.Modal.getOrCreateInstance(this.el('modalCart')).show();
    },
    addCartera(c) {
        var rec = { codchq: c.CODCHQ, codcue: '11103', banco: c.BANCO, syn: c.SYN, fex: c.FEX, fax: c.FAX, lib: c.LIB, imp: c.IMP };
        this.chqs.push(rec);
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + this.esc(c.BANCO) + ' <span class="badge bg-secondary">cartera</span></td><td>' + this.esc(c.SYN) + '</td><td>' + this.esc(c.FEX) + '</td><td>' + this.esc(c.FAX) + '</td><td>' + this.esc(c.LIB) + '</td>' +
            '<td class="op-num">' + this.n(c.IMP) + '</td><td><button type="button" class="btn btn-sm btn-outline-danger c-del"><i class="bi bi-x"></i></button></td>';
        this.el('chqBody').appendChild(tr);
        tr.querySelector('.c-del').addEventListener('click', function () { tr.remove(); OP.chqs = OP.chqs.filter(function (x) { return x !== rec; }); OP.recalc(); });
        this.recalc();
    },
    // ---- Cheque propio ----
    addCheque() {
        var rec = { codchq: null, codcue: '11103', codban: '', syn: '', fexiso: '', faxiso: '', lib: '', imp: 0 };
        this.chqs.push(rec);
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input class="form-control form-control-sm c-ban" placeholder="Banco propio"></td>' +
            '<td><input class="form-control form-control-sm c-syn"></td>' +
            '<td><input type="date" class="form-control form-control-sm c-fex"></td>' +
            '<td><input type="date" class="form-control form-control-sm c-fax"></td>' +
            '<td><input class="form-control form-control-sm c-lib"></td>' +
            '<td><input type="number" step="0.01" class="form-control form-control-sm op-num c-imp"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger c-del"><i class="bi bi-x"></i></button></td>';
        this.el('chqBody').appendChild(tr);
        tr.querySelector('.c-ban').addEventListener('input', function () { rec.codbanTxt = this.value; });
        tr.querySelector('.c-syn').addEventListener('input', function () { rec.syn = this.value; });
        tr.querySelector('.c-fex').addEventListener('input', function () { rec.fexiso = this.value; });
        tr.querySelector('.c-fax').addEventListener('input', function () { rec.faxiso = this.value; });
        tr.querySelector('.c-lib').addEventListener('input', function () { rec.lib = this.value; });
        tr.querySelector('.c-imp').addEventListener('input', function () { rec.imp = parseFloat(this.value) || 0; OP.recalc(); });
        tr.querySelector('.c-del').addEventListener('click', function () { tr.remove(); OP.chqs = OP.chqs.filter(function (x) { return x !== rec; }); OP.recalc(); });
    },

    // Tilde SIAMOV: trae la alícuota IVA del proveedor (o editable si la categoría no tiene).
    onSia() {
        var on = this.el('siamov').checked;
        if (on) { this.el('aiamov').value = this.aiaDefault || 0; this.el('aiamov').disabled = !this.aiaEdit; }
        else { this.el('aiamov').value = 0; this.el('aiamov').disabled = true; }
        this.recalc();
    },
    recalc() {
        var refTot = this.refs.reduce(function (s, r) { return s + (r.imp || 0); }, 0);
        var total = Math.round(refTot * 100) / 100;   // cancelación: total = Σreferencias
        this.total = total;
        // Base de la retención IIBB: con el tilde, se netea desde el total con la alícuota IVA
        // (total / (1+alícuota/100)); sin tilde, base manual.
        var base;
        if (this.el('siamov').checked) {
            var aia = parseFloat(this.el('aiamov').value) || 0;
            base = Math.round((total / (1 + aia / 100)) * 100) / 100;
            this.el('rip').value = base.toFixed(2); this.el('rip').readOnly = true;
        } else { base = parseFloat(this.el('rip').value) || 0; this.el('rip').readOnly = false; }
        var arb = parseFloat(this.el('arb').value) || 0;
        this.rix = Math.round(base * arb) / 100; this.rix = Math.round(this.rix * 100) / 100;
        this.el('rix').textContent = this.n(this.rix);
        var chqTot = this.chqs.reduce(function (s, c) { return s + (c.imp || 0); }, 0);
        var neto = Math.round((total - this.rix) * 100) / 100;
        var efe = Math.max(0, Math.round((neto - chqTot) * 100) / 100);
        this.efe = efe;
        this.el('tEfectivo').textContent = this.n(efe);
        this.el('tCheques').textContent = this.n(chqTot);
        this.el('tNeto').textContent = this.n(neto);
        this.el('tTotal').textContent = this.n(total);
        this.el('refTotal').textContent = this.n(refTot);
        this.el('chqTotal').textContent = this.n(chqTot);
    },
    compRet() { this.recalc(); },

    async guardar() {
        this.el('opErr').textContent = '';
        this.recalc();
        if (!this.el('codcue').value) { this.el('opErr').textContent = 'Elegí un proveedor.'; return; }
        if (this.el('codaux').value == '342' && !this.refs.length) { this.el('opErr').textContent = 'Agregá al menos un comprobante a pagar.'; return; }
        if (this.total <= 0) { this.el('opErr').textContent = 'La orden no tiene importe.'; return; }
        if (this.el('rip').value > 0 && !this.el('codrri').value) { this.el('opErr').textContent = 'Elegí el régimen de la retención.'; return; }
        var data = {
            codcue: this.el('codcue').value, codaux: this.el('codaux').value, fexmov: this.el('fexmov').value, efectivo: this.efe,
            detmov: this.el('detmov').value, cipmov: (this.modo === 'capacitacion') ? 9999 : (this.el('cipmov').value || 1),
            codfdp: this.el('codfdp').value, totmov: this.total,
            referencias: this.refs.map(function (r) { return { refmov: r.refmov, fvxmov: r.fvxiso, imp: r.imp }; }),
            cheques: this.chqs.filter(function (c) { return c.imp > 0; }).map(function (c) {
                return c.codchq ? { codcue: '11103', codchq: c.codchq, imp: c.imp }
                    : { codcue: '11103', codban: c.codban || 0, syn: c.syn, fex: c.fexiso, fax: c.faxiso, plz: 0, lib: c.lib, cit: '', loc: '', imp: c.imp };
            }),
            ret: { rix: this.rix, pid: parseFloat(this.el('rip').value) || 0, arb: parseFloat(this.el('arb').value) || 0, codrri: this.el('codrri').value || 0, rid: 0, vei: 0, sri: 1, sia: this.el('siamov').checked ? 1 : 0, aia: parseFloat(this.el('aiamov').value) || 0 }
        };
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        this.el('btnGuardar').disabled = true;
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        if (!j.ok) { this.el('btnGuardar').disabled = false; this.el('opErr').textContent = j.error; return; }
        var nro = '0000-' + String(j.data.cinmov).padStart(8, '0');
        this.el('nummov').value = String(j.data.nummov).padStart(8, '0');
        this.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
        if (j.data.rinmov) this.el('rinDisp').value = j.data.rinmov;
        this.toast('Orden de pago grabada · Movimiento ' + String(j.data.nummov).padStart(8, '0') + ' · Nº ' + nro, 'success');
        window.open('imprimir.php?nummov=' + j.data.nummov + '&print=1', '_blank');
    },

    // ---- Buscar / vista ----
    async loadList() {
        var p = { q: this.el('opBuscarQ').value.trim() };
        if (this.el('opBuscarD').value) p.desde = this.el('opBuscarD').value;
        if (this.el('opBuscarH').value) p.hasta = this.el('opBuscarH').value;
        var j = await this.api('listar', p); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var rows = j.data.ordenes.map(function (r) {
            var est = r.ANU ? '<span class="badge bg-danger">Anulada</span>' : '<span class="badge bg-success">OK</span>';
            return ['<span data-order="' + r.FEXMOVO + '">' + OP.esc(r.FEXMOV) + '</span>', OP.esc(r.COMP), OP.esc(r.DENMOV), '<span data-order="' + r.TOTMOV + '" class="d-block text-end fw-medium">' + OP.n(r.TOTMOV) + '</span>', est, r.NUMMOV];
        });
        if (!this.dt) this.dt = $('#grdOp').DataTable({ autoWidth: false, pageLength: 15, columns: [{ title: 'Fecha' }, { title: 'Comprobante' }, { title: 'Proveedor' }, { title: 'Total', className: 'text-end' }, { title: 'Estado' }, { visible: false }], columnDefs: [{ targets: [0, 3], type: 'num' }], order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }, createdRow: function (row, d) { row.addEventListener('click', function () { OP.verOrden(d[5]); }); } });
        this.dt.clear().rows.add(rows).draw();
        if (j.data.tope) this.toast('Mostrando las 200 más recientes; afiná el filtro.', 'info');
    },
    async verOrden(num) {
        var j = await this.api('detalle', { nummov: num }); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data; this.viewNum = num;
        var bm = bootstrap.Modal.getInstance(this.el('modalBuscar')); if (bm) bm.hide();
        this.el('nummov').value = String(d.NUMMOV).padStart(8, '0');
        this.el('cinmov').value = String(d.CINMOV).padStart(8, '0');
        this.el('fexmov').value = d.FEXISO;
        this.el('codaux').value = d.CODAUX; this.el('codcue').value = d.CODCUE; this.el('provQ').value = d.DENMOV;
        this.el('provInfo').textContent = [d.CITMOV].filter(Boolean).join(' · ');
        this.el('saldo').value = this.n(d.SOCMOV); this.el('detmov').value = d.DETMOV; this.el('codfdp').value = d.CODFDP;
        this.el('codrri').value = d.CODRRI || ''; this.el('arb').value = d.ARBMOV || 0;
        this.rix = d.RIXMOV; this.el('rix').textContent = this.n(d.RIXMOV); this.el('rinDisp').value = d.RINMOV || '';
        this.refs = [];
        this.el('refBody').innerHTML = d.referencias.map(function (r) { return '<tr><td>' + OP.esc(r.COMP) + '</td><td>' + OP.esc(r.FVXMOV) + '</td><td class="op-num">' + OP.n(r.IMP) + '</td><td class="op-num">' + OP.n(r.IMP) + '</td><td></td></tr>'; }).join('');
        this.el('refTotal').textContent = this.n(d.referencias.reduce(function (s, r) { return s + r.IMP; }, 0));
        this.chqs = [];
        var chqTot = d.cheques.reduce(function (s, c) { return s + c.IMP; }, 0);
        this.el('chqBody').innerHTML = d.cheques.map(function (c) { return '<tr><td>' + OP.esc(c.BANCO) + '</td><td>' + OP.esc(c.SYN) + '</td><td></td><td>' + OP.esc(c.FAX) + '</td><td>' + OP.esc(c.LIB) + '</td><td class="op-num">' + OP.n(c.IMP) + '</td><td></td></tr>'; }).join('');
        this.el('chqTotal').textContent = this.n(chqTot);
        var ret = d.RIXMOV, neto = Math.round((d.TOTMOV - ret) * 100) / 100, efe = Math.round((neto - chqTot) * 100) / 100;
        this.el('tEfectivo').textContent = this.n(efe); this.el('tCheques').textContent = this.n(chqTot);
        this.el('tNeto').textContent = this.n(neto); this.el('tTotal').textContent = this.n(d.TOTMOV);
        this.lockForm(true, d.ANU);
    },
    lockForm(locked, anulado) {
        this.viewMode = locked;
        Array.prototype.forEach.call(document.querySelectorAll('#opForm input, #opForm select, #opForm textarea'), function (el) { el.disabled = locked; });
        this.el('btnAddRef').style.display = locked ? 'none' : '';
        this.el('btnAddCart').style.display = locked ? 'none' : ''; this.el('btnAddChq').style.display = locked ? 'none' : '';
        this.el('btnGuardar').style.display = locked ? 'none' : '';
        this.el('btnImprimirHdr').style.display = locked ? '' : 'none';
        this.el('btnAnularHdr').style.display = (locked && !anulado) ? '' : 'none';
    },
    async anular(num) {
        if (!confirm('¿Anular la orden de pago? Se revierte el asiento, los comprobantes pagados y los cheques. No se puede deshacer.')) return;
        var fd = new FormData(); fd.append('action', 'anular'); fd.append('nummov', num);
        var j = await this.api('anular', {}, { method: 'POST', body: fd });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.toast('Orden ' + num + ' anulada.', 'success'); this.verOrden(num);
    },

    autocomplete(input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() { list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + OP.esc(label(o)) + '</div>'; }).join(''); list.classList.toggle('show', items.length > 0); }
        input.addEventListener('input', function () { clearTimeout(t); var q = input.value.trim(); if (q.length < 1) { items = []; render(); return; } t = setTimeout(async function () { var j = await OP.api(action, { q: q }); items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }, 180); });
        input.addEventListener('keydown', function (e) { if (!list.classList.contains('show')) return; if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); } else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); } else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); onPick(items[hi]); list.classList.remove('show'); } } else if (e.key === 'Escape') list.classList.remove('show'); });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },
    toast(msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 6000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { OP.init(); });
