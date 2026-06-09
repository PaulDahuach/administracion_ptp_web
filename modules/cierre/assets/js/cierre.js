/* Cierre Diario de Caja — resumen de caja (ledger) + cambio de fecha del sistema. */
const CIE = {
    RO: window.CIE_RO, data: null,
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },

    async init() {
        this.el('btnVer').addEventListener('click', () => this.cargar(this.el('fFecha').value));
        this.el('btnHoy').addEventListener('click', () => this.cargar(this.data ? this.data.sysdate : ''));
        this.el('fFecha').addEventListener('keydown', e => { if (e.key === 'Enter') this.cargar(this.el('fFecha').value); });
        if (!this.RO) {
            this.el('btnCerrar').addEventListener('click', () => this.abrirCerrar());
            this.el('btnCerrarOk').addEventListener('click', () => this.cerrar());
        }
        await this.cargar('');   // default = fecha del sistema
    },

    async cargar(fecha) {
        const j = await this.api('resumen', fecha ? { fecha } : {});
        if (!j.ok) { this.el('cierreBody').innerHTML = '<div class="alert alert-danger">' + this.esc(j.error) + '</div>'; return; }
        this.data = j.data;
        this.el('fFecha').value = j.data.fecha;
        this.el('sysdate').textContent = j.data.sysdateDisp;
        if (!this.RO) this.el('btnCerrar').disabled = !j.data.esSistema;   // cerrar solo en la fecha del sistema
        this.render(j.data);
    },

    sal(v) { const neg = String(v).indexOf('-') === 0; return `<span class="cie-num ${neg ? 'cie-neg' : ''}">${this.esc(v)}</span>`; },

    render(d) {
        const box = (titulo, p) => `<div class="card fc-card h-100"><div class="card-header"><span>${titulo}</span></div>
          <div class="card-body cie-pos">
            <div><label>Saldo anterior</label>${this.sal(p.ant)}</div>
            <div><label>Ingresos del día</label>${this.sal(p.ing)}</div>
            <div><label>Egresos del día</label>${this.sal(p.egr)}</div>
            <div class="cie-act"><label>Saldo actual</label>${this.sal(p.act)}</div>
          </div></div>`;

        let drift = '';
        if (d.stored) {
            drift = `<div class="alert alert-secondary py-1 px-2 small mb-2">
              <i class="bi bi-clock-history me-1"></i>Cierre guardado el ${this.esc(d.stored.now)}: efectivo ${this.esc(d.stored.eft)} · cheques ${this.esc(d.stored.chq)}.
              Si difiere de los valores de arriba, es porque se corrigió data de ese día después de cerrar (el resumen muestra siempre el estado actual del libro mayor).</div>`;
        }

        const det = (titulo, cols, rows, render) => `<div class="card fc-card"><div class="card-header"><span>${titulo} <span class="badge bg-secondary ms-1">${rows.length}</span></span></div>
          <div class="card-body p-0"><table class="table table-sm cie-grid mb-0"><thead><tr>${cols.map(c => `<th${c.r ? ' class="text-end"' : ''}>${c.t}</th>`).join('')}</tr></thead>
          <tbody>${rows.length ? rows.map(render).join('') : `<tr><td colspan="${cols.length}" class="text-muted small">Sin movimientos.</td></tr>`}</tbody></table></div></div>`;

        this.el('cierreBody').innerHTML =
            `<div class="cie-fecha mb-2">Posición de caja al <strong>${this.esc(d.fechaDisp)}</strong>${d.esSistema ? ' <span class="badge bg-success">fecha del sistema</span>' : ''}</div>`
            + drift
            + `<div class="row g-3 mb-1">
                 <div class="col-md-5">${box('<i class="bi bi-cash me-1"></i>Efectivo (Caja)', d.efectivo)}</div>
                 <div class="col-md-5">${box('<i class="bi bi-bank me-1"></i>Cheques en Cartera', d.cheques)}</div>
                 <div class="col-md-2"><div class="card fc-card h-100"><div class="card-header"><span>Total</span></div>
                   <div class="card-body cie-tot">
                     <div><label>Saldo actual</label>${this.sal(d.total)}</div>
                     <div><label>Retenciones</label>${this.sal(d.totret)}</div>
                     <div><label>Interdepósito</label>${this.sal(d.totidp)}</div>
                   </div></div></div>
               </div>`
            + det('<i class="bi bi-cash me-1"></i>Movimientos de Efectivo',
                [{ t: 'Mov.' }, { t: 'Comprobante' }, { t: 'Cuenta Corriente' }, { t: 'Detalle' }, { t: 'Ingresos', r: 1 }, { t: 'Egresos', r: 1 }],
                d.detEfectivo, r => `<tr><td>${r.num}</td><td>${this.esc(r.comp)}</td><td>${this.esc(r.cta)}</td><td>${this.esc(r.det)}</td><td class="text-end">${this.esc(r.ing)}</td><td class="text-end">${this.esc(r.egr)}</td></tr>`)
            + det('<i class="bi bi-bank me-1"></i>Cheques de Terceros',
                [{ t: 'Mov.' }, { t: 'Comprobante' }, { t: 'Banco' }, { t: 'Nº' }, { t: 'Ingresos', r: 1 }, { t: 'Egresos', r: 1 }],
                d.detCheques, r => `<tr><td>${r.num}</td><td>${this.esc(r.comp)}</td><td>${this.esc(r.banco)}</td><td>${this.esc(r.nro)}</td><td class="text-end">${this.esc(r.ing)}</td><td class="text-end">${this.esc(r.egr)}</td></tr>`);
    },

    abrirCerrar() {
        if (!this.data || !this.data.esSistema) return;
        this.el('cerrarFeccie').textContent = this.data.sysdateDisp;
        // default nueva = sistema + 1 día
        const d = new Date(this.data.sysdate + 'T00:00:00'); d.setDate(d.getDate() + 1);
        this.el('fNueva').value = d.toISOString().slice(0, 10);
        this.el('cerrarErr').textContent = '';
        new bootstrap.Modal(this.el('modalCerrar')).show();
    },

    async cerrar() {
        this.el('cerrarErr').textContent = '';
        const fd = new FormData(); fd.append('nueva', this.el('fNueva').value);
        const j = await (await fetch('api.php?action=cerrar', { method: 'POST', body: fd })).json();
        if (!j.ok) { this.el('cerrarErr').textContent = j.error || 'Error al cerrar'; return; }
        bootstrap.Modal.getInstance(this.el('modalCerrar')).hide();
        this.toast('Día cerrado. Fecha del sistema: ' + j.data.nuevaDisp, 'success');
        await this.cargar(j.data.nueva);   // ahora mostramos la nueva fecha del sistema
    },

    async api(action, params = {}) {
        const url = new URL('api.php', window.location.href);
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
        return await (await fetch(url)).json();
    },
    toast(msg, type = 'info') {
        const t = this.el('toastMsg'); this.el('toastBody').textContent = msg;
        t.className = 'toast align-items-center border-0 text-bg-' + type;
        bootstrap.Toast.getOrCreateInstance(t, { delay: type === 'danger' ? 7000 : 4000 }).show();
    },
};
document.addEventListener('DOMContentLoaded', () => CIE.init());
