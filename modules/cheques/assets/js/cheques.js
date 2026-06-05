/**
 * Cheques — Frontend. Solo lectura.
 */
const App = {
    dt: null,
    orden: null,        // 'acred' | 'emi' (viene del menú: Cheques x Fecha de…)
    presetOrder: [],    // orden inicial del DataTable según ?orden=

    init() {
        // Atajo desde el menú: ?orden=acred|entrada → preselecciona la base de fecha y deja
        // el listado ya ordenado por esa fecha (col 4 Emisión / col 5 Acred.).
        // Columnas: Banco0 Nº1 Librador2 CUIT3 Emisión4 Ingreso5 Acred6 Importe7 Estado8
        const p = new URLSearchParams(location.search).get('orden');
        if (p === 'acred')        { this.orden = 'acred';   this.el('cboBase').value = 'acred';   this.presetOrder = [[6, 'asc']]; }
        else if (p === 'entrada') { this.orden = 'entrada'; this.el('cboBase').value = 'entrada'; this.presetOrder = [[5, 'asc']]; }
        else if (p === 'emi')     { this.orden = 'emi';     this.el('cboBase').value = 'emi';     this.presetOrder = [[4, 'asc']]; }

        this.el('btnBuscar').addEventListener('click', () => this.buscar());
        this.el('cboEstado').addEventListener('change', () => this.buscar());
        ['txtQ', 'txtImporte', 'txtDesde', 'txtHasta'].forEach(id =>
            this.el(id).addEventListener('keydown', e => { if (e.key === 'Enter') this.buscar(); }));
        this.buscar();
    },

    async buscar() {
        const url = new URL('api.php', location.href);
        url.searchParams.set('action', 'search');
        url.searchParams.set('estado', this.el('cboEstado').value);
        url.searchParams.set('q', this.el('txtQ').value.trim());
        url.searchParams.set('importe', this.el('txtImporte').value || '');
        url.searchParams.set('base', this.el('cboBase').value);
        if (this.orden) url.searchParams.set('orden', this.orden);
        if (this.el('txtDesde').value) url.searchParams.set('desde', this.el('txtDesde').value);
        if (this.el('txtHasta').value) url.searchParams.set('hasta', this.el('txtHasta').value);

        this.el('cardGrid').style.display = '';
        const tb = document.querySelector('#tblChq tbody');
        if (this.dt) { this.dt.destroy(); this.dt = null; }
        tb.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">Buscando…</td></tr>';

        const r = await (await fetch(url)).json();
        if (!r.ok) { tb.innerHTML = `<tr><td colspan="9" class="text-danger text-center py-3">${this.esc(r.error)}</td></tr>`; return; }
        const d = r.data;

        this.el('stCant').textContent = d.cantidad + (d.tope ? '+' : '');
        this.el('stTotal').textContent = '$' + this.num(d.total);
        this.el('statsRow').style.display = '';

        if (!d.cheques.length) { tb.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">Sin cheques</td></tr>'; return; }
        tb.innerHTML = d.cheques.map(c => {
            let badge = '';
            if (c.DIF) badge += '<span class="badge bg-warning text-dark me-1">Diferido</span>';
            if (c.VAD) badge += '<span class="badge bg-info">Cartera</span>';
            const echeq = (c.LOC || '').toUpperCase().indexOf('E CHEQ') >= 0 ? ' <i class="bi bi-cpu text-muted" title="e-Cheq"></i>' : '';
            return `<tr>
                <td>${this.esc(c.BANCO)}</td>
                <td>${this.esc(c.NRO)}${echeq}</td>
                <td>${this.esc(c.LIB || '—')}</td>
                <td class="text-muted">${this.esc(c.CIT)}</td>
                <td data-order="${c.FEMIO}">${this.esc(c.FEMI)}</td>
                <td data-order="${c.FENTO}">${this.esc(c.FENT)}</td>
                <td data-order="${c.FACRO}">${this.esc(c.FACR)}</td>
                <td class="text-end fw-medium" data-order="${c.IMP}">${this.num(c.IMP)}</td>
                <td>${badge}</td>
            </tr>`;
        }).join('');

        this.dt = $('#tblChq').DataTable({
            order: this.presetOrder, pageLength: 25,
            columnDefs: [{ targets: [4, 5, 6, 7], type: 'num' }],   // Emisión/Ingreso/Acred. por serial, Importe por número
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
        });
    },

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { const n = parseFloat(v); return isNaN(n) ? '0,00' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
};
document.addEventListener('DOMContentLoaded', () => App.init());
