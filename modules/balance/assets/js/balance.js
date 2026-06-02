/**
 * Balance de Sumas y Saldos — Frontend. Solo lectura.
 */
const App = {
    init() {
        this.el('btnConsultar').addEventListener('click', () => this.consultar());
        this.el('btnImprimir').addEventListener('click', () => window.print());
        ['txtDesde', 'txtHasta'].forEach(id => this.el(id).addEventListener('keydown', e => { if (e.key === 'Enter') this.consultar(); }));
        this.consultar();
    },

    async consultar() {
        const desde = this.el('txtDesde').value, hasta = this.el('txtHasta').value;
        if (!desde || !hasta) return;
        this.el('cardGrid').style.display = '';
        this.el('tbodyBal').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Cargando… (puede demorar unos segundos)</td></tr>';

        const url = new URL('api.php', location.href);
        url.searchParams.set('action', 'list');
        url.searchParams.set('desde', desde);
        url.searchParams.set('hasta', hasta);
        const r = await (await fetch(url)).json();
        if (!r.ok) { this.el('tbodyBal').innerHTML = `<tr><td colspan="6" class="text-danger text-center py-3">${this.esc(r.error)}</td></tr>`; return; }
        const d = r.data;

        const tb = this.el('tbodyBal');
        if (!d.cuentas.length) {
            tb.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Sin movimientos en el período</td></tr>';
        } else {
            tb.innerHTML = d.cuentas.map(c => `<tr class="${c.imp ? '' : 'parent'}">
                <td>${this.esc(c.codcue)}</td>
                <td class="nivel-${c.nivel}">${this.esc(c.den)}</td>
                <td class="text-end">${c.ant ? this.num(c.ant) : '—'}</td>
                <td class="text-end">${c.deb ? this.num(c.deb) : '—'}</td>
                <td class="text-end">${c.cre ? this.num(c.cre) : '—'}</td>
                <td class="text-end fw-medium">${this.num(c.saldo)}</td></tr>`).join('');
        }

        const T = d.totales;
        this.el('ftAnt').textContent = this.num(T.ant);
        this.el('ftDeb').textContent = this.num(T.deb);
        this.el('ftCre').textContent = this.num(T.cre);
        this.el('ftSaldo').textContent = this.num(T.saldo);

        this.el('stAnt').textContent = '$' + this.num(T.ant);
        this.el('stDeb').textContent = '$' + this.num(T.deb);
        this.el('stCre').textContent = '$' + this.num(T.cre);
        this.el('stSaldo').textContent = '$' + this.num(T.saldo);
        this.el('statsRow').style.display = '';

        // Chequeo de balanceo: Debe == Haber y Saldo ≈ 0
        const dif = Math.abs(T.deb - T.cre);
        const chk = this.el('balanceChk');
        if (dif < 1 && Math.abs(T.saldo) < 1) {
            chk.className = 'badge bg-success';
            chk.innerHTML = '<i class="bi bi-check-circle me-1"></i>Balanceado (Debe = Haber)';
        } else {
            chk.className = 'badge bg-warning text-dark';
            chk.textContent = 'Descuadre: ' + this.num(dif);
        }

        this.el('phPeriodo').textContent = this.dmy(desde) + ' – ' + this.dmy(hasta);
        this.el('btnImprimir').disabled = false;
    },

    dmy(iso) { const p = iso.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; },
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { const n = parseFloat(v); return isNaN(n) ? '0,00' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
};
document.addEventListener('DOMContentLoaded', () => App.init());
