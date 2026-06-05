/* Recibos (cobranzas) — carga. Referencias + retenciones + cheques + totales + grabar/buscar. */
const RC = {
    modo: window.RC_MODO || 'operador',
    refs: [],   // {refmov, fvxiso, comp, saldo, imp}
    chqs: [],   // {codban, syn, fexiso, faxiso, lib, imp}
    bancos: [], seq: 0, dt: null,

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    n(v) { var x = parseFloat(v); return isNaN(x) ? '0,00' : x.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    f(v) { return parseFloat(String(v).replace(/\./g, '').replace(',', '.')) || parseFloat(v) || 0; },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        return await (await fetch(url, opts || {})).json();
    },

    async init() {
        this.el('fexmov').value = new Date().toISOString().slice(0, 10);
        var ops = await this.api('operaciones'); if (ops.ok) this.el('codaux').innerHTML = ops.data.map(function (o) { return '<option value="' + o.CODAUX + '"' + (o.CODAUX == 484 ? ' selected' : '') + '>' + RC.esc(o.DENAUX) + '</option>'; }).join('');
        if (this.modo !== 'capacitacion') { var p = await this.api('pdvs'); if (p.ok) this.el('cipmov').innerHTML = p.data.map(function (x) { return '<option value="' + x.CODPDV + '">' + (x.NOMPDV ? RC.esc(x.NOMPDV) + ' (' + x.CODPDV + ')' : x.CODPDV) + '</option>'; }).join(''); }
        else this.el('cipmov').closest('.col-md-2').style.display = 'none';
        var b = await this.api('bancos'); this.bancos = b.ok ? b.data : [];

        this.autocomplete(this.el('cliQ'), this.el('cliList'), 'buscar_clientes', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { RC.pickCliente(o.CODCUE); });
        this.el('btnAddRef').addEventListener('click', function () { RC.abrirPendientes(); });
        this.el('btnAddChq').addEventListener('click', function () { RC.addCheque(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnGuardar').addEventListener('click', function () { RC.guardar(); });
        this.el('efectivo').addEventListener('input', function () { RC.recalc(); });
        this.el('codaux').addEventListener('change', function () { RC.recalc(); });
        document.querySelectorAll('.ret-imp').forEach(function (i) { i.addEventListener('input', function () { RC.recalc(); }); });
        this.el('btnBuscar').addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(RC.el('modalBuscar')).show(); });
        this.el('modalBuscar').addEventListener('shown.bs.modal', function () { if (!RC.dt) RC.loadList(); });
        this.el('recBuscarGo').addEventListener('click', function () { RC.loadList(); });
        this.addCheque();
    },

    async pickCliente(codcue) {
        var j = await this.api('get_cliente', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.el('codcue').value = codcue; this.el('cliQ').value = j.data.DENCUE;
        this.el('saldo').value = this.n(j.data.SALDO);
        this.el('cliInfo').textContent = (j.data.CITMOV || j.data.CITCUE || '') + ' · ' + (j.data.DOMICILIO || '') + ' · ' + (j.data.LOCALIDAD || '');
        this.el('btnAddRef').disabled = false;
        this.refs = []; this.el('refBody').innerHTML = ''; this.recalc();
    },

    // ---- Referencias ----
    async abrirPendientes() {
        var j = await this.api('pendientes', { codcue: this.el('codcue').value });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var used = this.refs.map(function (r) { return r.refmov + '|' + r.fvxiso; });
        var rows = j.data.filter(function (p) { return used.indexOf(p.REFMOV + '|' + p.FVXISO) < 0; })
            .map(function (p) { return [RC.esc(p.COMP), RC.esc(p.FEXMOV), RC.esc(p.FVXMOV), '<span class="d-block text-end" data-order="' + p.SALDO + '">' + RC.n(p.SALDO) + '</span>', JSON.stringify(p)]; });
        if ($.fn.dataTable.isDataTable('#grdPend')) $('#grdPend').DataTable().destroy();
        $('#grdPend tbody').remove();
        var dt = $('#grdPend').DataTable({ data: rows, columns: [{ title: 'Comprobante' }, { title: 'Emisión' }, { title: 'Vencimiento' }, { title: 'Saldo', className: 'text-end' }, { visible: false }], pageLength: 10, order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }, createdRow: function (row, d) { row.addEventListener('click', function () { RC.addRef(JSON.parse(d[4])); bootstrap.Modal.getInstance(RC.el('modalPend')).hide(); }); } });
        bootstrap.Modal.getOrCreateInstance(this.el('modalPend')).show();
    },
    addRef(p) {
        var rec = { refmov: p.REFMOV, fvxiso: p.FVXISO, comp: p.COMP, saldo: p.SALDO, imp: p.SALDO };
        this.refs.push(rec);
        var tr = document.createElement('tr'); tr.dataset.k = p.REFMOV + '|' + p.FVXISO;
        tr.innerHTML = '<td>' + this.esc(p.COMP) + '</td><td>' + this.esc(p.FVXMOV) + '</td><td class="rc-num">' + this.n(p.SALDO) + '</td>' +
            '<td><input class="form-control form-control-sm rc-num r-imp" value="' + p.SALDO.toFixed(2) + '"></td>' +
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

    retImp(rt) { var el = document.querySelector('.ret-imp[data-rt="' + rt + '"]'); return el ? this.f(el.value) : 0; },
    recalc() {
        var refTot = this.refs.reduce(function (s, r) { return s + (r.imp || 0); }, 0);
        var chqTot = this.chqs.reduce(function (s, c) { return s + (c.imp || 0); }, 0);
        var efe = parseFloat(this.el('efectivo').value) || 0;
        var ret = this.retImp(1) + this.retImp(2) + this.retImp(3) + this.retImp(4);
        var cobrar = efe + chqTot, recibo = cobrar + ret;
        this.el('refTotal').textContent = this.n(refTot);
        this.el('chqTotal').textContent = this.n(chqTot);
        this.el('retTotal').textContent = this.n(ret);
        this.el('tCheques').textContent = this.n(chqTot);
        this.el('tCobrar').textContent = this.n(cobrar);
        this.el('tRet').textContent = this.n(ret);
        this.el('tRecibo').textContent = this.n(recibo);
        var esAnt = this.el('codaux').value == '483';   // anticipo: no cancela comprobantes
        var dif = Math.round((recibo - refTot) * 100) / 100;
        this.el('boxDif').style.display = (!esAnt && Math.abs(dif) >= 0.005) ? '' : 'none';
        this.el('tDif').textContent = this.n(dif);
    },

    async guardar() {
        this.el('rcErr').textContent = '';
        var esAnt = this.el('codaux').value == '483';
        if (!this.el('codcue').value) { this.el('rcErr').textContent = 'Elegí un cliente.'; return; }
        if (!esAnt && !this.refs.length) { this.el('rcErr').textContent = 'Agregá al menos un comprobante a cancelar.'; return; }
        var refTot = this.refs.reduce(function (s, r) { return s + (r.imp || 0); }, 0);
        var ret = this.retImp(1) + this.retImp(2) + this.retImp(3) + this.retImp(4);
        var chqTot = this.chqs.filter(function (c) { return c.imp > 0; }).reduce(function (s, c) { return s + c.imp; }, 0);
        var efe = parseFloat(this.el('efectivo').value) || 0;
        if (!esAnt) {
            var dif = Math.round((efe + chqTot + ret - refTot) * 100) / 100;
            if (Math.abs(dif) >= 0.005) { this.el('rcErr').textContent = 'El recibo (cobrado + retenciones) no coincide con lo que se cancela. Diferencia: ' + this.n(dif); return; }
        } else if ((efe + chqTot + ret) <= 0) { this.el('rcErr').textContent = 'El anticipo no tiene importe (cheques/efectivo).'; return; }
        var data = {
            codcue: this.el('codcue').value, codaux: this.el('codaux').value, fexmov: this.el('fexmov').value, fixmov: this.el('fexmov').value,
            codfdp: this.el('codfdp').value, efectivo: efe, detmov: this.el('detmov').value,
            cipmov: (this.modo === 'capacitacion') ? null : this.el('cipmov').value,
            referencias: this.refs.map(function (r) { return { refmov: r.refmov, fvxmov: r.fvxiso, imp: r.imp }; }),
            cheques: this.chqs.filter(function (c) { return c.imp > 0; }).map(function (c) { return { codban: c.codban, syn: c.syn, fex: c.fexiso, fax: c.faxiso, lib: c.lib, imp: c.imp, plz: 0, cit: '', loc: '' }; }),
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
        this.el('btnGuardar').disabled = false;
        if (!j.ok) { this.el('rcErr').textContent = j.error; return; }
        var pdv = j.data.cipmov ? String(j.data.cipmov).padStart(4, '0') : '9999';
        this.toast('Recibo grabado: ' + pdv + '-' + String(j.data.cinmov).padStart(8, '0') + ' (mov ' + j.data.nummov + ')', 'success');
        window.open('imprimir.php?nummov=' + j.data.nummov + '&print=1', '_blank');
        setTimeout(function () { location.reload(); }, 800);
    },
    retNum(rt) { var el = document.querySelector('.ret-num[data-rt="' + rt + '"]'); return el ? (parseInt(el.value, 10) || 0) : 0; },
    gv(sel) { var el = document.querySelector(sel); return el ? (parseInt(el.value, 10) || 0) : 0; },

    // ---- Buscar / detalle ----
    async loadList() {
        var p = { q: this.el('recBuscarQ').value.trim() };
        if (this.el('recBuscarD').value) p.desde = this.el('recBuscarD').value;
        if (this.el('recBuscarH').value) p.hasta = this.el('recBuscarH').value;
        if (this.dt) { this.dt.destroy(); this.dt = null; $('#grdRec tbody').remove(); }
        var j = await this.api('listar', p); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var rows = j.data.recibos.map(function (r) {
            var est = r.ANU ? '<span class="badge bg-danger">Anulado</span>' : '<span class="badge bg-success">OK</span>';
            return ['<span data-order="' + r.FEXMOVO + '">' + RC.esc(r.FEXMOV) + '</span>', RC.esc(r.COMP), RC.esc(r.DENMOV), '<span data-order="' + r.TOTMOV + '" class="d-block text-end fw-medium">' + RC.n(r.TOTMOV) + '</span>', est, r.NUMMOV];
        });
        this.dt = $('#grdRec').DataTable({ data: rows, destroy: true, pageLength: 15, columns: [{ title: 'Fecha' }, { title: 'Comprobante' }, { title: 'Cliente' }, { title: 'Total', className: 'text-end' }, { title: 'Estado' }, { visible: false }], columnDefs: [{ targets: [0, 3], type: 'num' }], order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }, createdRow: function (row, d) { row.addEventListener('click', function () { RC.verDetalle(d[5]); }); } });
        if (j.data.tope) this.toast('Mostrando los 200 recibos más recientes; afiná el filtro.', 'info');
    },
    async verDetalle(num) {
        var j = await this.api('detalle', { nummov: num }); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data;
        this.el('detTit').innerHTML = '<i class="bi bi-receipt me-2"></i>' + this.esc(d.COMP) + (d.ANU ? ' <span class="badge bg-danger">Anulado</span>' : '');
        var refs = d.referencias.map(function (r) { return '<tr><td>' + RC.esc(r.COMP) + '</td><td>' + RC.esc(r.FVXMOV) + '</td><td class="text-end">' + RC.n(r.IMP) + '</td></tr>'; }).join('');
        var chqs = d.cheques.map(function (c) { return '<tr><td>' + RC.esc(c.BANCO) + '</td><td>' + RC.esc(c.SYN) + '</td><td>' + RC.esc(c.FAX) + '</td><td>' + RC.esc(c.LIB) + '</td><td class="text-end">' + RC.n(c.IMP) + '</td></tr>'; }).join('');
        var rets = d.retenciones.map(function (r) { return '<tr><td>' + RC.esc(r.TIPO) + '</td><td class="text-end">' + RC.n(r.IMP) + '</td></tr>'; }).join('');
        this.el('detBody').innerHTML =
            '<div class="small mb-2"><b>Cliente:</b> ' + this.esc(d.DENMOV) + (d.CITMOV ? ' · ' + this.esc(d.CITMOV) : '') + ' · <b>Emisión:</b> ' + this.esc(d.FEXMOV) + ' · <b>Total:</b> ' + this.n(d.TOTMOV) + (d.DETMOV ? '<br><b>Detalle:</b> ' + this.esc(d.DETMOV) : '') + '</div>' +
            '<div class="fw-bold small mt-2">Comprobantes cancelados</div><table class="table table-sm"><thead><tr><th>Comprobante</th><th>Vto</th><th class="text-end">Importe</th></tr></thead><tbody>' + refs + '</tbody></table>' +
            (rets ? '<div class="fw-bold small">Retenciones</div><table class="table table-sm"><tbody>' + rets + '</tbody></table>' : '') +
            (chqs ? '<div class="fw-bold small">Cheques</div><table class="table table-sm"><thead><tr><th>Banco</th><th>Serie-Nº</th><th>Acred.</th><th>Librador</th><th class="text-end">Importe</th></tr></thead><tbody>' + chqs + '</tbody></table>' : '');
        this.el('btnImprimir').onclick = function () { window.open('imprimir.php?nummov=' + d.NUMMOV, '_blank'); };
        var anu = this.el('btnAnular');
        anu.style.display = d.ANU ? 'none' : '';
        anu.onclick = function () { RC.anular(d.NUMMOV); };
        bootstrap.Modal.getOrCreateInstance(this.el('modalDet')).show();
    },
    async anular(num) {
        if (!confirm('¿Anular el recibo? Se revierten los comprobantes cancelados, el asiento contable y los cheques recibidos. No se puede deshacer.')) return;
        var fd = new FormData(); fd.append('action', 'anular'); fd.append('nummov', num);
        var j = await this.api('anular', {}, { method: 'POST', body: fd });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.toast('Recibo ' + num + ' anulado.', 'success');
        bootstrap.Modal.getInstance(this.el('modalDet')).hide();
        this.loadList();
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
