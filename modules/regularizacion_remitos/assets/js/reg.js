/**
 * Regularización de Remitos Pendientes (Deudores) — frontend.
 * Elegí cliente → grilla de remitos pendientes → tildá → Regularizar (SRPMOV=False).
 */
const App = {
    API: 'api.php',

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    pad(v, n) { return String(parseInt(v, 10) || 0).padStart(n, '0'); },

    async api(action, params = {}, post = false) {
        const url = new URL(this.API, location.href);
        url.searchParams.set('action', action);
        let opt;
        if (post) {
            const fd = new FormData();
            for (const k in params) {
                if (Array.isArray(params[k])) params[k].forEach(v => fd.append(k + '[]', v));
                else fd.append(k, params[k]);
            }
            opt = { method: 'POST', body: fd };
        } else {
            for (const k in params) url.searchParams.set(k, params[k]);
        }
        return await (await fetch(url, opt)).json();
    },

    toast(msg, ok = true) {
        const t = this.el('toastMsg');
        t.className = 'toast align-items-center border-0 text-bg-' + (ok ? 'success' : 'danger');
        this.el('toastBody').textContent = msg;
        new bootstrap.Toast(t, { delay: 3500 }).show();
    },

    init() {
        this.el('cboCuenta').addEventListener('change', () => this.loadRemitos());
        this.el('chkAll').addEventListener('change', e => {
            document.querySelectorAll('.rr-chk').forEach(c => c.checked = e.target.checked);
            this.updateBtn();
        });
        const b = this.el('btnReg');
        if (b) b.addEventListener('click', () => this.regularizar());
        this.loadCuentas();
    },

    async loadCuentas() {
        const r = await this.api('cuentas');
        const sel = this.el('cboCuenta');
        if (!r.ok) { sel.innerHTML = '<option value="">(error)</option>'; this.toast(r.error || 'Error', false); return; }
        if (!r.data.length) {
            sel.innerHTML = '<option value="">— No hay remitos pendientes —</option>';
            this.el('lblInfo').textContent = '';
            this.el('cardGrid').style.display = 'none';
            return;
        }
        sel.innerHTML = '<option value="">— Elegir cliente —</option>' +
            r.data.map(c => `<option value="${c.CODCUE}">${this.esc((c.DENCUE || '').trim())} (${c.N})</option>`).join('');
        this.el('lblInfo').textContent = r.data.length + ' cliente(s) con remitos pendientes';
    },

    async loadRemitos() {
        const cc = this.el('cboCuenta').value;
        const card = this.el('cardGrid'), body = this.el('rrBody');
        if (!cc) { card.style.display = 'none'; return; }
        const r = await this.api('remitos', { codcue: cc });
        if (!r.ok) { this.toast(r.error || 'Error', false); return; }
        body.innerHTML = r.data.map(m => `<tr>
            <td><input type="checkbox" class="rr-chk" value="${m.NUMMOV}"></td>
            <td class="r mono">${this.pad(m.NUMMOV, 8)}</td>
            <td>${this.esc(m.FECHA || '')}</td>
            <td class="mono">${this.pad(m.CIPMOV, 4)}</td>
            <td class="mono">${this.pad(m.CINMOV, 8)}</td>
            <td>${this.esc((m.DETMOV || '').trim())}</td></tr>`).join('');
        this.el('chkAll').checked = false;
        card.style.display = '';
        body.querySelectorAll('.rr-chk').forEach(c => c.addEventListener('change', () => this.updateBtn()));
        this.updateBtn();
    },

    updateBtn() {
        const n = document.querySelectorAll('.rr-chk:checked').length;
        const b = this.el('btnReg');
        if (b) b.disabled = (n === 0);
    },

    async regularizar() {
        const cc = this.el('cboCuenta').value;
        const ids = [...document.querySelectorAll('.rr-chk:checked')].map(c => c.value);
        if (!cc || !ids.length) return;
        if (!confirm(`¿Regularizar ${ids.length} remito(s)?\nDejarán de figurar como pendientes de facturación (SRPMOV=False).`)) return;
        const b = this.el('btnReg'); b.disabled = true;
        const r = await this.api('regularizar', { codcue: cc, nummov: ids }, true);
        if (r.ok) {
            this.toast(`${r.data.regularizados} remito(s) regularizado(s).`);
            this.el('cboCuenta').value = '';
            this.el('cardGrid').style.display = 'none';
            this.loadCuentas();
        } else {
            this.toast(r.error || 'Error al regularizar', false);
            b.disabled = false;
        }
    },
};
document.addEventListener('DOMContentLoaded', () => App.init());
