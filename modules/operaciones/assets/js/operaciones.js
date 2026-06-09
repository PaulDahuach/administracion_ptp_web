/* Operaciones — visor de solo lectura. Mecánica: Buscar (modal) → llena el form (read-only). */
const OP = {
    dt: null,
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },

    EMPTY: {
        cod: '', den: '', origen: '', modulo: '', sys: 0,
        ci_cod: '', ci_num: '—', ci_ult: '', ci_ccte: 0, ci_iden: 0, ci_pdv: 0,
        ce_grav: 0, ce_cuit: 0, ce_rs: 0, ce_num: 0, ce_chq: 0, ce_cons: 0,
        modelos: [], auxiliares: [],
    },

    init() {
        this.el('opDetail').innerHTML = this.detailHtml(this.EMPTY);   // form desplegado vacío (como el resto)
        this.el('btnBuscar').addEventListener('click', () => new bootstrap.Modal(this.el('modalBuscar')).show());
        this.el('modalBuscar').addEventListener('shown.bs.modal', () => this.loadList());
    },

    async loadList() {
        const j = await this.api('list');
        if (!j.ok) return;
        const data = (j.data || []).map(o => [o.cod, o.den, o.origen]);
        const self = this;
        if (this.dt) { this.dt.clear().rows.add(data).draw(); }
        else {
            this.dt = $('#grdBuscar').DataTable({
                data: data, pageLength: 25, order: [[0, 'asc']],
                columnDefs: [{ targets: 0, type: 'num' }],
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
                createdRow: function (row, d) {
                    row.addEventListener('click', () => {
                        self.cargar(d[0]);
                        bootstrap.Modal.getInstance(self.el('modalBuscar')).hide();
                    });
                }
            });
        }
        setTimeout(() => $('#modalBuscar .dataTables_filter input').trigger('focus'), 150);
    },

    async cargar(cod) {
        const j = await this.api('get', { cod });
        if (!j.ok) { this.el('opDetail').innerHTML = '<div class="alert alert-danger">' + this.esc(j.error) + '</div>'; return; }
        this.el('opDetail').innerHTML = this.detailHtml(j.data);
    },

    chk(v) { return v ? '<i class="bi bi-check-square-fill text-success"></i>' : '<i class="bi bi-square text-muted"></i>'; },

    detailHtml(d) {
        const titulo = d.cod ? (this.esc(d.cod) + ' — ' + this.esc(d.den)) : 'Operación';
        let h = `<div class="card fc-card"><div class="card-header"><span><i class="bi bi-gear me-1"></i>${titulo}</span>`
            + (d.sys ? '<span class="badge bg-secondary">Sistema</span>' : '') + `</div><div class="card-body op-datos">
            <div><label>Código</label><span>${this.esc(d.cod) || '—'}</span></div>
            <div><label>Origen</label><span>${this.esc(d.origen) || '—'}</span></div>
            <div><label>Módulo</label><span>${this.esc(d.modulo) || '—'}</span></div>
          </div></div>`;

        h += `<div class="row g-2"><div class="col-md-6"><div class="card fc-card h-100"><div class="card-header"><span>Comprobante Interno</span></div>
            <div class="card-body op-flags">
              <div><label>Código</label><span>${this.esc(d.ci_cod) || '—'}</span></div>
              <div><label>Numeración</label><span>${this.esc(d.ci_num)}</span></div>
              <div><label>Último Nº</label><span>${this.esc(d.ci_ult)}</span></div>
              <div><label>C.Cte. Deudora</label><span>${this.chk(d.ci_ccte)}</span></div>
              <div><label>Identificación</label><span>${this.chk(d.ci_iden)}</span></div>
              <div><label>Punto de Venta</label><span>${this.chk(d.ci_pdv)}</span></div>
            </div></div></div>
          <div class="col-md-6"><div class="card fc-card h-100"><div class="card-header"><span>Comprobante Externo</span></div>
            <div class="card-body op-flags">
              <div><label>Gravado</label><span>${this.chk(d.ce_grav)}</span></div>
              <div><label>C.U.I.T.</label><span>${this.chk(d.ce_cuit)}</span></div>
              <div><label>Razón Social</label><span>${this.chk(d.ce_rs)}</span></div>
              <div><label>Número</label><span>${this.chk(d.ce_num)}</span></div>
              <div><label>Cheques</label><span>${this.chk(d.ce_chq)}</span></div>
              <div><label>Constancia</label><span>${this.chk(d.ce_cons)}</span></div>
            </div></div></div></div>`;

        if (!d.cod) return h;   // estado vacío: solo el form (datos + comprobantes)

        if (d.modelos.length) {
            h += d.modelos.map(m => `<div class="card fc-card"><div class="card-header"><span><i class="bi bi-diagram-2 me-1"></i>Modelo: ${this.esc(m.den)}</span></div>
              <div class="card-body p-0"><table class="table table-sm op-grid mb-0">
                <thead><tr><th>#</th><th>Cuenta</th><th>Centro de Costo</th><th class="text-end">Debe %</th><th class="text-end">Haber %</th></tr></thead>
                <tbody>${m.imputaciones.map(i => `<tr><td>${i.ord}</td><td><span class="op-ccode">${this.esc(i.cuenta)}</span> ${this.esc(i.cuentaDen)}</td><td>${this.esc(i.centro)}</td><td class="text-end">${this.esc(i.debe)}</td><td class="text-end">${this.esc(i.haber)}</td></tr>`).join('')}</tbody>
              </table></div></div>`).join('');
        } else {
            h += '<div class="card fc-card"><div class="card-body text-muted small">Sin modelos de imputación configurados.</div></div>';
        }

        if (d.auxiliares.length) {
            h += `<div class="card fc-card"><div class="card-header"><span><i class="bi bi-list-ul me-1"></i>Auxiliares</span></div>
              <div class="card-body p-0"><table class="table table-sm op-grid mb-0">
                <thead><tr><th>Código</th><th>Denominación</th><th>IVA</th><th>Cuenta</th></tr></thead>
                <tbody>${d.auxiliares.map(a => `<tr><td>${this.esc(a.cod)}</td><td>${this.esc(a.den)}</td><td>${this.chk(a.iva)}</td><td>${this.esc(a.cuenta)}</td></tr>`).join('')}</tbody>
              </table></div></div>`;
        }
        return h;
    },

    async api(action, params = {}) {
        const url = new URL('api.php', window.location.href);
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
        return await (await fetch(url)).json();
    },
};
document.addEventListener('DOMContentLoaded', () => OP.init());
