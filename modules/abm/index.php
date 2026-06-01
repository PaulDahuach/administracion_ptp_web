<?php
/** ABM genérico — diseño "form desplegado + Buscar" (estilo RDN/cuentas). ?m= */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$DEFS = require __DIR__ . '/defs.php';
$m = (isset($_GET['m']) ? $_GET['m'] : '');
$def = (isset($DEFS[$m]) ? $DEFS[$m] : null);
if (!$def) { module_head('Maestro', 'bi-table'); echo '<div class="alert alert-danger">Maestro inválido.</div>'; module_foot(); exit; }

$ro = db_readonly();
// Toolbar: Nuevo/Guardar/Cancelar + Buscar/Editar/Eliminar
$toolbar = '<div class="btn-group me-2">';
if (!$ro) $toolbar .=
    '<button id="btnNuevo" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuevo</button>' .
    '<button id="btnGuardar" class="btn btn-primary btn-sm" disabled><i class="bi bi-check-lg me-1"></i>Guardar</button>' .
    '<button id="btnCancelar" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-x-lg me-1"></i>Cancelar</button>';
$toolbar .= '</div><div class="btn-group me-2">' .
    '<button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>';
if (!$ro) $toolbar .=
    '<button id="btnEditar" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-pencil me-1"></i>Editar</button>' .
    '<button id="btnEliminar" class="btn btn-outline-danger btn-sm" disabled><i class="bi bi-trash me-1"></i>Eliminar</button>';
$toolbar .= '</div>';

module_head($def['titulo'], (isset($def['icono']) ? $def['icono'] : 'bi-table'), $toolbar);
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="assets/css/abm.css" rel="stylesheet">
<script>window.ABM_M = <?= json_encode($m) ?>; window.ABM_RO = <?= $ro ? 'true' : 'false' ?>;</script>

<div class="fc-form mode-view" id="mainForm">
  <div class="card fc-card">
    <div class="card-header" data-bs-toggle="collapse" data-bs-target="#cMain">
      <span><i class="bi <?= h((isset($def['icono']) ? $def['icono'] : 'bi-table')) ?> me-1"></i><?= h($def['titulo']) ?>
        <span class="text-muted ms-2" style="text-transform:none">Código: <span id="fCodigo">—</span></span></span>
      <i class="bi bi-chevron-down collapse-icon"></i>
    </div>
    <div id="cMain" class="collapse show">
      <div class="card-body">
        <div class="row g-2" id="formFields"></div>
        <div class="text-danger small mt-2" id="formErr"></div>
      </div>
    </div>
  </div>
  <div id="hijosCont"></div>
</div>

<!-- MODAL BUSCAR -->
<div class="modal fade" id="modalBuscar" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-search me-2"></i>Buscar — <?= h($def['titulo']) ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm table-hover w-100" id="grdBuscar"><thead><tr id="grdBuscarHead"></tr></thead></table>
      </div>
      <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
    </div>
  </div>
</div>

<!-- MODAL CONFIRMAR -->
<div class="modal fade" id="modalConfirm" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2"><h6 class="modal-title">Confirmar</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="confirmBody"></div>
      <div class="modal-footer py-1">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-sm btn-danger" id="btnConfirmOk">Eliminar</button>
      </div>
    </div>
  </div>
</div>

<div class="fc-toast-container">
  <div id="toastMsg" class="toast align-items-center border-0" role="alert">
    <div class="d-flex"><div class="toast-body" id="toastBody"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>

<?php
module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/abm.js"></script>
');
