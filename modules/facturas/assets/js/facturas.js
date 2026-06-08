/* Facturas de Venta — emisión con CAE de AFIP. Cliente → remitos pendientes → productos → totales → emitir.
   Factura A: el precio del producto es NETO; IVA se suma. Importes punto-decimal (en-US). */
const FV = {
    cli: null, lineas: [], seq: 0, emitida: false,

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
        var cd = await this.api('condiciones'); if (cd.ok) this.el('codcdv').innerHTML = cd.data.map(function (o) { return '<option value="' + o.CODCDV + '">' + FV.esc(o.DENCDV) + '</option>'; }).join('');
        var fp = await this.api('formas_pago'); this.chqFdp = {}; if (fp.ok) { this.el('codfdp').innerHTML = fp.data.map(function (o) { FV.chqFdp[o.CODFDP] = (o.CHQFDP === true || o.CHQFDP === -1 || o.CHQFDP === 1); return '<option value="' + o.CODFDP + '">' + FV.esc(o.DENFDP) + '</option>'; }).join(''); }
        var bk = await this.api('bancos'); this.bancos = bk.ok ? bk.data : [];
        this.cheques = [];
        this.el('codcdv').addEventListener('change', function () { FV.toggleCheques(); });
        this.el('codfdp').addEventListener('change', function () { FV.toggleCheques(); });
        this.el('btnAddChq').addEventListener('click', function () { FV.addCheque(); });
        this.autocomplete(this.el('cliQ'), this.el('cliList'), 'buscar_clientes', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { FV.pickCliente(o.CODCUE); });
        this.el('btnAddRem').addEventListener('click', function () { FV.abrirRemitos(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnEmitir').addEventListener('click', function () { FV.emitir(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (FV.anulNum) FV.anular(FV.anulNum); });
        this.el('pdcmov').addEventListener('input', function () { FV.recalc(); });
        this.el('btnBuscar').addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance(FV.el('modalBuscar')).show(); FV.loadList(); });
        this.el('bGo').addEventListener('click', function () { FV.loadList(); });
        this.el('bQ').addEventListener('keydown', function (e) { if (e.key === 'Enter') FV.loadList(); });
    },

    async pickCliente(codcue) {
        var j = await this.api('get_cliente', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data; this.cli = d;
        this.el('codcue').value = codcue; this.el('cliQ').value = d.DENCUE;
        this.el('saldo').value = this.n(d.SALDO);
        this.saldoAFavor = (parseFloat(d.SALDO) < 0) ? Math.round(-parseFloat(d.SALDO) * 100) / 100 : 0;   // crédito disponible
        if (this.saldoAFavor > 0) this.toast('El cliente tiene saldo a favor $' + this.n(this.saldoAFavor) + ' — se aplicará a la factura.', 'info');
        this.el('letra').value = d.LETRA || 'A';
        this.el('cliInfo').textContent = [d.CITCUE, d.DENCRI, d.DOMICILIO, d.LOCALIDAD].filter(Boolean).join(' · ');
        if (d.CODCDV) this.el('codcdv').value = d.CODCDV;
        this.el('btnAddRem').disabled = false;
        this.lineas = []; this.el('prodBody').innerHTML = ''; this.recalc();
    },

    // ---- Remitos pendientes ----
    async abrirRemitos() {
        var j = await this.api('remitos_pendientes', { codcue: this.el('codcue').value });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var used = {}; this.lineas.forEach(function (l) { used[l.mrvmov + '-' + l.orvmov] = 1; });
        var rems = j.data.filter(function (r) { return r.lineas.some(function (l) { return !used[l.mrvmov + '-' + l.orvmov]; }); });
        if (!rems.length) { this.el('remList').innerHTML = ''; this.el('remVacio').style.display = ''; }
        else {
            this.el('remVacio').style.display = 'none';
            this.el('remList').innerHTML = rems.map(function (r) {
                var ln = r.lineas.filter(function (l) { return !used[l.mrvmov + '-' + l.orvmov]; });
                return '<div class="card mb-2"><div class="card-header py-1 d-flex justify-content-between align-items-center"><span><b>' + FV.esc(r.COMP) + '</b> · ' + FV.esc(r.FEXMOV) + '</span>' +
                    '<button class="btn btn-sm btn-success rem-add" data-num="' + r.NUMMOV + '">Agregar todo</button></div>' +
                    '<table class="table table-sm mb-0"><tbody>' + ln.map(function (l, k) {
                        return '<tr><td style="width:90px" class="fv-num">' + FV.n(l.cant) + '</td><td>' + FV.esc(l.codpro) + '</td><td>' + FV.esc(l.denmov) + '</td><td class="fv-num" style="width:120px">' + FV.n(l.pun) + '</td><td class="fv-num" style="width:130px">' + FV.n(l.total) + '</td>' +
                            '<td style="width:80px"><button class="btn btn-sm btn-outline-primary rem-line" data-num="' + r.NUMMOV + '" data-k="' + k + '">+</button></td></tr>';
                    }).join('') + '</tbody></table></div>';
            }).join('');
            // wire (guardo las líneas por remito para el handler)
            this._remData = {}; rems.forEach(function (r) { FV._remData[r.NUMMOV] = r.lineas.filter(function (l) { return !used[l.mrvmov + '-' + l.orvmov]; }); });
            Array.prototype.forEach.call(this.el('remList').querySelectorAll('.rem-add'), function (b) { b.addEventListener('click', function () { (FV._remData[this.getAttribute('data-num')] || []).forEach(function (l) { FV.addLinea(l); }); bootstrap.Modal.getInstance(FV.el('modalRem')).hide(); }); });
            Array.prototype.forEach.call(this.el('remList').querySelectorAll('.rem-line'), function (b) { b.addEventListener('click', function () { var l = (FV._remData[this.getAttribute('data-num')] || [])[+this.getAttribute('data-k')]; if (l) { FV.addLinea(l); this.closest('tr').remove(); } }); });
        }
        bootstrap.Modal.getOrCreateInstance(this.el('modalRem')).show();
    },

    addLinea(l) {
        var rec = JSON.parse(JSON.stringify(l)); rec._id = ++this.seq;
        this.lineas.push(rec);
        var ptp = rec.pdlmov ? String(rec.pdlmov) : '';
        var remN = String(rec.mrvmov);
        var tr = document.createElement('tr');
        if (!rec.ali) rec.ali = 21;
        tr.innerHTML = '<td class="fv-num">' + this.n(rec.cant) + '</td><td>' + this.esc(remN) + '</td><td>' + this.esc(ptp) + '</td><td>' + this.esc(rec.codpro) + '</td><td>' + this.esc(rec.denmov) + '</td>' +
            '<td><input type="number" step="0.0001" class="form-control form-control-sm fv-num l-pun" value="' + rec.pun + '"></td>' +
            '<td><select class="form-select form-select-sm l-ali"><option value="21"' + (rec.ali == 10.5 ? '' : ' selected') + '>21%</option><option value="10.5"' + (rec.ali == 10.5 ? ' selected' : '') + '>10.5%</option></select></td>' +
            '<td class="fv-num l-tot">' + this.n(rec.total) + '</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger l-del"><i class="bi bi-x"></i></button></td>';
        this.el('prodBody').appendChild(tr);
        tr.querySelector('.l-ali').addEventListener('change', function () { rec.ali = parseFloat(this.value) || 21; FV.recalc(); });
        tr.querySelector('.l-pun').addEventListener('input', function () { rec.pun = parseFloat(this.value) || 0; rec.total = Math.round(rec.cant * rec.pun * 100) / 100; tr.querySelector('.l-tot').textContent = FV.n(rec.total); FV.recalc(); });
        tr.querySelector('.l-del').addEventListener('click', function () { tr.remove(); FV.lineas = FV.lineas.filter(function (x) { return x !== rec; }); FV.recalc(); });
        this.recalc();
    },

    recalc() {
        // Factura A: el precio es neto; IVA se suma. (MVP: agrupa por alícuota.)
        var sub = 0, buckets = {};
        this.lineas.forEach(function (l) { var t = Math.round(l.cant * l.pun * 100) / 100; sub += t; var a = l.ali || 21; buckets[a] = (buckets[a] || 0) + t; });
        sub = Math.round(sub * 100) / 100;
        var neto = sub, iva = 0;
        for (var a in buckets) iva += Math.round(buckets[a] * a) / 100;
        iva = Math.round(iva * 100) / 100;
        var total = Math.round((neto + iva) * 100) / 100;
        this.totales = { neto: neto, iva: iva, total: total, buckets: buckets };
        var alics = Object.keys(buckets).filter(function (a) { return buckets[a] > 0; }).map(function (a) { return (parseFloat(a) % 1 === 0 ? parseFloat(a) : parseFloat(a).toFixed(1)) + '%'; });
        this.el('lblAli').textContent = alics.length ? alics.join(' + ') : '21%';
        this.el('subTotal').textContent = this.n(sub);
        this.el('tNeto').textContent = this.n(neto);
        this.el('tIva').textContent = this.n(iva);
        this.el('tTotal').textContent = this.n(total);
        // Saldo a favor del cliente: se aplica a esta factura → "A cobrar" = total − crédito aplicado.
        var saf = this.saldoAFavor || 0;
        if (saf > 0 && total > 0) {
            var aplica = Math.min(saf, total);
            this.el('boxSaf').style.display = ''; this.el('tSaf').textContent = this.n(aplica);
            this.el('boxCobrar').style.display = ''; this.el('tCobrar').textContent = this.n(Math.round((total - aplica) * 100) / 100);
        } else { this.el('boxSaf').style.display = 'none'; this.el('boxCobrar').style.display = 'none'; }
    },

    // ---- Cheques (contado) ----
    toggleCheques() {
        var show = (this.el('codcdv').value == '1') && !!this.chqFdp[this.el('codfdp').value];
        this.el('cardChq').style.display = show ? '' : 'none';
        if (!show) { this.cheques = []; this.el('chqBody').innerHTML = ''; this.chqRecalc(); }
    },
    addCheque() {
        var rec = { codban: this.bancos.length ? this.bancos[0].CODBAN : 0, syn: '', fexiso: '', faxiso: '', lib: '', cit: '', imp: 0 };
        this.cheques.push(rec);
        var opts = this.bancos.map(function (b) { return '<option value="' + b.CODBAN + '">' + FV.esc(b.DENBAN) + '</option>'; }).join('');
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><select class="form-select form-select-sm c-ban">' + opts + '</select></td>' +
            '<td><input class="form-control form-control-sm c-syn"></td>' +
            '<td><input type="date" class="form-control form-control-sm c-fex"></td>' +
            '<td><input type="date" class="form-control form-control-sm c-fax"></td>' +
            '<td><input class="form-control form-control-sm c-lib"></td>' +
            '<td><input class="form-control form-control-sm c-cit"></td>' +
            '<td><input type="number" step="0.01" class="form-control form-control-sm fv-num c-imp"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger c-del"><i class="bi bi-x"></i></button></td>';
        this.el('chqBody').appendChild(tr);
        tr.querySelector('.c-ban').addEventListener('change', function () { rec.codban = parseInt(this.value, 10) || 0; });
        tr.querySelector('.c-syn').addEventListener('input', function () { rec.syn = this.value; });
        tr.querySelector('.c-fex').addEventListener('input', function () { rec.fexiso = this.value; });
        tr.querySelector('.c-fax').addEventListener('input', function () { rec.faxiso = this.value; });
        tr.querySelector('.c-lib').addEventListener('input', function () { rec.lib = this.value; });
        tr.querySelector('.c-cit').addEventListener('input', function () { rec.cit = this.value; });
        tr.querySelector('.c-imp').addEventListener('input', function () { rec.imp = parseFloat(this.value) || 0; FV.chqRecalc(); });
        tr.querySelector('.c-del').addEventListener('click', function () { tr.remove(); FV.cheques = FV.cheques.filter(function (x) { return x !== rec; }); FV.chqRecalc(); });
    },
    chqRecalc() { var t = (this.cheques || []).reduce(function (s, c) { return s + (c.imp || 0); }, 0); this.el('chqTotal').textContent = this.n(Math.round(t * 100) / 100); },

    async emitir() {
        this.el('fvErr').textContent = '';
        if (this.emitida) { this.toast('La factura ya fue emitida.', 'info'); return; }
        if (!this.el('codcue').value) { this.el('fvErr').textContent = 'Elegí un cliente.'; return; }
        if (!this.lineas.length) { this.el('fvErr').textContent = 'Agregá al menos un producto (de un remito).'; return; }
        if (this.totales.total <= 0) { this.el('fvErr').textContent = 'La factura no tiene importe.'; return; }
        var contadoChq = (this.el('codcdv').value == '1') && !!this.chqFdp[this.el('codfdp').value];
        var chqTot = 0;
        if (contadoChq) {
            chqTot = Math.round(this.cheques.reduce(function (s, c) { return s + (c.imp || 0); }, 0) * 100) / 100;
            if (Math.abs(chqTot - this.totales.total) > 0.05) { this.el('fvErr').textContent = 'En contado con cheque, los cheques deben cubrir el total (' + this.n(this.totales.total) + ').'; return; }
        }
        var c = this.cli, t = this.totales;
        var ivaArr = []; for (var a in t.buckets) { var net = Math.round(t.buckets[a] * 100) / 100; ivaArr.push({ ali: parseFloat(a), net: net, iri: Math.round(net * a) / 100, dec: parseFloat(a) == 21 ? 1 : 0 }); }
        var data = {
            codcue: this.el('codcue').value, fexmov: this.el('fexmov').value, ciimov: this.el('letra').value,
            codcdv: this.el('codcdv').value, codfdp: this.el('codfdp').value, pdcmov: parseFloat(this.el('pdcmov').value) || 0,
            detmov: this.el('detmov').value, cotmov: 1, coddst: 1, codven: c.CODVEN || '', codcri: c.CODCRI, cond_iva: c.COND_IVA,
            citmov: c.CITCUE, dcxmov: c.DCXCUE, dnxmov: c.DNXCUE, dpxmov: c.DPXCUE, ddxmov: c.DDXCUE, codloc: c.CODLOC,
            spimov: 1, apimov: 0, mpimov: 0, pixmov: 0, soc: c.SALDO,
            netmov: t.neto, irimov: t.iva, totmov: t.total, impcaj: 0,
            iva: ivaArr,
            productos: this.lineas.map(function (l) { return { mrvmov: l.mrvmov, orvmov: l.orvmov, odcmov: l.odcmov, pdlmov: l.pdlmov, odpmov: l.odpmov, codpro: l.codpro, denmov: l.denmov, codudm: l.codudm, fctmov: l.fctmov, dummov: l.dummov, codmon: l.codmon, decmov: l.decmov, pulmov: l.pul, punmov: l.pun, pucmov: l.puc, cosmov: l.cos, egr: l.cant, stk: l.stk, cic: l.cic, ndb: Math.round(l.cant * l.pun * 100) / 100 }; }),
            vencimientos: [],
            impcaj: contadoChq ? chqTot : 0,
            cheques: contadoChq ? this.cheques.map(function (q) { return { codban: q.codban, syn: q.syn, fex: q.fexiso, fax: q.faxiso || q.fexiso, plz: 0, lib: q.lib, cit: q.cit, loc: '', imp: q.imp }; }) : []
        };
        this.el('btnEmitir').disabled = true; this.el('btnEmitir').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Autorizando en AFIP…';
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        this.el('btnEmitir').innerHTML = '<i class="bi bi-cloud-arrow-up me-1"></i>Emitir factura (AFIP)';
        if (!j.ok) {
            this.el('btnEmitir').disabled = false;
            this.el('fvErr').textContent = j.error;   // detalle (el backend distingue AFIP caído vs rechazado)
            this.toast(j.kind === 'unreachable' ? 'AFIP no responde — reintentá en unos minutos (no se grabó nada).' : (j.kind === 'rejected' ? 'AFIP rechazó el comprobante — corregí los datos.' : 'No se pudo emitir la factura.'), j.kind === 'unreachable' ? 'warning' : 'danger');
            return;
        }
        this.emitida = true;
        this.el('nummov').value = String(j.data.nummov).padStart(8, '0');
        this.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
        if (j.data.cae) { this.el('caeDisp').textContent = j.data.cae; this.el('caeVto').textContent = j.data.cae_vto; this.el('caeWrap').style.display = ''; }
        Array.prototype.forEach.call(document.querySelectorAll('#fvForm input, #fvForm select, .l-pun, .l-del, #btnAddRem'), function (el) { el.disabled = true; });
        this.el('btnImprimirHdr').style.display = ''; this.el('btnImprimirHdr').onclick = function () { window.open('imprimir.php?nummov=' + j.data.nummov, '_blank'); };
        if (j.data.anulable) { this.anulNum = j.data.nummov; this.el('btnAnularHdr').style.display = ''; }
        var nro = this.el('letra').value + ' ' + String(j.data.cinmov).padStart(8, '0');
        this.toast(j.data.cae ? ('Factura ' + nro + ' autorizada · CAE ' + j.data.cae) : ('Factura ' + nro + ' grabada (capacitación · sin CAE)'), 'success');
    },

    // ---- Búsqueda / vista ----
    async loadList() {
        var p = { q: this.el('bQ').value.trim() };
        if (this.el('bD').value) p.desde = this.el('bD').value;
        if (this.el('bH').value) p.hasta = this.el('bH').value;
        var j = await this.api('listar', p); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var rows = j.data.facturas;
        this.el('bVacio').style.display = rows.length ? 'none' : '';
        this.el('bBody').innerHTML = rows.map(function (r) {
            var est = r.ANU ? '<span class="badge bg-danger">Anulada</span>' : '<span class="badge bg-success">OK</span>';
            return '<tr style="cursor:pointer" data-num="' + r.NUMMOV + '"><td>' + FV.esc(r.FEXMOV) + '</td><td>' + FV.esc(r.COMP) + '</td><td>' + FV.esc(r.DENMOV) + '</td><td class="fv-num">' + FV.n(r.TOTMOV) + '</td><td class="cae-box small">' + FV.esc(r.CAE) + '</td><td>' + est + '</td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('bBody').querySelectorAll('tr'), function (tr) { tr.addEventListener('click', function () { FV.verFactura(+this.getAttribute('data-num')); }); });
        if (j.data.tope) this.toast('Mostrando las 200 más recientes; afiná el filtro.', 'info');
    },
    async verFactura(num) {
        var j = await this.api('detalle', { nummov: num }); if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data;
        var bm = bootstrap.Modal.getInstance(this.el('modalBuscar')); if (bm) bm.hide();
        this.el('nummov').value = String(d.NUMMOV).padStart(8, '0');
        this.el('cinmov').value = String(d.CINMOV).padStart(8, '0');
        this.el('letra').value = d.LETRA; this.el('fexmov').value = d.FEXISO;
        this.el('codcue').value = d.CODCUE; this.el('cliQ').value = d.DENMOV;
        this.el('saldo').value = ''; this.el('cliInfo').textContent = [d.CITMOV, d.DENCRI, d.DOMICILIO, d.LOCALIDAD].filter(Boolean).join(' · ');
        if (d.CODCDV) this.el('codcdv').value = d.CODCDV; if (d.CODFDP) this.el('codfdp').value = d.CODFDP;
        this.el('pdcmov').value = d.PDCMOV; this.el('detmov').value = d.DETMOV;
        this.lineas = [];
        this.el('prodBody').innerHTML = d.productos.map(function (p) {
            return '<tr><td class="fv-num">' + FV.n(p.cant) + '</td><td>' + FV.esc(p.rem ? String(p.rem) : '') + '</td><td>' + FV.esc(p.ptp || '') + '</td><td>' + FV.esc(p.codpro) + '</td><td>' + FV.esc(p.denmov) + '</td><td class="fv-num">' + FV.n(p.pun) + '</td><td></td><td class="fv-num">' + FV.n(p.total) + '</td><td></td></tr>';
        }).join('');
        this.el('subTotal').textContent = this.n(d.NETMOV);
        this.el('tNeto').textContent = this.n(d.NETMOV); this.el('tIva').textContent = this.n(d.IRIMOV); this.el('tTotal').textContent = this.n(d.TOTMOV);
        if (d.CAE) { this.el('caeDisp').textContent = d.CAE; this.el('caeVto').textContent = d.CAE_VTO; this.el('caeWrap').style.display = ''; }
        this.lockForm(num, d.ANULABLE);
    },
    lockForm(num, anulable) {
        Array.prototype.forEach.call(document.querySelectorAll('#fvForm input, #fvForm select'), function (el) { el.disabled = true; });
        this.el('btnEmitir').style.display = 'none'; this.el('btnAddRem').style.display = 'none';
        this.el('btnImprimirHdr').style.display = ''; this.el('btnImprimirHdr').onclick = function () { window.open('imprimir.php?nummov=' + num, '_blank'); };
        if (anulable) { this.anulNum = num; this.el('btnAnularHdr').style.display = ''; }
        this.emitida = true;
    },
    async anular(num) {
        if (!confirm('¿Anular esta factura?\nSe revierten el asiento contable, la cuenta corriente, los vencimientos y el stock (y el recibo de contado si lo hubiera). No se puede deshacer.')) return;
        var fd = new FormData(); fd.append('action', 'anular'); fd.append('nummov', num);
        var j = await this.api('anular', {}, { method: 'POST', body: fd });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.el('btnAnularHdr').style.display = 'none'; this.el('btnImprimirHdr').style.display = 'none';
        this.toast('Factura ' + num + ' anulada.', 'success');
    },
    autocomplete(input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() { list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + FV.esc(label(o)) + '</div>'; }).join(''); list.classList.toggle('show', items.length > 0); }
        input.addEventListener('input', function () { clearTimeout(t); var q = input.value.trim(); if (q.length < 1) { items = []; render(); return; } t = setTimeout(async function () { var j = await FV.api(action, { q: q }); items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }, 180); });
        input.addEventListener('keydown', function (e) { if (!list.classList.contains('show')) return; if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); } else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); } else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); onPick(items[hi]); list.classList.remove('show'); } } else if (e.key === 'Escape') list.classList.remove('show'); });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },
    toast(msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 7000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { FV.init(); });
