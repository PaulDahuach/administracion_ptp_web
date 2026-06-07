/**
 * Saldos Actuales (Deudores) — Frontend. Solo lectura.
 * VE_AMBOS=true: columnas Blanco/Capacitacion/Total. false: un solo "Saldo" (operador/capacitación).
 * Click → Resumen de Cuenta del cliente.
 */
const App = {
    init() { this.load(); },

    async load() {
        const r = await (await fetch('api.php?action=list')).json();
        if (!r.ok) { alert('Error: ' + r.error); return; }
        const d = r.data;
        const ve = !!window.VE_AMBOS;
        const cls = v => v > 0 ? 'saldo-pos' : (v < 0 ? 'saldo-neg' : 'text-muted');
        const tb = document.querySelector('#tblSaldos tbody');

        if (ve) {
            this.el('stBlanco').textContent = '$' + this.num(d.totBlanco);
            this.el('stCapacitacion').textContent = '$' + this.num(d.totCapacitacion);
            this.el('stTotal').textContent = '$' + this.num(d.totTotal);
            this.el('stCant').textContent = d.cantidad;
            this.el('ftBlanco').textContent = this.num(d.totBlanco);
            this.el('ftCapacitacion').textContent = this.num(d.totCapacitacion);
            this.el('ftTotal').textContent = this.num(d.totTotal);
            tb.innerHTML = d.clientes.map(c => `<tr data-codcue="${c.codcue}">
                <td class="text-muted">${c.codcue}</td>
                <td>${this.esc(c.den)}</td>
                <td class="text-muted">${this.esc(c.cit)}</td>
                <td class="text-end ${cls(c.blanco)}" data-order="${c.blanco}">${c.blanco ? this.num(c.blanco) : '—'}</td>
                <td class="text-end ${cls(c.capacitacion)}" data-order="${c.capacitacion}">${c.capacitacion ? this.num(c.capacitacion) : '—'}</td>
                <td class="text-end fw-bold ${cls(c.total)}" data-order="${c.total}">${this.num(c.total)}</td>
            </tr>`).join('');
        } else {
            this.el('stTotal').textContent = '$' + this.num(d.total);
            this.el('stCant').textContent = d.cantidad;
            this.el('ftTotal').textContent = this.num(d.total);
            tb.innerHTML = d.clientes.map(c => `<tr data-codcue="${c.codcue}">
                <td class="text-muted">${c.codcue}</td>
                <td>${this.esc(c.den)}</td>
                <td class="text-muted">${this.esc(c.cit)}</td>
                <td class="text-end fw-bold ${cls(c.saldo)}" data-order="${c.saldo}">${this.num(c.saldo)}</td>
            </tr>`).join('');
        }

        document.querySelectorAll('#tblSaldos tbody tr').forEach(tr =>
            tr.addEventListener('click', () => { location.href = '../resumen_cuenta/?codcue=' + tr.dataset.codcue; }));

        const numCol = ve ? [0, 3, 4, 5] : [0, 3];
        const orderCol = ve ? 5 : 3;
        $('#tblSaldos').DataTable({
            order: [[orderCol, 'desc']],
            pageLength: 25,
            columnDefs: [{ targets: numCol, type: 'num' }],
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
