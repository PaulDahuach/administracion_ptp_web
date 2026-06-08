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
        this.el('asHaber').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); if (AS.el('chqRow').style.display !== 'none') AS.el('chqBan').focus(); else AS.addImp(); } });
        this.el('asDebe').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); if (AS.el('chqRow').style.display !== 'none') AS.el('chqBan').focus(); else AS.el('asHaber').focus(); } });
        this.el('btnGrabar').addEventListener('click', function () { AS.grabar(); });
        this.el('btnAnularHdr').addEventListener('click', function () { if (AS.anulNum) AS.anular(AS.anulNum); });
        this.el('btnBuscar').addEventListener('click', function () { AS.openBuscar(); });
        this.el('btnNuevo').addEventListener('click', function () { location.reload(); });
        this.vadPref = this.el('asForm').getAttribute('data-vadpref') || '';
        this.bankPref = this.el('asForm').getAttribute('data-bankpref') || '';
        this.difPref = this.el('asForm').getAttribute('data-difpref') || '';
        this.ivaAcct = this.el('asForm').getAttribute('data-iva-acct') || '';
        this.el('chqSyn').addEventListener('change', function () { AS.chequeLookup(); });
        this.el('chqBan').addEventListener('change', function () { AS.chequeLookup(); });
        this.el('codope').addEventListener('change', function () { AS.opConfig(AS.el('codope').value); });
        this.el('compAux').addEventListener('change', function () { AS.toggleIvaRow(); });
        this.el('asCbx').addEventListener('change', function () { AS.resolveDepCue(); });
        ['compNet1', 'compAli1', 'compNet2', 'compAli2', 'compNog', 'compAp1', 'compAp2', 'compIp1', 'compIp2'].forEach(function (id) { AS.el(id).addEventListener('input', function () { AS.compIva(); }); });
        this.el('toggleAli2').addEventListener('click', function (e) { e.preventDefault(); var r = AS.el('compRow2'); r.style.display = (r.style.display === 'none' || !r.style.display) ? 'flex' : 'none'; });
        this.el('btnImpIva').addEventListener('click', function () { AS.impIva(); });
        this.renderImps();
    },

    // ── Comprobante con IVA (OP Contado, Fase 3a) ──
    opConfig: function (codope) {
        var ax = this.el('compAux'), cue = this.el('asCueQ'), cbx = this.el('asCbx'), mod = this.el('asMod'), iva = this.el('compIvaRow'), ext = this.el('compExtRow');
        ax.innerHTML = ''; ax.disabled = true; cue.disabled = true; cue.value = ''; this.el('asCue').value = '';
        cbx.disabled = true; cbx.value = ''; mod.innerHTML = '<option value="">—</option>'; mod.disabled = true; iva.style.display = 'none'; ext.style.display = 'none';
        this.el('cicmov').value = ''; this.el('cipmov').value = ''; this.depCue = ''; this.depCueLabel = '';
        if (!codope) return;
        this.api('op_config', { codope: codope }).then(function (j) {
            if (!j.ok || !j.data) return;
            var d = j.data;
            AS.el('cicmov').value = d.iccope || ''; AS.el('cipmov').value = '0';   // comprobante interno (auto)
            if (d.auxiliares && d.auxiliares.length) {   // CODAUX: habilita + dispara el comprobante externo + IVA
                ax.innerHTML = d.auxiliares.map(function (a) { return '<option value="' + a.CODAUX + '" data-iva="' + (a.IVAAUX ? '1' : '0') + '">' + AS.esc((a.DENAUX || '').trim()) + '</option>'; }).join('');
                ax.disabled = false; iva.style.display = 'flex'; ext.style.display = '';
            }
            if (d.modelos && d.modelos.length) {         // CODMOD: sólo operaciones con modelo (Depósito Bancario)
                mod.innerHTML = '<option value="">—</option>' + d.modelos.map(function (m) { return '<option value="' + m.CODMOD + '">' + AS.esc((m.DENMOD || '').trim()) + '</option>'; }).join('');
                mod.disabled = false;
            }
            cue.disabled = !d.cueEnabled;    // CODCUE: cuenta corriente, según la operación
            cbx.disabled = !d.bancoEnabled;  // CODCBX: cuenta bancaria, sólo Depósito Bancario
            AS.toggleIvaRow();
        });
    },
    resolveDepCue: function () {   // CODCBX → cuenta contable del banco (Depósito Bancario, DEBE banco)
        var v = this.el('asCbx').value; this.depCue = ''; this.depCueLabel = '';
        if (!v) return;
        this.api('cuenta_cbx', { codcbx: v }).then(function (j) {
            if (j.ok && j.data) { AS.depCue = j.data.codcue; AS.depCueLabel = j.data.codcue + ' · ' + j.data.dencue; }
        });
    },
    toggleIvaRow: function () { this.compIva(); },
    compIva: function () {
        var n1 = this.r2(this.el('compNet1').value), a1 = this.r2(this.el('compAli1').value);
        var n2 = this.r2(this.el('compNet2').value), a2 = this.r2(this.el('compAli2').value);
        var i1 = Math.round(n1 * a1) / 100, i2 = Math.round(n2 * a2) / 100;
        this.el('compIva1').value = i1 ? this.n(i1) : '';
        this.el('compIva2').value = i2 ? this.n(i2) : '';
        var base = n1 + n2, ap1 = this.r2(this.el('compAp1').value), ap2 = this.r2(this.el('compAp2').value);
        if (ap1 > 0) this.el('compIp1').value = (Math.round(base * ap1) / 100).toFixed(2);   // % → $ sobre el neto gravado
        if (ap2 > 0) this.el('compIp2').value = (Math.round(base * ap2) / 100).toFixed(2);
        var tot = Math.round((n1 + i1 + n2 + i2 + this.r2(this.el('compNog').value) + this.r2(this.el('compIp1').value) + this.r2(this.el('compIp2').value)) * 100) / 100;
        this.el('compTot').value = this.n(tot);
    },
    impIva: function () {
        if (!this.ivaAcct) { this.toast('No está configurada la cuenta de IVA crédito fiscal.', 'warning'); return; }
        var iva = Math.round((this.r2(this.el('compNet1').value) * this.r2(this.el('compAli1').value) + this.r2(this.el('compNet2').value) * this.r2(this.el('compAli2').value))) / 100;
        if (iva <= 0) { this.toast('No hay IVA para imputar.', 'warning'); return; }
        if (this.lineas.some(function (l) { return l.codcue === AS.ivaAcct; })) { this.toast('El IVA crédito fiscal ya está imputado.', 'warning'); return; }
        this.lineas.push({ codcue: this.ivaAcct, cuenta: this.ivaAcct + ' · I.V.A. CRÉDITO FISCAL', codcdc: 1, centro: '', debe: iva, cre: 0, chq: null, cheque: '' });
        this.renderImps();
        this.toast('IVA crédito fiscal imputado al Debe: ' + this.n(iva), 'success');
    },
    fillComprobante: function (cmp, codope) {
        var self = this;
        this.api('auxiliares', { codope: codope }).then(function (j) {
            var aux = (j.ok && j.data) ? j.data : [];
            self.el('compAux').innerHTML = aux.map(function (a) { return '<option value="' + a.CODAUX + '">' + AS.esc((a.DENAUX || '').trim()) + '</option>'; }).join('');
            self.el('compIvaRow').style.display = 'flex'; self.el('compExtRow').style.display = '';
            self.el('compAux').value = String(cmp.codaux);
            var setSel = function (el, v) { v = v || ''; if (v && !Array.prototype.some.call(el.options, function (o) { return o.value === v; })) el.insertAdjacentHTML('beforeend', '<option value="' + AS.esc(v) + '">' + AS.esc(v) + '</option>'); el.value = v; };
            setSel(self.el('compCec'), cmp.cec); setSel(self.el('compCei'), cmp.cei);
            self.el('compCep').value = cmp.cep; self.el('compCen').value = cmp.cen;
            self.el('compCef').value = cmp.cef || ''; self.el('compFix').value = cmp.fix || '';
            self.el('compCit').value = cmp.citmov || ''; self.el('compDen').value = cmp.denmov || ''; self.el('compCri').value = cmp.codcri ? String(cmp.codcri) : '';
            var ivas = cmp.ivas || [];
            self.el('compNet1').value = ivas[0] ? ivas[0].net : ''; self.el('compAli1').value = ivas[0] ? ivas[0].ali : '';
            if (ivas[1]) { self.el('compNet2').value = ivas[1].net; self.el('compAli2').value = ivas[1].ali; self.el('compRow2').style.display = 'flex'; }
            self.el('compNog').value = cmp.nogmov || ''; self.el('compIp1').value = cmp.ip1mov || ''; self.el('compIp2').value = cmp.ip2mov || '';
            self.el('compAp1').value = cmp.ap1mov || ''; self.el('compAp2').value = cmp.ap2mov || '';
            self.compIva();
        });
    },

    pickCuenta: function (o) {
        this.cuentaSel = { codcue: o.CODCUE, label: o.CODCUE + ' · ' + (o.DENCUE || '').trim() };
        this.el('asCta').value = o.CODCUE; this.el('asCtaQ').value = this.cuentaSel.label;
        var cc = String(o.CODCUE);
        if (this.vadPref !== '' && cc.indexOf(this.vadPref) === 0) { this.setChequeMode('terceros'); this.el('chqBan').focus(); }
        else if (this.difPref !== '' && cc.indexOf(this.difPref) === 0) { this.setChequeMode('diferido', cc); this.el('chqSyn').focus(); }
        else if (this.bankPref !== '' && cc.indexOf(this.bankPref) === 0) { this.setChequeMode('propio', cc); this.el('chqSyn').focus(); }
        else { this.setChequeMode(null); this.el('asDebe').focus(); }
    },
    setChequeMode: function (mode, codcue) {
        this.chqMode = mode || null;
        var row = this.el('chqRow');
        if (!mode) { row.style.display = 'none'; return; }
        this.resetCheque(); row.style.display = 'flex';
        var ter = (mode === 'terceros');
        this.el('chqColBan').style.display = ter ? '' : 'none';
        this.el('chqColBanInfo').style.display = ter ? 'none' : '';
        ['chqColPlz', 'chqColLib', 'chqColCit', 'chqColLoc'].forEach(function (id) { AS.el(id).style.display = ter ? '' : 'none'; });
        var labels = {
            terceros: '<i class="bi bi-bank me-1"></i>Cheque de tercero — tipeá banco + número; si está en cartera se autocarga (depósito), sino cargá los datos (alta)',
            propio: '<i class="bi bi-bank me-1"></i>Cheque propio — el banco es el de la cuenta; cargá número + fechas + el importe en Haber',
            diferido: '<i class="bi bi-bank me-1"></i>Cheque diferido (posdatado) — el banco es el de la cuenta; emisión (importe en Haber) o vencimiento (Debe)'
        };
        this.el('chqRowLabel').innerHTML = labels[mode] || labels.propio;
        this.chqBancoSel = null;
        if (!ter && codcue) {
            this.el('chqBanInfo').textContent = '…';
            this.api('cuenta_banco', { codcue: codcue }).then(function (j) {
                if (j.ok && j.data) { AS.chqBancoSel = j.data; AS.el('chqBanInfo').textContent = j.data.denban || ('Banco ' + j.data.codban); }
                else { AS.el('chqBanInfo').textContent = '(la cuenta no tiene banco asociado)'; }
            });
        }
    },
    chequeLookup: function () {
        if (this.chqMode === 'propio') { this.chequePropioCheck(); return; }
        if (this.chqMode === 'diferido') { this.chequeDiferidoCheck(); return; }
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
    chequePropioCheck: function () {
        var syn = this.el('chqSyn').value.trim();
        var est = this.el('chqEstado');
        if (!syn || !this.chqBancoSel) { est.style.display = 'none'; return; }
        this.api('cheque_lookup', { codban: this.chqBancoSel.codban, syn: syn }).then(function (j) {
            if (j.ok && j.data) { AS.toast('Ese cheque propio ya existe (no se puede re-emitir).', 'warning'); est.textContent = 'Ya existe — no re-emitir'; est.className = 'badge bg-danger mt-3'; est.style.display = ''; }
            else { est.textContent = 'Cheque propio nuevo'; est.className = 'badge bg-info text-dark mt-3'; est.style.display = ''; }
        });
    },
    chequeDiferidoCheck: function () {
        var syn = this.el('chqSyn').value.trim();
        var est = this.el('chqEstado');
        if (!syn || !this.chqBancoSel) { est.style.display = 'none'; return; }
        this.api('cheque_lookup', { codban: this.chqBancoSel.codban, syn: syn }).then(function (j) {
            if (j.ok && j.data) {
                if (j.data.diferido) { AS.el('asDebe').value = j.data.imp; AS.el('asHaber').value = ''; est.textContent = 'Vencimiento — diferido ($' + AS.n(j.data.imp) + ', va al Debe)'; est.className = 'badge bg-warning text-dark mt-3'; est.style.display = ''; }
                else { AS.toast('Ese cheque ya existe y no está diferido.', 'warning'); est.textContent = 'Ya existe — no diferido'; est.className = 'badge bg-danger mt-3'; est.style.display = ''; }
            } else { est.textContent = 'Emisión — cheque diferido nuevo (importe en Haber)'; est.className = 'badge bg-info text-dark mt-3'; est.style.display = ''; }
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
            var syn = this.el('chqSyn').value.trim();
            if (this.chqMode === 'propio' || this.chqMode === 'diferido') {
                if (!syn) { this.toast('Cargá el número del cheque.', 'warning'); return; }
                if (this.chqMode === 'propio' && cre <= 0) { this.toast('El cheque propio es un pago: va al Haber.', 'warning'); return; }
                var bn = this.chqBancoSel ? (this.chqBancoSel.denban || ('Banco ' + this.chqBancoSel.codban)) : '';
                var tag = this.chqMode === 'diferido' ? ' (diferido)' : ' (propio)';
                chq = { codban: this.chqBancoSel ? this.chqBancoSel.codban : 0, syn: syn, fde: this.el('chqFde').value, plz: 0, fda: this.el('chqFda').value, lib: '', cit: '', loc: '', disp: bn + ' Nº ' + syn + tag };
            } else {
                var ban = this.el('chqBan').value;
                if (!ban || !syn) { this.toast('Elegí el banco y el número del cheque.', 'warning'); return; }
                var bopt = this.el('chqBan').selectedOptions[0];
                chq = { codban: parseInt(ban, 10), syn: syn, fde: this.el('chqFde').value, plz: parseInt(this.el('chqPlz').value, 10) || 0, fda: this.el('chqFda').value, lib: this.el('chqLib').value, cit: this.el('chqCit').value, loc: this.el('chqLoc').value, disp: (bopt ? bopt.textContent : '') + ' Nº ' + syn };
            }
        }
        var opt = this.el('asCdc').selectedOptions[0];
        this.lineas.push({
            codcue: this.cuentaSel.codcue, cuenta: this.cuentaSel.label,
            codcdc: parseInt(this.el('asCdc').value, 10) || 1, centro: opt ? opt.textContent : '',
            debe: deb, cre: cre, chq: chq, cheque: chq ? chq.disp : ''
        });
        var depMod = this.el('asMod').value;   // Depósito Bancario: Valores (4, cheque al Haber) / Efectivo (3, importe al Haber) → banco al Debe
        if (cre > 0 && this.depCue && ((depMod === '4' && this.chqMode === 'terceros') || depMod === '3')) {
            var depchq = (chq && chq.codban) ? { codban: chq.codban, syn: chq.syn } : null;   // Valores: linkear el cheque a la línea del banco (como el legacy)
            this.lineas.push({ codcue: this.depCue, cuenta: this.depCueLabel, codcdc: 1, centro: 'ADMINISTRACION', debe: cre, cre: 0, chq: null, depchq: depchq, cheque: 'depósito al banco' + (depchq ? ' · ' + (chq.disp || '') : '') });
        }
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
        if (this.el('compIvaRow').style.display === 'none') this.el('compTot').value = this.n(td);   // sin comprobante: el Total refleja el asiento (Σ Debe)
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
            lineas: this.lineas.map(function (l) { var o = { codcue: l.codcue, codcdc: l.codcdc, debe: l.debe, cre: l.cre }; if (l.chq) { o.codban = l.chq.codban; o.syn = l.chq.syn; o.fde = l.chq.fde; o.plz = l.chq.plz; o.fda = l.chq.fda; o.lib = l.chq.lib; o.cit = l.chq.cit; o.loc = l.chq.loc; } if (l.depchq) { o.depchq = l.depchq; } return o; })
        };
        payload.codcbx = parseInt(this.el('asCbx').value, 10) || 0;
        payload.codmod = parseInt(this.el('asMod').value, 10) || 0;
        if (this.el('compIvaRow').style.display !== 'none') {   // sólo operaciones con comprobante (OP Contado): las cajas de IVA visibles
            if (!this.el('compAux').value) { this.toast('Elegí el tipo de comprobante (auxiliar).', 'warning'); return; }
            var ivas = [];
            var n1 = this.r2(this.el('compNet1').value), a1 = this.r2(this.el('compAli1').value);
            var n2 = this.r2(this.el('compNet2').value), a2 = this.r2(this.el('compAli2').value);
            if (n1 > 0) ivas.push({ net: n1, ali: a1, iva: Math.round(n1 * a1) / 100 });
            if (n2 > 0) ivas.push({ net: n2, ali: a2, iva: Math.round(n2 * a2) / 100 });
            payload.comprobante = {
                codaux: this.el('compAux').value, cec: this.el('compCec').value, cei: this.el('compCei').value,
                cep: this.el('compCep').value, cen: this.el('compCen').value, cef: this.el('compCef').value, fix: this.el('compFix').value,
                citmov: this.el('compCit').value, denmov: this.el('compDen').value, codcri: this.el('compCri').value,
                ivas: ivas, nogmov: this.r2(this.el('compNog').value), ip1mov: this.r2(this.el('compIp1').value), ip2mov: this.r2(this.el('compIp2').value),
                ap1mov: this.r2(this.el('compAp1').value), ap2mov: this.r2(this.el('compAp2').value)
            };
        }
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
            self.el('cicmov').value = d.CIC || ''; self.el('ciimov').value = d.CII || ''; self.el('cipmov').value = (d.CIP != null ? d.CIP : '');
            self.el('compTot').value = self.n(d.TOTAL);
            if (d.codcbx) {   // Depósito Bancario: repoblar banco + modelo en el header
                self.el('asCbx').value = String(d.codcbx);
                if (d.codmod) { self.el('asMod').innerHTML = '<option value="' + d.codmod + '">' + (AS.esc(d.denmod) || ('Modelo ' + d.codmod)) + '</option>'; self.el('asMod').value = String(d.codmod); }
            }
            self.el('fexmov').value = d.FEXISO; self.el('codope').value = String(d.CODOPE); self.el('detmov').value = d.DETMOV;
            self.lineas = (d.lineas || []).map(function (l) { return { codcue: l.codcue, cuenta: l.cuenta, codcdc: l.codcdc, centro: l.centro, debe: l.debe, cre: l.cre, cheque: l.cheque }; });
            self.renderImps();
            if (d.comprobante) self.fillComprobante(d.comprobante, d.CODOPE); else self.el('compCard').style.display = 'none';
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
        var bc = this.el('btnConstancia'); bc.style.display = ''; bc.onclick = function () { window.open('constancia.php?nummov=' + num + '&print=1', '_blank'); };
        this.readonly = true;
    },

    autocomplete: function (input, list, action, label, onPick) {
        var hi = -1, items = [], t = null;
        function render() { list.innerHTML = items.map(function (o, k) { return '<div class="ac-opt' + (k === hi ? ' active' : '') + '" data-k="' + k + '">' + AS.esc(label(o)) + '</div>'; }).join(''); list.classList.toggle('show', items.length > 0); }
        input.addEventListener('input', function () { clearTimeout(t); var q = input.value.trim(); if (q.length < 1) { items = []; render(); return; } t = setTimeout(function () { AS.api(action, { q: q }).then(function (j) { items = j.ok ? j.data : []; hi = items.length ? 0 : -1; render(); }); }, 180); });
        input.addEventListener('keydown', function (e) { if (!list.classList.contains('show')) return; if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); render(); } else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); } else if (e.key === 'Enter') { if (hi >= 0) { e.preventDefault(); e.stopPropagation(); onPick(items[hi]); list.classList.remove('show'); } } else if (e.key === 'Escape') list.classList.remove('show'); });
        list.addEventListener('mousedown', function (e) { var o = e.target.closest('.ac-opt'); if (o) { e.preventDefault(); onPick(items[+o.dataset.k]); list.classList.remove('show'); } });
        input.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('show'); }, 150); });
    },
    toast: function (msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 6000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { AS.init(); });
