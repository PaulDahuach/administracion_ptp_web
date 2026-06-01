/**
 * Saldos Actuales (Deudores) — Frontend. Solo lectura.
 * Una fila por deudor: Blanco / Negro / Total. Click → Resumen de Cuenta.
 */
const App = {
    init() {
        this.load();
    },

    async load() {
        const r = await (await fetch('api.php?action=list')).json();
        if (!r.ok) { alert('Error: ' + r.error); return; }
        const d = r.data;

        this.el('stBlanco').textContent = '$' + this.num(d.totBlanco);
        this.el('stNegro').textContent = '$' + this.num(d.totNegro);
        this.el('stTotal').textContent = '$' + this.num(d.totTotal);
        this.el('stTotal').className = 'stat-value ' + (d.totTotal >= 0 ? 'saldo-pos' : 'saldo-neg');
        this.el('stCant').textContent = d.cantidad;
        this.el('ftBlanco').textContent = this.num(d.totBlanco);
        this.el('ftNegro').textContent = this.num(d.totNegro);
        this.el('ftTotal').textContent = this.num(d.totTotal);

        const tb = document.querySelector('#tblSaldos tbody');
        tb.innerHTML = d.clientes.map(c => {
            const cls = v => v > 0 ? 'saldo-pos' : (v < 0 ? 'saldo-neg' : 'text-muted');
            return `<tr data-codcue="${c.codcue}">
                <td class="text-muted">${c.codcue}</td>
                <td>${this.esc(c.den)}</td>
                <td class="text-muted">${this.esc(c.cit)}</td>
                <td class="text-end ${cls(c.blanco)}" data-order="${c.blanco}">${c.blanco ? this.num(c.blanco) : '—'}</td>
                <td class="text-end ${cls(c.negro)}" data-order="${c.negro}">${c.negro ? this.num(c.negro) : '—'}</td>
                <td class="text-end fw-bold ${cls(c.total)}" data-order="${c.total}">${this.num(c.total)}</td>
            </tr>`;
        }).join('');

        // Click fila → resumen de cuenta del cliente
        tb.querySelectorAll('tr').forEach(tr => tr.addEventListener('click', () => {
            location.href = '../resumen_cuenta/?codcue=' + tr.dataset.codcue;
        }));

        // DataTable (orden por Total desc por defecto)
        if ($.fn.dataTable.isDataTable('#tblSaldos')) $('#tblSaldos').DataTable().destroy();
        $('#tblSaldos').DataTable({
            order: [[5, 'desc']],
            pageLength: 25,
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
        });
        this.el('btnImprimir').disabled = false;
        this.el('btnImprimir').onclick = () => window.print();
    },

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { const n = parseFloat(v); return isNaN(n) ? '' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
};
document.addEventListener('DOMContentLoaded', () => App.init());
