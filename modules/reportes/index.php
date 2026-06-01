<?php
/** Reportes/listados — vista genérica (columnas dinámicas). ?r=<clave> */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$DEFS = require __DIR__ . '/defs.php';
$r = (isset($_GET['r']) ? $_GET['r'] : '');
$def = (isset($DEFS[$r]) ? $DEFS[$r] : null);
if (!$def) { module_head('Reportes', 'bi-bar-chart'); echo '<div class="alert alert-danger">Reporte inválido.</div>'; module_foot(); exit; }

module_head($def['titulo'], (isset($def['icono']) ? $def['icono'] : 'bi-bar-chart'),
    '<button id="btnReload" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Refrescar</button>' .
    '<button id="btnPrint" class="btn btn-outline-light btn-sm ms-1"><i class="bi bi-printer me-1"></i>Imprimir</button>');
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<script>window.REP_R = <?= json_encode($r) ?>; window.REP_TIT = <?= json_encode($def['titulo']) ?>;</script>

<div class="card">
  <div class="card-body">
    <span class="text-muted small" id="resumen">—</span>
    <table id="tbl" class="table table-sm table-striped table-hover w-100 mt-2"><thead><tr id="thead"></tr></thead><tbody></tbody></table>
  </div>
</div>

<?php
module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/reportes.js"></script>
');
