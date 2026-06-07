/* Remitos Acreedores — form: cabecera + subform (Producto · Unidad · Stock Remitido · Cantidad) + búsqueda + carga readonly. */
var RA = {
    lineas: [], prodSel: null, readonly: false, anulNum: null, _bqInit: false,

    el: function (id) { return document.getElementById(id); },
    esc: function (s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; },
    n: function (v) { var x = parseFloat(v) || 0; return x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 }); },
    r4: function (v) { return Math.round((parseFloat(v) || 0) * 10000) / 10000; },

    api: function (action, params, opts) {
        var qs = new URLSearchParams(params || {}).toString();
        return fetch('api.php?action=' + action + (qs ? '&' + qs : ''), opts || {}).then(function (r) { return r.json(); });
    },

    init: function () {
        var today = new Date().toISOString().slice(0, 10);
        this.el('fexmov').value = today; this.el('cef').value = today;
        this.autocomplete(this.el('provQ'), this.el('provList'), 'buscar_proveedores', function (o) { return o.CODCUE + ' · ' + o.DENCUE + (o.CITCUE ? ' · ' + o.CITCUE : ''); }, function (o) { RA.pickProv(o); });
        this.autocomplete(this.el('prodQ'), this.el('prodList'), 'productos', function (o) { return o.CODPRO + ' · ' + o.DENPRO; }, function (o) { RA.pickProd(o); });
        this.el('prodUdm').addEventListener('change', function () { RA.unidadChange(); });
        this.el('btnAddProd').addEventListener('click', function () { RA.addLinea(); });
        this.el('prodCant').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); RA.addLinea(); } });
        this.el('btnGrabar').addEventListener('click', function () { RA.grabar(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (RA.anulNum) RA.anular(RA.anulNum); });
        this.el('btnBuscar').addEventListener('click', function () { RA.openBuscar(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
    },

    pickProv: function (o) {
        this.el('codcue').value = o.CODCUE; this.el('provQ').value = o.CODCUE + ' · ' + (o.DENCUE || '').trim();
        this.el('provInfo').textContent = [o.CITCUE].filter(Boolean).join(' · ');
    },

    pickProd: function (o) {
        this.prodSel = { codpro: o.CODPRO, denpro: (o.DENPRO || '').trim(), rmcstk: parseFloat(o.RMCSTK) || 0, unidades: [] };
        this.el('prodCod').value = o.CODPRO; this.el('prodQ').value = o.CODPRO + ' · ' + this.prodSel.denpro;
        var def = o.CODUDM;
        this.api('unidades', { codpro: o.CODPRO }).then(function (j) {
            var us = (j.ok && j.data) ? j.data : [];
            RA.prodSel.unidades = us;
            RA.el('prodUdm').innerHTML = us.map(function (u) { return '<option value="' + u.CODUDM + '" data-fct="' + u.FCTPUM + '" data-dum="' + (u.DECUDM || 0) + '" data-nom="' + RA.esc(u.DENUDM) + '">' + RA.esc(u.DENUDM) + '</option>'; }).join('');
            if (def) RA.el('prodUdm').value = def;
            RA.unidadChange();
            RA.el('prodCant').focus();
        });
    },

    unidadChange: function () {
        var opt = this.el('prodUdm').selectedOptions[0];
        var fct = opt ? (parseFloat(opt.getAttribute('data-fct')) || 1) : 1;
        var rmc = this.prodSel ? this.prodSel.rmcstk : 0;
        this.el('prodExi').value = fct ? this.n(rmc / fct) : '0';
    },

    addLinea: function () {
        if (!this.prodSel) { this.toast('Elegí un producto.', 'warning'); return; }
        var cant = this.r4(this.el('prodCant').value);
        if (cant <= 0) { this.toast('Poné la cantidad.', 'warning'); return; }
        var opt = this.el('prodUdm').selectedOptions[0];
        this.lineas.push({
            codpro: this.prodSel.codpro, denmov: this.prodSel.denpro,
            codudm: parseInt(this.el('prodUdm').value, 10) || 1,
            unidad: opt ? opt.getAttribute('data-nom') : '',
            fctmov: opt ? (parseFloat(opt.getAttribute('data-fct')) || 1) : 1,
            dummov: opt ? (parseInt(opt.getAttribute('data-dum'), 10) || 2) : 2,
            eximov: this.r4(this.el('prodExi').value.replace(/,/g, '')),
            cant: cant
        });
        this.prodSel = null; this.el('prodCod').value = ''; this.el('prodQ').value = '';
        this.el('prodUdm').innerHTML = ''; this.el('prodExi').value = ''; this.el('prodCant').value = '';
        this.renderLineas(); this.el('prodQ').focus();
    },

    renderLineas: function () {
        this.el('prodBody').innerHTML = this.lineas.map(function (l, k) {
            return '<tr><td>' + RA.esc(l.codpro + ' · ' + l.denmov) + '</td><td>' + RA.esc(l.unidad || '') + '</td>' +
                '<td class="ra-num">' + RA.n(l.eximov) + '</td><td class="ra-num">' + RA.n(l.cant) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger l-del" data-k="' + k + '"><i class="bi bi-x"></i></button></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('prodBody').querySelectorAll('.l-del'), function (b) { b.addEventListener('click', function () { RA.lineas.splice(+this.getAttribute('data-k'), 1); RA.renderLineas(); }); });
        this.el('prodCount').textContent = this.lineas.length;
        this.el('prodTot').textContent = this.n(this.lineas.reduce(function (s, l) { return s + l.cant; }, 0));
    },

    grabar: function () {
        if (this.readonly) return;
        var self = this; this.el('raErr').textContent = '';
        if (!this.el('codcue').value) { this.toast('Elegí el proveedor.', 'warning'); return; }
        if (!this.lineas.length) { this.toast('Agregá al menos un producto.', 'warning'); return; }
        var payload = {
            codcue: this.el('codcue').value, fexmov: this.el('fexmov').value,
            cep: this.el('cep').value, cen: this.el('cen').value, cef: this.el('cef').value,
            lineas: this.lineas
        };
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(payload));
        this.api('guardar', {}, { method: 'POST', body: fd }).then(function (j) {
            if (!j.ok) { self.el('raErr').textContent = j.error; self.toast(j.error, 'danger'); return; }
            self.el('nummov').value = String(j.data.nummov).padStart(8, '0');
            self.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
            self.el('cipmov').value = String(j.data.cipmov == null ? 0 : j.data.cipmov).padStart(4, '0');
            self.toast('Remito ' + j.data.nummov + ' grabado.', 'success');
            self.lockForm(j.data.nummov, true, false, false);
        });
    },

    anular: function (num) {
        if (!confirm('¿Anular este remito?\nSe descompromete el stock (RMCSTK). No se puede deshacer.')) return;
        var self = this;
        var fd = new FormData(); fd.append('action', 'anular'); fd.append('nummov', num);
        this.api('anular', {}, { method: 'POST', body: fd }).then(function (j) {
            if (!j.ok) { self.toast(j.error, 'danger'); return; }
            self.el('btnAnularHdr').style.display = 'none';
            self.toast('Remito ' + num + ' anulado.', 'success');
            self.el('roBanner').innerHTML += ' · <span class="badge bg-danger">ANULADO</span>';
        });
    },

    // ---- Buscar / ver ----
    openBuscar: function () {
        if (!this._bqInit) { this._bqInit = true; this.el('btnBQ').addEventListener('click', function () { RA.buscar(); }); this.el('bqQ').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); RA.buscar(); } }); }
        bootstrap.Modal.getOrCreateInstance(this.el('modalBuscar')).show();
        this.buscar();
    },
    buscar: function () {
        var self = this;
        this.api('listar', { q: this.el('bqQ').value, desde: this.el('bqDesde').value, hasta: this.el('bqHasta').value }).then(function (j) {
            var rows = (j.ok && j.data) ? j.data : [];
            self.el('bqBody').innerHTML = rows.length ? rows.map(function (r) {
                return '<tr class="bq-row" data-num="' + r.NUMMOV + '" style="cursor:pointer"><td>' + RA.esc(r.NUMERO) + (r.ANULADO ? ' <span class="badge bg-danger">ANULADO</span>' : '') + '</td><td>' + RA.esc(r.FECHA) + '</td><td>' + RA.esc(r.PROVEEDOR) + '</td><td class="small">' + RA.esc(r.COMP) + '</td></tr>';
            }).join('') : '<tr><td colspan="4" class="text-muted py-3">Sin resultados.</td></tr>';
            Array.prototype.forEach.call(self.el('bqBody').querySelectorAll('.bq-row'), function (tr) { tr.addEventListener('click', function () { RA.cargarRA(+this.getAttribute('data-num')); }); });
        });
    },
    cargarRA: function (num) {
        var self = this;
        this.api('detalle', { nummov: num }).then(function (j) {
            if (!j.ok) { self.toast(j.error, 'danger'); return; }
            var d = j.data;
            var bm = bootstrap.Modal.getInstance(self.el('modalBuscar')); if (bm) bm.hide();
            self.el('codcue').value = d.CODCUE; self.el('provQ').value = d.PROVEEDOR; self.el('provInfo').textContent = d.CUIT;
            self.el('nummov').value = d.NUMERO; self.el('cinmov').value = d.NUMERO; self.el('cipmov').value = d.CIPMOV;
            self.el('fexmov').value = d.FEXISO;
            self.el('cep').value = d.CEP; self.el('cen').value = d.CEN; self.el('cef').value = d.CEFISO;
            self.lineas = (d.lineas || []).map(function (l) { return { codpro: l.codpro, denmov: l.denmov, codudm: l.codudm, unidad: l.unidad, fctmov: l.fctmov, dummov: l.dummov, eximov: l.eximov, cant: l.cant }; });
            self.renderLineas();
            self.lockForm(num, d.ANULABLE, d.ANULADO, d.FACTURADO);
        });
    },
    lockForm: function (num, anulable, anulado, facturado) {
        Array.prototype.forEach.call(document.querySelectorAll('#raForm input, #raForm select, #raForm textarea'), function (el) { el.disabled = true; });
        Array.prototype.forEach.call(document.querySelectorAll('#raForm button'), function (el) { el.style.display = 'none'; });
        this.el('btnGrabar').style.display = 'none';
        var b = this.el('roBanner'); b.style.display = '';
        b.innerHTML = '<i class="bi bi-eye me-1"></i>Remito Acreedor <b>Nº ' + this.esc(num) + '</b> — modo <b>sólo lectura</b>' +
            (anulado ? ' · <span class="badge bg-danger">ANULADO</span>' : '') +
            (facturado ? ' · <span class="badge bg-success">FACTURADO por un CP</span>' : '') +
            ' · <a href="#" id="roNuevo">Cargar otro / Nuevo</a>';
        var rn = this.el('roNuevo'); if (rn) rn.addEventListener('click', function (e) { e.preventDefault(); location.reload(); });
        if (anulable && !anulado) { this.anulNum = num; this.el('btnAnularHdr').style.display = ''; }
        this.readonly = true;
    },

    autocomplete: function (input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() { list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + RA.esc(label(o)) + '</div>'; }).join(''); list.classList.toggle('show', items.length > 0); }
        input.addEventListener('input', function () { clearTimeout(t); var q = input.value.trim(); if (q.length < 1) { items = []; render(); return; } t = setTimeout(function () { RA.api(action, { q: q }).then(function (j) { items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }); }, 180); });
        input.addEventListener('keydown', function (e) { if (!list.classList.contains('show')) return; if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); } else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); } else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); onPick(items[hi]); list.classList.remove('show'); } } else if (e.key === 'Escape') list.classList.remove('show'); });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },
    toast: function (msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 6000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { RA.init(); });
