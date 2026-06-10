<?php
/** Cancelación de Anticipos (Acreedores) — aplica anticipos contra comprobantes. Porta Frm CA Cancelacion de Anticipos. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$ro = db_readonly();
$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$fechaIso = '';
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $fechaIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }

$toolbar = '<div class="btn-group me-2">';
if (!$ro) $toolbar .= '<button id="btnNuevo" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuevo</button>' .
    '<button id="btnGrabar" class="btn btn-primary btn-sm" disabled><i class="bi bi-check-lg me-1"></i>Grabar</button>' .
    '<button id="btnCancelar" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-x-lg me-1"></i>Cancelar</button>';
$toolbar .= '</div><button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>';
module_head('Cancelación de Anticipos', 'bi-arrow-left-right', $toolbar);
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="assets/css/ca.css?v=2" rel="stylesheet">
<script>window.CA_RO=<?= $ro ? 'true' : 'false' ?>; window.CA_FECHA=<?= json_encode($fechaIso) ?>;</script>

<div id="mainForm" class="mode-idle">
  <div class="card fc-card"><div class="card-body ca-head">
    <div class="pf pf-prov"><label>Proveedor</label><div class="ac-wrap"><input type="hidden" id="f_cue"><input id="f_prov" class="form-control" autocomplete="off" placeholder="Buscar proveedor…" disabled><div class="ac-list"></div></div></div>
    <div class="pf"><label>Fecha</label><input id="f_fex" type="date" class="form-control pf-mid" disabled></div>
    <div class="pf pf-wide"><label>Detalle</label><input id="f_det" class="form-control" maxlength="80" disabled></div>
    <div class="ca-saldos">
      <div><label>Saldo Anticipos</label><span id="sAnt">—</span></div>
      <div><label>Saldo Operativo</label><span id="sOper">—</span></div>
    </div>
  </div></div>

  <div class="card fc-card"><div class="card-header"><span><i class="bi bi-cash-stack me-1"></i>Anticipos / Acreditaciones</span><span class="ca-tot" id="totAnt"></span></div>
    <div class="card-body p-0"><table class="table table-sm ca-tbl mb-0"><thead><tr>
      <th style="width:2.5rem"></th><th>Comp. Interno</th><th>Comp. Externo</th><th>Detalle</th><th class="text-end">Saldo</th><th class="text-end" style="width:9rem">Importe</th>
    </tr></thead><tbody id="tbAnt"></tbody></table></div></div>

  <div class="card fc-card"><div class="card-header"><span><i class="bi bi-receipt me-1"></i>Comprobantes pendientes (Referencias)</span><span class="ca-tot" id="totRef"></span></div>
    <div class="card-body p-0"><table class="table table-sm ca-tbl mb-0"><thead><tr>
      <th style="width:2.5rem"></th><th>Vencimiento</th><th>Comp. Interno</th><th>Comp. Externo</th><th>Detalle</th><th class="text-end">Saldo</th><th class="text-end" style="width:9rem">Importe</th>
    </tr></thead><tbody id="tbRef"></tbody></table></div></div>

  <div class="ca-bar" id="caBar"></div>
  <div class="text-danger small mt-2" id="formErr"></div>
</div>

<!-- MODAL BUSCAR -->
<div class="modal fade" id="modalBuscar" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-search me-2"></i>Buscar — Cancelaciones</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><table class="table table-sm table-hover w-100" id="grdBuscar"><thead><tr><th>Nº</th><th>Fecha</th><th>Proveedor</th><th class="text-end">Total</th><th></th></tr></thead></table></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL VER -->
<div class="modal fade" id="modalVer" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title" id="verTit">Cancelación</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="verBody"></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL CONFIRMAR -->
<div class="modal fade" id="modalConfirm" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title">Confirmar</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="confirmBody"></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-primary" id="btnConfirmOk">Grabar</button></div>
</div></div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0" role="alert"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php
module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/ca.js?v=2"></script>
');
