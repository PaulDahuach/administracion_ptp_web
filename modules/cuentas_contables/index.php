<?php
/** Cuentas Contables (Plan de Cuentas) — maintenance. Porta Frm IC Cuentas Contables.
 *  Form (izquierda) + árbol jerárquico ilustrativo (derecha, navegación). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$ro = db_readonly();
$bancarias = db_query("SELECT CODCBX, DENCBX FROM [Tbl Cuentas Bancarias] ORDER BY DENCBX;");
$holistor  = db_query("SELECT CODHOL, DENHOL FROM [Tbl Holistor] ORDER BY DENHOL;");

$toolbar = '<div class="btn-group me-2">';
if (!$ro) $toolbar .=
    '<button id="btnNuevo" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuevo</button>' .
    '<button id="btnGuardar" class="btn btn-primary btn-sm" disabled><i class="bi bi-check-lg me-1"></i>Guardar</button>' .
    '<button id="btnCancelar" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-x-lg me-1"></i>Cancelar</button>';
if (!$ro) $toolbar .=
    '<button id="btnEliminar" class="btn btn-outline-danger btn-sm" disabled><i class="bi bi-trash me-1"></i>Eliminar</button>';
$toolbar .= '</div>';

module_head('Cuentas Contables', 'bi-diagram-3', $toolbar);
?>
<link href="assets/css/cc.css?v=1" rel="stylesheet">
<script>
window.CC_RO = <?= $ro ? 'true' : 'false' ?>;
window.CC_BANCARIAS = <?= json_encode($bancarias) ?>;
window.CC_HOLISTOR = <?= json_encode($holistor) ?>;
</script>

<div class="row g-3">
  <!-- FORM (izquierda) -->
  <div class="col-lg-7">
    <div class="fc-form mode-view" id="mainForm" data-keynav data-keynav-submit="#btnGuardar">
      <div class="card fc-card">
        <div class="card-header"><span><i class="bi bi-diagram-3 me-1"></i>Cuenta Contable</span></div>
        <div class="card-body">
          <div class="cc-frow"><label>Código</label>
            <div><input type="text" id="f_cod" class="form-control" maxlength="7" autocomplete="off" disabled>
              <span class="cc-niv" id="f_nivcue">—</span></div></div>
          <div class="cc-frow"><label>Denominación <span class="text-danger">*</span></label>
            <div><input type="text" id="f_den" class="form-control" maxlength="50"></div></div>
          <div class="cc-frow"><label>Imputable</label>
            <div><input type="checkbox" id="f_imp" class="form-check-input"></div></div>
          <div class="cc-frow"><label>Cuenta Bancaria</label>
            <div><select id="f_codcbx" class="form-select"></select></div></div>
          <div class="cc-frow"><label>Tipo Mov. Holistor</label>
            <div><select id="f_codhol" class="form-select"></select></div></div>
          <div class="cc-flags">
            <label class="form-check"><input type="checkbox" id="f_ccc" class="form-check-input"> Consolidada</label>
            <label class="form-check"><input type="checkbox" id="f_dec" class="form-check-input"> Decimales</label>
            <label class="form-check"><input type="checkbox" id="f_con" class="form-check-input"> Conciliable</label>
            <label class="form-check"><input type="checkbox" id="f_gas" class="form-check-input"> Reporta en Gastos</label>
            <label class="form-check"><input type="checkbox" id="f_gex" class="form-check-input"> Reporta en G. Extr.</label>
            <label class="form-check"><input type="checkbox" id="f_dis" class="form-check-input"> Discontinuada</label>
          </div>
          <div class="text-danger small mt-2" id="formErr"></div>
        </div>
      </div>
      <!-- saldos read-only -->
      <div class="card fc-card" id="cardSaldos">
        <div class="card-header"><span><i class="bi bi-cash-stack me-1"></i>Saldos</span></div>
        <div class="card-body cc-saldos">
          <div><label>Débitos</label><span id="s_deb" class="cc-num">0.00</span></div>
          <div><label>Créditos</label><span id="s_cre" class="cc-num">0.00</span></div>
          <div><label>Saldo Actual</label><span id="s_act" class="cc-num">0.00</span></div>
          <div><label>Saldo Inicial</label><span id="s_ini" class="cc-num">0.00</span></div>
          <div><label>Saldo Conciliado</label><span id="s_con" class="cc-num">0.00</span></div>
        </div>
      </div>
    </div>
  </div>
  <!-- ÁRBOL (derecha, ilustrativo / navegación) -->
  <div class="col-lg-5">
    <div class="card fc-card cc-treecard">
      <div class="card-header"><span><i class="bi bi-list-nested me-1"></i>Plan de Cuentas</span>
        <input type="search" id="treeFilter" class="form-control form-control-sm cc-treefilter" placeholder="Filtrar…"></div>
      <div class="card-body p-0"><div id="tree" class="cc-tree"></div></div>
    </div>
  </div>
</div>

<!-- MODAL CONFIRMAR -->
<div class="modal fade" id="modalConfirm" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title">Confirmar</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="confirmBody"></div>
    <div class="modal-footer py-1">
      <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
      <button type="button" class="btn btn-sm btn-danger" id="btnConfirmOk">Eliminar</button>
    </div>
  </div></div>
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
<script src="assets/js/cuentas_contables.js?v=1"></script>
');
