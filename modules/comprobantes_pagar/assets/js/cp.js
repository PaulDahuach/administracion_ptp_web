/* Comprobantes a Pagar (acreedores) — registrar la factura del proveedor. Proveedor → comprobante del
   proveedor → neto/IVA → imputación contable (Debe, multi-fila) → vencimientos (multi-fila) → grabar.
   Sin productos/stock (v1). */
const CP = {
    prov: null, grabado: false, totales: { neto: 0, iva: 0, nog: 0, ali: 21, total: 0 },
    imps: [], vtos: [], productos: [], centros: {}, impSel: null, prodSel: null,
    ivaCta() { return document.getElementById('cpForm').getAttribute('data-ivacta') || ''; },

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
        this.el('fexmov').value = hoy; this.el('cef').value = hoy; this.el('vtoFx').value = this.addDays(hoy, 30);
        var cc = await this.api('centros_costo');
        if (cc.ok) { this.el('impCdc').innerHTML = cc.data.map(function (o) { CP.centros[o.CODCDC] = (o.DENCDC || '').trim(); return '<option value="' + o.CODCDC + '">' + CP.esc(o.DENCDC) + '</option>'; }).join(''); }
        this.autocomplete(this.el('provQ'), this.el('provList'), 'buscar_proveedores', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { CP.pickProv(o.CODCUE); });
        this.autocomplete(this.el('impCtaQ'), this.el('impCtaList'), 'cuentas', function (o) { return o.CODCUE + ' · ' + o.DENCUE; }, function (o) { CP.impSel = { codcue: o.CODCUE, label: o.CODCUE + ' · ' + (o.DENCUE || '').trim() }; CP.el('impCta').value = o.CODCUE; CP.el('impCtaQ').value = CP.impSel.label; });
        ['netmov', 'alimov', 'nogmov'].forEach(function (id) { CP.el(id).addEventListener('input', function () { CP.recalc(); }); });
        this.el('btnAddImp').addEventListener('click', function () { CP.addImp(); });
        this.el('btnSugIva').addEventListener('click', function () { CP.sugerirIva(); });
        this.el('btnAddVto').addEventListener('click', function () { CP.addVto(); });
        this.el('conProd').addEventListener('change', function () { CP.toggleProd(); });
        this.el('btnAddProd').addEventListener('click', function () { CP.addProd(); });
        this.autocomplete(this.el('prodQ'), this.el('prodList'), 'productos', function (o) { return o.CODPRO + ' · ' + o.DENPRO; }, function (o) { CP.prodSel = { codpro: o.CODPRO, denpro: (o.DENPRO || '').trim(), cospro: o.COSPRO, codudm: o.CODUDM, codmon: o.CODMON }; CP.el('prodCod').value = o.CODPRO; CP.el('prodQ').value = o.CODPRO + ' · ' + (o.DENPRO || '').trim(); CP.el('prodCos').value = o.COSPRO || ''; });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnGrabar').addEventListener('click', function () { CP.grabar(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (CP.anulNum) CP.anular(CP.anulNum); });
        this.recalc();
    },

    async pickProv(codcue) {
        var j = await this.api('get_proveedor', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data; this.prov = d;
        this.el('codcue').value = codcue; this.el('provQ').value = d.DENCUE;
        this.el('saldo').value = this.n(d.SALDO);
        this.el('provInfo').textContent = [d.CITCUE, d.DENCRI, d.DOMICILIO, d.LOCALIDAD].filter(Boolean).join(' · ');
    },

    recalc() {
        var ali = parseFloat(this.el('alimov').value) || 0, nog = this.r2(this.el('nogmov').value);
        var conProd = this.el('conProd').checked;
        var neto = conProd ? this.prodSum() : this.r2(this.el('netmov').value);
        if (conProd) this.el('netmov').value = neto;
        var iva = Math.round(neto * ali) / 100, total = Math.round((neto + iva + nog) * 100) / 100;
        this.el('irimov').value = this.n(iva);
        this.totales = { neto: neto, iva: iva, nog: nog, ali: ali, total: total };
        this.el('tNeto').textContent = this.n(neto); this.el('tIva').textContent = this.n(iva); this.el('tNog').textContent = this.n(nog); this.el('tTotal').textContent = this.n(total);
        this.el('impTot').textContent = this.n(total); this.el('vtoTot').textContent = this.n(total);
        this.refresh();
    },
    impSum() { return Math.round(this.imps.reduce(function (s, i) { return s + i.debmov; }, 0) * 100) / 100; },
    vtoSum() { return Math.round(this.vtos.reduce(function (s, v) { return s + v.cremov; }, 0) * 100) / 100; },
    refresh() {
        var t = this.totales.total, is = this.impSum(), vs = this.vtoSum();
        this.el('impSum').textContent = this.n(is); this.el('vtoSum').textContent = this.n(vs);
        this.el('impOk').innerHTML = (Math.abs(is - t) < 0.01 && t > 0) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-exclamation-circle text-warning"></i>';
        this.el('vtoOk').innerHTML = (Math.abs(vs - t) < 0.01 && t > 0) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-exclamation-circle text-warning"></i>';
        this.el('impDeb').value = Math.max(0, Math.round((t - is) * 100) / 100) || '';
        this.el('vtoImp').value = Math.max(0, Math.round((t - vs) * 100) / 100) || '';
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

    // ---- Productos (entra a stock) ----
    toggleProd() {
        var on = this.el('conProd').checked;
        this.el('cardProd').style.display = on ? '' : 'none';
        this.el('netmov').readOnly = on;
        if (!on) { this.productos = []; this.renderProds(); }
        this.recalc();
    },
    prodNet(p) { return Math.round(p.cant * p.cos * (1 - p.bon / 100) * 100) / 100; },
    prodSum() { return Math.round(this.productos.reduce(function (s, p) { return s + CP.prodNet(p); }, 0) * 100) / 100; },
    addProd() {
        if (!this.prodSel) { this.toast('Elegí un producto.', 'warning'); return; }
        var cant = this.r2(this.el('prodCant').value), cos = parseFloat(this.el('prodCos').value) || 0, bon = this.r2(this.el('prodBon').value);
        if (cant <= 0 || cos <= 0) { this.toast('Poné cantidad y costo.', 'warning'); return; }
        this.productos.push({ codpro: this.prodSel.codpro, denpro: this.prodSel.denpro, cant: cant, cos: cos, bon: bon, stk: this.el('prodStk').checked, codudm: this.prodSel.codudm, codmon: this.prodSel.codmon });
        this.prodSel = null; this.el('prodCod').value = ''; this.el('prodQ').value = ''; this.el('prodCant').value = ''; this.el('prodCos').value = '';
        this.renderProds(); this.recalc();
    },
    renderProds() {
        this.el('prodBody').innerHTML = this.productos.map(function (p, k) {
            return '<tr><td>' + CP.esc(p.codpro + ' · ' + p.denpro) + '</td><td class="cp-num">' + CP.n(p.cant) + '</td><td class="cp-num">' + CP.n(p.cos) + '</td><td class="cp-num">' + CP.n(p.bon) + '</td>' +
                '<td>' + (p.stk ? '<i class="bi bi-check-lg text-success"></i>' : '—') + '</td><td class="cp-num">' + CP.n(CP.prodNet(p)) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger p-del" data-k="' + k + '"><i class="bi bi-x"></i></button></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('prodBody').querySelectorAll('.p-del'), function (b) { b.addEventListener('click', function () { CP.productos.splice(+this.getAttribute('data-k'), 1); CP.renderProds(); CP.recalc(); }); });
    },

    async grabar() {
        this.el('cpErr').textContent = '';
        if (this.grabado) { this.toast('El comprobante ya fue grabado.', 'info'); return; }
        var conProd = this.el('conProd').checked;
        if (conProd && !this.productos.length) { this.el('cpErr').textContent = 'Agregá al menos un producto (o destildá "Con productos").'; return; }
        var t = this.totales, p = this.prov;
        if (!this.el('codcue').value) { this.el('cpErr').textContent = 'Elegí un proveedor.'; return; }
        if (!(parseInt(this.el('cen').value, 10) > 0)) { this.el('cpErr').textContent = 'Cargá el número del comprobante del proveedor.'; return; }
        if (t.total <= 0) { this.el('cpErr').textContent = 'Ingresá el neto / no gravado del comprobante.'; return; }
        if (Math.abs(this.impSum() - t.total) >= 0.01) { this.el('cpErr').textContent = 'La imputación (' + this.n(this.impSum()) + ') no coincide con el total (' + this.n(t.total) + ').'; return; }
        if (Math.abs(this.vtoSum() - t.total) >= 0.01) { this.el('cpErr').textContent = 'Los vencimientos (' + this.n(this.vtoSum()) + ') no coinciden con el total (' + this.n(t.total) + ').'; return; }
        var data = {
            codcue: this.el('codcue').value, fexmov: this.el('fexmov').value, codcat: conProd ? 1 : 2, detmov: this.el('detmov').value,
            cec: this.el('cec').value, cei: this.el('cei').value, cep: this.el('cep').value, cen: this.el('cen').value, cef: this.el('cef').value,
            citmov: p.CITCUE, codcri: p.CODCRI, nogmov: t.nog, total: t.total,
            ivas: t.neto > 0 ? [{ net: t.neto, ali: t.ali, iva: t.iva }] : [],
            imputaciones: this.imps.map(function (i) { return { codcue: i.codcue, codcdc: i.codcdc, debmov: i.debmov }; }),
            vencimientos: this.vtos.map(function (v) { return { fvxmov: v.fvxmov, detmov: '', cremov: v.cremov }; }),
            productos: conProd ? this.productos.map(function (p) { return { codpro: p.codpro, denmov: p.denpro, ingmov: p.cant, punmov: p.cos, bonmov: p.bon, stkmov: p.stk ? 1 : 0, codmon: p.codmon, codudm: p.codudm, fctmov: 1 }; }) : []
        };
        this.el('btnGrabar').disabled = true; this.el('btnGrabar').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Grabando…';
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        this.el('btnGrabar').innerHTML = '<i class="bi bi-save me-1"></i>Grabar comprobante';
        if (!j.ok) { this.el('btnGrabar').disabled = false; this.el('cpErr').textContent = j.error; return; }
        this.grabado = true;
        this.el('nummov').value = String(j.data.nummov).padStart(8, '0'); this.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
        Array.prototype.forEach.call(document.querySelectorAll('#cpForm input, #cpForm select, #cpForm button.btn-outline-primary, #cpForm button.btn-outline-secondary, .i-del, .v-del'), function (el) { el.disabled = true; });
        if (j.data.anulable) { this.anulNum = j.data.nummov; this.el('btnAnularHdr').style.display = ''; }
        this.toast('Comprobante a pagar grabado: Nº ' + String(j.data.cinmov).padStart(8, '0') + ' (mov ' + j.data.nummov + ', total ' + this.n(j.data.total) + ').', 'success');
    },

    async anular(num) {
        if (!confirm('¿Anular este comprobante a pagar?\nSe revierten el asiento contable, la cuenta corriente del proveedor y los vencimientos. No se puede deshacer.')) return;
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
