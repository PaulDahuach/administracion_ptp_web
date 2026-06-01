<?php
/**
 * PLANTILLA DE MÓDULO — Vista (solo-lectura).
 * Lista una tabla en un DataTable. Adaptá título, icono y columnas.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

module_head('Módulo de Ejemplo', 'bi-table',
    '<button class="btn btn-outline-light btn-sm" id="btnReload"><i class="bi bi-arrow-clockwise me-1"></i>Refrescar</button>');
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">

<div class="card">
    <div class="card-body">
        <table id="tbl" class="table table-sm table-striped table-hover w-100">
            <thead><tr id="thead"></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<?php
module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/module.js"></script>
');
