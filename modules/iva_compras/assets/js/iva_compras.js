/**
 * I.V.A. Compras — Frontend. Solo lectura.
 */
const App = {
    init() {
        this.el('btnConsultar').addEventListener('click', () => this.consultar());
        this.el('btnImprimir').addEventListener('click', () => window.print());
        ['txtDesde', 'txtHasta'].forEach(id => this.el(id).addEventListener('keydown', e => { if (e.key === 'Enter') this.consultar(); }));
        this.consultar();
    },

    async consultar() {
        const desde = this.el('txtDesde').value, hasta = this.el('txtHasta').value, libro = this.el('cboLibro').value;
        if (!desde || !hasta) return;
        this.el('cardGrid').style.display = '';
        this.el('tbodyIva').innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">Cargando…</td></tr>';

        const url = new URL('api.php', location.href);
        url.searchParams.set('action', 'list');
        url.searchParams.set('desde', desde);
        url.searchParams.set('hasta', hasta);
        url.searchParams.set('libro', libro);
        const r = await (await fetch(url)).json();
        if (!r.ok) { this.el('tbodyIva').innerHTML = `<tr><td colspan="10" class="text-danger text-center py-3">${this.esc(r.error)}</td></tr>`; return; }
        const d = r.data;

        const tb = this.el('tbodyIva');
        if (!d.comprobantes.length) {
            tb.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">Sin comprobantes en el período</td></tr>';
        } else {
            tb.innerHTML = d.comprobantes.map(c => `<tr class="${c.TOTAL < 0 ? 'neg' : ''}">
                <td>${this.esc(c.FECHA)}</td>
                <td class="fw-medium">${this.esc(c.COMP)}</td>
                <td class="text-truncate" style="max-width:260px">${this.esc(c.DENMOV)}</td>
                <td>${this.esc(c.INICRI)}</td>
                <td class="text-muted">${this.esc(c.CITMOV)}</td>
                <td class="text-end">${this.num(c.NETO)}</td>
                <td class="text-end">${this.num(c.IVA)}</td>
                <td class="text-end">${c.NOGRAV ? this.num(c.NOGRAV) : '—'}</td>
                <td class="text-end">${c.PERCIVA ? this.num(c.PERCIVA) : '—'}</td>
                <td class="text-end fw-medium">${this.num(c.TOTAL)}</td></tr>`).join('');
        }

        const T = d.totales;
        this.el('ftCant').textContent = d.cantidad;
        this.el('ftNeto').textContent = this.num(T.neto);
        this.el('ftIva').textContent = this.num(T.iva);
        this.el('ftNoGrav').textContent = this.num(T.nograv);
        this.el('ftPercIva').textContent = this.num(T.percIva);
        this.el('ftTotal').textContent = this.num(T.total);

        this.el('stNeto').textContent = '$' + this.num(T.neto);
        this.el('stIva').textContent = '$' + this.num(T.iva);
        this.el('stPercIva').textContent = '$' + this.num(T.percIva);
        this.el('stTotal').textContent = '$' + this.num(T.total);
        this.el('statsRow').style.display = '';

        const rb = this.el('tbodyResumen');
        rb.innerHTML = d.resumen.map(x => `<tr>
            <td class="fw-medium">${this.esc(x.tipo)}</td>
            <td class="text-end">${this.num(x.alicuota)}%</td>
            <td class="text-end text-muted">${x.n}</td>
            <td class="text-end">${this.num(x.neto)}</td>
            <td class="text-end">${this.num(x.iva)}</td>
            <td class="text-end">${this.num(x.percIva)}</td>
            <td class="text-end fw-medium">${this.num(x.total)}</td></tr>`).join('');
        this.el('cardResumen').style.display = d.resumen.length ? '' : 'none';

        this.el('phPeriodo').textContent = this.dmy(desde) + ' – ' + this.dmy(hasta) +
            (libro !== 'todos' ? ' · Libro ' + libro.charAt(0).toUpperCase() + libro.slice(1) : '');
        this.el('btnImprimir').disabled = false;
    },

    dmy(iso) { const p = iso.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; },
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { const n = parseFloat(v); return isNaN(n) ? '0,00' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
};
document.addEventListener('DOMContentLoaded', () => App.init());
