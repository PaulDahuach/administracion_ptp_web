<?php
/** Operaciones — visor de solo lectura (Frm IC Operaciones). Misma mecánica que el resto:
 *  form desplegado + botón Buscar (arriba der.) → modal → la elección llena el form (read-only). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$toolbar = '<div class="btn-group me-2">' .
    '<button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button></div>';
module_head('Operaciones', 'bi-gear-wide-connected', $toolbar);
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="assets/css/op.css?v=3" rel="stylesheet">

<div class="alert alert-secondary py-1 px-2 small mb-2">
  <i class="bi bi-info-circle me-1"></i>Configuración de sistema (solo lectura): define cómo cada operación imputa al mayor. Elegí una con <strong>Buscar</strong>.
</div>

<div id="opDetail" class="op-detail"><div class="text-muted p-3">Elegí una operación con el botón <strong>Buscar</strong>.</div></div>

<!-- MODAL BUSCAR -->
<div class="modal fade" id="modalBuscar" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-search me-2"></i>Buscar — Operaciones</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm table-hover w-100" id="grdBuscar"><thead><tr><th>Cód</th><th>Denominación</th><th>Origen</th></tr></thead></table>
      </div>
      <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
    </div>
  </div>
</div>

<?php
module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/operaciones.js?v=3"></script>
');
