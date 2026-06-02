/**
 * Búsqueda de Comprobantes — Frontend. Solo lectura.
 */
const App = {
    dt: null,

    init() {
        this.el('btnBuscar').addEventListener('click', () => this.buscar());
        ['txtQ', 'txtImporte', 'txtDesde', 'txtHasta'].forEach(id =>
            this.el(id).addEventListener('keydown', e => { if (e.key === 'Enter') this.buscar(); }));
        this.el('txtQ').focus();
    },

    async buscar() {
        const url = new URL('api.php', location.href);
        url.searchParams.set('action', 'search');
        url.searchParams.set('q', this.el('txtQ').value.trim());
        url.searchParams.set('tipo', this.el('cboTipo').value);
        url.searchParams.set('importe', this.el('txtImporte').value || '');
        url.searchParams.set('libro', this.el('cboLibro').value);
        if (this.el('txtDesde').value) url.searchParams.set('desde', this.el('txtDesde').value);
        if (this.el('txtHasta').value) url.searchParams.set('hasta', this.el('txtHasta').value);

        this.el('cardGrid').style.display = '';
        const tb = document.querySelector('#tblComp tbody');
        if (this.dt) { this.dt.destroy(); this.dt = null; }
        tb.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Buscando…</td></tr>';

        const r = await (await fetch(url)).json();
        if (!r.ok) { tb.innerHTML = `<tr><td colspan="6" class="text-danger text-center py-3">${this.esc(r.error)}</td></tr>`; this.el('infoRes').textContent = ''; return; }
        const d = r.data;
        this.el('infoRes').textContent = d.cantidad + ' comprobante' + (d.cantidad === 1 ? '' : 's') + (d.tope ? ' (tope 200, refiná la búsqueda)' : '');

        if (!d.comprobantes.length) {
            tb.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Sin resultados</td></tr>';
            return;
        }
        tb.innerHTML = d.comprobantes.map(c => {
            const linkable = (c.ORI === 'D' || c.ORI === 'A') && c.CODCUE > 0;
            const dest = c.ORI === 'D' ? '../resumen_cuenta/?codcue=' + c.CODCUE : '../resumen_cuenta_acr/?codcue=' + c.CODCUE;
            return `<tr class="${c.ANULADO ? 'anulado' : ''} ${linkable ? 'link' : ''}" ${linkable ? 'data-dest="' + dest + '"' : ''}>
                <td>${this.esc(c.FECHA)}</td>
                <td class="fw-medium">${this.esc(c.COMP)}${c.ANULADO ? ' <span class="badge bg-danger">ANUL</span>' : ''}</td>
                <td><span class="text-muted small">${this.esc(c.OPER)}</span><br>${this.esc(c.DENMOV)}</td>
                <td class="text-muted">${this.esc(c.CITMOV)}</td>
                <td class="text-end fw-medium" data-order="${c.TOTAL}">${this.num(c.TOTAL)}</td>
                <td class="text-muted small">${this.esc(c.CAE)}</td>
            </tr>`;
        }).join('');

        document.querySelectorAll('#tblComp tbody tr.link').forEach(tr =>
            tr.addEventListener('click', () => { location.href = tr.dataset.dest; }));

        this.dt = $('#tblComp').DataTable({
            order: [], pageLength: 25,
            columnDefs: [{ targets: [4], type: 'num' }],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
        });
    },

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { const n = parseFloat(v); return isNaN(n) ? '0,00' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
};
document.addEventListener('DOMContentLoaded', () => App.init());
