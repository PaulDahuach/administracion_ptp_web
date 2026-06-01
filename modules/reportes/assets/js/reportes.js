/* Reportes/listados — columnas dinámicas desde los datos. */
(function () {
    var table = null;
    function esc(s) { return (s === null || s === undefined) ? '' : String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    async function load() {
        document.getElementById('resumen').textContent = 'Cargando...';
        var j = await (await fetch('api.php?action=list&r=' + encodeURIComponent(window.REP_R))).json();
        if (!j.ok) { document.getElementById('resumen').textContent = 'Error: ' + j.error; return; }
        var rows = j.data || [];
        var cols = rows.length ? Object.keys(rows[0]) : [];
        document.getElementById('thead').innerHTML = cols.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('');
        var data = rows.map(function (r) { return cols.map(function (c) { return esc(r[c]); }); });
        if (table) { table.destroy(); document.querySelector('#tbl tbody').innerHTML = ''; }
        table = new DataTable('#tbl', {
            data: data, columns: cols.map(function (c) { return { title: c }; }),
            pageLength: 50, language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' }
        });
        document.getElementById('resumen').textContent = rows.length + ' registro(s)';
    }

    document.getElementById('btnReload').addEventListener('click', load);
    document.getElementById('btnPrint').addEventListener('click', function () { window.print(); });
    load();
})();
