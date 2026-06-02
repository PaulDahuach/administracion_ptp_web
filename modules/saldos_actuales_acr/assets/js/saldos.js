/**
 * Saldos Actuales (Acreedores / Proveedores) — Frontend. Solo lectura.
 * Fila por proveedor: Blanco/Negro/Total. Negativo = le debemos (rojo).
 * Click → Resumen de Cuenta del proveedor.
 */
const App = {
    init() { this.load(); },

    async load() {
        const r = await (await fetch('api.php?action=list')).json();
        if (!r.ok) { alert('Error: ' + r.error); return; }
        const d = r.data;

        this.el('stPagar').textContent = '$' + this.num(d.totPagar);
        this.el('stBlanco').textContent = '$' + this.num(d.totBlanco);
        this.el('stNegro').textContent = '$' + this.num(d.totNegro);
        this.el('stCant').textContent = d.cantidad;
        this.el('ftBlanco').textContent = this.num(d.totBlanco);
        this.el('ftNegro').textContent = this.num(d.totNegro);
        this.el('ftTotal').textContent = this.num(d.totTotal);

        // Acreedores: negativo (le debemos) = rojo; positivo (a favor) = verde
        const cls = v => v < 0 ? 'saldo-pos' : (v > 0 ? 'saldo-neg' : 'text-muted');
        const tb = document.querySelector('#tblSaldos tbody');
        tb.innerHTML = d.clientes.map(c => `<tr data-codcue="${c.codcue}">
                <td class="text-muted">${c.codcue}</td>
                <td>${this.esc(c.den)}</td>
                <td class="text-muted">${this.esc(c.cit)}</td>
                <td class="text-end ${cls(c.blanco)}" data-order="${c.blanco}">${c.blanco ? this.num(c.blanco) : '—'}</td>
                <td class="text-end ${cls(c.negro)}" data-order="${c.negro}">${c.negro ? this.num(c.negro) : '—'}</td>
                <td class="text-end fw-bold ${cls(c.total)}" data-order="${c.total}">${this.num(c.total)}</td>
            </tr>`).join('');

        tb.querySelectorAll('tr').forEach(tr => tr.addEventListener('click', () => {
            location.href = '../resumen_cuenta_acr/?codcue=' + tr.dataset.codcue;
        }));

        if ($.fn.dataTable.isDataTable('#tblSaldos')) $('#tblSaldos').DataTable().destroy();
        $('#tblSaldos').DataTable({
            order: [[5, 'asc']],   // más negativo (mayor deuda) primero
            pageLength: 25,
            columnDefs: [{ targets: [0, 3, 4, 5], type: 'num' }],
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
