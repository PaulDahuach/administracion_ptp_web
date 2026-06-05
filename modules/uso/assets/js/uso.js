/* Estadísticas de uso — adopción del sistema nuevo. */
const U = {
    el(id) { return document.getElementById(id); },
    esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },
    iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); },

    init() {
        var hoy = new Date(), desde = new Date(); desde.setDate(hoy.getDate() - 29);
        this.el('fDesde').value = this.iso(desde); this.el('fHasta').value = this.iso(hoy);
        this.el('btnFiltrar').addEventListener('click', () => this.load());
        this.el('btnReload').addEventListener('click', () => this.load());
        this.el('fSistema').addEventListener('change', () => this.load());
        document.querySelectorAll('.preset').forEach(b => b.addEventListener('click', () => {
            var h = new Date(), d = new Date(); d.setDate(h.getDate() - (parseInt(b.dataset.d, 10) - 1));
            this.el('fDesde').value = this.iso(d); this.el('fHasta').value = this.iso(h); this.load();
        }));
        this.load();
    },

    async load() {
        var qs = 'desde=' + encodeURIComponent(this.el('fDesde').value) + '&hasta=' + encodeURIComponent(this.el('fHasta').value)
               + '&sistema=' + encodeURIComponent(this.el('fSistema').value);
        var j = await (await fetch('api.php?action=stats&' + qs)).json();
        if (!j.ok) { this.toast(j.error || 'Error', 'danger'); return; }
        var d = j.data;
        this.populSistemas(d.sistemas || []);
        this.el('kHits').textContent = this.n(d.kpis.hits);
        this.el('kUsr').textContent = this.n(d.kpis.usuarios);
        this.el('kMaq').textContent = this.n(d.kpis.maquinas);
        this.el('kDias').textContent = this.n(d.kpis.dias);
        this.tablaSis(d.porSistema);
        this.bars(d.porDia);
        this.tablaMod(d.porModulo);
        this.tablaUsr(d.porUsuario);
        this.tablaMaq(d.porMaquina);
    },

    populSistemas(sistemas) {
        var sel = this.el('fSistema');
        if (sel.options.length > 1) return;   // ya poblado (no pisar la selección)
        sistemas.forEach(s => { var o = document.createElement('option'); o.value = s; o.textContent = s; sel.appendChild(o); });
    },
    tablaSis(rows) {
        rows = rows || [];
        this.el('tSis').innerHTML = rows.length ? rows.map(r =>
            '<tr><td><strong>' + this.esc(r.sistema) + '</strong></td><td class="text-end">' + this.n(r.hits) + '</td><td class="text-end">' + r.usuarios + '</td><td class="small text-muted">' + this.esc(this.fh(r.ultimo)) + '</td></tr>'
        ).join('') : '<tr><td colspan="4" class="text-muted text-center py-2">—</td></tr>';
    },

    bars(dias) {
        var box = this.el('bars'); box.innerHTML = '';
        if (!dias.length) { this.el('barsLbl').textContent = 'Sin datos en el período.'; return; }
        var max = Math.max.apply(null, dias.map(x => x.hits)) || 1;
        dias.forEach(x => {
            var b = document.createElement('div'); b.className = 'bar';
            b.style.height = Math.max(2, Math.round(x.hits / max * 88)) + 'px';
            b.title = x.dia + ': ' + x.hits + ' accesos';
            box.appendChild(b);
        });
        this.el('barsLbl').textContent = dias[0].dia + ' → ' + dias[dias.length - 1].dia + ' · pico ' + max + '/día';
    },

    tablaMod(rows) {
        this.el('tMod').innerHTML = rows.length ? rows.map(r =>
            '<tr><td>' + this.esc(r.modulo) + '</td><td class="text-end">' + this.n(r.hits) + '</td><td class="small text-muted">' + this.esc(this.fh(r.ultimo)) + '</td></tr>'
        ).join('') : '<tr><td colspan="3" class="text-muted text-center py-2">—</td></tr>';
    },
    tablaUsr(rows) {
        this.el('tUsr').innerHTML = rows.length ? rows.map(r =>
            '<tr><td>' + this.esc(r.user) + '</td><td class="text-end">' + this.n(r.hits) + '</td><td class="text-end">' + r.maquinas + '</td><td class="small text-muted">' + this.esc(this.fh(r.ultimo)) + '</td></tr>'
        ).join('') : '<tr><td colspan="4" class="text-muted text-center py-2">—</td></tr>';
    },
    tablaMaq(rows) {
        this.el('tMaq').innerHTML = rows.length ? rows.map(r =>
            '<tr><td>' + this.esc(r.ip) + (r.host ? ' <span class="small text-muted">' + this.esc(r.host) + '</span>' : '') + '</td><td class="text-end">' + this.n(r.hits) + '</td><td class="text-end">' + r.usuarios + '</td><td class="small text-muted">' + this.esc(this.fh(r.ultimo)) + '</td></tr>'
        ).join('') : '<tr><td colspan="4" class="text-muted text-center py-2">—</td></tr>';
    },

    n(v) { return Number(v || 0).toLocaleString('es-AR'); },
    fh(ts) { if (!ts) return ''; var p = ts.split(' '); if (p.length < 2) return ts; var d = p[0].split('-'); return d[2] + '/' + d[1] + ' ' + p[1].slice(0, 5); },

    toast(msg, type) {
        var t = this.el('toastMsg'); this.el('toastBody').textContent = msg;
        t.className = 'toast align-items-center border-0 text-bg-' + (type || 'info');
        bootstrap.Toast.getOrCreateInstance(t, { delay: 5000 }).show();
    },
};
document.addEventListener('DOMContentLoaded', () => U.init());
