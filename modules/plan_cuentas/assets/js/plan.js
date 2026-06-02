/**
 * Plan de Cuentas — Frontend. Solo lectura.
 * Click en cuenta imputable → Mayor de esa cuenta.
 */
const App = {
    init() { this.load(); },

    async load() {
        const r = await (await fetch('api.php?action=list')).json();
        if (!r.ok) { alert('Error: ' + r.error); return; }
        const tb = document.querySelector('#tblPlan tbody');
        tb.innerHTML = r.data.cuentas.map(c => {
            const cls = c.imp ? 'imp' : 'parent';
            const saldoCls = c.saldo > 0 ? 'saldo-pos' : (c.saldo < 0 ? 'saldo-neg' : '');
            return `<tr class="${cls}" data-codcue="${this.esc(c.codcue)}" data-imp="${c.imp}">
                <td>${this.esc(c.codcue)}</td>
                <td class="nivel-${c.nivel}">${this.esc(c.den)}</td>
                <td class="text-center">${c.imp ? '<i class="bi bi-check-lg text-success"></i>' : ''}</td>
                <td class="text-end ${saldoCls}" data-order="${c.saldo === null ? '' : c.saldo}">${c.saldo === null ? '' : this.num(c.saldo)}</td>
            </tr>`;
        }).join('');

        tb.querySelectorAll('tr.imp').forEach(tr => tr.addEventListener('click', () => {
            location.href = '../mayor/?codcue=' + encodeURIComponent(tr.dataset.codcue);
        }));

        $('#tblPlan').DataTable({
            order: [],                 // mantener orden jerárquico (por código)
            pageLength: 50,
            columnDefs: [{ targets: [3], type: 'num' }],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
        });
        document.getElementById('btnImprimir').disabled = false;
        document.getElementById('btnImprimir').onclick = () => window.print();
    },

    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { const n = parseFloat(v); return isNaN(n) ? '' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
};
document.addEventListener('DOMContentLoaded', () => App.init());
