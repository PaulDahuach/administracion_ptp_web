/* Notas de Crédito — emisión con CAE de AFIP. Cliente → concepto → neto → referencias a FV → emitir.
   El concepto define si lleva IVA. Las referencias aplican el crédito a vencimientos de FV pendientes. */
const NC = {
    cli: null, conceptos: {}, refs: [], prods: [], emitida: false,

    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    n(v) { var x = parseFloat(v); return isNaN(x) ? '0.00' : x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        return await (await fetch(url, opts || {})).json();
    },

    async init() {
        this.el('fexmov').value = new Date().toISOString().slice(0, 10);
        var cc = await this.api('conceptos');
        if (cc.ok) {
            this.el('codaux').innerHTML = cc.data.map(function (o) { NC.conceptos[o.CODAUX] = o; return '<option value="' + o.CODAUX + '">' + NC.esc(o.DENAUX) + (o.IVA ? '' : ' (no gravado)') + '</option>'; }).join('');
            this.onConcepto();
        }
        this.autocomplete(this.el('cliQ'), this.el('cliList'), 'buscar_clientes', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { NC.pickCliente(o.CODCUE); });
        this.el('codaux').addEventListener('change', function () { NC.onConcepto(); });
        this.el('netmov').addEventListener('input', function () { NC.recalc(); });
        this.el('btnAddRef').addEventListener('click', function () { NC.abrirReferencias(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.el('btnEmitir').addEventListener('click', function () { NC.emitir(); });
    },

    concepto() { return this.conceptos[this.el('codaux').value] || { IVA: true }; },
    esDevolucion() { return this.el('codaux').value == '462'; },
    onConcepto() {
        var iva = !!this.concepto().IVA;
        var dev = this.esDevolucion();
        this.el('boxIva').style.display = iva ? '' : 'none';
        this.el('lblNeto').textContent = dev ? '(de productos)' : (iva ? 'gravado' : '(no gravado)');
        this.el('cardProd').style.display = dev ? '' : 'none';
        this.el('netmov').readOnly = dev;                  // en devolución el neto sale de los productos
        if (!dev) { this.prods = []; this.el('prodBody').innerHTML = ''; }
        this.recalc();
    },

    async pickCliente(codcue) {
        var j = await this.api('get_cliente', { codcue: codcue });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var d = j.data; this.cli = d;
        this.el('codcue').value = codcue; this.el('cliQ').value = d.DENCUE;
        this.el('saldo').value = this.n(d.SALDO);
        this.el('letra').value = d.LETRA || 'A';
        this.el('cliInfo').textContent = [d.CITCUE, d.DENCRI, d.DOMICILIO, d.LOCALIDAD].filter(Boolean).join(' · ');
        this.el('btnAddRef').disabled = false;
        this.refs = []; this.el('refBody').innerHTML = ''; this.prods = []; this.el('prodBody').innerHTML = ''; this.el('prodTotal').textContent = '0.00'; this.recalc();
    },

    recalc() {
        var neto;
        if (this.esDevolucion()) { neto = Math.round((this.prods || []).reduce(function (s, p) { return s + (p.devolver || 0) * (p.pun || 0); }, 0) * 100) / 100; this.el('netmov').value = neto; }
        else neto = Math.round((parseFloat(this.el('netmov').value) || 0) * 100) / 100;
        var iva = this.concepto().IVA ? Math.round(neto * 21) / 100 : 0;
        var total = Math.round((neto + iva) * 100) / 100;
        this.totales = { neto: neto, iva: iva, total: total };
        this.el('tNeto').textContent = this.n(neto);
        this.el('tIva').textContent = this.n(iva);
        this.el('tTotal').textContent = this.n(total);
    },

    // ---- Referencias ----
    async abrirReferencias() {
        var j = await this.api('pendientes', { codcue: this.el('codcue').value });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        var used = {}; this.refs.forEach(function (r) { used[r.refmov + '|' + r.fvxmov] = 1; });
        var pend = j.data.filter(function (p) { return !used[p.REFMOV + '|' + p.FVXISO]; });
        this.el('pendVacio').style.display = pend.length ? 'none' : '';
        this._pend = pend;
        this.el('pendBody').innerHTML = pend.map(function (p, k) {
            return '<tr><td>' + NC.esc(p.COMP) + '</td><td>' + NC.esc(p.FEXMOV) + '</td><td>' + NC.esc(p.FVXMOV) + '</td><td>' + NC.esc(p.DETMOV) + '</td><td class="nc-num">' + NC.n(p.SALDO) + '</td>' +
                '<td><button class="btn btn-sm btn-outline-primary pend-add" data-k="' + k + '">+</button></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('pendBody').querySelectorAll('.pend-add'), function (b) { b.addEventListener('click', function () { NC.addRef(NC._pend[+this.getAttribute('data-k')]); this.closest('tr').remove(); }); });
        bootstrap.Modal.getOrCreateInstance(this.el('modalRef')).show();
    },
    async addRef(p) {
        var dev = this.esDevolucion();
        var rec = { refmov: p.REFMOV, fvxmov: p.FVXISO, comp: p.COMP, venc: p.FVXMOV, det: p.DETMOV, saldo: p.SALDO, imp: 0 };
        if (!dev) {
            var asignado = this.refs.reduce(function (s, r) { return s + r.imp; }, 0);
            var resto = Math.max(0, Math.round(((this.totales ? this.totales.total : 0) - asignado) * 100) / 100);
            rec.imp = Math.round(Math.min(p.SALDO, resto > 0 ? resto : p.SALDO) * 100) / 100;
        }
        this.refs.push(rec);
        var tr = document.createElement('tr');
        var impCell = dev ? '<td class="nc-num r-imp-ro">' + this.n(rec.imp) + '</td>'   // en devolución el monto sale de los productos
                          : '<td><input type="number" step="0.01" class="form-control form-control-sm nc-num r-imp" value="' + rec.imp + '"></td>';
        tr.innerHTML = '<td>' + this.esc(rec.comp) + '</td><td>' + this.esc(rec.venc) + '</td><td>' + this.esc(rec.det) + '</td><td class="nc-num">' + this.n(rec.saldo) + '</td>' + impCell +
            '<td><button type="button" class="btn btn-sm btn-outline-danger r-del"><i class="bi bi-x"></i></button></td>';
        this.el('refBody').appendChild(tr);
        rec._impCell = tr.querySelector('.r-imp-ro');
        var inp = tr.querySelector('.r-imp'); if (inp) inp.addEventListener('input', function () { rec.imp = parseFloat(this.value) || 0; NC.refRecalc(); });
        tr.querySelector('.r-del').addEventListener('click', function () { tr.remove(); NC.refs = NC.refs.filter(function (x) { return x !== rec; }); NC.prods = NC.prods.filter(function (x) { return x.nmdmov !== rec.refmov; }); NC.renderProds(); NC.prodRecalc(); });
        this.refRecalc();
        if (dev) {
            var j = await this.api('productos_fv', { nummov: p.REFMOV });
            if (j.ok) { j.data.forEach(function (pr) { pr.nmdmov = p.REFMOV; pr.devolver = pr.qty; NC.prods.push(pr); }); NC.renderProds(); NC.prodRecalc(); }
        }
    },
    refRecalc() { var t = this.refs.reduce(function (s, r) { return s + (r.imp || 0); }, 0); this.el('refTotal').textContent = this.n(Math.round(t * 100) / 100); },

    // ---- Productos devueltos (DEVOLUCION) ----
    renderProds() {
        this.el('prodBody').innerHTML = this.prods.map(function (p, k) {
            return '<tr><td>' + NC.esc(p.codpro) + '</td><td>' + NC.esc(p.denmov) + '</td><td>' + NC.esc(p.nmdmov) + '</td><td class="nc-num">' + NC.n(p.qty) + '</td>' +
                '<td><input type="number" step="0.01" class="form-control form-control-sm nc-num p-dev" data-k="' + k + '" value="' + p.devolver + '"></td>' +
                '<td class="nc-num">' + NC.n(p.pun) + '</td><td class="nc-num p-tot">' + NC.n(p.devolver * p.pun) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger p-del" data-k="' + k + '"><i class="bi bi-x"></i></button></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('prodBody').querySelectorAll('.p-dev'), function (inp) {
            inp.addEventListener('input', function () { var p = NC.prods[+this.getAttribute('data-k')]; p.devolver = parseFloat(this.value) || 0; this.closest('tr').querySelector('.p-tot').textContent = NC.n(p.devolver * p.pun); NC.prodRecalc(); });
        });
        Array.prototype.forEach.call(this.el('prodBody').querySelectorAll('.p-del'), function (b) {
            b.addEventListener('click', function () { NC.prods.splice(+this.getAttribute('data-k'), 1); NC.renderProds(); NC.prodRecalc(); });
        });
        this.el('prodTotal').textContent = this.n(Math.round(this.prods.reduce(function (s, p) { return s + p.devolver * p.pun; }, 0) * 100) / 100);
    },
    prodRecalc() {
        this.recalc();
        this.el('prodTotal').textContent = this.el('tNeto').textContent;
        // cada referencia acredita el bruto (neto×1.21) de los productos devueltos de SU FV
        this.refs.forEach(function (r) {
            var net = NC.prods.filter(function (p) { return p.nmdmov === r.refmov; }).reduce(function (s, p) { return s + (p.devolver || 0) * (p.pun || 0); }, 0);
            r.imp = Math.round(net * 1.21 * 100) / 100;
            if (r._impCell) r._impCell.textContent = NC.n(r.imp);
        });
        this.refRecalc();
    },

    async emitir() {
        this.el('ncErr').textContent = '';
        if (this.emitida) { this.toast('La NC ya fue emitida.', 'info'); return; }
        if (!this.el('codcue').value) { this.el('ncErr').textContent = 'Elegí un cliente.'; return; }
        if (this.totales.total <= 0) { this.el('ncErr').textContent = 'Ingresá el neto de la nota de crédito.'; return; }
        var refTot = Math.round(this.refs.reduce(function (s, r) { return s + (r.imp || 0); }, 0) * 100) / 100;
        if (refTot - this.totales.total > 0.05) { this.el('ncErr').textContent = 'Las referencias (' + this.n(refTot) + ') superan el total de la NC (' + this.n(this.totales.total) + ').'; return; }
        if (this.refs.some(function (r) { return r.imp > r.saldo + 0.05; })) { this.el('ncErr').textContent = 'Una referencia acredita más que el saldo del comprobante.'; return; }
        var c = this.cli, t = this.totales;
        var data = {
            codcue: this.el('codcue').value, fexmov: this.el('fexmov').value, ciimov: this.el('letra').value,
            codaux: this.el('codaux').value, codcdv: c.CODCDV || 2, detmov: this.el('detmov').value,
            citmov: c.CITCUE, dcxmov: c.DCXCUE, dnxmov: c.DNXCUE, codloc: c.CODLOC, codcri: c.CODCRI, cond_iva: c.COND_IVA,
            spimov: 0, mpimov: 0, pixmov: 0, ali: 21,
            netmov: t.neto, irimov: t.iva, totmov: t.total, soc: c.SALDO,
            refs: this.refs.map(function (r) { return { nummov: r.refmov, fvxmov: r.fvxmov, imp: r.imp }; })
        };
        if (this.esDevolucion()) {
            var pp = this.prods.filter(function (p) { return p.devolver > 0; });
            if (!pp.length) { this.el('ncErr').textContent = 'Agregá una FV y poné la cantidad a devolver.'; return; }
            if (this.prods.some(function (p) { return p.devolver > p.qty + 0.0001; })) { this.el('ncErr').textContent = 'No podés devolver más de lo facturado en un producto.'; return; }
            data.productos = pp.map(function (p) { return { codpro: p.codpro, denmov: p.denmov, qty: p.devolver, reingresa: p.reingresa, stkmov: p.stkmov, nmdmov: p.nmdmov, omdmov: p.omdmov, punmov: p.pun, pucmov: p.puc, cosmov: p.cos, fctmov: p.fctmov, dummov: p.dummov, codudm: p.codudm, codmon: p.codmon, codsuc: p.codsuc, decmov: p.decmov, cic: p.cic, ndb: Math.round(p.devolver * p.pun * 100) / 100 }; });
        }
        this.el('btnEmitir').disabled = true; this.el('btnEmitir').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Autorizando…';
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(data));
        var j = await this.api('guardar', {}, { method: 'POST', body: fd });
        this.el('btnEmitir').innerHTML = '<i class="bi bi-cloud-arrow-up me-1"></i>Emitir NC (AFIP)';
        if (!j.ok) { this.el('btnEmitir').disabled = false; this.el('ncErr').textContent = j.error; return; }
        this.emitida = true;
        this.el('nummov').value = String(j.data.nummov).padStart(8, '0');
        this.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
        if (j.data.cae) { this.el('caeDisp').textContent = j.data.cae; this.el('caeVto').textContent = j.data.cae_vto; this.el('caeWrap').style.display = ''; }
        Array.prototype.forEach.call(document.querySelectorAll('#ncForm input, #ncForm select, .r-imp, .r-del, #btnAddRef'), function (el) { el.disabled = true; });
        this.el('btnImprimirHdr').style.display = ''; this.el('btnImprimirHdr').onclick = function () { window.open('imprimir.php?nummov=' + j.data.nummov, '_blank'); };
        var nro = this.el('letra').value + ' ' + String(j.data.cinmov).padStart(8, '0');
        this.toast(j.data.cae ? ('NC ' + nro + ' autorizada · CAE ' + j.data.cae) : ('NC ' + nro + ' grabada (capacitación · sin CAE)'), 'success');
    },

    autocomplete(input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() { list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + NC.esc(label(o)) + '</div>'; }).join(''); list.classList.toggle('show', items.length > 0); }
        input.addEventListener('input', function () { clearTimeout(t); var q = input.value.trim(); if (q.length < 1) { items = []; render(); return; } t = setTimeout(async function () { var j = await NC.api(action, { q: q }); items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }, 180); });
        input.addEventListener('keydown', function (e) { if (!list.classList.contains('show')) return; if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); } else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); } else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); onPick(items[hi]); list.classList.remove('show'); } } else if (e.key === 'Escape') list.classList.remove('show'); });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },
    toast(msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 7000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { NC.init(); });
