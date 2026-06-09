<?php
/** Ajustes de Stock — transaccional que mueve la existencia. Porta Frm SI Ajustes. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$ro = db_readonly();
$conceptos = db_query("SELECT CODAUX AS id, DENAUX AS den FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=200 ORDER BY DENAUX;");
$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$fechaIso = '';
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $fechaIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }

$toolbar = '<div class="btn-group me-2">';
if (!$ro) $toolbar .=
    '<button id="btnNuevo" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuevo</button>' .
    '<button id="btnGuardar" class="btn btn-primary btn-sm" disabled><i class="bi bi-check-lg me-1"></i>Guardar</button>' .
    '<button id="btnCancelar" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-x-lg me-1"></i>Cancelar</button>';
$toolbar .= '</div><div class="btn-group me-2"><button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>';
if (!$ro) $toolbar .= '<button id="btnAnular" class="btn btn-outline-danger btn-sm" disabled><i class="bi bi-slash-circle me-1"></i>Anular</button>';
$toolbar .= '</div>';
module_head('Ajustes de Stock', 'bi-boxes', $toolbar);
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="assets/css/aj.css?v=1" rel="stylesheet">
<script>
window.AJ_RO = <?= $ro ? 'true' : 'false' ?>;
window.AJ_CONCEPTOS = <?= json_encode($conceptos) ?>;
window.AJ_FECHA = <?= json_encode($fechaIso) ?>;
</script>

<div class="aj-form mode-idle" id="mainForm" data-keynav>
  <div class="card fc-card"><div class="card-header"><span><i class="bi bi-boxes me-1"></i>Ajuste de Stock <span class="text-muted ms-2" id="fNum">—</span></span></div>
    <div class="card-body aj-head">
      <div class="pf"><label>Fecha <span class="text-danger">*</span></label><input id="f_fex" type="date" class="form-control pf-mid"></div>
      <div class="pf"><label>Concepto <span class="text-danger">*</span></label><select id="f_codaux" class="form-select"></select></div>
      <div class="pf"><label>Cotización</label><input id="f_cot" type="number" step="any" class="form-control fc-num pf-mid" value="1"></div>
      <div class="pf pf-wide"><label>Detalle</label><input id="f_det" class="form-control" maxlength="80"></div>
    </div>
  </div>

  <div class="card fc-card"><div class="card-header"><span><i class="bi bi-card-list me-1"></i>Productos</span>
      <button type="button" class="btn btn-outline-primary btn-sm" id="btnAddLinea" disabled><i class="bi bi-plus-lg me-1"></i>Agregar</button></div>
    <div class="card-body p-0"><table class="table table-sm aj-tbl mb-0"><thead><tr>
      <th style="width:34%">Producto</th><th style="width:16%">Unidad</th><th class="text-end" style="width:14%">Stock actual</th>
      <th class="text-end" style="width:15%">Ingreso</th><th class="text-end" style="width:15%">Egreso</th><th style="width:2rem"></th>
    </tr></thead><tbody id="tbLineas"></tbody></table></div></div>

  <div class="text-danger small mt-2" id="formErr"></div>
</div>

<!-- MODAL BUSCAR -->
<div class="modal fade" id="modalBuscar" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-search me-2"></i>Buscar — Ajustes de Stock</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="row g-2 mb-2">
      <div class="col-auto"><input id="bq" class="form-control form-control-sm" placeholder="Concepto / detalle / nº"></div>
      <div class="col-auto"><input id="bdesde" type="date" class="form-control form-control-sm"></div>
      <div class="col-auto"><input id="bhasta" type="date" class="form-control form-control-sm"></div>
      <div class="col-auto"><button class="btn btn-sm btn-primary" id="bGo">Filtrar</button></div>
    </div>
    <table class="table table-sm table-hover w-100" id="grdBuscar"><thead><tr><th>Nº</th><th>Fecha</th><th>Concepto</th><th>Detalle</th><th class="text-end">Total</th><th></th></tr></thead></table>
  </div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL CONFIRMAR -->
<div class="modal fade" id="modalConfirm" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title">Confirmar</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="confirmBody"></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-danger" id="btnConfirmOk">Aceptar</button></div>
</div></div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0" role="alert"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php
module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/ajustes.js?v=1"></script>
');
