/* Notas de Débito — emisión con CAE de AFIP. Cliente → concepto → neto/detalle → vencimiento (a debitar)
   + FV asociada opcional (CbtesAsoc de AFIP) → emitir. El concepto define si lleva IVA. La ND debita al
   cliente (crea su propio vencimiento, suma a la deuda); espejo de la NC. */
const ND = {
    cli: null, conceptos: {}, emitida: false, totales: { neto: 0, iva: 0, pix: 0, total: 0 },

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
        this.el('fexmov').value = hoy;
        this.el('fvxmov').value = this.addDays(hoy, 30);   // vencimiento por defecto: emisión + 30 días
        var cc = await this.api('conceptos');
        if (cc.ok) {
            this.el('codaux').innerHTML = cc.data.map(function (o) { ND.conceptos[o.CODAUX] = o; return '<option value="' + o.CODAUX + '">' + ND.esc(o.DENAUX) + (o.IVA ? '' : ' (no gravado)') + '</option>'; }).join('');
            this.onConcepto();
        }
        this.autocomplete(this.el('cliQ'), this.el('cliList'), 'buscar_clientes', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { ND.pickCliente(o.CODCUE); });
        this.el('codaux').addEventListener('change', function () { ND.onConcepto(); });
        this.el('netmov').addEventListener('input', function () { ND.recalc(); });
        this.el('fexmov').addEventListener('change', function () { if (ND.el('fexmov').value) ND.el('fvxmov').value = ND.addDays(ND.el('fexmov').value, 30); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnEmitir').addEventListener('click', function () { ND.emitir(); });
    },

    concepto() { return this.conceptos[this.el('codaux').value] || { IVA: true }; },
    onConcepto() {
        var iva = !!this.concepto().IVA;
        this.el('boxIva').style.display = iva ? '' : 'none';
        this.el('lblNeto').textContent = iva ? 'gravado' : '(no gravado)';
        this.recalc();
    },

    async pickCliente(codcue) {
        var j = await this.api('get_cliente', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data; this.cli = d;
        this.el('codcue').value = codcue; this.el('cliQ').value = d.DENCUE;
        this.el('saldo').value = this.n(d.SALDO);
        this.el('letra').value = d.LETRA || 'A';
        this.el('cliInfo').textContent = [d.CITCUE, d.DENCRI, d.DOMICILIO, d.LOCALIDAD].filter(Boolean).join(' · ');
        // FV del cliente para asociar (CbtesAsoc de AFIP)
        var f = await this.api('facturas', { codcue: codcue });
        var opts = '<option value="">— ninguna —</option>';
        if (f.ok) opts += f.data.map(function (o) { return '<option value="' + o.NUMMOV + '">' + ND.esc(o.COMP) + ' · ' + ND.esc(o.FEXMOV) + ' · $' + ND.n(o.TOTMOV) + '</option>'; }).join('');
        this.el('refFv').innerHTML = opts; this.el('refFv').disabled = false;
        this.recalc();
    },

    recalc() {
        var neto = Math.round((parseFloat(this.el('netmov').value) || 0) * 100) / 100;
        var iva = this.concepto().IVA ? Math.round(neto * 21) / 100 : 0;
        // Percepción IIBB (réplica del legacy): activa según el cliente + switch PIXCDC; neto > MNPPIX → neto×alícuota.
        var perc = (this.cli && this.cli.PERCEP) ? this.cli.PERCEP : null;
        var pix = (perc && perc.activa && neto > perc.mnppix) ? Math.round(neto * perc.alipix) / 100 : 0;
        var total = Math.round((neto + iva + pix) * 100) / 100;
        this.totales = { neto: neto, iva: iva, pix: pix, total: total };
        this.el('boxPix').style.display = (perc && perc.activa) ? '' : 'none';
        this.el('tPix').textContent = this.n(pix);
        this.el('lblPix').textContent = (perc && perc.activa) ? (perc.alipix + '%') : '';
        this.el('tNeto').textContent = this.n(neto);
        this.el('tIva').textContent = this.n(iva);
        this.el('tTotal').textContent = this.n(total);
    },

    async emitir() {
        this.el('ndErr').textContent = '';
        if (this.emitida) { this.toast('La ND ya fue emitida.', 'info'); return; }
        if (!this.el('codcue').value) { this.el('ndErr').textContent = 'Elegí un cliente.'; return; }
        if (this.totales.total <= 0) { this.el('ndErr').textContent = 'Ingresá el neto de la nota de débito.'; return; }
        if (!this.el('fvxmov').value) { this.el('ndErr').textContent = 'Indicá la fecha de vencimiento (a debitar).'; return; }
        var c = this.cli, t = this.totales;
        var refFv = this.el('refFv').value;
        var data = {
            codcue: this.el('codcue').value, fexmov: this.el('fexmov').value, ciimov: this.el('letra').value,
            codaux: this.el('codaux').value, codcdv: c.CODCDV || 2, detmov: this.el('detmov').value,
            citmov: c.CITCUE, dcxmov: c.DCXCUE, dnxmov: c.DNXCUE, codloc: c.CODLOC, codcri: c.CODCRI, cond_iva: c.COND_IVA,
            ali: 21, netmov: t.neto, irimov: t.iva, totmov: t.total, soc: c.SALDO,
            vtos: [{ fvxmov: this.el('fvxmov').value, detmov: '', debmov: t.total }],
            refs: refFv ? [{ nummov: refFv }] : []
        };
        this.el('btnEmitir').disabled = true; this.el('btnEmitir').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Autorizando…';
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        this.el('btnEmitir').innerHTML = '<i class="bi bi-cloud-arrow-up me-1"></i>Emitir ND (AFIP)';
        if (!j.ok) { this.el('btnEmitir').disabled = false; this.el('ndErr').textContent = j.error; return; }
        this.emitida = true;
        this.el('nummov').value = String(j.data.nummov).padStart(8, '0');
        this.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
        if (j.data.cae) { this.el('caeDisp').textContent = j.data.cae; this.el('caeVto').textContent = j.data.cae_vto; this.el('caeWrap').style.display = ''; }
        Array.prototype.forEach.call(document.querySelectorAll('#ndForm input, #ndForm select'), function (el) { el.disabled = true; });
        this.el('btnImprimirHdr').style.display = ''; this.el('btnImprimirHdr').onclick = function () { window.open('imprimir.php?nummov=' + j.data.nummov, '_blank'); };
        var nro = this.el('letra').value + ' ' + String(j.data.cinmov).padStart(8, '0');
        this.toast(j.data.cae ? ('ND ' + nro + ' autorizada · CAE ' + j.data.cae) : ('ND ' + nro + ' grabada (capacitación · sin CAE)'), 'success');
    },

    autocomplete(input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() { list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + ND.esc(label(o)) + '</div>'; }).join(''); list.classList.toggle('show', items.length > 0); }
        input.addEventListener('input', function () { clearTimeout(t); var q = input.value.trim(); if (q.length < 1) { items = []; render(); return; } t = setTimeout(async function () { var j = await ND.api(action, { q: q }); items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }, 180); });
        input.addEventListener('keydown', function (e) { if (!list.classList.contains('show')) return; if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); } else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); } else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); onPick(items[hi]); list.classList.remove('show'); } } else if (e.key === 'Escape') list.classList.remove('show'); });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },
    toast(msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 7000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { ND.init(); });
