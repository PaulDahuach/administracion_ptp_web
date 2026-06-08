/* Asientos contables manuales (Imputaciones Contables) — form: operación + grilla de imputaciones con control de cuadre. */
var AS = {
    lineas: [], cuentaSel: null, readonly: false, anulNum: null, _bqInit: false,

    el: function (id) { return document.getElementById(id); },
    esc: function (s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; },
    n: function (v) { var x = parseFloat(v) || 0; return x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    r2: function (v) { return Math.round((parseFloat(v) || 0) * 100) / 100; },

    api: function (action, params, opts) {
        var qs = new URLSearchParams(params || {}).toString();
        return fetch('api.php?action=' + action + (qs ? '&' + qs : ''), opts || {}).then(function (r) { return r.json(); });
    },

    init: function () {
        this.el('fexmov').value = new Date().toISOString().slice(0, 10);
        this.autocomplete(this.el('asCtaQ'), this.el('asCtaList'), 'cuentas', function (o) { return o.CODCUE + ' · ' + o.DENCUE; }, function (o) { AS.pickCuenta(o); });
        this.el('btnAddImp').addEventListener('click', function () { AS.addImp(); });
        this.el('asHaber').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); AS.addImp(); } });
        this.el('asDebe').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); AS.el('asHaber').focus(); } });
        this.el('btnGrabar').addEventListener('click', function () { AS.grabar(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (AS.anulNum) AS.anular(AS.anulNum); });
        this.el('btnBuscar').addEventListener('click', function () { AS.openBuscar(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.vadPref = this.el('asForm').getAttribute('data-vadpref') || '';
        this.el('chqSyn').addEventListener('change', function () { AS.chequeLookup(); });
        this.el('chqBan').addEventListener('change', function () { AS.chequeLookup(); });
        this.renderImps();
    },

    pickCuenta: function (o) {
        this.cuentaSel = { codcue: o.CODCUE, label: o.CODCUE + ' · ' + (o.DENCUE || '').trim() };
        this.el('asCta').value = o.CODCUE; this.el('asCtaQ').value = this.cuentaSel.label;
        var esChq = this.vadPref !== '' && String(o.CODCUE).indexOf(this.vadPref) === 0;
        this.el('chqRow').style.display = esChq ? 'flex' : 'none';
        if (esChq) { this.resetCheque(); this.el('chqBan').focus(); } else { this.el('asDebe').focus(); }
    },
    chequeLookup: function () {
        var ban = this.el('chqBan').value, syn = this.el('chqSyn').value.trim();
        var est = this.el('chqEstado');
        if (!ban || !syn) { est.style.display = 'none'; return; }
        this.api('cheque_lookup', { codban: ban, syn: syn }).then(function (j) {
            var ro = function (v) { ['chqFde', 'chqPlz', 'chqFda', 'chqLib', 'chqCit', 'chqLoc'].forEach(function (id) { AS.el(id).readOnly = v; }); };
            if (j.ok && j.data) {
                if (!j.data.enCartera) { AS.toast('Ese cheque ya no está en cartera (fue depositado).', 'warning'); est.textContent = 'No está en cartera'; est.className = 'badge bg-danger mt-3'; est.style.display = ''; return; }
                AS.el('chqFde').value = j.data.fde; AS.el('chqPlz').value = j.data.plz; AS.el('chqFda').value = j.data.fda;
                AS.el('chqLib').value = j.data.lib; AS.el('chqCit').value = j.data.cit; AS.el('chqLoc').value = j.data.loc;
                AS.el('asHaber').value = j.data.imp; AS.el('asDebe').value = '';
                ro(true);
                est.textContent = 'Depósito — en cartera ($' + AS.n(j.data.imp) + ')'; est.className = 'badge bg-success mt-3'; est.style.display = '';
            } else {
                ro(false);
                est.textContent = 'Alta — cheque nuevo (cargá los datos + el Debe)'; est.className = 'badge bg-info text-dark mt-3'; est.style.display = '';
            }
        });
    },
    resetCheque: function () {
        var today = new Date().toISOString().slice(0, 10);
        this.el('chqBan').value = ''; this.el('chqSyn').value = '';
        this.el('chqFde').value = today; this.el('chqPlz').value = 0; this.el('chqFda').value = today;
        this.el('chqLib').value = ''; this.el('chqCit').value = ''; this.el('chqLoc').value = '';
        ['chqFde', 'chqPlz', 'chqFda', 'chqLib', 'chqCit', 'chqLoc'].forEach(function (id) { AS.el(id).readOnly = false; });
        this.el('chqEstado').style.display = 'none';
    },

    addImp: function () {
        if (!this.cuentaSel) { this.toast('Elegí la cuenta contable.', 'warning'); return; }
        var deb = this.r2(this.el('asDebe').value), cre = this.r2(this.el('asHaber').value);
        if (deb <= 0 && cre <= 0) { this.toast('Poné un importe en Debe o Haber.', 'warning'); return; }
        if (deb > 0 && cre > 0) { this.toast('Una imputación va al Debe O al Haber, no a los dos.', 'warning'); return; }
        var esChq = this.el('chqRow').style.display !== 'none';
        var chq = null;
        if (esChq) {
            var ban = this.el('chqBan').value, syn = this.el('chqSyn').value.trim();
            if (!ban || !syn) { this.toast('Elegí el banco y el número del cheque.', 'warning'); return; }
            var bopt = this.el('chqBan').selectedOptions[0];
            chq = { codban: parseInt(ban, 10), syn: syn, fde: this.el('chqFde').value, plz: parseInt(this.el('chqPlz').value, 10) || 0, fda: this.el('chqFda').value, lib: this.el('chqLib').value, cit: this.el('chqCit').value, loc: this.el('chqLoc').value, disp: (bopt ? bopt.textContent : '') + ' Nº ' + syn };
        }
        var opt = this.el('asCdc').selectedOptions[0];
        this.lineas.push({
            codcue: this.cuentaSel.codcue, cuenta: this.cuentaSel.label,
            codcdc: parseInt(this.el('asCdc').value, 10) || 1, centro: opt ? opt.textContent : '',
            debe: deb, cre: cre, chq: chq, cheque: chq ? chq.disp : ''
        });
        this.cuentaSel = null; this.el('asCta').value = ''; this.el('asCtaQ').value = '';
        this.el('asDebe').value = ''; this.el('asHaber').value = '';
        if (esChq) { this.resetCheque(); this.el('chqRow').style.display = 'none'; }
        this.renderImps(); this.el('asCtaQ').focus();
    },

    renderImps: function () {
        this.el('impBody').innerHTML = this.lineas.map(function (l, k) {
            var chq = l.cheque ? '<div class="small text-info"><i class="bi bi-bank"></i> ' + AS.esc(l.cheque) + '</div>' : '';
            return '<tr><td>' + AS.esc(l.cuenta) + chq + '</td><td class="small">' + AS.esc(l.centro || '') + '</td>' +
                '<td class="as-num">' + (l.debe > 0 ? AS.n(l.debe) : '·') + '</td><td class="as-num">' + (l.cre > 0 ? AS.n(l.cre) : '·') + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger i-del" data-k="' + k + '"><i class="bi bi-x"></i></button></td></tr>';
        }).join('');
        Array.prototype.forEach.call(this.el('impBody').querySelectorAll('.i-del'), function (b) { b.addEventListener('click', function () { AS.lineas.splice(+this.getAttribute('data-k'), 1); AS.renderImps(); }); });
        var td = this.lineas.reduce(function (s, l) { return s + l.debe; }, 0);
        var tc = this.lineas.reduce(function (s, l) { return s + l.cre; }, 0);
        var dif = Math.round((td - tc) * 100) / 100;
        this.el('totDebe').textContent = this.n(td);
        this.el('totHaber').textContent = this.n(tc);
        this.el('totDif').textContent = this.n(dif);
        var ok = (dif === 0 && td > 0);
        this.el('balInd').innerHTML = this.lineas.length ? (ok ? '<span class="badge bg-success"><i class="bi bi-check-lg"></i> Cuadra</span>' : '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> No cuadra</span>') : '';
    },

    grabar: function () {
        if (this.readonly) return;
        var self = this; this.el('asErr').textContent = '';
        if (!this.el('codope').value) { this.toast('Elegí la operación.', 'warning'); return; }
        if (this.lineas.length < 2) { this.toast('El asiento necesita al menos 2 imputaciones.', 'warning'); return; }
        var payload = {
            codope: this.el('codope').value, fexmov: this.el('fexmov').value, detmov: this.el('detmov').value,
            lineas: this.lineas.map(function (l) { var o = { codcue: l.codcue, codcdc: l.codcdc, debe: l.debe, cre: l.cre }; if (l.chq) { o.codban = l.chq.codban; o.syn = l.chq.syn; o.fde = l.chq.fde; o.plz = l.chq.plz; o.fda = l.chq.fda; o.lib = l.chq.lib; o.cit = l.chq.cit; o.loc = l.chq.loc; } return o; })
        };
        var fd = new FormData(); fd.append('action', 'guardar'); fd.append('data', JSON.stringify(payload));
        this.api('guardar', {}, { method: 'POST', body: fd }).then(function (j) {
            if (!j.ok) { self.el('asErr').textContent = j.error; self.toast(j.error, 'danger'); return; }
            self.el('nummov').value = String(j.data.nummov).padStart(8, '0');
            self.el('cinmov').value = String(j.data.cinmov).padStart(8, '0');
            self.toast('Asiento ' + j.data.nummov + ' grabado.', 'success');
            self.lockForm(j.data.nummov, true, false);
        });
    },

    anular: function (num) {
        if (!confirm('¿Anular este asiento?\nSe revierten los saldos contables (DEBCUE/CRECUE) de cada cuenta. No se puede deshacer.')) return;
        var self = this;
        var fd = new FormData(); fd.append('action', 'anular'); fd.append('nummov', num);
        this.api('anular', {}, { method: 'POST', body: fd }).then(function (j) {
            if (!j.ok) { self.toast(j.error, 'danger'); return; }
            self.el('btnAnularHdr').style.display = 'none';
            self.toast('Asiento ' + num + ' anulado.', 'success');
            self.el('roBanner').innerHTML += ' · <span class="badge bg-danger">ANULADO</span>';
        });
    },

    // ---- Buscar / ver ----
    openBuscar: function () {
        if (!this._bqInit) { this._bqInit = true; this.el('btnBQ').addEventListener('click', function () { AS.buscar(); }); this.el('bqQ').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); AS.buscar(); } }); }
        bootstrap.Modal.getOrCreateInstance(this.el('modalBuscar')).show();
        this.buscar();
    },
    buscar: function () {
        var self = this;
        this.api('listar', { q: this.el('bqQ').value, desde: this.el('bqDesde').value, hasta: this.el('bqHasta').value }).then(function (j) {
            var rows = (j.ok && j.data) ? j.data : [];
            self.el('bqBody').innerHTML = rows.length ? rows.map(function (r) {
                return '<tr class="bq-row" data-num="' + r.NUMMOV + '" style="cursor:pointer"><td>' + AS.esc(r.NUMERO) + (r.ANULADO ? ' <span class="badge bg-danger">ANULADO</span>' : '') + '</td><td>' + AS.esc(r.FECHA) + '</td><td class="small">' + AS.esc(r.OPERACION) + '</td><td class="small">' + AS.esc(r.DETALLE) + '</td><td class="as-num">' + AS.n(r.TOTAL) + '</td></tr>';
            }).join('') : '<tr><td colspan="5" class="text-muted py-3">Sin resultados.</td></tr>';
            Array.prototype.forEach.call(self.el('bqBody').querySelectorAll('.bq-row'), function (tr) { tr.addEventListener('click', function () { AS.cargarAS(+this.getAttribute('data-num')); }); });
        });
    },
    cargarAS: function (num) {
        var self = this;
        this.api('detalle', { nummov: num }).then(function (j) {
            if (!j.ok) { self.toast(j.error, 'danger'); return; }
            var d = j.data;
            var bm = bootstrap.Modal.getInstance(self.el('modalBuscar')); if (bm) bm.hide();
            self.el('nummov').value = d.NUMERO; self.el('cinmov').value = d.NUMERO;
            self.el('fexmov').value = d.FEXISO; self.el('codope').value = String(d.CODOPE); self.el('detmov').value = d.DETMOV;
            self.lineas = (d.lineas || []).map(function (l) { return { codcue: l.codcue, cuenta: l.cuenta, codcdc: l.codcdc, centro: l.centro, debe: l.debe, cre: l.cre, cheque: l.cheque }; });
            self.renderImps();
            self.lockForm(num, d.ANULABLE, d.ANULADO);
        });
    },
    lockForm: function (num, anulable, anulado) {
        Array.prototype.forEach.call(document.querySelectorAll('#asForm input, #asForm select, #asForm textarea'), function (el) { el.disabled = true; });
        Array.prototype.forEach.call(document.querySelectorAll('#asForm button'), function (el) { el.style.display = 'none'; });
        this.el('btnGrabar').style.display = 'none';
        var b = this.el('roBanner'); b.style.display = '';
        b.innerHTML = '<i class="bi bi-eye me-1"></i>Asiento <b>Nº ' + this.esc(num) + '</b> — modo <b>sólo lectura</b>' +
            (anulado ? ' · <span class="badge bg-danger">ANULADO</span>' : '') +
            ' · <a href="#" id="roNuevo">Cargar otro / Nuevo</a>';
        var rn = this.el('roNuevo'); if (rn) rn.addEventListener('click', function (e) { e.preventDefault(); location.reload(); });
        if (anulable && !anulado) { this.anulNum = num; this.el('btnAnularHdr').style.display = ''; }
        this.readonly = true;
    },

    autocomplete: function (input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() { list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + AS.esc(label(o)) + '</div>'; }).join(''); list.classList.toggle('show', items.length > 0); }
        input.addEventListener('input', function () { clearTimeout(t); var q = input.value.trim(); if (q.length < 1) { items = []; render(); return; } t = setTimeout(function () { AS.api(action, { q: q }).then(function (j) { items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }); }, 180); });
        input.addEventListener('keydown', function (e) { if (!list.classList.contains('show')) return; if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); } else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); } else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); onPick(items[hi]); list.classList.remove('show'); } } else if (e.key === 'Escape') list.classList.remove('show'); });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },
    toast: function (msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 6000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { AS.init(); });
