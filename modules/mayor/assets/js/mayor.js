/**
 * Mayor x Cuenta — Frontend. Solo lectura.
 */
const App = {
    API: 'api.php',
    cta: null,

    init() {
        this.bind();
        const cc = new URLSearchParams(location.search).get('codcue');
        if (cc) {
            this.el('hdnCodcue').value = cc;
            this.el('btnConsultar').disabled = false;
            this.api('get_cuenta', { codcue: cc }).then(r => {
                if (r.ok) { this.cta = r.data; this.el('txtCuenta').value = r.data.CODCUE + ' — ' + (r.data.DENCUE || '').trim(); this.consultar(); }
            });
        }
    },

    bind() {
        let t;
        this.el('txtCuenta').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => this.autocomplete(e.target.value), 250); });
        this.el('txtCuenta').addEventListener('focus', e => { if (e.target.value.length >= 1) this.autocomplete(e.target.value); });
        document.addEventListener('click', e => { if (!e.target.closest('.ac-wrap')) this.el('acList').classList.remove('show'); });
        this.el('btnConsultar').addEventListener('click', () => this.consultar());
        this.el('btnImprimir').addEventListener('click', () => window.print());
        this.el('cboFecha').addEventListener('change', () => { if (this.el('hdnCodcue').value) this.consultar(); });
        ['txtDesde', 'txtHasta'].forEach(id => this.el(id).addEventListener('keydown', e => { if (e.key === 'Enter') this.consultar(); }));
    },

    async autocomplete(q) {
        const list = this.el('acList');
        if (q.length < 1) { list.classList.remove('show'); return; }
        const r = await this.api('buscar_cuentas', { q });
        if (!r.ok || !r.data.length) { list.classList.remove('show'); return; }
        list.innerHTML = r.data.map(c =>
            `<div class="ac-item" data-cc="${this.esc(c.CODCUE)}" data-den="${this.esc(c.DENCUE)}">
                <span class="ac-code">${this.esc(c.CODCUE)}</span>${this.esc(c.DENCUE)}</div>`).join('');
        list.querySelectorAll('.ac-item').forEach(it => it.addEventListener('click', () => {
            this.cta = { CODCUE: it.dataset.cc, DENCUE: it.dataset.den };
            this.el('txtCuenta').value = it.dataset.cc + ' — ' + (it.dataset.den || '').trim();
            this.el('hdnCodcue').value = it.dataset.cc;
            this.el('btnConsultar').disabled = false;
            list.classList.remove('show');
        }));
        list.classList.add('show');
    },

    async consultar() {
        const cc = this.el('hdnCodcue').value;
        if (!cc) return;
        const desde = this.el('txtDesde').value, hasta = this.el('txtHasta').value, fecha = this.el('cboFecha').value;
        this.el('cardCuenta').style.display = '';
        this.el('cardGrid').style.display = '';
        this.el('tbodyMayor').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Cargando…</td></tr>';

        const params = { codcue: cc, fecha };
        if (desde) params.desde = desde;
        if (hasta) params.hasta = hasta;
        const r = await this.api('mayor', params);
        if (!r.ok) { this.el('tbodyMayor').innerHTML = `<tr><td colspan="7" class="text-danger text-center py-3">${this.esc(r.error)}</td></tr>`; return; }
        const d = r.data;

        this.el('lblCuenta').textContent = d.cuenta.codcue + ' — ' + d.cuenta.den;
        this.el('lblPeriodo').textContent = (desde ? this.dmy(desde) : 'Inicio') + ' – ' + (hasta ? this.dmy(hasta) : 'Hoy') +
            ' · ' + (fecha === 'mov' ? 'F.Mov' : 'F.Comp');
        this.el('lblCant').textContent = d.movimientos.length;

        this.render(d);
        this.el('phCuenta').textContent = d.cuenta.codcue + ' — ' + d.cuenta.den;
        this.el('phPeriodo').textContent = this.el('lblPeriodo').textContent;
        this.el('btnImprimir').disabled = false;
    },

    render(d) {
        const movs = d.movimientos || [];
        const tb = this.el('tbodyMayor');
        let html = '';
        const sa = parseFloat(d.saldoAnterior);
        html += `<tr class="saldo-anterior-row">
            <td colspan="6" class="fst-italic">Saldo anterior</td>
            <td class="text-end fw-bold">${this.num(d.saldoAnterior)}</td></tr>`;
        if (!movs.length) {
            tb.innerHTML = html + '<tr><td colspan="7" class="text-center text-muted py-3">Sin asientos en el período</td></tr>';
            ['totalDebe', 'totalHaber'].forEach(id => this.el(id).textContent = '');
            this.el('totalSaldo').textContent = this.num(d.saldo);
            this.fillStats(d);
            return;
        }
        html += movs.map(m => `<tr>
            <td>${this.esc(m.FECHA)}</td>
            <td class="fw-medium">${this.esc(m.COMP)}</td>
            <td class="text-truncate" style="max-width:280px">${this.esc(m.DETALLE)}</td>
            <td class="text-muted">${this.esc(m.CDC)}</td>
            <td class="text-end">${m.DEBE ? this.num(m.DEBE) : ''}</td>
            <td class="text-end">${m.HABER ? this.num(m.HABER) : ''}</td>
            <td class="text-end fw-medium">${this.num(m.SALDO)}</td></tr>`).join('');
        tb.innerHTML = html;
        this.el('totalDebe').textContent = this.num(d.totalDebe);
        this.el('totalHaber').textContent = this.num(d.totalHaber);
        this.el('totalSaldo').textContent = this.num(d.saldo);
        this.fillStats(d);
    },

    fillStats(d) {
        this.el('statSaldoAnt').textContent = '$' + this.num(d.saldoAnterior);
        this.el('statDebitos').textContent = '$' + this.num(d.totalDebe);
        this.el('statCreditos').textContent = '$' + this.num(d.totalHaber);
        this.el('statSaldoFinal').textContent = '$' + this.num(d.saldo);
        this.el('statsRow').style.display = '';
    },

    dmy(iso) { const p = iso.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; },
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { const n = parseFloat(v); return isNaN(n) ? '0,00' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

    async api(action, params = {}) {
        const url = new URL(this.API, location.href);
        url.searchParams.set('action', action);
        for (const k in params) url.searchParams.set(k, params[k]);
        return await (await fetch(url)).json();
    },
};
document.addEventListener('DOMContentLoaded', () => App.init());
