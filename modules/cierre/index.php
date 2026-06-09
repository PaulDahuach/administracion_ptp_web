<?php
/** Cierre Diario de Caja — resumen de caja (derivado del ledger) + cambio de fecha del sistema. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$ro = db_readonly();
$toolbar = '<div class="btn-group me-2">';
if (!$ro) $toolbar .= '<button id="btnCerrar" class="btn btn-warning btn-sm" disabled><i class="bi bi-calendar-check me-1"></i>Cerrar día y cambiar fecha</button>';
$toolbar .= '</div>';
module_head('Cierre Diario de Caja', 'bi-cash-coin', $toolbar);
?>
<link href="assets/css/cierre.css?v=1" rel="stylesheet">

<div class="card fc-card">
  <div class="card-body cierre-bar">
    <div><label>Fecha</label><input type="date" id="fFecha" class="form-control form-control-sm"></div>
    <button id="btnVer" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>Ver</button>
    <button id="btnHoy" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar-event me-1"></i>Ir a fecha del sistema</button>
    <span class="cierre-sys ms-auto">Fecha del sistema: <strong id="sysdate">—</strong></span>
  </div>
</div>

<div id="cierreBody"><div class="text-muted p-3">Cargando…</div></div>

<!-- MODAL CERRAR -->
<div class="modal fade" id="modalCerrar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-calendar-check me-2"></i>Cerrar día y cambiar fecha</h6>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <p class="small mb-2">Se cierra la fecha del sistema <strong id="cerrarFeccie">—</strong> (queda el registro de auditoría) y se avanza a la nueva fecha de operación.</p>
      <label class="form-label small mb-1">Nueva fecha del sistema</label>
      <input type="date" id="fNueva" class="form-control form-control-sm">
      <div class="text-danger small mt-2" id="cerrarErr"></div>
    </div>
    <div class="modal-footer py-1">
      <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
      <button type="button" class="btn btn-sm btn-warning" id="btnCerrarOk"><i class="bi bi-calendar-check me-1"></i>Cerrar y cambiar fecha</button>
    </div>
  </div></div>
</div>

<div class="fc-toast-container">
  <div id="toastMsg" class="toast align-items-center border-0" role="alert">
    <div class="d-flex"><div class="toast-body" id="toastBody"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>
  </div>
</div>

<script>window.CIE_RO = <?= $ro ? 'true' : 'false' ?>;</script>
<?php
module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/js/cierre.js?v=1"></script>
');
