<?php
/** Exportación I.V.A. Compras a Holistor — genera el .txt de ancho fijo + ABM de mapeo cuenta→CODHOL. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

// Default: mes anterior (export mensual típico).
$hoy = new DateTime('first day of this month');
$desde = (clone $hoy)->modify('-1 month')->format('Y-m-d');
$hasta = (clone $hoy)->modify('-1 day')->format('Y-m-d');

$toolbar = '<button id="btnPrev" class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>Vista previa</button>'
         . ' <button id="btnExp" class="btn btn-success btn-sm" disabled><i class="bi bi-download me-1"></i>Exportar .txt</button>';
module_head('Exportación I.V.A. Compras a Holistor', 'bi-file-earmark-arrow-down', $toolbar);
?>
<style>
  .hol-num { text-align:right; font-variant-numeric:tabular-nums; }
  #holPrev td, #holPrev th { white-space:nowrap; }
</style>

<div class="card fc-card mb-3"><div class="card-body">
  <div class="row g-2 align-items-end">
    <div class="col-auto"><label class="form-label mb-1 small">Desde (Imputación I.V.A.)</label><input type="date" id="desde" class="form-control form-control-sm" value="<?= $desde ?>"></div>
    <div class="col-auto"><label class="form-label mb-1 small">Hasta</label><input type="date" id="hasta" class="form-control form-control-sm" value="<?= $hasta ?>"></div>
    <div class="col-auto"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="conHeader"><label class="form-check-label small" for="conHeader">Incluir encabezado de columnas</label></div></div>
    <div class="col"><div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Exporta el <b>libro blanco</b> (como el legacy): comprobantes de acreedores e internos con I.V.A., por fecha de imputación.</div></div>
  </div>
</div></div>

<div id="holMsg"></div>

<!-- Cuentas sin mapear (bloquean el export) -->
<div class="card fc-card mb-3" id="cardSin" style="display:none">
  <div class="card-header bg-warning-subtle"><i class="bi bi-exclamation-triangle me-1"></i>Cuentas contables sin vincular a Holistor <span class="small text-muted">— asigná el tipo de movimiento para poder exportar</span></div>
  <div class="card-body"><div id="sinList"></div></div>
</div>

<!-- Vista previa -->
<div class="card fc-card" id="cardPrev" style="display:none">
  <div class="card-header d-flex align-items-center"><span><i class="bi bi-table me-1"></i>Filas a exportar</span><span class="small ms-auto" id="prevCount"></span></div>
  <div class="card-body">
    <div class="table-responsive" style="max-height:460px; overflow:auto">
      <table class="table table-sm table-hover mb-0" id="holPrev"><thead><tr><th>Fecha</th><th>Comprobante</th><th>Proveedor</th><th>Cuenta</th><th>CODHOL</th><th class="hol-num">Alíc.</th><th class="hol-num">Importe</th></tr></thead><tbody id="prevBody"></tbody></table>
    </div>
  </div>
</div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php module_foot('
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/holistor.js?v=1"></script>
'); ?>
