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
        this.rixOff = !!window.OP_RIXOFF;   // switch global Rec Control: empresa NO retiene IIBB
        this.el('fexmov').value = new Date().toISOString().slice(0, 10);
        this.el('cef').value = this.el('fexmov').value;   // comprobante proveedor: emisión = hoy por defecto
        this.el('txtfax').value = this.el('fexmov').value; // interdepósito: acreditación = emisión por defecto
        this.el('saldo').value = '0.00';
        if (this.rixOff) {   // agente de retención IIBB desactivado → bloquear la sección
            ['siamov', 'rip', 'arb', 'aiamov'].forEach(function (id) { OP.el(id).disabled = true; });
            this.el('retReg').textContent = '— desactivada (Rec Control)'; this.el('retReg').className = 'text-warning';
        }
        var ops = await this.api('operaciones'); if (ops.ok) this.el('codaux').innerHTML = ops.data.map(function (o) { return '<option value="' + o.CODAUX + '">' + OP.esc(o.DENAUX) + '</option>'; }).join('');
        var cb = await this.api('cuentas_bancarias'); if (cb.ok) this.el('codcbx').innerHTML = '<option value="">Cuenta…</option>' + cb.data.map(function (x) { return '<option value="' + x.CODCBX + '">' + OP.esc(x.DENCUE) + '</option>'; }).join('');
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
        this.el('aiamov').addEventListener('blur', function () { if (this.value !== '') this.value = (parseFloat(this.value) || 0).toFixed(2); });
        this.el('cep').addEventListener('blur', function () { this.value = String(parseInt(this.value, 10) || 0).padStart(4, '0'); });
        this.el('cen').addEventListener('blur', function () { this.value = String(parseInt(this.value, 10) || 0).padStart(8, '0'); });
        this.el('efectivo').addEventListener('input', function () { OP.recalc(); });
        this.el('codfdp').addEventListener('change', function () {   // interdepósito (CODFDP=5): habilita cuenta bancaria + acreditación
            var inter = this.value == '5';
            OP.el('lblEfeOp').textContent = inter ? 'Importe' : 'Efectivo';
            OP.el('codcbx').disabled = !inter; OP.el('txtfax').disabled = !inter;
            if (!inter) OP.el('codcbx').value = '';
        });
        this.el('siamov').addEventListener('change', function () { OP.onSia(); });
        this.el('btnBuscar').addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(OP.el('modalBuscar')).show(); });
        this.el('modalBuscar').addEventListener('shown.bs.modal', function () { OP.loadList(); });
        this.el('opBuscarGo').addEventListener('click', function () { OP.loadList(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (OP.viewNum) OP.anular(OP.viewNum); });
        this.el('btnImprimirHdr').addEventListener('click', function () { if (OP.viewNum) window.open('imprimir.php?nummov=' + OP.viewNum, '_blank'); });
        this.el('btnConstanciaHdr').addEventListener('click', function () { if (OP.viewNum) window.open('constancia.php?nummov=' + OP.viewNum, '_blank'); });
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
        // Retención IIBB: el switch global (RIXCDC) manda; si está activo, la exención del proveedor
        // (VEICUE). Retiene sólo si !rixOff && !exento && sujeto. (CODCUE_AfterUpdate del legacy.)
        this.exento = !!d.EXENTO; this.veiIso = d.VEIISO || '';
        this.provSri = (d.SRICUE === true || d.SRICUE === 1); this.provCodrri = d.CODRRI || 0;   // para validar al grabar
        var retDis = function (off, note, cls) {
            ['siamov', 'rip', 'arb'].forEach(function (id) { OP.el(id).disabled = off; });
            OP.el('retReg').textContent = note; OP.el('retReg').className = cls || 'text-info';
            if (off) { OP.el('codrri').value = ''; OP.el('arb').value = 0; }
        };
        if (this.rixOff) { /* ya desactivada en init */ }
        else if (this.exento) retDis(true, '(exento hasta ' + d.VEIMOV + ')', 'text-info');
        else {
            retDis(false, d.SUJETO ? (d.DENRRI ? '(' + d.DENRRI + ')' : '') : '(no sujeto)', 'text-info');
            this.el('codrri').value = d.CODRRI || '';
            this.el('arb').value = d.SUJETO ? (d.ALIRRI || 0) : 0;
        }
        // alícuota IVA para netear (SIAMOV/AIAMOV) — de la categoría del proveedor
        this.aiaDefault = d.AIAMOV || 0; this.aiaEdit = !!d.AIAEDIT;
        this.el('siamov').checked = false; this.onSia();
        this.refs = []; this.el('refBody').innerHTML = ''; this.recalc();
    },

    // ---- Referencias ----
    async abrirPendientes() {
        var j = await this.api('pendientes', { codcue: this.el('codcue').value }); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var used = this.refs.map(function (r) { return r.refmov + '|' + r.fvxiso; });
        var sub = function (a, b) { return OP.esc(a) + (b ? '<br><span class="text-muted small">' + OP.esc(b) + '</span>' : ''); };
        var rows = j.data.filter(function (p) { return used.indexOf(p.REFMOV + '|' + p.FVXISO) < 0; })
            .map(function (p) { return [OP.esc(p.FVXMOV), sub(p.INT, p.INTFEX), sub(p.EXT, p.EXTFEX), OP.esc(p.DETMOV), '<span class="d-block text-end" data-order="' + p.SALDO + '">' + OP.n(p.SALDO) + '</span>', JSON.stringify(p)]; });
        if (!this.dtPend) this.dtPend = $('#grdPend').DataTable({ autoWidth: false, columns: [{ title: 'Vencimiento' }, { title: 'Comp. Interno' }, { title: 'Comp. Externo' }, { title: 'Detalle' }, { title: 'Saldo', className: 'text-end' }, { visible: false }], pageLength: 10, order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }, createdRow: function (row, d) { row.addEventListener('click', function () { OP.addRef(JSON.parse(d[5])); bootstrap.Modal.getInstance(OP.el('modalPend')).hide(); }); } });
        this.dtPend.clear().rows.add(rows).draw();
        bootstrap.Modal.getOrCreateInstance(this.el('modalPend')).show();
    },
    addRef(p) {
        var rec = { refmov: p.REFMOV, fvxiso: p.FVXISO, comp: p.EXT, saldo: p.SALDO, imp: p.SALDO };
        this.refs.push(rec);
        var sub = function (a, b) { return OP.esc(a) + (b ? '<br><span class="text-muted small">' + OP.esc(b) + '</span>' : ''); };
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + this.esc(p.FVXMOV) + '</td><td>' + sub(p.INT, p.INTFEX) + '</td><td>' + sub(p.EXT, p.EXTFEX) + '</td><td>' + this.esc(p.DETMOV) + '</td><td class="op-num">' + this.n(p.SALDO) + '</td>' +
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
            .map(function (c) { return [OP.esc(c.CUENTA), OP.esc(c.BANCO), OP.esc(c.SYN), OP.esc(c.FAX), OP.esc(c.LIB), '<span class="d-block text-end" data-order="' + c.IMP + '">' + OP.n(c.IMP) + '</span>', JSON.stringify(c)]; });
        if (!this.dtCart) this.dtCart = $('#grdCart').DataTable({ autoWidth: false, columns: [{ title: 'Cuenta' }, { title: 'Banco' }, { title: 'Serie-Nº' }, { title: 'Acred.' }, { title: 'Librador' }, { title: 'Importe', className: 'text-end' }, { visible: false }], pageLength: 10, order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }, createdRow: function (row, d) { row.addEventListener('click', function () { OP.addCartera(JSON.parse(d[6])); bootstrap.Modal.getInstance(OP.el('modalCart')).hide(); }); } });
        this.dtCart.clear().rows.add(rows).draw();
        bootstrap.Modal.getOrCreateInstance(this.el('modalCart')).show();
    },
    addCartera(c) {
        var rec = { codchq: c.CODCHQ, codcue: '11103', cuenta: c.CUENTA, banco: c.BANCO, syn: c.SYN, fex: c.FEX, plz: c.PLZ, fax: c.FAX, lib: c.LIB, cit: c.CIT, loc: c.LOC, imp: c.IMP };
        this.chqs.push(rec);
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + this.esc(c.CUENTA) + '</td><td>' + this.esc(c.BANCO) + ' <span class="badge bg-secondary">cartera</span></td><td>' + this.esc(c.SYN) + '</td><td>' + this.esc(c.FEX) + '</td><td class="text-center">' + this.esc(c.PLZ) + '</td><td>' + this.esc(c.FAX) + '</td><td>' + this.esc(c.LIB) + '</td><td>' + this.esc(c.CIT) + '</td><td>' + this.esc(c.LOC) + '</td><td class="op-num">' + this.n(c.IMP) + '</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger c-del"><i class="bi bi-x"></i></button></td>';
        this.el('chqBody').appendChild(tr);
        tr.querySelector('.c-del').addEventListener('click', function () { tr.remove(); OP.chqs = OP.chqs.filter(function (x) { return x !== rec; }); OP.recalc(); });
        this.recalc();
    },
    // ---- Cheque propio ----
    addCheque() {
        var rec = { codchq: null, codcue: '11103', codbanTxt: '', syn: '', fexiso: '', plz: 0, faxiso: '', lib: '', cit: '', loc: '', imp: 0 };
        this.chqs.push(rec);
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input class="form-control form-control-sm" value="VALORES A DEPOSITAR" readonly></td>' +
            '<td><input class="form-control form-control-sm c-ban" placeholder="Banco"></td>' +
            '<td><input class="form-control form-control-sm c-syn"></td>' +
            '<td><input type="date" class="form-control form-control-sm c-fex"></td>' +
            '<td><input type="number" class="form-control form-control-sm op-num c-plz" value="0"></td>' +
            '<td><input type="date" class="form-control form-control-sm c-fax"></td>' +
            '<td><input class="form-control form-control-sm c-lib"></td>' +
            '<td><input class="form-control form-control-sm c-cit"></td>' +
            '<td><input class="form-control form-control-sm c-loc"></td>' +
            '<td><input type="number" step="0.01" class="form-control form-control-sm op-num c-imp"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger c-del"><i class="bi bi-x"></i></button></td>';
        this.el('chqBody').appendChild(tr);
        tr.querySelector('.c-ban').addEventListener('input', function () { rec.codbanTxt = this.value; });
        tr.querySelector('.c-syn').addEventListener('input', function () { rec.syn = this.value; });
        tr.querySelector('.c-fex').addEventListener('input', function () { rec.fexiso = this.value; });
        tr.querySelector('.c-plz').addEventListener('input', function () { rec.plz = parseInt(this.value, 10) || 0; });
        tr.querySelector('.c-fax').addEventListener('input', function () { rec.faxiso = this.value; });
        tr.querySelector('.c-lib').addEventListener('input', function () { rec.lib = this.value; });
        tr.querySelector('.c-cit').addEventListener('input', function () { rec.cit = this.value; });
        tr.querySelector('.c-loc').addEventListener('input', function () { rec.loc = this.value; });
        tr.querySelector('.c-imp').addEventListener('input', function () { rec.imp = parseFloat(this.value) || 0; OP.recalc(); });
        tr.querySelector('.c-del').addEventListener('click', function () { tr.remove(); OP.chqs = OP.chqs.filter(function (x) { return x !== rec; }); OP.recalc(); });
    },

    // Tilde SIAMOV: trae la alícuota IVA del proveedor (o editable si la categoría no tiene).
    onSia() {
        var on = this.el('siamov').checked;
        if (on) { this.el('aiamov').value = (parseFloat(this.aiaDefault) || 0).toFixed(2); this.el('aiamov').disabled = !this.aiaEdit; }
        else { this.el('aiamov').value = ''; this.el('aiamov').disabled = true; }   // null cuando no está tildado
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
        if (this.rixOff || this.exento) this.rix = 0;   // agente desactivado (Rec Control) o proveedor exento (VEICUE)
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
        // Validaciones de retención IIBB (CODCUE_BeforeUpdate del legacy), sólo si la empresa retiene.
        if (!this.rixOff) {
            if (!this.exento && this.provSri && !this.provCodrri) { this.el('opErr').textContent = 'Régimen de Retención Ingresos Brutos inexistente para el proveedor.'; return; }
            if (this.exento && this.veiIso && this.el('fexmov').value > this.veiIso) { this.el('opErr').textContent = 'Vencimiento de exención de Retención Ingresos Brutos inconsistente: la exención venció (' + this.veiIso.split('-').reverse().join('/') + ') antes de la fecha de la orden.'; return; }
        }
        var data = {
            codcue: this.el('codcue').value, codaux: this.el('codaux').value, fexmov: this.el('fexmov').value, efectivo: this.efe,
            detmov: this.el('detmov').value, cipmov: (this.modo === 'capacitacion') ? 9999 : (this.el('cipmov').value || 1),
            codfdp: this.el('codfdp').value, codcbx: this.el('codcbx').value || '', fax: this.el('txtfax').value || '', totmov: this.total,
            cec: this.el('cec').value || 'RC', cep: parseInt(this.el('cep').value, 10) || 0, cen: parseInt(this.el('cen').value, 10) || 0, cef: this.el('cef').value || this.el('fexmov').value,
            referencias: this.refs.map(function (r) { return { refmov: r.refmov, fvxmov: r.fvxiso, imp: r.imp }; }),
            cheques: this.chqs.filter(function (c) { return c.imp > 0; }).map(function (c) {
                return c.codchq ? { codcue: '11103', codchq: c.codchq, imp: c.imp }
                    : { codcue: '11103', codban: 0, syn: c.syn, fex: c.fexiso, fax: c.faxiso, plz: c.plz || 0, lib: c.lib, cit: c.cit || '', loc: c.loc || '', imp: c.imp };
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
        var sub = function (a, b) { return OP.esc(a) + (b ? '<br><span class="text-muted small">' + OP.esc(b) + '</span>' : ''); };
        this.el('refBody').innerHTML = d.referencias.map(function (r) { return '<tr><td>' + OP.esc(r.FVXMOV) + '</td><td>' + sub(r.INT, r.INTFEX) + '</td><td>' + sub(r.EXT, r.EXTFEX) + '</td><td>' + OP.esc(r.DETMOV) + '</td><td class="op-num">' + OP.n(r.IMP) + '</td><td class="op-num">' + OP.n(r.IMP) + '</td><td></td></tr>'; }).join('');
        this.el('refTotal').textContent = this.n(d.referencias.reduce(function (s, r) { return s + r.IMP; }, 0));
        this.chqs = [];
        var chqTot = d.cheques.reduce(function (s, c) { return s + c.IMP; }, 0);
        this.el('chqBody').innerHTML = d.cheques.map(function (c) { return '<tr><td>' + OP.esc(c.CUENTA) + '</td><td>' + OP.esc(c.BANCO) + '</td><td>' + OP.esc(c.SYN) + '</td><td>' + OP.esc(c.FEX) + '</td><td class="text-center">' + OP.esc(c.PLZ) + '</td><td>' + OP.esc(c.FAX) + '</td><td>' + OP.esc(c.LIB) + '</td><td>' + OP.esc(c.CIT) + '</td><td>' + OP.esc(c.LOC) + '</td><td class="op-num">' + OP.n(c.IMP) + '</td><td></td></tr>'; }).join('');
        this.el('chqTotal').textContent = this.n(chqTot);
        var ret = d.RIXMOV, neto = Math.round((d.TOTMOV - ret) * 100) / 100, efe = Math.round((neto - chqTot) * 100) / 100;
        this.el('tEfectivo').textContent = this.n(efe); this.el('tCheques').textContent = this.n(chqTot);
        this.el('tNeto').textContent = this.n(neto); this.el('tTotal').textContent = this.n(d.TOTMOV);
        this.lockForm(true, d.ANU);
        if (d.RIXMOV > 0 && d.RINMOV > 0) this.el('btnConstanciaHdr').style.display = '';   // hay retención IIBB
    },
    lockForm(locked, anulado) {
        this.viewMode = locked;
        Array.prototype.forEach.call(document.querySelectorAll('#opForm input, #opForm select, #opForm textarea'), function (el) { el.disabled = locked; });
        this.el('btnAddRef').style.display = locked ? 'none' : '';
        this.el('btnAddCart').style.display = locked ? 'none' : ''; this.el('btnAddChq').style.display = locked ? 'none' : '';
        this.el('btnGuardar').style.display = locked ? 'none' : '';
        this.el('btnImprimirHdr').style.display = locked ? '' : 'none';
        this.el('btnConstanciaHdr').style.display = 'none';   // lo muestra verOrden si hay retención
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
