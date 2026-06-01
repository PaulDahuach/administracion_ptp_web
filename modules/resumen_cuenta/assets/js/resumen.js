/**
 * Resumen de Cuenta (Deudores) — Frontend. Solo lectura.
 * Adaptado de RDN/resumen al kit (esquema PTP: codope/cicmov/fexmov).
 */
const App = {
    API: 'api.php',
    cli: null,

    init() {
        this.bind();
        const cc = new URLSearchParams(location.search).get('codcue');
        if (cc) {
            this.el('hdnCodcue').value = cc;
            this.el('btnConsultar').disabled = false;
            this.api('get_cliente', { codcue: cc }).then(r => {
                if (r.ok) { this.cli = r.data; this.el('txtCliente').value = (r.data.DENCUE || '').trim(); this.consultar(); }
            });
        }
    },

    bind() {
        let t;
        this.el('txtCliente').addEventListener('input', e => { clearTimeout(t); t = setTimeout(() => this.autocomplete(e.target.value), 300); });
        this.el('txtCliente').addEventListener('focus', e => { if (e.target.value.length >= 2) this.autocomplete(e.target.value); });
        document.addEventListener('click', e => { if (!e.target.closest('.ac-wrap')) this.el('acList').classList.remove('show'); });
        this.el('btnConsultar').addEventListener('click', () => this.consultar());
        this.el('btnImprimir').addEventListener('click', () => window.print());
        ['txtDesde', 'txtHasta'].forEach(id => this.el(id).addEventListener('keydown', e => { if (e.key === 'Enter') this.consultar(); }));
    },

    async autocomplete(q) {
        const list = this.el('acList');
        if (q.length < 2) { list.classList.remove('show'); return; }
        const r = await this.api('buscar_clientes', { q });
        if (!r.ok || !r.data.length) { list.classList.remove('show'); return; }
        list.innerHTML = r.data.map(c =>
            `<div class="ac-item" data-cc="${c.CODCUE}" data-den="${this.esc(c.DENCUE)}" data-cit="${this.esc(c.CITCUE || '')}">
                <span class="ac-code">${this.esc(c.CITCUE || '')}</span>${this.esc(c.DENCUE)}</div>`).join('');
        list.querySelectorAll('.ac-item').forEach(it => it.addEventListener('click', () => {
            this.cli = { CODCUE: it.dataset.cc, DENCUE: it.dataset.den, CITCUE: it.dataset.cit };
            this.el('txtCliente').value = (it.dataset.den || '').trim();
            this.el('hdnCodcue').value = it.dataset.cc;
            this.el('btnConsultar').disabled = false;
            list.classList.remove('show');
        }));
        list.classList.add('show');
    },

    async consultar() {
        const cc = this.el('hdnCodcue').value;
        if (!cc) { return; }
        const desde = this.el('txtDesde').value, hasta = this.el('txtHasta').value;
        this.el('cardCliente').style.display = '';
        this.el('cardGrid').style.display = '';
        this.el('tbodyResumen').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Cargando…</td></tr>';

        const params = { codcue: cc };
        if (desde) params.desde = desde;
        if (hasta) params.hasta = hasta;
        const r = await this.api('resumen', params);
        if (!r.ok) { this.el('tbodyResumen').innerHTML = `<tr><td colspan="7" class="text-danger text-center py-3">${this.esc(r.error)}</td></tr>`; return; }

        this.el('lblCliente').textContent = (this.cli.DENCUE || '').trim();
        this.el('lblCuit').textContent = this.cli.CITCUE ? 'CUIT: ' + this.cli.CITCUE : '';
        this.el('lblPeriodo').textContent = (desde ? this.dmy(desde) : 'Inicio') + ' – ' + (hasta ? this.dmy(hasta) : 'Hoy');
        this.el('lblCantMov').textContent = r.data.movimientos.length;

        this.renderGrid(r.data);
        this.renderStats(r.data);
        this.el('phCliente').textContent = (this.cli.DENCUE || '').trim();
        this.el('phCuit').textContent = this.cli.CITCUE || '';
        this.el('phPeriodo').textContent = this.el('lblPeriodo').textContent;
        this.el('btnImprimir').disabled = false;
    },

    renderGrid(d) {
        const movs = d.movimientos || [];
        const tb = this.el('tbodyResumen');
        let html = '';
        const sa = parseFloat(d.saldoAnterior);
        if (sa !== 0) {
            html += `<tr class="saldo-anterior-row">
                <td colspan="4" class="fst-italic">Saldo anterior</td>
                <td class="text-end">${sa > 0 ? this.num(d.saldoAnterior) : ''}</td>
                <td class="text-end">${sa < 0 ? this.num(Math.abs(sa)) : ''}</td>
                <td class="text-end fw-bold ${sa > 0 ? 'saldo-pos' : 'saldo-neg'}">${this.num(d.saldoAnterior)}</td></tr>`;
        }
        if (!movs.length && sa === 0) {
            tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Sin movimientos en el período</td></tr>';
            ['totalDebe', 'totalHaber', 'totalSaldo'].forEach(id => this.el(id).textContent = '');
            return;
        }
        html += movs.map(m => {
            const s = parseFloat(m.SALDO);
            return `<tr class="row-${this.esc(m.CIC)}">
                <td class="text-end text-muted">${m.NUMMOV}</td>
                <td>${this.esc(m.FECHA)}</td>
                <td class="fw-medium">${this.esc(m.COMP)}</td>
                <td class="text-muted d-none d-md-table-cell text-truncate" style="max-width:260px">${this.esc(m.DETMOV)}</td>
                <td class="text-end">${m.DEBE ? this.num(m.DEBE) : ''}</td>
                <td class="text-end">${m.HABER ? this.num(m.HABER) : ''}</td>
                <td class="text-end fw-bold ${s > 0 ? 'saldo-pos' : (s < 0 ? 'saldo-neg' : '')}">${this.num(m.SALDO)}</td></tr>`;
        }).join('');
        tb.innerHTML = html;
        this.el('totalDebe').textContent = this.num(d.totalDebe);
        this.el('totalHaber').textContent = this.num(d.totalHaber);
        const sf = parseFloat(d.saldo);
        this.el('totalSaldo').textContent = this.num(d.saldo);
        this.el('totalSaldo').className = 'text-end fw-bold ' + (sf > 0 ? 'saldo-pos' : 'saldo-neg');
    },

    renderStats(d) {
        const sa = parseFloat(d.saldoAnterior), sf = parseFloat(d.saldo);
        this.el('statSaldoAnt').textContent = '$' + this.num(d.saldoAnterior);
        this.el('statSaldoAnt').className = 'stat-value ' + (sa > 0 ? 'saldo-pos' : (sa < 0 ? 'saldo-neg' : ''));
        this.el('statDebitos').textContent = '$' + this.num(d.totalDebe);
        this.el('statCreditos').textContent = '$' + this.num(d.totalHaber);
        this.el('statSaldoFinal').textContent = '$' + this.num(d.saldo);
        this.el('statSaldoFinal').className = 'stat-value ' + (sf > 0 ? 'saldo-pos' : 'saldo-neg');
        this.el('statSaldoIcon').className = 'stat-icon ' + (sf > 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success');
        this.el('statsRow').style.display = '';
    },

    dmy(iso) { const p = iso.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; },
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { const n = parseFloat(v); return isNaN(n) ? '' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

    async api(action, params = {}) {
        const url = new URL(this.API, location.href);
        url.searchParams.set('action', action);
        for (const k in params) url.searchParams.set(k, params[k]);
        return await (await fetch(url)).json();
    },
};
document.addEventListener('DOMContentLoaded', () => App.init());
