/* PLANTILLA DE MÓDULO — front. Construye columnas dinámicamente desde data. */
(function () {
    var table = null;

    async function load() {
        var r = await fetch('api.php?action=list');
        var j = await r.json();
        if (!j.ok) { alert(j.error || 'Error'); return; }
        render(j.data || []);
    }

    function render(rows) {
        var cols = rows.length ? Object.keys(rows[0]) : [];
        // Encabezados
        var thead = document.getElementById('thead');
        thead.innerHTML = cols.map(function (c) { return '<th>' + c + '</th>'; }).join('');
        // (Re)inicializar DataTable
        if (table) { table.destroy(); }
        table = new DataTable('#tbl', {
            data: rows.map(function (row) { return cols.map(function (c) { return row[c]; }); }),
            columns: cols.map(function (c) { return { title: c }; }),
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/es-AR.json' },
            pageLength: 25
        });
    }

    document.getElementById('btnReload').addEventListener('click', load);
    load();
})();
