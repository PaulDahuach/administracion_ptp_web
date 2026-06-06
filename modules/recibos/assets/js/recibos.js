/* Recibos (cobranzas) — carga. Referencias + retenciones + cheques + totales + grabar/buscar. */
const RC = {
    modo: window.RC_MODO || 'operador',
    refs: [],   // {refmov, fvxiso, comp, saldo, imp}
    chqs: [],   // {codban, syn, fexiso, faxiso, lib, imp}
    bancos: [], seq: 0, dt: null,

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    // Convención de importes del sistema: PUNTO = decimal, COMA = miles (independiente del locale de Windows).
    n(v) { var x = parseFloat(v); return isNaN(x) ? '0.00' : x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    f(v) { return parseFloat(v) || 0; },   // los importes vienen de inputs type=number (punto-decimal)
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        return await (await fetch(url, opts || {})).json();
    },

    async init() {
        this.el('fexmov').value = new Date().toISOString().slice(0, 10);   // emisión = hoy (readonly)
        this.el('fixmov').value = this.el('fexmov').value;                 // imput. IVA = emisión por defecto
        this.el('saldo').value = '0.00';                                   // saldo: 0.00 hasta elegir cliente
        var ops = await this.api('operaciones'); if (ops.ok) this.el('codaux').innerHTML = ops.data.map(function (o) { return '<option value="' + o.CODAUX + '"' + (o.CODAUX == 484 ? ' selected' : '') + '>' + RC.esc(o.DENAUX) + '</option>'; }).join('');
        // PDV (CIPMOV): auto (primer punto de venta), readonly y formateado a 4 dígitos. Capacitación = 9999.
        if (this.modo !== 'capacitacion') { var p = await this.api('pdvs'); if (p.ok && p.data.length) { var pdv0 = p.data[0].CODPDV; this.el('cipmov').value = pdv0; this.el('cipmovDisp').value = String(pdv0).padStart(4, '0'); } }
        else { this.el('cipmov').value = ''; this.el('cipmovDisp').value = '9999'; }
        var b = await this.api('bancos'); this.bancos = b.ok ? b.data : [];
        var cb = await this.api('cuentas_bancarias'); if (cb.ok) this.el('codcbx').innerHTML = '<option value="">Cuenta…</option>' + cb.data.map(function (x) { return '<option value="' + x.CODCBX + '">' + RC.esc(x.DENCUE) + '</option>'; }).join('');
        var rg = await this.api('regimenes'); if (rg.ok) { var selRg = document.querySelector('.ret-rg'); if (selRg) selRg.innerHTML = '<option value="">Régimen…</option>' + rg.data.map(function (x) { return '<option value="' + x.CODRRG + '">' + RC.esc(x.DENRRG) + '</option>'; }).join(''); }

        this.autocomplete(this.el('cliQ'), this.el('cliList'), 'buscar_clientes', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { RC.pickCliente(o.CODCUE); });
        this.el('btnAddRef').addEventListener('click', function () { RC.abrirPendientes(); });
        this.el('btnAddChq').addEventListener('click', function () { RC.addCheque(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnGuardar').addEventListener('click', function () { RC.guardar(); });
        this.el('efectivo').addEventListener('input', function () { RC.recalc(); });
        this.el('codaux').addEventListener('change', function () { RC.recalc(); });
        this.el('codfdp').addEventListener('change', function () { RC.onFdp(); });
        document.querySelectorAll('.ret-imp').forEach(function (i) { i.addEventListener('input', function () { RC.recalc(); }); });
        this.el('btnBuscar').addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(RC.el('modalBuscar')).show(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (RC.viewNum) RC.anular(RC.viewNum); });
        this.el('btnImprimirHdr').addEventListener('click', function () { if (RC.viewNum) window.open('imprimir.php?nummov=' + RC.viewNum, '_blank'); });
        this.el('modalBuscar').addEventListener('shown.bs.modal', function () { RC.loadList(); });
        this.el('recBuscarGo').addEventListener('click', function () { RC.loadList(); });
        this.addCheque();
    },

    async pickCliente(codcue) {
        var j = await this.api('get_cliente', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.el('codcue').value = codcue; this.el('cliQ').value = j.data.DENCUE;
        this.saldoNum = parseFloat(j.data.SALDO) || 0;   // saldo (deuda) del cliente, para validar anticipos
        this.el('saldo').value = this.n(j.data.SALDO);
        this.el('cliInfo').textContent = (j.data.CITMOV || j.data.CITCUE || '') + ' · ' + (j.data.DOMICILIO || '') + ' · ' + (j.data.LOCALIDAD || '');
        // Operación auto por saldo (como el legacy): saldo>0 → Cancelación, ≤0 → Anticipo. Readonly.
        this.el('codaux').value = (j.data.SALDO > 0) ? '484' : '483';
        this.refs = []; this.el('refBody').innerHTML = ''; this.recalc();
    },

    // ---- Referencias ----
    async abrirPendientes() {
        var j = await this.api('pendientes', { codcue: this.el('codcue').value });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var used = this.refs.map(function (r) { return r.refmov + '|' + r.fvxiso; });
        var rows = j.data.filter(function (p) { return used.indexOf(p.REFMOV + '|' + p.FVXISO) < 0; })
            .map(function (p) { return [RC.esc(p.COMP), RC.esc(p.FEXMOV), RC.esc(p.FVXMOV), '<span class="d-block text-end" data-order="' + p.SALDO + '">' + RC.n(p.SALDO) + '</span>', JSON.stringify(p)]; });
        // Inicializar el DataTable una sola vez; en aperturas siguientes sólo limpiar + recargar
        // (destruir/re-crear rompía DataTables y abortaba el modal.show la 2ª vez).
        if (!this.dtPend) {
            this.dtPend = $('#grdPend').DataTable({ autoWidth: false, columns: [{ title: 'Comprobante' }, { title: 'Emisión' }, { title: 'Vencimiento' }, { title: 'Saldo', className: 'text-end' }, { visible: false }], pageLength: 10, order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }, createdRow: function (row, d) { row.addEventListener('click', function () { RC.addRef(JSON.parse(d[4])); bootstrap.Modal.getInstance(RC.el('modalPend')).hide(); }); } });
        }
        this.dtPend.clear().rows.add(rows).draw();
        bootstrap.Modal.getOrCreateInstance(this.el('modalPend')).show();
    },
    addRef(p) {
        var rec = { refmov: p.REFMOV, fvxiso: p.FVXISO, comp: p.COMP, saldo: p.SALDO, imp: p.SALDO };
        this.refs.push(rec);
        var tr = document.createElement('tr'); tr.dataset.k = p.REFMOV + '|' + p.FVXISO;
        tr.innerHTML = '<td>' + this.esc(p.COMP) + '</td><td>' + this.esc(p.FVXMOV) + '</td><td class="rc-num">' + this.n(p.SALDO) + '</td>' +
            '<td><input type="number" step="0.01" class="form-control form-control-sm rc-num r-imp" value="' + p.SALDO.toFixed(2) + '"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger r-del"><i class="bi bi-x"></i></button></td>';
        this.el('refBody').appendChild(tr);
        tr.querySelector('.r-imp').addEventListener('input', function () { rec.imp = RC.f(this.value); RC.recalc(); });
        tr.querySelector('.r-del').addEventListener('click', function () { tr.remove(); RC.refs = RC.refs.filter(function (x) { return x !== rec; }); RC.recalc(); });
        this.recalc();
    },

    // ---- Cheques ----
    addCheque() {
        var i = this.seq++;
        var opts = '<option value="">Banco…</option>' + this.bancos.map(function (b) { return '<option value="' + b.CODBAN + '">' + RC.esc(b.DENBAN) + '</option>'; }).join('');
        var rec = { codban: '', syn: '', fexiso: '', faxiso: '', lib: '', imp: 0 };
        this.chqs.push(rec);
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><select class="form-select form-select-sm c-ban">' + opts + '</select></td>' +
            '<td><input class="form-control form-control-sm c-syn"></td>' +
            '<td><input type="date" class="form-control form-control-sm c-fex"></td>' +
            '<td><input type="date" class="form-control form-control-sm c-fax"></td>' +
            '<td><input class="form-control form-control-sm c-lib"></td>' +
            '<td><input type="number" step="0.01" class="form-control form-control-sm rc-num c-imp"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger c-del"><i class="bi bi-x"></i></button></td>';
        this.el('chqBody').appendChild(tr);
        tr.querySelector('.c-ban').addEventListener('change', function () { rec.codban = this.value; });
        tr.querySelector('.c-syn').addEventListener('input', function () { rec.syn = this.value; });
        tr.querySelector('.c-fex').addEventListener('input', function () { rec.fexiso = this.value; });
        tr.querySelector('.c-fax').addEventListener('input', function () { rec.faxiso = this.value; });
        tr.querySelector('.c-lib').addEventListener('input', function () { rec.lib = this.value; });
        tr.querySelector('.c-imp').addEventListener('input', function () { rec.imp = parseFloat(this.value) || 0; RC.recalc(); });
        tr.querySelector('.c-del').addEventListener('click', function () { tr.remove(); RC.chqs = RC.chqs.filter(function (x) { return x !== rec; }); RC.recalc(); });
    },

    onFdp() { this.recalc(); },
    retImp(rt) { var el = document.querySelector('.ret-imp[data-rt="' + rt + '"]'); return el ? this.f(el.value) : 0; },
    // Totales como el legacy: el EFECTIVO es el TAPÓN por diferencia (no se tipea), salvo anticipo
    // (sin referencias → el operador tipea el importe) e interdepósito (tipea el depósito).
    recalc() {
        var fdp = this.el('codfdp').value;
        var inter = fdp == '5';                         // interdepósito
        var useChq = fdp == '4';                        // cheques solo con forma de pago = Cheques
        var canc = this.el('codaux').value == '484';    // cancelación
        var ant = this.el('codaux').value == '483';     // anticipo (no cancela comprobantes)
        var refTot = this.refs.reduce(function (s, r) { return s + (r.imp || 0); }, 0);
        var chqTot = useChq ? this.chqs.reduce(function (s, c) { return s + (c.imp || 0); }, 0) : 0;
        var ret = this.retImp(1) + this.retImp(2) + this.retImp(3) + this.retImp(4);
        var typed = ant || inter;   // muestra el input editable (anticipo: importe; interdep: depósito)
        var efe;
        if (inter && canc) {
            // Interdepósito + cancelación: el depósito = Σreferencias − retenciones (lo que va al banco).
            // Se auto-establece, salvo que el operador ya haya cargado un valor MAYOR a la suma de
            // referencias (sobre-depósito → queda a favor). El guard de foco evita pisarlo mientras tipea.
            var cur = parseFloat(this.el('efectivo').value) || 0;
            if (cur <= refTot + 0.005 && document.activeElement !== this.el('efectivo')) {
                cur = Math.max(0, Math.round((refTot - ret) * 100) / 100);
                this.el('efectivo').value = cur.toFixed(2);
            }
            efe = cur;
        } else if (ant) {
            efe = parseFloat(this.el('efectivo').value) || 0;   // anticipo: lo tipea el operador
        } else {
            efe = Math.max(0, Math.round((refTot - ret - chqTot) * 100) / 100);   // cancelación: tapón (oculto)
        }
        var cobrar = efe + chqTot;
        var recibo = Math.round((cobrar + ret) * 100) / 100;
        this.efe = efe; this.recibo = recibo;
        // Habilitación por contexto (como el legacy): Referencias solo en Cancelación; Cheques solo si
        // forma de pago = Cheques; Cuenta bancaria solo en Interdepósito.
        this.el('btnAddRef').disabled = !canc || !this.el('codcue').value;
        document.getElementById('cardChq').style.display = useChq ? '' : 'none';
        this.el('codcbx').disabled = !inter;
        // EFECTIVO: read-only (tapón, cancelación) o editable (input, anticipo/interdepósito).
        this.el('efectivo').style.display = typed ? '' : 'none';
        this.el('tEfectivo').style.display = typed ? 'none' : '';
        this.el('tEfectivo').textContent = this.n(efe);
        this.el('lblEfe').textContent = inter ? 'Importe' : 'Efectivo';
        this.el('boxChq').style.display = useChq ? '' : 'none';
        this.el('boxCobrar').style.display = inter ? 'none' : '';
        this.el('refTotal').textContent = this.n(refTot);
        this.el('chqTotal').textContent = this.n(chqTot);
        this.el('retTotal').textContent = this.n(ret);
        this.el('tCheques').textContent = this.n(chqTot);
        this.el('tCobrar').textContent = this.n(cobrar);
        this.el('tRet').textContent = this.n(ret);
        this.el('tRecibo').textContent = this.n(recibo);
    },

    async guardar() {
        this.el('rcErr').textContent = '';
        this.recalc();   // asegura this.efe / this.recibo actualizados
        var esAnt = this.el('codaux').value == '483';
        var inter = this.el('codfdp').value == '5';
        if (!this.el('codcue').value) { this.el('rcErr').textContent = 'Elegí un cliente.'; return; }
        if (!esAnt && !this.refs.length) { this.el('rcErr').textContent = 'Agregá al menos un comprobante a cancelar.'; return; }
        if (this.recibo <= 0) { this.el('rcErr').textContent = 'El recibo no tiene importe.'; return; }
        if (inter && !this.el('codcbx').value) { this.el('rcErr').textContent = 'Elegí la cuenta bancaria del interdepósito.'; return; }
        if (this.retImp(2) > 0 && !this.gv('.ret-rg')) { this.el('rcErr').textContent = 'Falta el código de régimen de la retención de Ganancias.'; return; }
        // Cancelación: el recibo no puede totalizar menos que lo que se cancela, y sólo puede haber
        // excedente (anticipo) si se cancela TODA la deuda del cliente (si no, el anticipo quedaría
        // enmascarado por las deudas que coexisten e imposible de cancelar).
        if (!esAnt) {
            var refTotG = this.refs.reduce(function (s, r) { return s + (r.imp || 0); }, 0);
            var exc = Math.round((this.recibo - refTotG) * 100) / 100;
            if (exc < -0.005) { this.el('rcErr').textContent = 'El total del recibo (' + this.n(this.recibo) + ') no puede ser menor a lo que se cancela (' + this.n(refTotG) + ').'; return; }
            if (exc > 0.005 && refTotG < (this.saldoNum || 0) - 0.005) {
                this.el('rcErr').textContent = 'Sólo se puede cobrar de más (generar anticipo) cancelando TODA la deuda del cliente. Saldo: ' + this.n(this.saldoNum) + ' · estás cancelando: ' + this.n(refTotG) + '. El excedente de ' + this.n(exc) + ' quedaría como anticipo bloqueado por las deudas pendientes.';
                return;
            }
        }
        var data = {
            codcue: this.el('codcue').value, codaux: this.el('codaux').value, fexmov: this.el('fexmov').value,
            fixmov: this.el('fixmov').value || this.el('fexmov').value,
            codfdp: this.el('codfdp').value, codcbx: this.el('codcbx').value, efectivo: this.efe, detmov: this.el('detmov').value,
            cipmov: (this.modo === 'capacitacion') ? null : this.el('cipmov').value,
            referencias: this.refs.map(function (r) { return { refmov: r.refmov, fvxmov: r.fvxiso, imp: r.imp }; }),
            cheques: inter ? [] : this.chqs.filter(function (c) { return c.imp > 0; }).map(function (c) { return { codban: c.codban, syn: c.syn, fex: c.fexiso, fax: c.faxiso, lib: c.lib, imp: c.imp, plz: 0, cit: '', loc: '' }; }),
            retenciones: {
                rt1: this.retImp(1), rip: 0, rin: this.retNum(1),
                rt2: this.retImp(2), rgp: this.gv('.ret-gp'), rgn: this.gv('.ret-gn'), codrrg: this.gv('.ret-rg'),
                rt3: this.retImp(3), rvp: 0, rvn: this.retNum(3),
                rt4: this.retImp(4), rsp: 0, rsn: this.retNum(4)
            }
        };
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        this.el('btnGuardar').disabled = true;
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        if (!j.ok) { this.el('btnGuardar').disabled = false; this.el('rcErr').textContent = j.error; return; }
        // Mostrar el Movimiento Nº y el Nº de recibo asignados (clave para soporte); el form queda
        // asentado hasta que el operador toca "Nuevo".
        var pdv = j.data.cipmov ? String(j.data.cipmov).padStart(4, '0') : '9999';
        var nro8 = String(j.data.cinmov).padStart(8, '0');
        var mov8 = String(j.data.nummov).padStart(8, '0');
        this.el('nummov').value = mov8;
        this.el('cipmovDisp').value = pdv;
        this.el('cinmov').value = nro8;
        this.toast('Recibo grabado · Movimiento Nº ' + mov8 + ' · Recibo ' + pdv + '-' + nro8, 'success');
        window.open('imprimir.php?nummov=' + j.data.nummov + '&print=1', '_blank');
    },
    retNum(rt) { var el = document.querySelector('.ret-num[data-rt="' + rt + '"]'); return el ? (parseInt(el.value, 10) || 0) : 0; },
    gv(sel) { var el = document.querySelector(sel); return el ? (parseInt(el.value, 10) || 0) : 0; },

    // ---- Buscar / detalle ----
    async loadList() {
        var p = { q: this.el('recBuscarQ').value.trim() };
        if (this.el('recBuscarD').value) p.desde = this.el('recBuscarD').value;
        if (this.el('recBuscarH').value) p.hasta = this.el('recBuscarH').value;
        var j = await this.api('listar', p); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var rows = j.data.recibos.map(function (r) {
            var est = r.ANU ? '<span class="badge bg-danger">Anulado</span>' : '<span class="badge bg-success">OK</span>';
            return ['<span data-order="' + r.FEXMOVO + '">' + RC.esc(r.FEXMOV) + '</span>', RC.esc(r.COMP), RC.esc(r.DENMOV), '<span data-order="' + r.TOTMOV + '" class="d-block text-end fw-medium">' + RC.n(r.TOTMOV) + '</span>', est, r.NUMMOV];
        });
        if (!this.dt) {
            this.dt = $('#grdRec').DataTable({ autoWidth: false, pageLength: 15, columns: [{ title: 'Fecha' }, { title: 'Comprobante' }, { title: 'Cliente' }, { title: 'Total', className: 'text-end' }, { title: 'Estado' }, { visible: false }], columnDefs: [{ targets: [0, 3], type: 'num' }], order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }, createdRow: function (row, d) { row.addEventListener('click', function () { RC.verRecibo(d[5]); }); } });
        }
        this.dt.clear().rows.add(rows).draw();
        if (j.data.tope) this.toast('Mostrando los 200 recibos más recientes; afiná el filtro.', 'info');
    },
    // Ver un recibo emitido EN LA PÁGINA, con todo bloqueado (sólo se puede anular/imprimir).
    async verRecibo(num) {
        var j = await this.api('detalle', { nummov: num }); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data; this.viewNum = num;
        var bm = bootstrap.Modal.getInstance(this.el('modalBuscar')); if (bm) bm.hide();
        var inter = d.CODFDP == 5;
        // Header
        this.el('nummov').value = String(d.NUMMOV).padStart(8, '0');
        this.el('cipmov').value = d.CIPMOV || '';
        this.el('cipmovDisp').value = d.CIPMOV ? String(d.CIPMOV).padStart(4, '0') : '9999';
        this.el('cinmov').value = d.CINMOV ? String(d.CINMOV).padStart(8, '0') : '';
        this.el('fexmov').value = d.FEXISO; this.el('fixmov').value = d.FIXISO;
        this.el('codaux').value = d.CODAUX; this.el('codcue').value = d.CODCUE;
        this.el('cliQ').value = d.DENMOV;
        this.el('cliInfo').textContent = [d.CITMOV, d.DOMICILIO, d.LOCALIDAD].filter(Boolean).join(' · ');
        this.el('saldo').value = this.n(d.SOCMOV); this.el('detmov').value = d.DETMOV;
        this.el('codfdp').value = d.CODFDP; this.el('codcbx').value = d.CODCBX || '';
        // Retenciones
        var sv = function (sel, v) { var e = document.querySelector(sel); if (e) e.value = v; };
        sv('.ret-imp[data-rt="1"]', d.ret.rt1 > 0 ? d.ret.rt1.toFixed(2) : ''); sv('.ret-num[data-rt="1"]', d.ret.rin || '');
        sv('.ret-imp[data-rt="2"]', d.ret.rt2 > 0 ? d.ret.rt2.toFixed(2) : ''); sv('.ret-gp', d.ret.rgp || ''); sv('.ret-gn', d.ret.rgn || ''); sv('.ret-rg', d.ret.codrrg || '');
        sv('.ret-imp[data-rt="3"]', d.ret.rt3 > 0 ? d.ret.rt3.toFixed(2) : ''); sv('.ret-num[data-rt="3"]', d.ret.rvn || '');
        sv('.ret-imp[data-rt="4"]', d.ret.rt4 > 0 ? d.ret.rt4.toFixed(2) : ''); sv('.ret-num[data-rt="4"]', d.ret.rsn || '');
        // Referencias (sólo lectura)
        this.refs = [];
        this.el('refBody').innerHTML = d.referencias.map(function (r) { return '<tr><td>' + RC.esc(r.COMP) + '</td><td>' + RC.esc(r.FVXMOV) + '</td><td class="rc-num">' + RC.n(r.IMP) + '</td><td class="rc-num">' + RC.n(r.IMP) + '</td><td></td></tr>'; }).join('');
        var refTot = d.referencias.reduce(function (s, r) { return s + r.IMP; }, 0);
        this.el('refTotal').textContent = this.n(refTot);
        // Cheques (sólo lectura)
        this.chqs = [];
        this.el('chqBody').innerHTML = d.cheques.map(function (c) { return '<tr><td>' + RC.esc(c.BANCO) + '</td><td>' + RC.esc(c.SYN) + '</td><td>' + RC.esc(c.FEXISO) + '</td><td>' + RC.esc(c.FAX) + '</td><td>' + RC.esc(c.LIB) + '</td><td class="rc-num">' + RC.n(c.IMP) + '</td><td></td></tr>'; }).join('');
        var chqTot = d.cheques.reduce(function (s, c) { return s + c.IMP; }, 0);
        this.el('chqTotal').textContent = this.n(chqTot);
        // Totales (valores guardados, sin recalcular)
        var ret = d.ret.rt1 + d.ret.rt2 + d.ret.rt3 + d.ret.rt4;
        var cobrar = Math.round((d.TOTMOV - ret) * 100) / 100;
        var efe = inter ? cobrar : Math.round((cobrar - chqTot) * 100) / 100;
        this.el('tEfectivo').textContent = this.n(efe); this.el('tEfectivo').style.display = ''; this.el('efectivo').style.display = 'none';
        this.el('lblEfe').textContent = inter ? 'Importe' : 'Efectivo';
        this.el('tCheques').textContent = this.n(chqTot); this.el('tRet').textContent = this.n(ret); this.el('retTotal').textContent = this.n(ret);
        this.el('tCobrar').textContent = this.n(cobrar); this.el('tRecibo').textContent = this.n(d.TOTMOV);
        this.el('boxChq').style.display = inter ? 'none' : ''; this.el('boxCobrar').style.display = inter ? 'none' : '';
        document.getElementById('cardChq').style.display = (d.CODFDP == 4 && d.cheques.length) ? '' : 'none';
        this.lockForm(true, d.ANU);
        this.el('rcErr').textContent = '';
    },
    /** Bloquea/desbloquea todos los campos y cambia los botones del header. */
    lockForm(locked, anulado) {
        this.viewMode = locked;
        Array.prototype.forEach.call(document.querySelectorAll('#rcForm input, #rcForm select, #rcForm textarea'), function (el) { el.disabled = locked; });
        this.el('btnAddRef').style.display = locked ? 'none' : '';
        this.el('btnAddChq').style.display = locked ? 'none' : '';
        this.el('btnGuardar').style.display = locked ? 'none' : '';
        this.el('btnImprimirHdr').style.display = locked ? '' : 'none';
        this.el('btnAnularHdr').style.display = (locked && !anulado) ? '' : 'none';
    },
    async anular(num) {
        if (!confirm('¿Anular el recibo? Se revierten los comprobantes cancelados, el asiento contable y los cheques recibidos. No se puede deshacer.')) return;
        var fd = new FormData(); fd.append('action', 'anular'); fd.append('nummov', num);
        var j = await this.api('anular', {}, { method: 'POST', body: fd });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.toast('Recibo ' + num + ' anulado.', 'success');
        this.verRecibo(num);   // recargar en pantalla (ahora anulado: se oculta Anular)
    },

    autocomplete(input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() { list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + RC.esc(label(o)) + '</div>'; }).join(''); list.classList.toggle('show', items.length > 0); }
        input.addEventListener('input', function () { clearTimeout(t); var q = input.value.trim(); if (q.length < 1) { items = []; render(); return; } t = setTimeout(async function () { var j = await RC.api(action, { q: q }); items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }, 180); });
        input.addEventListener('keydown', function (e) { if (!list.classList.contains('show')) return; if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); } else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); } else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); onPick(items[hi]); list.classList.remove('show'); } } else if (e.key === 'Escape') list.classList.remove('show'); });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },
    toast(msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 6000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { RC.init(); });
