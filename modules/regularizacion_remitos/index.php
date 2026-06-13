<?php
/** Regularización de Remitos Pendientes de Facturación (Deudores). Porta `Frm CD Remitos Pendientes`.
 *  Por cuenta cliente: grilla de remitos pendientes (SRPMOV=True) con check → "Regularizar" pone
 *  SRPMOV=False (los saca del pool sin facturar). Transaccional (readwrite). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$ro = db_readonly();
$capa = (auth_modo() === 'capacitacion');
$btn = $capa ? '<i class="bi bi-mortarboard me-1"></i>Regularizar (capacitación)' : '<i class="bi bi-check2-square me-1"></i>Regularizar seleccionados';
$toolbar = $ro ? '' : '<button id="btnReg" class="btn btn-success btn-sm" disabled>' . $btn . '</button>';
module_head('Regularización de Remitos Pendientes', 'bi-check2-square', $toolbar);
if ($ro) {
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La regularización requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}
?>
<style>
  #rrGrid td, #rrGrid th { vertical-align: middle; }
  #rrGrid td.mono, #rrGrid th.r { font-variant-numeric: tabular-nums; }
</style>

<div class="card mb-2"><div class="card-body py-2">
  <div class="row g-2 align-items-end">
    <div class="col-md-7">
      <label class="form-label mb-1 small">Cuenta Corriente (cliente con remitos pendientes)</label>
      <select id="cboCuenta" class="form-select form-select-sm"><option value="">— Cargando… —</option></select>
    </div>
    <div class="col-md-5 text-md-end small text-muted" id="lblInfo"></div>
  </div>
</div></div>

<div class="card" id="cardGrid" style="display:none"><div class="card-body p-0">
  <table class="table table-sm table-hover mb-0" id="rrGrid">
    <thead class="table-light"><tr>
      <th style="width:42px"><input type="checkbox" id="chkAll" title="Seleccionar todos"></th>
      <th class="r" style="width:90px">Mov Nº</th><th style="width:100px">Emisión</th>
      <th style="width:60px">PDV</th><th style="width:110px">Número</th><th>Detalle</th>
    </tr></thead>
    <tbody id="rrBody"></tbody>
  </table>
</div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php module_foot('
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/reg.js?v=1"></script>'); ?>
