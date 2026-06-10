/* Conciliación Bancaria — marca movimientos como conciliados. */
const CO = {
    RO: window.CO_RO, CUENTAS: window.CO_CUENTAS || [], HASTA: window.CO_HASTA || '',
    saldoConcNum: 0,
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    fmt(n) { return (parseFloat(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

    init() {
        this.el('f_cuenta').innerHTML = '<option value="">—</option>' + this.CUENTAS.map(c => `<option value="${this.esc(c.id)}">${this.esc(c.den)}</option>`).join('');
        this.el('f_hasta').value = this.HASTA;
        this.el('btnVer').addEventListener('click', () => this.ver());
        this.el('f_cuenta').addEventListener('change', () => this.ver());
        if (!this.RO) this.el('btnConciliar').addEventListener('click', () => this.conciliar());
        this.el('chkAll').addEventListener('change', () => {
            this.el('tbMov').querySelectorAll('.chk').forEach(c => { c.checked = this.el('chkAll').checked; });
            this.recalc();
        });
        this.el('tbMov').addEventListener('change', e => { if (e.target.classList.contains('chk')) this.recalc(); });
    },

    async ver() {
        this.el('formErr').textContent = '';
        const cuenta = this.el('f_cuenta').value, hasta = this.el('f_hasta').value, desde = this.el('f_desde').value;
        if (!cuenta || !hasta) { return; }
        const url = new URL('api.php', location.href);
        url.searchParams.set('action', 'listar'); url.searchParams.set('cuenta', cuenta); url.searchParams.set('hasta', hasta);
        if (desde) url.searchParams.set('desde', desde);
        const j = await (await fetch(url)).json();
        if (!j.ok) { this.el('formErr').textContent = j.error || 'Error'; this.toast(j.error || 'Error', 'danger'); return; }
        const d = j.data;
        this.saldoConcNum = parseFloat((d.saldoConc || '0').replace(/,/g, '')) || 0;
        this.el('saldos').style.display = '';
        this.el('sOper').textContent = d.saldoOper; this.el('sConc').textContent = d.saldoConc;
        this.el('sUlt').textContent = d.ultima || '—';
        this.el('totNote').textContent = (d.total > d.movimientos.length)
            ? `(${d.total} pendientes — se muestran los ${d.top} más antiguos; acotá con "Desde")`
            : `(${d.total} pendiente${d.total === 1 ? '' : 's'})`;
        this.el('tbMov').innerHTML = d.movimientos.map(m => `<tr>
            <td><input type="checkbox" class="chk" data-num="${m.nummov}" data-ord="${m.ordmov}" data-debe="${m.debe}" data-haber="${m.haber}"></td>
            <td>${this.esc(m.fecha)}</td><td>${m.nummov}</td><td>${this.esc(m.comp)}</td>
            <td>${this.esc(m.detalle)}${m.cheque ? ' · <span class="text-muted">' + this.esc(m.cheque) + (m.librador ? ' (' + this.esc(m.librador) + ')' : '') + '</span>' : ''}</td>
            <td>${this.esc(m.facr)}</td>
            <td class="text-end">${m.debe ? this.fmt(m.debe) : ''}</td><td class="text-end">${m.haber ? this.fmt(m.haber) : ''}</td></tr>`).join('')
            || '<tr><td colspan="8" class="text-muted small p-2">Sin movimientos pendientes hasta esa fecha.</td></tr>';
        this.el('chkAll').checked = false;
        this.recalc();
    },

    recalc() {
        let n = 0, deb = 0, hab = 0;
        this.el('tbMov').querySelectorAll('.chk:checked').forEach(c => {
            n++; deb += parseFloat(c.dataset.debe) || 0; hab += parseFloat(c.dataset.haber) || 0;
        });
        const neto = deb - hab, post = this.saldoConcNum + neto;
        this.el('proc').innerHTML = n ? `<span class="badge bg-secondary">${n} mov.</span> Débitos <b>${this.fmt(deb)}</b> · Créditos <b>${this.fmt(hab)}</b> · Saldo <b>${this.fmt(neto)}</b>` : '';
        this.el('sPost').textContent = this.fmt(post);
        if (!this.RO) this.el('btnConciliar').disabled = (n === 0);
    },

    async conciliar() {
        const items = [];
        this.el('tbMov').querySelectorAll('.chk:checked').forEach(c => items.push({ nummov: c.dataset.num, ordmov: c.dataset.ord }));
        if (!items.length) return;
        if (!await this.confirm('Vas a conciliar ' + items.length + ' movimiento(s). El saldo conciliado pasará a ' + this.el('sPost').textContent + '. ¿Confirmás?')) return;
        const fd = new FormData();
        fd.append('cuenta', this.el('f_cuenta').value); fd.append('hasta', this.el('f_hasta').value); fd.append('items', JSON.stringify(items));
        const j = await (await fetch('api.php?action=conciliar', { method: 'POST', body: fd })).json();
        if (!j.ok) { this.toast(j.error || 'No se pudo conciliar', 'danger'); return; }
        this.toast(j.data.conciliados + ' movimiento(s) conciliados', 'success');
        this.ver();
    },

    toast(msg, type) { const t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: type === 'danger' ? 7000 : 3000 }).show(); },
    confirm(message) { return new Promise(resolve => { const me = this.el('modalConfirm'); this.el('confirmBody').textContent = message; const modal = bootstrap.Modal.getOrCreateInstance(me); let done = false; const ok = this.el('btnConfirmOk'); const clean = () => { ok.removeEventListener('click', okH); me.removeEventListener('hidden.bs.modal', hidH); }; const okH = () => { if (done) return; done = true; clean(); modal.hide(); resolve(true); }; const hidH = () => { if (done) return; done = true; clean(); resolve(false); }; ok.addEventListener('click', okH); me.addEventListener('hidden.bs.modal', hidH); modal.show(); }); },
};
document.addEventListener('DOMContentLoaded', () => CO.init());
