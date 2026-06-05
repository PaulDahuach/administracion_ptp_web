/* Remitos deudores — carga (escritura). Autocomplete cliente/producto + grilla + grabar. */
const R = {
    modo: window.REM_MODO || 'operador',
    cli: null,             // datos del cliente elegido
    lines: [],             // {codpro,denmov,codudm,denudm,fctmov,dummov,codmon,cosmov,pulmov,stk,cant,punmov,odc,odp,pdl}
    seq: 0,

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    num(v) { var n = parseFloat(v); return isNaN(n) ? '0,00' : n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        var r = await fetch(url, opts || {});
        return await r.json();
    },

    async init() {
        var hoy = new Date().toISOString().slice(0, 10);
        this.el('fexmov').value = hoy; this.el('frvmov').value = hoy;
        // PDV: sólo en operador/integral; en capacitación se numera en 9999 (server) → ocultar combo
        if (this.modo === 'capacitacion') { this.el('boxPdv').style.display = 'none'; }
        else {
            var j = await this.api('pdvs');
            if (j.ok) this.el('cipmov').innerHTML = j.data.map(function (p) {
                return '<option value="' + p.CODPDV + '">' + (p.NOMPDV ? R.esc(p.NOMPDV) + ' (' + p.CODPDV + ')' : p.CODPDV) + '</option>';
            }).join('');
        }
        this.autocomplete(this.el('cliQ'), this.el('cliList'), 'buscar_clientes', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { R.pickCliente(o.CODCUE); });
        this.el('btnAddLn').addEventListener('click', function () { R.addLine(); });
        this.el('btnNuevo').addEventListener('click', function () { R.reset(); });
        this.el('btnGuardar').addEventListener('click', function () { R.guardar(); });
        this.addLine();
    },

    reset() {
        this.cli = null; this.lines = [];
        ['cliQ', 'codcue', 'cotmov', 'detmov'].forEach(function (id) { R.el(id).value = ''; });
        this.el('pdcmov').value = ''; this.el('saldo').value = ''; this.el('vdxmov').value = '0';
        this.el('cliInfo').textContent = ''; this.el('remErr').textContent = '';
        this.el('lnBody').innerHTML = ''; this.el('grTotal').textContent = '0,00';
        this.addLine();
        this.el('cliQ').focus();
    },

    async pickCliente(codcue) {
        var j = await this.api('get_cliente', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.cli = j.data;
        this.el('codcue').value = codcue;
        this.el('cliQ').value = j.data.DENCUE;
        this.el('pdcmov').value = this.num(j.data.LDPCAT);
        this.el('saldo').value = this.num(j.data.SALDO);
        this.el('cliInfo').textContent = (j.data.CITCUE || '') + ' · ' + (j.data.DOMICILIO || '') + ' · ' + (j.data.LOCALIDAD || '');
    },

    addLine() {
        var i = this.seq++;
        var tr = document.createElement('tr'); tr.dataset.i = i;
        tr.innerHTML =
            '<td class="ac-box"><input class="form-control form-control-sm l-cod" data-nocombo autocomplete="off" placeholder="Código"><div class="ac-list"></div></td>' +
            '<td class="l-den text-muted small"></td>' +
            '<td class="l-udm text-muted small"></td>' +
            '<td><input class="form-control form-control-sm l-odc" inputmode="numeric"></td>' +
            '<td><input class="form-control form-control-sm l-odp" inputmode="numeric"></td>' +
            '<td><input class="form-control form-control-sm l-pdl" inputmode="numeric"></td>' +
            '<td><input class="form-control form-control-sm rem-num l-cant" inputmode="decimal"></td>' +
            '<td><input class="form-control form-control-sm rem-num l-pun" inputmode="decimal"></td>' +
            '<td class="rem-num l-tot">0,00</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger l-del"><i class="bi bi-x"></i></button></td>';
        this.el('lnBody').appendChild(tr);
        var rec = { i: i, codpro: null };
        this.lines.push(rec);
        var cod = tr.querySelector('.l-cod'), list = tr.querySelector('.ac-list');
        this.autocomplete(cod, list, 'buscar_productos', function (o) { return o.CODPRO + ' · ' + o.DENPRO; }, function (o) { R.pickProducto(tr, o.CODPRO); });
        ['.l-cant', '.l-pun', '.l-odc', '.l-odp', '.l-pdl'].forEach(function (s) {
            tr.querySelector(s).addEventListener('input', function () { R.recalc(tr); });
        });
        tr.querySelector('.l-del').addEventListener('click', function () { tr.remove(); R.lines = R.lines.filter(function (x) { return x.i !== i; }); R.recalcAll(); });
        cod.focus();
    },

    async pickProducto(tr, codpro) {
        var j = await this.api('get_producto', { codpro: codpro, codsuc: this.el('coddst').value || 1 });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data, rec = this.lines.find(function (x) { return x.i == tr.dataset.i; });
        rec.codpro = d.CODPRO; rec.denmov = d.DENPRO; rec.codudm = d.CODUDM; rec.denudm = d.DENUDM;
        rec.fctmov = d.FCTPUM; rec.dummov = d.DECUDM; rec.codmon = d.CODMON; rec.cosmov = d.COSPRO; rec.pulmov = d.COSPRO; rec.stk = d.STK;
        tr.querySelector('.l-cod').value = d.CODPRO;
        tr.querySelector('.l-den').textContent = d.DENPRO + (d.STK && d.EXISTENCIA !== null ? ' · stock: ' + this.num(d.EXISTENCIA) : '');
        tr.querySelector('.l-udm').textContent = d.DENUDM;
        tr.querySelector('.l-cant').focus();
    },

    recalc(tr) {
        var rec = this.lines.find(function (x) { return x.i == tr.dataset.i; });
        rec.cant = parseFloat(tr.querySelector('.l-cant').value) || 0;
        rec.punmov = parseFloat(tr.querySelector('.l-pun').value) || 0;
        rec.odc = tr.querySelector('.l-odc').value; rec.odp = tr.querySelector('.l-odp').value; rec.pdl = tr.querySelector('.l-pdl').value;
        tr.querySelector('.l-tot').textContent = this.num(rec.cant * rec.punmov);
        this.recalcAll();
    },
    recalcAll() {
        var t = 0; this.lines.forEach(function (r) { t += (r.cant || 0) * (r.punmov || 0); });
        this.el('grTotal').textContent = this.num(t);
    },

    async guardar() {
        this.el('remErr').textContent = '';
        var codcue = this.el('codcue').value;
        if (!codcue) { this.el('remErr').textContent = 'Elegí un cliente.'; return; }
        var ls = this.lines.filter(function (r) { return r.codpro && (r.cant || 0) > 0; });
        if (!ls.length) { this.el('remErr').textContent = 'Agregá al menos un producto con cantidad.'; return; }
        var data = {
            codcue: codcue, fexmov: this.el('fexmov').value, frvmov: this.el('frvmov').value,
            coddst: this.el('coddst').value || 1, cotmov: this.el('cotmov').value, detmov: this.el('detmov').value,
            vdxmov: this.el('vdxmov').value || 0,
            cipmov: (this.modo === 'capacitacion') ? null : this.el('cipmov').value,
            lineas: ls.map(function (r) {
                return {
                    codpro: r.codpro, denmov: r.denmov, codudm: r.codudm, fctmov: r.fctmov, dummov: r.dummov,
                    codmon: r.codmon, cosmov: r.cosmov, punmov: r.punmov, pucmov: r.punmov, pulmov: r.pulmov,
                    stk: r.stk, cant: r.cant, odcmov: r.odc, odpmov: r.odp, pdlmov: r.pdl
                };
            })
        };
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        this.el('btnGuardar').disabled = true;
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        this.el('btnGuardar').disabled = false;
        if (!j.ok) { this.el('remErr').textContent = j.error; return; }
        var pdv = j.data.cipmov ? String(j.data.cipmov).padStart(4, '0') : '9999';
        var nro = String(j.data.cinmov).padStart(8, '0');
        this.toast('Remito grabado: ' + pdv + '-' + nro + ' (mov ' + j.data.nummov + '). Listo para el próximo.', 'success');
        this.reset();   // form limpio para el siguiente remito (impresión: próximamente)
    },

    // --- autocomplete genérico (servidor como fuente de verdad) ---
    autocomplete(input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() {
            list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + R.esc(label(o)) + '</div>'; }).join('');
            list.classList.toggle('show', items.length > 0);
        }
        input.addEventListener('input', function () {
            clearTimeout(t); var q = input.value.trim();
            if (q.length < 1) { items = []; render(); return; }
            t = setTimeout(async function () { var j = await R.api(action, { q: q }); items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }, 180);
        });
        input.addEventListener('keydown', function (e) {
            if (!list.classList.contains('show')) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); }
            else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); e.stopPropagation(); onPick(items[hi]); list.classList.remove('show'); } }
            else if (e.key === 'Escape') { list.classList.remove('show'); }
        });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },

    toast(msg, type) {
        var t = this.el('toastMsg'); this.el('toastBody').textContent = msg;
        t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info');
        bootstrap.Toast.getOrCreateInstance(t, { delay: 6000 }).show();
    },
};
document.addEventListener('DOMContentLoaded', function () { R.init(); });
