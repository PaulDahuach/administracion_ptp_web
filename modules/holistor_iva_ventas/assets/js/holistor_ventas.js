/* Exportación I.V.A. Ventas a Holistor — vista previa + descarga del .txt (sin mapeo: VTA/NG/PIB fijos). */
const HOL = {
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    n(v) { var x = parseFloat(v); return isNaN(x) ? '' : x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        return await (await fetch(url, opts || {})).json();
    },

    init() {
        this.el('btnPrev').addEventListener('click', function () { HOL.preview(); });
        this.el('btnExp').addEventListener('click', function () { HOL.exportar(); });
    },

    async preview() {
        this.el('holMsg').innerHTML = '';
        var d = this.el('desde').value, h = this.el('hasta').value;
        if (!d || !h) { this.toast('Elegí el rango de fechas.', 'warning'); return; }
        this.el('btnPrev').disabled = true;
        var j = await this.api('preview', { desde: d, hasta: h });
        this.el('btnPrev').disabled = false;
        if (!j.ok) { this.el('holMsg').innerHTML = '<div class="alert alert-danger">' + this.esc(j.error) + '</div>'; return; }
        this.el('cardPrev').style.display = '';
        this.el('btnExp').disabled = (j.data.total === 0);
        this.el('prevCount').innerHTML = '<b>' + j.data.comprobantes + '</b> comprobante(s) · <b>' + j.data.total + '</b> fila(s)' + (j.data.total > j.data.rows.length ? ' · mostrando ' + j.data.rows.length : '');
        this.el('prevBody').innerHTML = j.data.rows.map(function (r) {
            return '<tr><td>' + HOL.esc(r.FECHA) + '</td><td class="small">' + HOL.esc(r.COMP) + '</td><td>' + HOL.esc(r.CLIENTE) + '</td>' +
                '<td class="hol-num">' + (r.ALI === null ? '—' : HOL.n(r.ALI) + '%') + '</td><td class="hol-num">' + HOL.n(r.NETO) + '</td><td class="hol-num">' + HOL.n(r.IVA) + '</td>' +
                '<td class="hol-num">' + (r.PIX ? HOL.n(r.PIX) : '') + '</td></tr>';
        }).join('');
    },

    exportar() {
        var d = this.el('desde').value, h = this.el('hasta').value;
        if (!d || !h) { this.toast('Elegí el rango de fechas.', 'warning'); return; }
        var header = this.el('conHeader').checked ? '1' : '0';
        var url = new URL('api.php', location.href);
        url.searchParams.set('action', 'exportar'); url.searchParams.set('desde', d); url.searchParams.set('hasta', h); url.searchParams.set('header', header);
        window.location.href = url.toString();
    },

    toast(msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 6000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { HOL.init(); });
