/* Remitos deudores — carga (escritura). Autocomplete cliente/producto + grilla + grabar. */
const R = {
    modo: window.REM_MODO || 'operador',
    cli: null,             // datos del cliente elegido
    lines: [],             // {codpro,denmov,codudm,denudm,fctmov,dummov,codmon,cosmov,pulmov,stk,cant,punmov,odc,odp,pdl}
    seq: 0,
    dt: null,              // DataTable del modal Buscar

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { var n = parseFloat(v); return isNaN(n) ? '0,00' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        var r = await fetch(url, opts || {});
        return await r.json();
    },

    async init() {
        var hoy = new Date().toISOString().slice(0, 10);
        this.el('fexmov').value = hoy; this.el('frvmov').value = hoy;
        // PDV: sólo en operador/integral; en capacitación se numera en 9999 (server) → ocultar combo
        if (this.modo === 'capacitacion') { this.el('boxPdv').style.display = 'none'; }
        else {
            var j = await this.api('pdvs');
            if (j.ok) {
                this.el('cipmov').innerHTML = j.data.map(function (p) {
                    return '<option value="' + p.CODPDV + '">' + (p.NOMPDV ? R.esc(p.NOMPDV) + ' (' + p.CODPDV + ')' : p.CODPDV) + '</option>';
                }).join('');
                // Default por config (pto_vta_remitos): pre-seleccionar el talonario de remitos si está en la lista.
                var def = this.el('cipmov').getAttribute('data-default');
                if (def && this.el('cipmov').querySelector('option[value="' + def + '"]')) this.el('cipmov').value = def;
            }
        }
        this.autocomplete(this.el('cliQ'), this.el('cliList'), 'buscar_clientes', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { R.pickCliente(o.CODCUE); });
        this.el('btnAddLn').addEventListener('click', function () { R.addLine(); });
        this.el('btnNuevo').addEventListener('click', function () { R.reset(); });
        this.el('btnGuardar').addEventListener('click', function () { R.guardar(); });
        // Buscar: botón → modal con DataTable (patrón recepcion/definicion).
        this.el('btnBuscar').addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(R.el('modalBuscar')).show(); });
        this.el('modalBuscar').addEventListener('shown.bs.modal', function () { if (!R.dt) R.loadList(); R.el('remBuscarQ').focus(); });
        this.el('remBuscarGo').addEventListener('click', function () { R.loadList(); });
        ['remBuscarQ', 'remBuscarD', 'remBuscarH'].forEach(function (id) { R.el(id).addEventListener('keydown', function (e) { if (e.key === 'Enter') R.loadList(); }); });
        this.addLine();
    },

    async loadList() {
        var p = { q: this.el('remBuscarQ').value.trim() };
        if (this.el('remBuscarD').value) p.desde = this.el('remBuscarD').value;
        if (this.el('remBuscarH').value) p.hasta = this.el('remBuscarH').value;
        if (this.dt) { this.dt.destroy(); this.dt = null; $('#grdRem tbody').remove(); }
        var j = await this.api('listar', p);
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var rows = j.data.remitos.map(function (r) {
            var est = r.ANU ? '<span class="badge bg-danger">Anulado</span>' : (r.PEND ? '<span class="badge bg-warning text-dark">Pte. facturar</span>' : '<span class="badge bg-success">Facturado</span>');
            return ['<span data-order="' + r.FEXMOVO + '">' + R.esc(r.FEXMOV) + '</span>', R.esc(r.COMP), R.esc(r.DENMOV),
                '<span data-order="' + r.TOTMOV + '" class="d-block text-end fw-medium">' + R.num(r.TOTMOV) + '</span>', est, r.NUMMOV];
        });
        this.dt = $('#grdRem').DataTable({
            data: rows, destroy: true, pageLength: 15,
            columns: [{ title: 'Fecha' }, { title: 'Comprobante' }, { title: 'Cliente' }, { title: 'Total', className: 'text-end' }, { title: 'Estado' }, { visible: false }],
            columnDefs: [{ targets: [0, 3], type: 'num' }],
            order: [], language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
            createdRow: function (row, d) { row.addEventListener('click', function () { R.verDetalle(d[5]); }); }
        });
        if (j.data.tope) this.toast('Mostrando los 200 remitos más recientes; afiná el filtro para ver otros.', 'info');
    },

    async verDetalle(num) {
        var j = await this.api('detalle', { nummov: num });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data;
        this.el('detTit').innerHTML = '<i class="bi bi-truck me-2"></i>' + this.esc(d.COMP) + (d.ANU ? ' <span class="badge bg-danger">Anulado</span>' : '');
        var ls = d.lineas.map(function (l) {
            return '<tr><td>' + R.esc(l.CODPRO) + '</td><td>' + R.esc(l.DENMOV) + '</td><td>' + R.esc(l.UNIDAD) + '</td>' +
                '<td>' + R.esc(l.ODC) + '</td><td>' + R.esc(l.ODP) + '</td><td>' + R.esc(l.PDL) + '</td>' +
                '<td class="text-end">' + R.num(l.CANT) + '</td><td class="text-end">' + R.num(l.PUN) + '</td><td class="text-end">' + R.num(l.TOTAL) + '</td></tr>';
        }).join('');
        this.el('detBody').innerHTML =
            '<div class="row g-2 mb-2 small">' +
            '<div class="col-md-6"><b>Cliente:</b> ' + this.esc(d.DENMOV) + (d.CITMOV ? ' · ' + this.esc(d.CITMOV) : '') + '</div>' +
            '<div class="col-md-3"><b>Emisión:</b> ' + this.esc(d.FEXMOV) + '</div>' +
            '<div class="col-md-3"><b>Facturación:</b> ' + this.esc(d.FRVMOV) + '</div>' +
            (d.COTMOV ? '<div class="col-md-6"><b>Cotiz. u$s:</b> ' + this.esc(d.COTMOV) + '</div>' : '') +
            (d.DETMOV ? '<div class="col-12"><b>Detalle:</b> ' + this.esc(d.DETMOV) + '</div>' : '') + '</div>' +
            '<table class="table table-sm"><thead><tr><th>Código</th><th>Denominación</th><th>Unidad</th><th>O.Corte</th><th>O.Proc.</th><th>PTP</th><th class="text-end">Cant.</th><th class="text-end">P.U.Neto</th><th class="text-end">Total</th></tr></thead>' +
            '<tbody>' + ls + '</tbody><tfoot><tr class="fw-bold"><td colspan="8" class="text-end">Total:</td><td class="text-end">' + this.num(d.TOTMOV) + '</td></tr></tfoot></table>';
        this.el('btnImprimir').onclick = function () { window.open('imprimir.php?nummov=' + d.NUMMOV, '_blank'); };
        bootstrap.Modal.getOrCreateInstance(this.el('modalDet')).show();
    },

    reset() {
        this.cli = null; this.lines = [];
        ['cliQ', 'codcue', 'cotmov', 'detmov'].forEach(function (id) { R.el(id).value = ''; });
        this.el('pdcmov').value = ''; this.el('saldo').value = ''; this.el('vdxmov').value = '0';
        this.el('cliInfo').textContent = ''; this.el('remErr').textContent = '';
        this.el('lnBody').innerHTML = ''; this.el('grTotal').textContent = '0,00';
        this.addLine();
        this.el('cliQ').focus();
    },

    async pickCliente(codcue) {
        var j = await this.api('get_cliente', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.cli = j.data;
        this.el('codcue').value = codcue;
        this.el('cliQ').value = j.data.DENCUE;
        this.el('pdcmov').value = this.num(j.data.LDPCAT);
        this.el('saldo').value = this.num(j.data.SALDO);
        this.el('cliInfo').textContent = (j.data.CITCUE || '') + ' · ' + (j.data.DOMICILIO || '') + ' · ' + (j.data.LOCALIDAD || '');
    },

    addLine() {
        var i = this.seq++;
        var tr = document.createElement('tr'); tr.dataset.i = i;
        tr.innerHTML =
            '<td class="ac-box"><input class="form-control form-control-sm l-cod" data-nocombo autocomplete="off" placeholder="Código"><div class="ac-list"></div></td>' +
            '<td class="l-den text-muted small"></td>' +
            '<td class="l-udm text-muted small"></td>' +
            '<td><input class="form-control form-control-sm l-odc" inputmode="numeric"></td>' +
            '<td><input class="form-control form-control-sm l-odp" inputmode="numeric"></td>' +
            '<td><input class="form-control form-control-sm l-pdl" inputmode="numeric"></td>' +
            '<td><input class="form-control form-control-sm rem-num l-cant" inputmode="decimal"></td>' +
            '<td><input class="form-control form-control-sm rem-num l-pun" inputmode="decimal"></td>' +
            '<td class="rem-num l-tot">0,00</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger l-del"><i class="bi bi-x"></i></button></td>';
        this.el('lnBody').appendChild(tr);
        var rec = { i: i, codpro: null };
        this.lines.push(rec);
        var cod = tr.querySelector('.l-cod'), list = tr.querySelector('.ac-list');
        this.autocomplete(cod, list, 'buscar_productos', function (o) { return o.CODPRO + ' · ' + o.DENPRO; }, function (o) { R.pickProducto(tr, o.CODPRO); });
        ['.l-cant', '.l-pun', '.l-odc', '.l-odp', '.l-pdl'].forEach(function (s) {
            tr.querySelector(s).addEventListener('input', function () { R.recalc(tr); });
        });
        tr.querySelector('.l-del').addEventListener('click', function () { tr.remove(); R.lines = R.lines.filter(function (x) { return x.i !== i; }); R.recalcAll(); });
        cod.focus();
    },

    async pickProducto(tr, codpro) {
        var j = await this.api('get_producto', { codpro: codpro, codsuc: this.el('coddst').value || 1, codcue: this.el('codcue').value || 0 });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data, rec = this.lines.find(function (x) { return x.i == tr.dataset.i; });
        rec.codpro = d.CODPRO; rec.denmov = d.DENPRO; rec.codudm = d.CODUDM; rec.denudm = d.DENUDM;
        rec.fctmov = d.FCTPUM; rec.dummov = d.DECUDM; rec.codmon = d.CODMON; rec.cosmov = d.COSPRO; rec.pulmov = d.PLVPRO; rec.stk = d.STK;
        tr.querySelector('.l-cod').value = d.CODPRO;
        tr.querySelector('.l-den').textContent = d.DENPRO + (d.STK && d.EXISTENCIA !== null ? ' · stock: ' + this.num(d.EXISTENCIA) : '');
        tr.querySelector('.l-udm').textContent = d.DENUDM;
        tr.querySelector('.l-pun').value = d.PUN_SUG;   // precio sugerido (editable; el operador puede pisarlo)
        this.recalc(tr);
        tr.querySelector('.l-cant').focus();
    },

    recalc(tr) {
        var rec = this.lines.find(function (x) { return x.i == tr.dataset.i; });
        rec.cant = parseFloat(tr.querySelector('.l-cant').value) || 0;
        rec.punmov = parseFloat(tr.querySelector('.l-pun').value) || 0;
        rec.odc = tr.querySelector('.l-odc').value; rec.odp = tr.querySelector('.l-odp').value; rec.pdl = tr.querySelector('.l-pdl').value;
        tr.querySelector('.l-tot').textContent = this.num(rec.cant * rec.punmov);
        this.recalcAll();
    },
    recalcAll() {
        var t = 0; this.lines.forEach(function (r) { t += (r.cant || 0) * (r.punmov || 0); });
        this.el('grTotal').textContent = this.num(t);
    },

    async guardar() {
        this.el('remErr').textContent = '';
        var codcue = this.el('codcue').value;
        if (!codcue) { this.el('remErr').textContent = 'Elegí un cliente.'; return; }
        var ls = this.lines.filter(function (r) { return r.codpro && (r.cant || 0) > 0; });
        if (!ls.length) { this.el('remErr').textContent = 'Agregá al menos un producto con cantidad.'; return; }
        var data = {
            codcue: codcue, fexmov: this.el('fexmov').value, frvmov: this.el('frvmov').value,
            coddst: this.el('coddst').value || 1, cotmov: this.el('cotmov').value, detmov: this.el('detmov').value,
            vdxmov: this.el('vdxmov').value || 0,
            cipmov: (this.modo === 'capacitacion') ? null : this.el('cipmov').value,
            lineas: ls.map(function (r) {
                return {
                    codpro: r.codpro, denmov: r.denmov, codudm: r.codudm, fctmov: r.fctmov, dummov: r.dummov,
                    codmon: r.codmon, cosmov: r.cosmov, punmov: r.punmov, pucmov: r.punmov, pulmov: r.pulmov,
                    stk: r.stk, cant: r.cant, odcmov: r.odc, odpmov: r.odp, pdlmov: r.pdl
                };
            })
        };
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        this.el('btnGuardar').disabled = true;
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        this.el('btnGuardar').disabled = false;
        if (!j.ok) { this.el('remErr').textContent = j.error; return; }
        var pdv = j.data.cipmov ? String(j.data.cipmov).padStart(4, '0') : '9999';
        var nro = String(j.data.cinmov).padStart(8, '0');
        this.toast('Remito grabado: ' + pdv + '-' + nro + ' (mov ' + j.data.nummov + '). Listo para el próximo.', 'success');
        window.open('imprimir.php?nummov=' + j.data.nummov + '&print=1', '_blank');   // impresión sobre preimpreso
        this.reset();
    },

    // --- autocomplete genérico (servidor como fuente de verdad) ---
    autocomplete(input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() {
            list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + R.esc(label(o)) + '</div>'; }).join('');
            list.classList.toggle('show', items.length > 0);
        }
        input.addEventListener('input', function () {
            clearTimeout(t); var q = input.value.trim();
            if (q.length < 1) { items = []; render(); return; }
            t = setTimeout(async function () { var j = await R.api(action, { q: q }); items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }, 180);
        });
        input.addEventListener('keydown', function (e) {
            if (!list.classList.contains('show')) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); }
            else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); e.stopPropagation(); onPick(items[hi]); list.classList.remove('show'); } }
            else if (e.key === 'Escape') { list.classList.remove('show'); }
        });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },

    toast(msg, type) {
        var t = this.el('toastMsg'); this.el('toastBody').textContent = msg;
        t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info');
        bootstrap.Toast.getOrCreateInstance(t, { delay: 6000 }).show();
    },
};
document.addEventListener('DOMContentLoaded', function () { R.init(); });
