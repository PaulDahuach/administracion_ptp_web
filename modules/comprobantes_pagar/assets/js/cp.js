/* Comprobantes a Pagar (acreedores) — registrar la factura del proveedor. Proveedor → comprobante del
   proveedor → neto/IVA → imputación (Debe, multi-fila) → vencimientos (multi-fila) → grabar.
   Productos con costo en pesos o u$s (cotización), factor, lista, flete, "act. precio venta"; remitos del proveedor. */
const CP = {
    prov: null, grabado: false, totales: { neto: 0, iva: 0, nog: 0, ali: 21, total: 0 },
    imps: [], vtos: [], productos: [], remPend: [], antPend: [], centros: {}, impSel: null, prodSel: null, totManual: false,
    ivaCta() { return document.getElementById('cpForm').getAttribute('data-ivacta') || ''; },
    cotmov() { return parseFloat(this.el('cotmov').value) || 1; },

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    n(v) { var x = parseFloat(v); return isNaN(x) ? '0.00' : x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    r2(v) { return Math.round((parseFloat(v) || 0) * 100) / 100; },
    addDays(iso, days) { var d = new Date(iso + 'T00:00:00'); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        return await (await fetch(url, opts || {})).json();
    },

    async init() {
        var hoy = new Date().toISOString().slice(0, 10);
        this.el('fexmov').value = hoy; this.el('cef').value = hoy; this.el('fixmov').value = hoy; this.el('vtoFx').value = this.addDays(hoy, 30);
        var cc = await this.api('centros_costo');
        if (cc.ok) { this.el('impCdc').innerHTML = cc.data.map(function (o) { CP.centros[o.CODCDC] = (o.DENCDC || '').trim(); return '<option value="' + o.CODCDC + '">' + CP.esc(o.DENCDC) + '</option>'; }).join(''); }
        this.autocomplete(this.el('provQ'), this.el('provList'), 'buscar_proveedores', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { CP.pickProv(o.CODCUE); });
        this.autocomplete(this.el('impCtaQ'), this.el('impCtaList'), 'cuentas', function (o) { return o.CODCUE + ' · ' + o.DENCUE; }, function (o) { CP.impSel = { codcue: o.CODCUE, label: o.CODCUE + ' · ' + (o.DENCUE || '').trim() }; CP.el('impCta').value = o.CODCUE; CP.el('impCtaQ').value = CP.impSel.label; });
        this.autocomplete(this.el('prodQ'), this.el('prodList'), 'productos', function (o) { return o.CODPRO + ' · ' + o.DENPRO; }, function (o) {
            CP.prodSel = { codpro: o.CODPRO, denpro: (o.DENPRO || '').trim(), codudm: o.CODUDM, codmon: (o.CODMON || 'P').trim() };
            CP.el('prodCod').value = o.CODPRO; CP.el('prodQ').value = o.CODPRO + ' · ' + (o.DENPRO || '').trim();
            CP.el('prodMon').value = CP.prodSel.codmon; CP.el('prodCos').value = o.COSPRO || ''; CP.el('prodLis').value = o.PLCPRO || '';
            if (CP.prodSel.codmon === 'D' && o.COTPRO > 0 && CP.cotmov() <= 1) CP.el('cotmov').value = o.COTPRO;
            CP.monLabels();
        });
        ['netmov', 'alimov', 'net2mov', 'ali2mov', 'nogmov', 'ip1mov', 'ip2mov'].forEach(function (id) { CP.el(id).addEventListener('input', function () { CP.recalc(); }); });
        this.el('cotmov').addEventListener('input', function () { CP.recalc(); });
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
            if (shown) { CP.el('net2mov').value = '0'; CP.recalc(); }   // al colapsar, se limpia la 2ª alícuota
        });
        this.el('prodMon').addEventListener('change', function () { CP.monLabels(); });
        this.el('btnAddImp').addEventListener('click', function () { CP.addImp(); });
        this.el('btnSugIva').addEventListener('click', function () { CP.sugerirIva(); });
        this.el('btnAddVto').addEventListener('click', function () { CP.addVto(); });
        this.el('codcat').addEventListener('change', function () { CP.facturacionChange(); });
        this.el('btnAddProd').addEventListener('click', function () { CP.addProd(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnBuscarCP').addEventListener('click', function () { CP.openBuscar(); });
        this.el('btnGrabar').addEventListener('click', function () { CP.grabar(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (CP.anulNum) CP.anular(CP.anulNum); });
        this.setupCollapsibles();
        this.recalc();
    },
    // Click en el header de un card → colapsa/expande su cuerpo (chevron por CSS .collapsed)
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
    monLabels() {
        var m = this.el('prodMon').value;
        this.el('lblCos').textContent = m === 'D' ? 'Costo u$s' : 'Costo $';
        this.el('lblLis').textContent = m === 'D' ? 'Lista u$s' : 'Lista $';
    },

    async pickProv(codcue) {
        var j = await this.api('get_proveedor', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data; this.prov = d;
        this.el('codcue').value = codcue; this.el('provQ').value = d.DENCUE;
        this.el('saldo').value = this.n(d.SALDO);
        this.el('sancue').value = this.n(d.SALDO_ANTIC);
        this.el('provInfo').textContent = [d.CITCUE, d.DENCRI, d.DOMICILIO, d.LOCALIDAD].filter(Boolean).join(' · ');
        // FACTURACIÓN: default = la categoría del proveedor (bloqueada si la tiene, como el legacy)
        var cat = (d.CODCAT !== null && d.CODCAT !== '' && typeof d.CODCAT !== 'undefined') ? String(parseInt(d.CODCAT, 10)) : '';
        this.el('codcat').value = cat; this.el('codcat').disabled = (cat !== '');
        this.el('cei').value = d.ES_RI ? 'A' : 'C';   // letra según responsabilidad IVA (A=discrimina IVA, C=no)
        this.totManual = false;
        this.facturacionChange();                      // → aplicarResponsabilidad → recalc
        var r = await this.api('remitos_pendientes', { codcue: codcue });
        this.remPend = (r.ok && r.data) ? r.data : [];
        this.renderRemitos();
        var an = await this.api('anticipos_pendientes', { codcue: codcue });
        this.antPend = (an.ok && an.data) ? an.data : [];
        this.renderAnticipos();
    },

    recalc() {
        var conProd = this.esProductos();
        var ali1 = parseFloat(this.el('alimov').value) || 0, ali2 = parseFloat(this.el('ali2mov').value) || 0;
        var neto1 = conProd ? this.prodSum() : this.r2(this.el('netmov').value);
        if (conProd) this.el('netmov').value = neto1;
        var neto2 = this.r2(this.el('net2mov').value), nog = this.r2(this.el('nogmov').value);
        var iva1 = Math.round(neto1 * ali1) / 100, iva2 = Math.round(neto2 * ali2) / 100;
        var ip1 = this.r2(this.el('ip1mov').value), ip2 = this.r2(this.el('ip2mov').value);
        var neto = Math.round((neto1 + neto2) * 100) / 100, iva = Math.round((iva1 + iva2) * 100) / 100, perc = Math.round((ip1 + ip2) * 100) / 100;
        // Total del comprobante: suma de subtotales por default (editable). Si el usuario lo override, se respeta
        // (permite cargar el total real del proveedor aunque difiera de la discriminación). Aviso si difiere.
        var compTotal = Math.round((neto + iva + nog + perc) * 100) / 100;
        if (!this.totManual) this.el('totmov').value = compTotal.toFixed(2);
        var total = Math.round((parseFloat(this.el('totmov').value) || 0) * 100) / 100;
        var dif = Math.round((total - compTotal) * 100) / 100, discrim = (this.el('cei').value !== 'C');
        this.el('totWarn').textContent = (discrim && Math.abs(dif) >= 0.01) ? ('⚠ subtotales ' + this.n(compTotal) + ' · dif ' + (dif > 0 ? '+' : '') + this.n(dif)) : '';
        this.el('totReset').classList.toggle('d-none', !this.totManual);
        this.el('irimov').value = this.n(iva1); this.el('iri2mov').value = this.n(iva2);
        this.totales = { neto1: neto1, ali1: ali1, iva1: iva1, neto2: neto2, ali2: ali2, iva2: iva2, neto: neto, iva: iva, nog: nog, ip1: ip1, ip2: ip2, ap1: this.r2(this.el('ap1mov').value), ap2: this.r2(this.el('ap2mov').value), perc: perc, compTotal: compTotal, total: total };
        this.el('impTot').textContent = this.n(total); this.el('vtoTot').textContent = this.n(total);
        if (conProd) this.renderProds();
        this.refresh();
    },
    percepFromPct() {
        var neto1 = this.r2(this.el('netmov').value), neto2 = this.r2(this.el('net2mov').value), nog = this.r2(this.el('nogmov').value);
        var ap1 = parseFloat(this.el('ap1mov').value) || 0, ap2 = parseFloat(this.el('ap2mov').value) || 0;
        if (ap1 > 0) this.el('ip1mov').value = (Math.round((neto1 + neto2) * ap1) / 100).toFixed(2);          // Perc. IVA s/ neto gravado
        if (ap2 > 0) this.el('ip2mov').value = (Math.round((neto1 + neto2 + nog) * ap2) / 100).toFixed(2);    // Perc. IIBB s/ neto + no gravado
        this.recalc();
    },
    impSum() { return Math.round(this.imps.reduce(function (s, i) { return s + i.debmov; }, 0) * 100) / 100; },
    vtoSum() { return Math.round(this.vtos.reduce(function (s, v) { return s + v.cremov; }, 0) * 100) / 100; },
    refresh() {
        var t = this.totales.total, is = this.impSum(), vs = this.vtoSum(), as = this.antSum();
        this.el('impSum').textContent = this.n(is); this.el('vtoSum').textContent = this.n(vs);
        if (this.el('antSum')) this.el('antSum').textContent = this.n(as);
        this.el('impOk').innerHTML = (Math.abs(is - t) < 0.01 && t > 0) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-exclamation-circle text-warning"></i>';
        this.el('vtoOk').innerHTML = (Math.abs(vs + as - t) < 0.01 && t > 0) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-exclamation-circle text-warning"></i>';
        this.el('impDeb').value = Math.max(0, Math.round((t - is) * 100) / 100) || '';
        this.el('vtoImp').value = Math.max(0, Math.round((t - as - vs) * 100) / 100) || '';   // los anticipos reducen lo que va a vencimientos
    },

    // ---- Imputación ----
    addImp() {
        if (!this.impSel) { this.toast('Elegí una cuenta.', 'warning'); return; }
        var deb = this.r2(this.el('impDeb').value);
        if (deb <= 0) { this.toast('Poné el importe del Debe.', 'warning'); return; }
        var cdc = this.el('impCdc').value;
        this.imps.push({ codcue: this.impSel.codcue, label: this.impSel.label, codcdc: cdc, cdcName: this.centros[cdc] || cdc, debmov: deb });
        this.impSel = null; this.el('impCta').value = ''; this.el('impCtaQ').value = '';
        this.renderImps(); this.refresh();
    },
    sugerirIva() {
        if (this.totales.iva <= 0) { this.toast('No hay IVA para imputar.', 'info'); return; }
        if (this.imps.some(function (i) { return i.codcue === CP.ivaCta(); })) { this.toast('La fila de IVA Crédito ya está.', 'info'); return; }
        var cdc = this.el('impCdc').value;
        this.imps.push({ codcue: this.ivaCta(), label: this.ivaCta() + ' · I.V.A. Crédito Fiscal', codcdc: cdc, cdcName: this.centros[cdc] || cdc, debmov: this.totales.iva });
        this.renderImps(); this.refresh();
    },
    renderImps() {
        this.el('impBody').innerHTML = this.imps.map(function (i, k) {
            return '<tr><td>' + CP.esc(i.label) + '</td><td>' + CP.esc(i.cdcName) + '</td><td class="cp-num">' + CP.n(i.debmov) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger i-del" data-k="' + k + '"><i class="bi bi-x"></i></button></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('impBody').querySelectorAll('.i-del'), function (b) { b.addEventListener('click', function () { CP.imps.splice(+this.getAttribute('data-k'), 1); CP.renderImps(); CP.refresh(); }); });
    },

    // ---- Vencimientos ----
    addVto() {
        var fx = this.el('vtoFx').value, imp = this.r2(this.el('vtoImp').value);
        if (!fx) { this.toast('Poné la fecha del vencimiento.', 'warning'); return; }
        if (imp <= 0) { this.toast('Poné el importe a pagar.', 'warning'); return; }
        this.vtos.push({ fvxmov: fx, cremov: imp });
        this.renderVtos(); this.refresh();
    },
    renderVtos() {
        this.el('vtoBody').innerHTML = this.vtos.map(function (v, k) {
            return '<tr><td>' + CP.esc(v.fvxmov.split('-').reverse().join('/')) + '</td><td class="cp-num">' + CP.n(v.cremov) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger v-del" data-k="' + k + '"><i class="bi bi-x"></i></button></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('vtoBody').querySelectorAll('.v-del'), function (b) { b.addEventListener('click', function () { CP.vtos.splice(+this.getAttribute('data-k'), 1); CP.renderVtos(); CP.refresh(); }); });
    },

    // ---- Productos (entra a stock) — gobernado por FACTURACIÓN (cboCODCAT=1=PRODUCTOS) ----
    esProductos() { return parseInt(this.el('codcat').value, 10) === 1; },
    facturacionChange() {
        var prod = this.esProductos();
        this.el('cardProd').style.display = prod ? '' : 'none';
        this.el('chkLDP').disabled = !prod;           // "Actualizar Precios de Venta": habilitado/tildado sólo con PRODUCTOS
        this.el('chkLDP').checked = prod;
        if (!prod) { this.productos = []; this.renderProds(); }
        this.aplicarResponsabilidad();
    },
    // Letra C (no Resp. Inscripto) = sin discriminación de IVA → sólo el total editable. Percepciones según APICUE/APBCUE.
    aplicarResponsabilidad() {
        var discrim = (this.el('cei').value !== 'C');
        var prod = this.esProductos();
        var piva = discrim && this.prov && this.prov.APLICA_PIVA;
        var piibb = discrim && this.prov && this.prov.APLICA_PIIBB;
        this.lockField('alimov', !discrim); this.lockField('net2mov', !discrim); this.lockField('ali2mov', !discrim); this.lockField('nogmov', !discrim);
        this.el('netmov').readOnly = prod;
        this.lockField('netmov', !discrim && !prod);
        this.lockField('ap1mov', !piva); this.lockField('ip1mov', !piva);
        this.lockField('ap2mov', !piibb); this.lockField('ip2mov', !piibb);
        if (!discrim) { this.el('row493').style.display = 'none'; this.el('net2mov').value = '0'; this.el('toggle493').innerHTML = '<i class="bi bi-plus-square me-1"></i>Neto D.493/01 (2ª alícuota)'; }
        this.el('toggle493').style.display = discrim ? '' : 'none';
        this.recalc();
    },
    lockField(id, locked) { var e = this.el(id); e.disabled = locked; if (locked) e.value = '0'; },
    costoPesos(p) { return (p.codmon === 'P') ? p.cos : Math.round(p.cos * this.cotmov() * 10000) / 10000; },   // costo unitario en $
    prodNet(p) { return Math.round(p.cant * this.costoPesos(p) * (1 - p.bon / 100) * 100) / 100; },
    prodSum() { return Math.round(this.productos.reduce(function (s, p) { return s + CP.prodNet(p); }, 0) * 100) / 100; },
    addProd() {
        if (!this.prodSel) { this.toast('Elegí un producto.', 'warning'); return; }
        var cant = this.r2(this.el('prodCant').value), cos = parseFloat(this.el('prodCos').value) || 0;
        if (cant <= 0 || cos <= 0) { this.toast('Poné cantidad y costo.', 'warning'); return; }
        this.productos.push({
            codpro: this.prodSel.codpro, denpro: this.prodSel.denpro, codudm: this.prodSel.codudm,
            codmon: this.el('prodMon').value, cant: cant, cos: cos,
            lis: parseFloat(this.el('prodLis').value) || 0, fct: parseFloat(this.el('prodFct').value) || 1,
            bon: this.r2(this.el('prodBon').value), flt: parseFloat(this.el('prodFlt').value) || 0,
            stk: this.el('prodStk').checked
        });
        this.prodSel = null; this.el('prodCod').value = ''; this.el('prodQ').value = '';
        this.el('prodCant').value = ''; this.el('prodCos').value = ''; this.el('prodLis').value = '0'; this.el('prodFct').value = '1'; this.el('prodBon').value = '0'; this.el('prodFlt').value = '0';
        this.renderProds(); this.recalc();
    },
    renderProds() {
        this.el('prodBody').innerHTML = this.productos.map(function (p, k) {
            return '<tr><td>' + CP.esc(p.codpro + ' · ' + p.denpro) + '</td><td>' + (p.codmon === 'D' ? 'u$s' : '$') + '</td>' +
                '<td class="cp-num">' + CP.n(p.cant) + '</td><td class="cp-num">' + CP.n(p.cos) + '</td><td class="cp-num">' + CP.n(CP.costoPesos(p)) + '</td>' +
                '<td class="cp-num">' + CP.n(p.bon) + '</td><td>' + (p.stk ? '<i class="bi bi-check-lg text-success"></i>' : '—') + '</td><td class="cp-num">' + CP.n(CP.prodNet(p)) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger p-del" data-k="' + k + '"><i class="bi bi-x"></i></button></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('prodBody').querySelectorAll('.p-del'), function (b) { b.addEventListener('click', function () { CP.productos.splice(+this.getAttribute('data-k'), 1); CP.renderProds(); CP.recalc(); }); });
    },

    // ---- Remitos del proveedor ----
    renderRemitos() {
        if (!this.remPend.length) { this.el('cardRem').style.display = 'none'; this.el('remList').innerHTML = ''; return; }
        this.el('cardRem').style.display = '';
        this.el('remList').innerHTML = this.remPend.map(function (r, k) {
            return '<div class="form-check"><input class="form-check-input rem-chk" type="checkbox" id="rem' + k + '" value="' + r.NUMMOV + '">' +
                '<label class="form-check-label" for="rem' + k + '"><b>' + CP.esc(r.FECHA) + ' · Nº ' + CP.esc(r.NUMERO) + '</b> — ' + CP.esc(r.PRODUCTOS) + '</label></div>';
        }).join('');
    },
    selectedRemitos() { return Array.prototype.slice.call(document.querySelectorAll('.rem-chk:checked')).map(function (c) { return parseInt(c.value, 10); }); },

    // ---- Anticipos / Acreditaciones (saldo a favor del proveedor) ----
    renderAnticipos() {
        if (!this.antPend.length) { this.el('cardAnt').style.display = 'none'; this.el('antBody').innerHTML = ''; return; }
        this.el('cardAnt').style.display = '';
        this.el('antBody').innerHTML = this.antPend.map(function (a, k) {
            return '<tr><td>' + CP.esc(a.COM) + '</td><td title="' + CP.esc(a.FECHA) + '">' + CP.esc(a.NUMERO) + '</td>' +
                '<td class="cp-num">' + CP.n(a.SALDO) + '</td>' +
                '<td><input type="number" step="0.01" class="form-control form-control-sm cp-num ant-imp" style="width:100%" data-k="' + k + '" data-max="' + a.SALDO + '" value="0"></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('antBody').querySelectorAll('.ant-imp'), function (inp) {
            inp.addEventListener('input', function () {
                var max = parseFloat(this.getAttribute('data-max')) || 0;
                if ((parseFloat(this.value) || 0) > max) this.value = max;   // no más que el saldo disponible
                CP.refresh();
            });
        });
    },
    antSum() { var inp = document.querySelectorAll('.ant-imp'); if (!inp.length && this.readonly) return this._antLoaded || 0; return Math.round(Array.prototype.slice.call(inp).reduce(function (s, i) { return s + (parseFloat(i.value) || 0); }, 0) * 100) / 100; },
    selectedAnticipos() {
        return Array.prototype.slice.call(document.querySelectorAll('.ant-imp')).map(function (i) {
            return { anttmov: CP.antPend[+i.getAttribute('data-k')].NUMMOV, imptmov: parseFloat(i.value) || 0 };
        }).filter(function (a) { return a.imptmov > 0; });
    },

    // ---- Buscar / ver CPs emitidos ----
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
        Array.prototype.forEach.call(this.el('bqBody').querySelectorAll('.bq-row'), function (tr) { tr.addEventListener('click', function () { CP.cargarCP(+this.getAttribute('data-num')); }); });
    },
    // Click en un resultado → cerrar modal + cargar el CP completo en el form, en modo SÓLO LECTURA (como el legacy / FV).
    async cargarCP(num) {
        var j = await this.api('detalle', { nummov: num });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data;
        var bm = bootstrap.Modal.getInstance(this.el('modalBuscarCP')); if (bm) bm.hide();
        this.prov = null;
        this.el('codcue').value = d.CODCUE; this.el('provQ').value = d.PROVEEDOR; this.el('provInfo').textContent = d.INFO;
        this.el('sancue').value = this.n(d.SANCUE); this.el('saldo').value = this.n(d.SOPCUE);
        this.el('nummov').value = d.NUMERO; this.el('cinmov').value = d.NUMERO; this.el('cipmov').value = d.CIPMOV;
        this.el('fexmov').value = d.FEXISO; this.el('cotmov').value = d.COTMOV;
        this.el('cec').value = d.CEC; this.el('cei').value = d.CEI;
        this.el('cep').value = d.CEP; this.el('cen').value = d.CEN; this.el('cef').value = d.CEFISO;
        this.el('fixmov').value = d.FIXISO; this.el('detmov').value = d.DETMOV; this.el('codcat').value = String(d.CODCAT);
        this.el('netmov').value = d.NET1; this.el('alimov').value = d.ALI1; this.el('irimov').value = d.IRI1;
        this.el('nogmov').value = d.NOGRAV;
        this.el('ap1mov').value = d.AP1; this.el('ip1mov').value = d.IP1; this.el('ap2mov').value = d.AP2; this.el('ip2mov').value = d.IP2;
        if (d.NET2 > 0) { this.el('row493').style.display = 'flex'; this.el('net2mov').value = d.NET2; this.el('ali2mov').value = d.ALI2; this.el('iri2mov').value = d.IRI2; }
        this.el('totmov').value = d.TOTAL;
        this.el('cardProd').style.display = d.CONPROD ? '' : 'none';
        this.productos = (d.productos || []).map(function (p) { return { codpro: p.codpro, denpro: p.denpro, codudm: 1, codmon: p.codmon, cant: p.cant, cos: p.cos, lis: 0, fct: 1, bon: p.bon, flt: 0, stk: p.stk }; });
        this.renderProds();
        this.imps = (d.imputacion || []).map(function (i) { return { codcue: i.codcue, label: i.label, codcdc: i.codcdc, cdcName: (CP.centros && CP.centros[i.codcdc]) || i.codcdc, debmov: i.debmov }; });
        this.renderImps();
        this.vtos = (d.vencimientos || []).map(function (v) { return { fvxmov: v.fvxiso, cremov: v.cremov }; });
        this.renderVtos();
        var ant = d.anticipos || [];
        this.el('cardAnt').style.display = ant.length ? '' : 'none';
        this.el('antBody').innerHTML = ant.map(function (a) { return '<tr><td>' + CP.esc(a.comp) + '</td><td colspan="2" class="text-muted small">aplicado</td><td class="cp-num">' + CP.n(a.importe) + '</td></tr>'; }).join('');
        var antTot = ant.reduce(function (s, a) { return s + a.importe; }, 0);
        this.totales = { total: this.r2(d.TOTAL), neto: this.r2(d.NET1) + this.r2(d.NET2), iva: this.r2(d.IRI1) + this.r2(d.IRI2), nograv: this.r2(d.NOGRAV) };
        this._antLoaded = Math.round(antTot * 100) / 100;   // anticipos cargados (sin inputs editables en readonly)
        this.refresh();
        this.el('impTot').textContent = this.n(d.TOTAL); this.el('vtoTot').textContent = this.n(d.TOTAL);
        this.lockForm(num, d.ANULABLE, d.ANULADO, d.remitos || []);
    },
    lockForm(num, anulable, anulado, remitos) {
        Array.prototype.forEach.call(document.querySelectorAll('#cpForm input, #cpForm select, #cpForm textarea'), function (el) { el.disabled = true; });
        Array.prototype.forEach.call(document.querySelectorAll('#cpForm button'), function (el) { el.style.display = 'none'; });
        this.el('btnGrabar').style.display = 'none';
        var b = this.el('roBanner'); b.style.display = '';
        b.innerHTML = '<i class="bi bi-eye me-1"></i>Comprobante a Pagar <b>Nº ' + this.esc(num) + '</b> — modo <b>sólo lectura</b>' +
            (anulado ? ' · <span class="badge bg-danger">ANULADO</span>' : '') +
            ((remitos && remitos.length) ? ' · Remitos facturados: ' + remitos.map(function (x) { return CP.esc(x); }).join(', ') : '') +
            ' · <a href="#" id="roNuevo">Cargar otro / Nuevo</a>';
        var rn = this.el('roNuevo'); if (rn) rn.addEventListener('click', function (e) { e.preventDefault(); location.reload(); });
        if (anulable && !anulado) { this.anulNum = num; this.el('btnAnularHdr').style.display = ''; }
        this.readonly = true;
    },

    async grabar() {
        this.el('cpErr').textContent = '';
        if (this.grabado) { this.toast('El comprobante ya fue grabado.', 'info'); return; }
        var conProd = this.esProductos();
        if (conProd && !this.productos.length) { this.el('cpErr').textContent = 'Facturación = PRODUCTOS: agregá al menos un producto (o cambiá la facturación a Servicios).'; return; }
        var t = this.totales, p = this.prov;
        if (!this.el('codcue').value) { this.el('cpErr').textContent = 'Elegí un proveedor.'; return; }
        if (!(parseInt(this.el('cen').value, 10) > 0)) { this.el('cpErr').textContent = 'Cargá el número del comprobante del proveedor.'; return; }
        if (t.total <= 0) { this.el('cpErr').textContent = 'Ingresá el neto / no gravado del comprobante.'; return; }
        if (Math.abs(this.impSum() - t.total) >= 0.01) { this.el('cpErr').textContent = 'La imputación (' + this.n(this.impSum()) + ') no coincide con el total (' + this.n(t.total) + ').'; return; }
        if (Math.abs(this.vtoSum() + this.antSum() - t.total) >= 0.01) { this.el('cpErr').textContent = 'Vencimientos (' + this.n(this.vtoSum()) + ') + anticipos (' + this.n(this.antSum()) + ') no cubren el total (' + this.n(t.total) + ').'; return; }
        var ivas = [];
        if (t.neto1 > 0) ivas.push({ net: t.neto1, ali: t.ali1, iva: t.iva1 });
        if (t.neto2 > 0) ivas.push({ net: t.neto2, ali: t.ali2, iva: t.iva2 });
        var data = {
            codcue: this.el('codcue').value, fexmov: this.el('fexmov').value, fixmov: this.el('fixmov').value, codcat: parseInt(this.el('codcat').value, 10) || 2, detmov: this.el('detmov').value,
            cec: this.el('cec').value, cei: this.el('cei').value, cep: this.el('cep').value, cen: this.el('cen').value, cef: this.el('cef').value,
            citmov: p.CITCUE, codcri: p.CODCRI, cotmov: this.cotmov(), nogmov: t.nog, ip1mov: t.ip1, ip2mov: t.ip2, ap1mov: t.ap1, ap2mov: t.ap2, total: t.total,
            ivas: ivas,
            imputaciones: this.imps.map(function (i) { return { codcue: i.codcue, codcdc: i.codcdc, debmov: i.debmov }; }),
            vencimientos: this.vtos.map(function (v) { return { fvxmov: v.fvxmov, detmov: '', cremov: v.cremov }; }),
            productos: conProd ? this.productos.map(function (p) { return { codpro: p.codpro, denmov: p.denpro, codmon: p.codmon, ingmov: p.cant, cosmov: p.cos, pulmov: p.lis, fctmov: p.fct, bonmov: p.bon, fltmov: p.flt, apvmov: CP.el('chkLDP').checked ? 1 : 0, stkmov: p.stk ? 1 : 0, codudm: p.codudm }; }) : [],
            remitos: this.selectedRemitos(), anticipos: this.selectedAnticipos()
        };
        this.el('btnGrabar').disabled = true; this.el('btnGrabar').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Grabando…';
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        this.el('btnGrabar').innerHTML = '<i class="bi bi-save me-1"></i>Grabar comprobante';
        if (!j.ok) { this.el('btnGrabar').disabled = false; this.el('cpErr').textContent = j.error; return; }
        this.grabado = true;
        this.el('nummov').value = String(j.data.nummov).padStart(8, '0'); this.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
        Array.prototype.forEach.call(document.querySelectorAll('#cpForm input, #cpForm select, #cpForm button.btn-outline-primary, #cpForm button.btn-outline-secondary, .i-del, .v-del, .p-del'), function (el) { el.disabled = true; });
        if (j.data.anulable) { this.anulNum = j.data.nummov; this.el('btnAnularHdr').style.display = ''; }
        this.toast('Comprobante a pagar grabado: Nº ' + String(j.data.cinmov).padStart(8, '0') + ' (mov ' + j.data.nummov + ', total ' + this.n(j.data.total) + ').', 'success');
    },

    async anular(num) {
        if (!confirm('¿Anular este comprobante a pagar?\nSe revierten el asiento contable, la cuenta corriente del proveedor, los vencimientos, el stock y los remitos. No se puede deshacer.')) return;
        var fd = new FormData(); fd.append('action', 'anular'); fd.append('nummov', num);
        var j = await this.api('anular', {}, { method: 'POST', body: fd });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.el('btnAnularHdr').style.display = 'none';
        this.toast('Comprobante a pagar ' + num + ' anulado.', 'success');
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
