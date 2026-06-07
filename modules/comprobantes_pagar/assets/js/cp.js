/* Comprobantes a Pagar (acreedores) — registrar la factura del proveedor. Proveedor → comprobante del
   proveedor → neto/IVA → cuenta del gasto (+ IVA crédito automático) → vencimiento → grabar.
   v1: una cuenta de gasto y un vencimiento (caso servicio/gasto, sin productos). */
const CP = {
    prov: null, grabado: false, totales: { neto: 0, iva: 0, nog: 0, total: 0 },
    ivaCta() { return document.getElementById('cpForm').getAttribute('data-ivacta') || ''; },

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    n(v) { var x = parseFloat(v); return isNaN(x) ? '0.00' : x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    addDays(iso, days) { var d = new Date(iso + 'T00:00:00'); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        return await (await fetch(url, opts || {})).json();
    },

    async init() {
        var hoy = new Date().toISOString().slice(0, 10);
        this.el('fexmov').value = hoy; this.el('cef').value = hoy; this.el('fvxmov').value = this.addDays(hoy, 30);
        var cc = await this.api('centros_costo');
        if (cc.ok) this.el('codcdc').innerHTML = cc.data.map(function (o) { return '<option value="' + o.CODCDC + '">' + CP.esc(o.DENCDC) + '</option>'; }).join('');
        this.autocomplete(this.el('provQ'), this.el('provList'), 'buscar_proveedores', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { CP.pickProv(o.CODCUE); });
        this.autocomplete(this.el('ctaQ'), this.el('ctaList'), 'cuentas', function (o) { return o.CODCUE + ' · ' + o.DENCUE; }, function (o) { CP.el('ctaGasto').value = o.CODCUE; CP.el('ctaQ').value = o.CODCUE + ' · ' + o.DENCUE; });
        ['netmov', 'alimov', 'nogmov'].forEach(function (id) { CP.el(id).addEventListener('input', function () { CP.recalc(); }); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnGrabar').addEventListener('click', function () { CP.grabar(); });
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
        var neto = Math.round((parseFloat(this.el('netmov').value) || 0) * 100) / 100;
        var ali = parseFloat(this.el('alimov').value) || 0;
        var nog = Math.round((parseFloat(this.el('nogmov').value) || 0) * 100) / 100;
        var iva = Math.round(neto * ali) / 100;
        var total = Math.round((neto + iva + nog) * 100) / 100;
        this.el('irimov').value = this.n(iva);
        this.totales = { neto: neto, iva: iva, nog: nog, ali: ali, total: total };
        this.el('tNeto').textContent = this.n(neto);
        this.el('tIva').textContent = this.n(iva);
        this.el('tNog').textContent = this.n(nog);
        this.el('tTotal').textContent = this.n(total);
    },

    async grabar() {
        this.el('cpErr').textContent = '';
        if (this.grabado) { this.toast('El comprobante ya fue grabado.', 'info'); return; }
        if (!this.el('codcue').value) { this.el('cpErr').textContent = 'Elegí un proveedor.'; return; }
        if (!(parseInt(this.el('cen').value, 10) > 0)) { this.el('cpErr').textContent = 'Cargá el número del comprobante del proveedor.'; return; }
        if (!this.el('ctaGasto').value) { this.el('cpErr').textContent = 'Elegí la cuenta del gasto/bien (imputación).'; return; }
        if (this.totales.total <= 0) { this.el('cpErr').textContent = 'Ingresá el neto / no gravado del comprobante.'; return; }
        if (!this.el('fvxmov').value) { this.el('cpErr').textContent = 'Indicá la fecha de vencimiento.'; return; }
        var t = this.totales, p = this.prov, cdc = this.el('codcdc').value;
        var imps = [{ codcue: this.el('ctaGasto').value, codcdc: cdc, debmov: Math.round((t.neto + t.nog) * 100) / 100, alimov: t.ali, ivamov: t.iva, totmov: t.total }];
        if (t.iva > 0) imps.push({ codcue: this.ivaCta(), codcdc: cdc, debmov: t.iva });
        var data = {
            codcue: this.el('codcue').value, fexmov: this.el('fexmov').value, codcat: 2, detmov: this.el('detmov').value,
            cec: this.el('cec').value, cei: this.el('cei').value, cep: this.el('cep').value, cen: this.el('cen').value, cef: this.el('cef').value,
            citmov: p.CITCUE, codcri: p.CODCRI, nogmov: t.nog, total: t.total,
            ivas: t.neto > 0 ? [{ net: t.neto, ali: t.ali, iva: t.iva }] : [],
            imputaciones: imps,
            vencimientos: [{ fvxmov: this.el('fvxmov').value, detmov: '', cremov: t.total }]
        };
        this.el('btnGrabar').disabled = true; this.el('btnGrabar').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Grabando…';
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        this.el('btnGrabar').innerHTML = '<i class="bi bi-save me-1"></i>Grabar comprobante';
        if (!j.ok) { this.el('btnGrabar').disabled = false; this.el('cpErr').textContent = j.error; return; }
        this.grabado = true;
        this.el('nummov').value = String(j.data.nummov).padStart(8, '0');
        this.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
        Array.prototype.forEach.call(document.querySelectorAll('#cpForm input, #cpForm select'), function (el) { el.disabled = true; });
        this.toast('Comprobante a pagar grabado: Nº ' + String(j.data.cinmov).padStart(8, '0') + ' (mov ' + j.data.nummov + ', total ' + this.n(j.data.total) + ').', 'success');
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
