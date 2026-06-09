/* Notas de Débito Acreedoras — registrar la ND del proveedor (Frm CA Debitos). Proveedor → comprobante
   del proveedor → neto/IVA → imputación (a acreditar: revierte gasto + IVA) → referencias (comprobantes
   del proveedor que se debitan) → grabar. El excedente (total − Σ referencias) queda como anticipo. */
const CP = {
    prov: null, grabado: false, totales: { neto: 0, iva: 0, nog: 0, ali: 21, total: 0 },
    imps: [], pend: [], centros: {}, impSel: null, totManual: false,
    ivaCta() { return document.getElementById('cpForm').getAttribute('data-ivacta') || ''; },

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    n(v) { var x = parseFloat(v); return isNaN(x) ? '0.00' : x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    r2(v) { return Math.round((parseFloat(v) || 0) * 100) / 100; },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        return await (await fetch(url, opts || {})).json();
    },

    async init() {
        var hoy = new Date().toISOString().slice(0, 10);
        this.el('fexmov').value = hoy; this.el('cef').value = hoy; this.el('fixmov').value = hoy;
        var cc = await this.api('centros_costo');
        if (cc.ok) { this.el('impCdc').innerHTML = cc.data.map(function (o) { CP.centros[o.CODCDC] = (o.DENCDC || '').trim(); return '<option value="' + o.CODCDC + '">' + CP.esc(o.DENCDC) + '</option>'; }).join(''); }
        this.autocomplete(this.el('provQ'), this.el('provList'), 'buscar_proveedores', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { CP.pickProv(o.CODCUE); });
        this.autocomplete(this.el('impCtaQ'), this.el('impCtaList'), 'cuentas', function (o) { return o.CODCUE + ' · ' + o.DENCUE; }, function (o) { CP.impSel = { codcue: o.CODCUE, label: o.CODCUE + ' · ' + (o.DENCUE || '').trim() }; CP.el('impCta').value = o.CODCUE; CP.el('impCtaQ').value = CP.impSel.label; });
        ['netmov', 'alimov', 'net2mov', 'ali2mov', 'nogmov', 'ip1mov', 'ip2mov'].forEach(function (id) { CP.el(id).addEventListener('input', function () { CP.recalc(); }); });
        this.el('totmov').addEventListener('input', function () { CP.totManual = true; CP.recalc(); });
        this.el('totReset').addEventListener('click', function (e) { e.preventDefault(); CP.totManual = false; CP.recalc(); });
        this.el('cei').addEventListener('change', function () { CP.aplicarResponsabilidad(); });
        this.el('ap1mov').addEventListener('input', function () { CP.percepFromPct(); });
        this.el('ap2mov').addEventListener('input', function () { CP.percepFromPct(); });
        this.el('toggle493').addEventListener('click', function (e) {
            e.preventDefault();
            var r = CP.el('row493'), shown = r.style.display !== 'none';
            r.style.display = shown ? 'none' : 'flex';
            this.innerHTML = (shown ? '<i class="bi bi-plus-square me-1"></i>' : '<i class="bi bi-dash-square me-1"></i>') + 'Neto D.493/01 (2ª alícuota)';
            if (shown) { CP.el('net2mov').value = '0'; CP.recalc(); }
        });
        this.el('btnAddImp').addEventListener('click', function () { CP.addImp(); });
        this.el('btnSugIva').addEventListener('click', function () { CP.sugerirIva(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnBuscarCP').addEventListener('click', function () { CP.openBuscar(); });
        this.el('btnGrabar').addEventListener('click', function () { CP.grabar(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (CP.anulNum) CP.anular(CP.anulNum); });
        this.setupCollapsibles();
        this.recalc();
    },
    setupCollapsibles() {
        Array.prototype.forEach.call(document.querySelectorAll('#cpForm .fc-card > .card-header'), function (h) {
            h.addEventListener('click', function () {
                var body = h.parentNode.querySelector('.card-body'); if (!body) return;
                var collapse = body.style.display !== 'none';
                body.style.display = collapse ? 'none' : '';
                h.classList.toggle('collapsed', collapse);
            });
        });
    },

    async pickProv(codcue) {
        var j = await this.api('get_proveedor', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data; this.prov = d;
        this.el('codcue').value = codcue; this.el('provQ').value = d.DENCUE;
        this.el('saldo').value = this.n(d.SALDO); this.el('sancue').value = this.n(d.SALDO_ANTIC);
        this.el('provInfo').textContent = [d.CITCUE, d.DENCRI, d.DOMICILIO, d.LOCALIDAD].filter(Boolean).join(' · ');
        this.el('cei').value = d.ES_RI ? 'A' : 'C';
        this.totManual = false;
        this.aplicarResponsabilidad();
        var p = await this.api('pendientes', { codcue: codcue });
        this.pend = (p.ok && p.data) ? p.data : [];
        this.renderRefs();
    },

    recalc() {
        var ali1 = parseFloat(this.el('alimov').value) || 0, ali2 = parseFloat(this.el('ali2mov').value) || 0;
        var neto1 = this.r2(this.el('netmov').value), neto2 = this.r2(this.el('net2mov').value), nog = this.r2(this.el('nogmov').value);
        var iva1 = Math.round(neto1 * ali1) / 100, iva2 = Math.round(neto2 * ali2) / 100;
        var ip1 = this.r2(this.el('ip1mov').value), ip2 = this.r2(this.el('ip2mov').value);
        var neto = Math.round((neto1 + neto2) * 100) / 100, iva = Math.round((iva1 + iva2) * 100) / 100, perc = Math.round((ip1 + ip2) * 100) / 100;
        var compTotal = Math.round((neto + iva + nog + perc) * 100) / 100;
        if (!this.totManual) this.el('totmov').value = compTotal.toFixed(2);
        var total = Math.round((parseFloat(this.el('totmov').value) || 0) * 100) / 100;
        var dif = Math.round((total - compTotal) * 100) / 100, discrim = (this.el('cei').value !== 'C');
        this.el('totWarn').textContent = (discrim && Math.abs(dif) >= 0.01) ? ('⚠ subtotales ' + this.n(compTotal) + ' · dif ' + (dif > 0 ? '+' : '') + this.n(dif)) : '';
        this.el('totReset').classList.toggle('d-none', !this.totManual);
        this.el('irimov').value = this.n(iva1); this.el('iri2mov').value = this.n(iva2);
        this.totales = { neto1: neto1, ali1: ali1, iva1: iva1, neto2: neto2, ali2: ali2, iva2: iva2, neto: neto, iva: iva, nog: nog, ip1: ip1, ip2: ip2, ap1: this.r2(this.el('ap1mov').value), ap2: this.r2(this.el('ap2mov').value), perc: perc, compTotal: compTotal, total: total };
        this.el('impTot').textContent = this.n(total);
        this.refresh();
    },
    percepFromPct() {
        var neto1 = this.r2(this.el('netmov').value), neto2 = this.r2(this.el('net2mov').value), nog = this.r2(this.el('nogmov').value);
        var ap1 = parseFloat(this.el('ap1mov').value) || 0, ap2 = parseFloat(this.el('ap2mov').value) || 0;
        if (ap1 > 0) this.el('ip1mov').value = (Math.round((neto1 + neto2) * ap1) / 100).toFixed(2);
        if (ap2 > 0) this.el('ip2mov').value = (Math.round((neto1 + neto2 + nog) * ap2) / 100).toFixed(2);
        this.recalc();
    },
    aplicarResponsabilidad() {
        var discrim = (this.el('cei').value !== 'C');
        var piva = discrim && this.prov && this.prov.APLICA_PIVA;
        var piibb = discrim && this.prov && this.prov.APLICA_PIIBB;
        this.lockField('alimov', !discrim); this.lockField('net2mov', !discrim); this.lockField('ali2mov', !discrim); this.lockField('nogmov', !discrim);
        this.lockField('netmov', !discrim);
        this.lockField('ap1mov', !piva); this.lockField('ip1mov', !piva);
        this.lockField('ap2mov', !piibb); this.lockField('ip2mov', !piibb);
        if (!discrim) { this.el('row493').style.display = 'none'; this.el('net2mov').value = '0'; this.el('toggle493').innerHTML = '<i class="bi bi-plus-square me-1"></i>Neto D.493/01 (2ª alícuota)'; }
        this.el('toggle493').style.display = discrim ? '' : 'none';
        this.recalc();
    },
    lockField(id, locked) { var e = this.el(id); e.disabled = locked; if (locked) e.value = '0'; },

    impSum() { return Math.round(this.imps.reduce(function (s, i) { return s + i.importe; }, 0) * 100) / 100; },
    refSum() {
        var inp = document.querySelectorAll('.ref-imp');
        if (!inp.length && this.readonly) return this._refLoaded || 0;
        return Math.round(Array.prototype.slice.call(inp).reduce(function (s, i) { return s + (parseFloat(i.value) || 0); }, 0) * 100) / 100;
    },
    refresh() {
        var t = this.totales.total, is = this.impSum(), rs = this.refSum();
        this.el('impSum').textContent = this.n(is);
        if (this.el('refSum')) this.el('refSum').textContent = this.n(rs);
        if (this.el('refExc')) this.el('refExc').textContent = this.n(Math.max(0, Math.round((t - rs) * 100) / 100));   // excedente → anticipo
        this.el('impOk').innerHTML = (Math.abs(is - t) < 0.01 && t > 0) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-exclamation-circle text-warning"></i>';
        this.el('impDeb').value = Math.max(0, Math.round((t - is) * 100) / 100) || '';
    },

    // ---- Imputación (a acreditar: revierte gasto + IVA) ----
    addImp() {
        if (!this.impSel) { this.toast('Elegí una cuenta.', 'warning'); return; }
        var imp = this.r2(this.el('impDeb').value);
        if (imp <= 0) { this.toast('Poné el importe a acreditar.', 'warning'); return; }
        var cdc = this.el('impCdc').value;
        var ali = parseFloat(this.el('impAli').value); if (isNaN(ali)) ali = 0;
        var iva = Math.round(imp * ali) / 100, tot = Math.round((imp + iva) * 100) / 100;   // ALIMOV/IVAMOV/TOTMOV (export Holistor)
        this.imps.push({ codcue: this.impSel.codcue, label: this.impSel.label, codcdc: cdc, cdcName: this.centros[cdc] || cdc, importe: imp, alimov: ali, ivamov: iva, totmov: tot });
        this.impSel = null; this.el('impCta').value = ''; this.el('impCtaQ').value = '';
        this.renderImps(); this.refresh();
    },
    sugerirIva() {
        if (this.totales.iva <= 0) { this.toast('No hay IVA para imputar.', 'info'); return; }
        if (this.imps.some(function (i) { return i.codcue === CP.ivaCta(); })) { this.toast('La fila de IVA Crédito ya está.', 'info'); return; }
        var cdc = this.el('impCdc').value;
        this.imps.push({ codcue: this.ivaCta(), label: this.ivaCta() + ' · I.V.A. Crédito Fiscal', codcdc: cdc, cdcName: this.centros[cdc] || cdc, importe: this.totales.iva, alimov: null, ivamov: null, totmov: null });
        this.renderImps(); this.refresh();
    },
    renderImps() {
        this.el('impBody').innerHTML = this.imps.map(function (i, k) {
            var aliTxt = (i.alimov === null || typeof i.alimov === 'undefined') ? '—' : (i.alimov > 0 ? CP.n(i.alimov) + '%' : 'No grav.');
            return '<tr><td>' + CP.esc(i.label) + '</td><td>' + CP.esc(i.cdcName) + '</td><td class="cp-num">' + aliTxt + '</td><td class="cp-num">' + CP.n(i.importe) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger i-del" data-k="' + k + '"><i class="bi bi-x"></i></button></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('impBody').querySelectorAll('.i-del'), function (b) { b.addEventListener('click', function () { CP.imps.splice(+this.getAttribute('data-k'), 1); CP.renderImps(); CP.refresh(); }); });
    },

    // ---- Referencias (comprobantes del proveedor que se debitan) ----
    renderRefs() {
        if (!this.pend.length) { this.el('refList').innerHTML = '<span class="text-muted">El proveedor no tiene comprobantes pendientes.</span>'; this.refresh(); return; }
        this.el('refList').innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Comprobante</th><th>Venc.</th><th class="cp-num">Saldo</th><th class="cp-num" style="width:108px">A debitar</th></tr></thead><tbody>' +
            this.pend.map(function (p, k) {
                return '<tr><td class="small"><b>' + CP.esc(p.INT) + '</b>' + (p.EXT ? ' · ' + CP.esc(p.EXT) : '') + '</td><td class="small">' + CP.esc(p.FVXMOV) + '</td><td class="cp-num">' + CP.n(p.SALDO) + '</td>' +
                    '<td><input type="number" step="0.01" class="form-control form-control-sm cp-num ref-imp" data-k="' + k + '" data-max="' + p.SALDO + '" value="0"></td></tr>';
            }).join('') + '</tbody></table>';
        Array.prototype.forEach.call(this.el('refList').querySelectorAll('.ref-imp'), function (inp) {
            inp.addEventListener('input', function () { var max = parseFloat(this.getAttribute('data-max')) || 0; if ((parseFloat(this.value) || 0) > max) this.value = max; CP.refresh(); });
        });
        this.refresh();
    },
    selectedRefs() {
        return Array.prototype.slice.call(document.querySelectorAll('.ref-imp')).map(function (i) {
            var p = CP.pend[+i.getAttribute('data-k')]; return { refmov: p.REFMOV, fvxmov: p.FVXISO, imp: parseFloat(i.value) || 0 };
        }).filter(function (r) { return r.imp > 0; });
    },

    // ---- Buscar / ver ND Acreedoras emitidas ----
    openBuscar() {
        if (!this._bqInit) {
            this._bqInit = true;
            this.el('btnBQ').addEventListener('click', function () { CP.buscar(); });
            this.el('bqQ').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); CP.buscar(); } });
        }
        bootstrap.Modal.getOrCreateInstance(this.el('modalBuscarCP')).show();
        this.buscar();
    },
    async buscar() {
        this.el('bqDetalle').innerHTML = '';
        var j = await this.api('listar', { q: this.el('bqQ').value, desde: this.el('bqDesde').value, hasta: this.el('bqHasta').value });
        var rows = (j.ok && j.data) ? j.data : [];
        this.el('bqBody').innerHTML = rows.length ? rows.map(function (r) {
            return '<tr class="bq-row" data-num="' + r.NUMMOV + '" style="cursor:pointer"><td>' + CP.esc(r.NUMERO) + (r.ANULADO ? ' <span class="badge bg-danger">ANULADO</span>' : '') + '</td><td>' + CP.esc(r.FECHA) + '</td><td>' + CP.esc(r.PROVEEDOR) + '</td><td class="small">' + CP.esc(r.COMP) + '</td><td class="text-end cp-num">' + CP.n(r.TOTAL) + '</td></tr>';
        }).join('') : '<tr><td colspan="5" class="text-muted py-3">Sin resultados.</td></tr>';
        Array.prototype.forEach.call(this.el('bqBody').querySelectorAll('.bq-row'), function (tr) { tr.addEventListener('click', function () { CP.cargarND(+this.getAttribute('data-num')); }); });
    },
    async cargarND(num) {
        var j = await this.api('detalle', { nummov: num });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data;
        var bm = bootstrap.Modal.getInstance(this.el('modalBuscarCP')); if (bm) bm.hide();
        this.prov = null;
        this.el('codcue').value = d.CODCUE; this.el('provQ').value = d.PROVEEDOR; this.el('provInfo').textContent = d.INFO;
        this.el('sancue').value = this.n(d.SANCUE); this.el('saldo').value = this.n(d.SOPCUE);
        this.el('nummov').value = d.NUMERO; this.el('cinmov').value = d.NUMERO; this.el('cipmov').value = d.CIPMOV;
        this.el('fexmov').value = d.FEXISO;
        this.el('cec').value = d.CEC; this.el('cei').value = d.CEI;
        this.el('cep').value = d.CEP; this.el('cen').value = d.CEN; this.el('cef').value = d.CEFISO;
        this.el('fixmov').value = d.FIXISO; this.el('detmov').value = d.DETMOV;
        this.el('netmov').value = d.NET1; this.el('alimov').value = d.ALI1; this.el('irimov').value = d.IRI1;
        this.el('nogmov').value = d.NOGRAV;
        this.el('ap1mov').value = d.AP1; this.el('ip1mov').value = d.IP1; this.el('ap2mov').value = d.AP2; this.el('ip2mov').value = d.IP2;
        if (d.NET2 > 0) { this.el('row493').style.display = 'flex'; this.el('net2mov').value = d.NET2; this.el('ali2mov').value = d.ALI2; this.el('iri2mov').value = d.IRI2; }
        this.el('totmov').value = d.TOTAL;
        this.imps = (d.imputacion || []).map(function (i) { return { codcue: i.codcue, label: i.label, codcdc: i.codcdc, cdcName: (CP.centros && CP.centros[i.codcdc]) || i.codcdc, importe: i.importe }; });
        this.renderImps();
        var refs = d.referencias || [];
        this._refLoaded = Math.round(refs.reduce(function (s, r) { return s + r.imp; }, 0) * 100) / 100;
        this.el('refList').innerHTML = refs.length
            ? ('<table class="table table-sm mb-0"><thead><tr><th>Comprobante</th><th>Venc.</th><th class="cp-num">Debitado</th></tr></thead><tbody>' +
                refs.map(function (r) { return '<tr><td class="small"><b>' + CP.esc(r.comp) + '</b>' + (r.compext ? ' · ' + CP.esc(r.compext) : '') + '</td><td class="small">' + CP.esc(r.fvx) + '</td><td class="cp-num">' + CP.n(r.imp) + '</td></tr>'; }).join('') + '</tbody></table>')
            : '<span class="text-muted">Sin referencias.</span>';
        this.totales = { total: this.r2(d.TOTAL), neto: this.r2(d.NET1) + this.r2(d.NET2), iva: this.r2(d.IRI1) + this.r2(d.IRI2), nograv: this.r2(d.NOGRAV) };
        this.refresh();
        this.el('impTot').textContent = this.n(d.TOTAL); this.el('refSum').textContent = this.n(this._refLoaded);
        this.el('refExc').textContent = this.n(Math.max(0, Math.round((this.r2(d.TOTAL) - this._refLoaded) * 100) / 100));
        this.lockForm(num, d.ANULABLE, d.ANULADO);
    },
    lockForm(num, anulable, anulado) {
        Array.prototype.forEach.call(document.querySelectorAll('#cpForm input, #cpForm select, #cpForm textarea'), function (el) { el.disabled = true; });
        Array.prototype.forEach.call(document.querySelectorAll('#cpForm button'), function (el) { el.style.display = 'none'; });
        this.el('btnGrabar').style.display = 'none';
        var b = this.el('roBanner'); b.style.display = '';
        b.innerHTML = '<i class="bi bi-eye me-1"></i>Nota de Débito Acreedora <b>Nº ' + this.esc(num) + '</b> — modo <b>sólo lectura</b>' +
            (anulado ? ' · <span class="badge bg-danger">ANULADA</span>' : '') +
            ' · <a href="#" id="roNuevo">Cargar otra / Nueva</a>';
        var rn = this.el('roNuevo'); if (rn) rn.addEventListener('click', function (e) { e.preventDefault(); location.reload(); });
        if (anulable && !anulado) { this.anulNum = num; this.el('btnAnularHdr').style.display = ''; }
        this.readonly = true;
    },

    async grabar() {
        this.el('cpErr').textContent = '';
        if (this.grabado) { this.toast('La nota de débito ya fue grabada.', 'info'); return; }
        var t = this.totales, p = this.prov;
        if (!this.el('codcue').value) { this.el('cpErr').textContent = 'Elegí un proveedor.'; return; }
        if (!(parseInt(this.el('cen').value, 10) > 0)) { this.el('cpErr').textContent = 'Cargá el número del comprobante del proveedor.'; return; }
        if (t.total <= 0) { this.el('cpErr').textContent = 'Ingresá el neto / no gravado del comprobante.'; return; }
        if (Math.abs(this.impSum() - t.total) >= 0.01) { this.el('cpErr').textContent = 'La imputación (' + this.n(this.impSum()) + ') no coincide con el total (' + this.n(t.total) + ').'; return; }
        if (this.refSum() - t.total >= 0.01) { this.el('cpErr').textContent = 'Las referencias (' + this.n(this.refSum()) + ') no pueden superar el total (' + this.n(t.total) + ').'; return; }
        var ivas = [];
        if (t.neto1 > 0) ivas.push({ net: t.neto1, ali: t.ali1, iva: t.iva1 });
        if (t.neto2 > 0) ivas.push({ net: t.neto2, ali: t.ali2, iva: t.iva2 });
        var data = {
            codcue: this.el('codcue').value, fexmov: this.el('fexmov').value, fixmov: this.el('fixmov').value, detmov: this.el('detmov').value,
            cec: this.el('cec').value, cei: this.el('cei').value, cep: this.el('cep').value, cen: this.el('cen').value, cef: this.el('cef').value,
            citmov: p.CITCUE, codcri: p.CODCRI, nogmov: t.nog, ip1mov: t.ip1, ip2mov: t.ip2, ap1mov: t.ap1, ap2mov: t.ap2, total: t.total,
            ivas: ivas,
            imputaciones: this.imps.map(function (i) { return { codcue: i.codcue, codcdc: i.codcdc, importe: i.importe, alimov: i.alimov, ivamov: i.ivamov, totmov: i.totmov }; }),
            referencias: this.selectedRefs()
        };
        this.el('btnGrabar').disabled = true; this.el('btnGrabar').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Grabando…';
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        this.el('btnGrabar').innerHTML = '<i class="bi bi-save me-1"></i>Grabar nota de débito';
        if (!j.ok) { this.el('btnGrabar').disabled = false; this.el('cpErr').textContent = j.error; return; }
        this.grabado = true;
        this.el('nummov').value = String(j.data.nummov).padStart(8, '0'); this.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
        Array.prototype.forEach.call(document.querySelectorAll('#cpForm input, #cpForm select, #cpForm button.btn-outline-primary, #cpForm button.btn-outline-secondary, .i-del, .ref-imp'), function (el) { el.disabled = true; });
        if (j.data.anulable) { this.anulNum = j.data.nummov; this.el('btnAnularHdr').style.display = ''; }
        this.toast('Nota de débito acreedora grabada: Nº ' + String(j.data.cinmov).padStart(8, '0') + ' (mov ' + j.data.nummov + ', total ' + this.n(j.data.total) + ').', 'success');
    },

    async anular(num) {
        if (!confirm('¿Anular esta nota de débito acreedora?\nSe revierten el asiento contable, la cuenta corriente del proveedor y las referencias a los comprobantes. No se puede deshacer.')) return;
        var fd = new FormData(); fd.append('action', 'anular'); fd.append('nummov', num);
        var j = await this.api('anular', {}, { method: 'POST', body: fd });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.el('btnAnularHdr').style.display = 'none';
        this.toast('Nota de débito acreedora ' + num + ' anulada.', 'success');
    },

    autocomplete(input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() { list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + CP.esc(label(o)) + '</div>'; }).join(''); list.classList.toggle('show', items.length > 0); }
        input.addEventListener('input', function () { clearTimeout(t); var q = input.value.trim(); if (q.length < 1) { items = []; render(); return; } t = setTimeout(async function () { var j = await CP.api(action, { q: q }); items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }, 180); });
        input.addEventListener('keydown', function (e) { if (!list.classList.contains('show')) return; if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); } else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); } else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); onPick(items[hi]); list.classList.remove('show'); } } else if (e.key === 'Escape') list.classList.remove('show'); });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },
    toast(msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 7000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { CP.init(); });
