/**
 * Bancos / Conciliación — Frontend. Solo lectura.
 */
const App = {
    init() {
        this.el('cboCuenta').addEventListener('change', () => {
            this.el('btnConsultar').disabled = !this.el('cboCuenta').value;
            if (this.el('cboCuenta').value) this.consultar();
        });
        this.el('btnConsultar').addEventListener('click', () => this.consultar());
        this.el('btnImprimir').addEventListener('click', () => window.print());
        this.el('cboEstado').addEventListener('change', () => { if (this.el('cboCuenta').value) this.consultar(); });
        this.el('cboBase').addEventListener('change', () => { if (this.el('cboCuenta').value) this.consultar(); });
        ['txtDesde', 'txtHasta'].forEach(id => this.el(id).addEventListener('keydown', e => { if (e.key === 'Enter') this.consultar(); }));
    },

    async consultar() {
        const cc = this.el('cboCuenta').value;
        if (!cc) return;
        const desde = this.el('txtDesde').value, hasta = this.el('txtHasta').value;
        const estado = this.el('cboEstado').value, base = this.el('cboBase').value;
        this.el('cardGrid').style.display = '';
        this.el('tbodyBco').innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">Cargando…</td></tr>';

        const url = new URL('api.php', location.href);
        url.searchParams.set('action', 'list');
        url.searchParams.set('codcue', cc);
        url.searchParams.set('estado', estado);
        url.searchParams.set('base', base);
        if (desde) url.searchParams.set('desde', desde);
        if (hasta) url.searchParams.set('hasta', hasta);
        const r = await (await fetch(url)).json();
        if (!r.ok) { this.el('tbodyBco').innerHTML = `<tr><td colspan="9" class="text-danger text-center py-3">${this.esc(r.error)}</td></tr>`; return; }
        const d = r.data;

        const tb = this.el('tbodyBco');
        let html = `<tr class="saldo-anterior-row"><td colspan="7" class="fst-italic">Saldo anterior</td>
            <td class="text-end fw-bold">${this.num(d.saldoAnterior)}</td><td></td></tr>`;
        if (!d.movimientos.length) {
            tb.innerHTML = html + '<tr><td colspan="9" class="text-center text-muted py-3">Sin movimientos</td></tr>';
        } else {
            html += d.movimientos.map(m => `<tr>
                <td>${this.esc(m.FECHA)}</td>
                <td class="fw-medium">${this.esc(m.COMP)}</td>
                <td class="text-truncate" style="max-width:230px">${this.esc(m.DETALLE)}</td>
                <td class="text-muted small">${this.esc(m.CHEQUE)}</td>
                <td>${this.esc(m.FACR)}</td>
                <td class="text-end">${m.DEBE ? this.num(m.DEBE) : ''}</td>
                <td class="text-end">${m.HABER ? this.num(m.HABER) : ''}</td>
                <td class="text-end fw-medium">${this.num(m.SALDO)}</td>
                <td class="text-center">${m.CONC ? '<i class="bi bi-check-circle-fill text-success" title="Conciliado"></i>' : '<i class="bi bi-circle text-muted" title="Pendiente"></i>'}</td>
            </tr>`).join('');
            tb.innerHTML = html;
        }
        this.el('totalDebe').textContent = this.num(d.totalDebe);
        this.el('totalHaber').textContent = this.num(d.totalHaber);
        this.el('totalSaldo').textContent = this.num(d.saldo);

        this.el('stAnt').textContent = '$' + this.num(d.saldoAnterior);
        this.el('stDeb').textContent = '$' + this.num(d.totalDebe);
        this.el('stCre').textContent = '$' + this.num(d.totalHaber);
        this.el('stSaldo').textContent = '$' + this.num(d.saldo);
        this.el('statsRow').style.display = '';

        const opt = this.el('cboCuenta').selectedOptions[0];
        this.el('phCuenta').textContent = opt ? opt.textContent : d.cuenta.codcue;
        this.el('phPeriodo').textContent = (desde ? this.dmy(desde) : 'Inicio') + ' – ' + (hasta ? this.dmy(hasta) : 'Hoy') +
            (estado !== 'todos' ? ' · ' + estado : '') + (d.pendCant ? ' · ' + d.pendCant + ' pend.' : '');
        this.el('btnImprimir').disabled = false;
    },

    dmy(iso) { const p = iso.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; },
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { const n = parseFloat(v); return isNaN(n) ? '0,00' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
};
document.addEventListener('DOMContentLoaded', () => App.init());
