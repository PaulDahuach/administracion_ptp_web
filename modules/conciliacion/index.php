<?php
/** Conciliación Bancaria — marca movimientos como conciliados. Porta Frm IC Conciliacion. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$ro = db_readonly();
$cuentas = db_query("SELECT CODCUE AS id, DENCUE AS den FROM [Tbl Cuentas Contables] WHERE CODCBX Is Not Null ORDER BY DENCUE;");
$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hastaIso = '';
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hastaIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }

$toolbar = '<div class="btn-group me-2"><button id="btnVer" class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>Ver</button></div>';
if (!$ro) $toolbar .= '<button id="btnConciliar" class="btn btn-success btn-sm" disabled><i class="bi bi-check2-square me-1"></i>Conciliar</button>';
module_head('Conciliación Bancaria', 'bi-bank', $toolbar);
?>
<link href="assets/css/conc.css?v=1" rel="stylesheet">
<script>window.CO_RO=<?= $ro ? 'true' : 'false' ?>; window.CO_CUENTAS=<?= json_encode($cuentas) ?>; window.CO_HASTA=<?= json_encode($hastaIso) ?>;</script>

<div id="mainForm">
  <div class="card fc-card"><div class="card-body conc-head">
    <div class="pf"><label>Cuenta</label><select id="f_cuenta" class="form-select"></select></div>
    <div class="pf"><label>Desde (opc.)</label><input id="f_desde" type="date" class="form-control pf-mid"></div>
    <div class="pf"><label>Hasta Fecha</label><input id="f_hasta" type="date" class="form-control pf-mid"></div>
  </div></div>

  <div class="row g-2 conc-saldos" id="saldos" style="display:none">
    <div class="col"><div class="card fc-card mb-0"><div class="card-body"><label>Saldo Operativo</label><div class="conc-big" id="sOper">—</div></div></div></div>
    <div class="col"><div class="card fc-card mb-0"><div class="card-body"><label>Última Conciliación</label><div class="conc-big" id="sUlt">—</div></div></div></div>
    <div class="col"><div class="card fc-card mb-0"><div class="card-body"><label>Saldo Conciliado</label><div class="conc-big" id="sConc">—</div></div></div></div>
    <div class="col"><div class="card fc-card mb-0 conc-post"><div class="card-body"><label>Saldo Post-Conciliación</label><div class="conc-big" id="sPost">—</div></div></div></div>
  </div>

  <div class="card fc-card"><div class="card-header"><span><i class="bi bi-card-list me-1"></i>Movimientos pendientes <span class="conc-proc" id="totNote"></span></span>
      <span class="conc-proc" id="proc"></span></div>
    <div class="card-body p-0"><table class="table table-sm conc-tbl mb-0"><thead><tr>
      <th style="width:2.5rem"><input type="checkbox" id="chkAll" title="Tildar todos"></th>
      <th>Fecha</th><th>Mov Nº</th><th>Comprobante</th><th>Detalle / Librador</th><th>Acred.</th>
      <th class="text-end">Debe</th><th class="text-end">Haber</th>
    </tr></thead><tbody id="tbMov"></tbody></table></div></div>

  <div class="text-danger small mt-2" id="formErr"></div>
</div>

<!-- MODAL CONFIRMAR -->
<div class="modal fade" id="modalConfirm" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title">Confirmar conciliación</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="confirmBody"></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-success" id="btnConfirmOk">Conciliar</button></div>
</div></div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0" role="alert"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php
module_foot('<script src="assets/js/conciliacion.js?v=1"></script>');
