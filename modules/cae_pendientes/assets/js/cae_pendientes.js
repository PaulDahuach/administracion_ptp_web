/* Pendientes de CAE — lista la cola + reintenta (B-php). Ver memoria afip-cae-contingencia. */
var CP = {
    el: function (id) { return document.getElementById(id); },
    esc: function (s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; },
    n: function (v) { return (parseFloat(v) || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    api: function (action) { return fetch('api.php?action=' + action, { method: 'POST' }).then(function (r) { return r.json(); }); },

    init: function () {
        this.el('btnRefrescar').addEventListener('click', function () { CP.load(); });
        this.el('btnReintentar').addEventListener('click', function () { CP.reintentar(); });
        this.load();
    },

    load: function () {
        this.api('listar').then(function (j) {
            if (!j.ok) { CP.msg(j.error, 'danger'); return; }
            var rows = j.data || [];
            CP.el('vacio').style.display = rows.length ? 'none' : '';
            CP.el('btnReintentar').disabled = !rows.length;
            CP.el('tblPend').querySelector('tbody').innerHTML = rows.map(function (r) {
                var badge = r.estado === 'rechazado'
                    ? '<span class="badge bg-danger">Rechazado</span>'
                    : '<span class="badge bg-warning text-dark">Pendiente</span>';
                return '<tr><td class="fw-semibold">' + CP.esc(r.comprobante) + '</td><td>' + CP.esc(r.cliente) +
                    '</td><td class="text-end">' + CP.n(r.total) + '</td><td>' + badge + '</td><td class="text-center">' + r.intentos +
                    '</td><td class="small text-muted">' + CP.esc(r.error) + '</td></tr>';
            }).join('');
        });
    },

    reintentar: function () {
        var b = this.el('btnReintentar');
        b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Reintentando…';
        this.api('reintentar').then(function (j) {
            b.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Reintentar ahora';
            if (!j.ok) { b.disabled = false; CP.msg(j.error, 'danger'); return; }
            var d = j.data;
            if (d.error) { CP.msg('AFIP no respondió: ' + d.error + '. Reintentá en unos minutos.', 'warning'); }
            else {
                var partes = ['Autorizados: ' + d.autorizados];
                if (d.reconciliados) partes.push('reconciliados: ' + d.reconciliados);
                if (d.rechazados) partes.push('rechazados: ' + d.rechazados);
                if (d.pendientes) partes.push('siguen pendientes: ' + d.pendientes);
                CP.msg(partes.join(' · '), d.rechazados ? 'warning' : 'success');
            }
            CP.load();
        });
    },

    msg: function (text, type) {
        this.el('cpResult').innerHTML = '<div class="alert alert-' + (type || 'info') + ' py-2 mb-0">' + this.esc(text) + '</div>';
    }
};
document.addEventListener('DOMContentLoaded', function () { CP.init(); });
