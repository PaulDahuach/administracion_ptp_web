/* Exportación I.V.A. Compras a Holistor — vista previa + mapeo cuenta→CODHOL + descarga del .txt. */
const HOL = {
    tipos: [],
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    n(v) { var x = parseFloat(v); return isNaN(x) ? '' : x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    async api(action, params, opts) {
        var url = new URL('api.php', location.href); url.searchParams.set('action', action);
        for (var k in (params || {})) url.searchParams.set(k, params[k]);
        return await (await fetch(url, opts || {})).json();
    },

    async init() {
        var t = await this.api('tipos');
        this.tipos = (t.ok && t.data) ? t.data : [];
        this.el('btnPrev').addEventListener('click', function () { HOL.preview(); });
        this.el('btnExp').addEventListener('click', function () { HOL.exportar(); });
    },

    tipoOptions(sel) {
        return '<option value="">— elegir tipo —</option>' + this.tipos.map(function (o) {
            return '<option value="' + HOL.esc(o.CODHOL) + '"' + (o.CODHOL === sel ? ' selected' : '') + '>' + HOL.esc(o.CODHOL) + ' · ' + HOL.esc(o.DENHOL) + '</option>';
        }).join('');
    },

    async preview() {
        this.el('holMsg').innerHTML = '';
        var d = this.el('desde').value, h = this.el('hasta').value;
        if (!d || !h) { this.toast('Elegí el rango de fechas.', 'warning'); return; }
        this.el('btnPrev').disabled = true;
        var j = await this.api('preview', { desde: d, hasta: h });
        this.el('btnPrev').disabled = false;
        if (!j.ok) { this.el('holMsg').innerHTML = '<div class="alert alert-danger">' + this.esc(j.error) + '</div>'; return; }
        var sin = j.data.sin_mapear || [];
        // Cuentas sin mapear → bloquean el export
        if (sin.length) {
            this.el('cardSin').style.display = '';
            this.el('sinList').innerHTML = sin.map(function (cue) {
                return '<div class="row g-2 align-items-center mb-2"><div class="col-auto" style="width:130px"><b>' + HOL.esc(cue) + '</b></div>' +
                    '<div class="col-auto" style="min-width:320px"><select class="form-select form-select-sm hol-tipo" data-cue="' + HOL.esc(cue) + '">' + HOL.tipoOptions('') + '</select></div>' +
                    '<div class="col-auto"><button type="button" class="btn btn-sm btn-outline-primary hol-save" data-cue="' + HOL.esc(cue) + '">Vincular</button></div></div>';
            }).join('');
            Array.prototype.forEach.call(this.el('sinList').querySelectorAll('.hol-save'), function (b) {
                b.addEventListener('click', function () {
                    var cue = this.getAttribute('data-cue');
                    var sel = HOL.el('sinList').querySelector('.hol-tipo[data-cue="' + cue + '"]');
                    HOL.mapear(cue, sel ? sel.value : '');
                });
            });
            this.el('btnExp').disabled = true;
        } else {
            this.el('cardSin').style.display = 'none';
            this.el('btnExp').disabled = (j.data.total === 0);
        }
        // Tabla
        this.el('cardPrev').style.display = '';
        this.el('prevCount').innerHTML = '<b>' + j.data.total + '</b> renglón(es)' + (j.data.total > j.data.rows.length ? ' · mostrando ' + j.data.rows.length : '') + (sin.length ? ' · <span class="text-warning">' + sin.length + ' cuenta(s) sin mapear</span>' : '');
        this.el('prevBody').innerHTML = j.data.rows.map(function (r) {
            return '<tr><td>' + HOL.esc(r.FECHA) + '</td><td class="small">' + HOL.esc(r.COMP) + '</td><td>' + HOL.esc(r.PROVEEDOR) + '</td><td>' + HOL.esc(r.CUENTA) + '</td>' +
                '<td>' + (r.CODHOL ? HOL.esc(r.CODHOL) : '<span class="badge bg-warning text-dark">sin mapear</span>') + '</td>' +
                '<td class="hol-num">' + (r.ALI === null ? '—' : HOL.n(r.ALI) + '%') + '</td><td class="hol-num">' + HOL.n(r.IMP) + '</td></tr>';
        }).join('');
    },

    async mapear(codcue, codhol) {
        if (!codhol) { this.toast('Elegí un tipo de movimiento Holistor.', 'warning'); return; }
        var fd = new FormData(); fd.append('action', 'set_codhol'); fd.append('codcue', codcue); fd.append('codhol', codhol);
        var j = await this.api('set_codhol', {}, { method: 'POST', body: fd });
        if (!j.ok) { this.toast(j.error, 'danger'); return; }
        this.toast('Cuenta ' + codcue + ' vinculada a ' + codhol + '.', 'success');
        this.preview();   // recargar (puede habilitar el export)
    },

    exportar() {
        var d = this.el('desde').value, h = this.el('hasta').value;
        if (!d || !h) { this.toast('Elegí el rango de fechas.', 'warning'); return; }
        var header = this.el('conHeader').checked ? '1' : '0';
        var url = new URL('api.php', location.href);
        url.searchParams.set('action', 'exportar'); url.searchParams.set('desde', d); url.searchParams.set('hasta', h); url.searchParams.set('header', header);
        window.location.href = url.toString();   // descarga (Content-Disposition: attachment)
    },

    toast(msg, type) { var t = this.el('toastMsg'); this.el('toastBody').textContent = msg; t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info'); bootstrap.Toast.getOrCreateInstance(t, { delay: 6000 }).show(); }
};
document.addEventListener('DOMContentLoaded', function () { HOL.init(); });
