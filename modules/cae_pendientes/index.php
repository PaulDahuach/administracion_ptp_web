<?php
/**
 * Pendientes de CAE — vista. Comprobantes electrónicos (FV/NC/ND) grabados sin CAE porque AFIP
 * estaba caído al emitir; la cola los autoriza al volver. Botón "Reintentar ahora" + auto al cargar.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();
module_head('Pendientes de CAE', 'bi-hourglass-split', '');
?>
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="text-muted small" style="max-width:640px">
      Comprobantes electrónicos grabados <b>sin CAE</b> porque AFIP no respondió al emitir. La cola se autoriza
      sola cuando AFIP vuelve (si está programado el reintento); también podés forzarla acá. Un <b>rechazado</b>
      (AFIP rechazó por datos) frena la cola de ese punto de venta hasta resolverlo a mano.
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary btn-sm" id="btnReintentar"><i class="bi bi-arrow-repeat me-1"></i>Reintentar ahora</button>
      <button class="btn btn-outline-secondary btn-sm" id="btnRefrescar" title="Refrescar"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
  </div>
  <div id="cpResult" class="mb-2"></div>
  <div class="card"><div class="card-body p-0">
    <table class="table table-sm table-hover mb-0" id="tblPend">
      <thead><tr>
        <th>Comprobante</th><th>Cliente</th><th class="text-end" style="width:140px">Importe</th>
        <th style="width:120px">Estado</th><th class="text-center" style="width:90px">Intentos</th><th>Último error de AFIP</th>
      </tr></thead>
      <tbody></tbody>
    </table>
  </div></div>
  <div id="vacio" class="text-center text-muted py-5" style="display:none"><i class="bi bi-check2-circle me-1"></i>No hay comprobantes pendientes de CAE.</div>
</div>
<?php module_foot('<script src="assets/js/cae_pendientes.js?v=1"></script>'); ?>
