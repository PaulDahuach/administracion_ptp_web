<?php
/** Recibos (cobranzas) — Carga. CODOPE=480, CICMOV=RC. Transaccional contable. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

if (db_readonly()) {
    module_head('Recibos', 'bi-receipt');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La emisión de recibos requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}

$toolbar = '<button id="btnGuardar" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Grabar recibo</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>'
         . ' <button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>';
module_head('Recibos — Cobranzas', 'bi-receipt', $toolbar);
?>
<link href="<?= bu('/modules/abm/assets/css/abm.css') ?>" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
  .rc-grid th, .rc-grid td { font-size:.82rem; vertical-align:middle; }
  .rc-grid input, .rc-grid select { font-size:.82rem; }
  .rc-num { text-align:right; font-variant-numeric:tabular-nums; }
  .ac-box { position:relative; }
  .ac-list { position:absolute; z-index:1080; left:0; right:0; top:100%; max-height:240px; overflow:auto;
      background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:.375rem; display:none; }
  .ac-list.show { display:block; }
  .ac-opt { padding:.25rem .6rem; cursor:pointer; font-size:.85rem; }
  .ac-opt.active, .ac-opt:hover { background:var(--fc-primary); color:#fff; }
  .tot-bar { display:flex; gap:1rem; flex-wrap:wrap; }
  .tot-bar .t { background:var(--bs-tertiary-bg); border-radius:.4rem; padding:.4rem .7rem; min-width:120px; }
  .tot-bar .t .lbl { font-size:.66rem; text-transform:uppercase; color:var(--bs-secondary-color); }
  .tot-bar .t .val { font-weight:700; font-variant-numeric:tabular-nums; }
  #grdRec tbody tr, #grdPend tbody tr { cursor:pointer; }
</style>

<div class="fc-form" id="rcForm">
  <div class="card fc-card mb-2"><div class="card-body">
    <div class="row g-2">
      <div class="col-md-5">
        <label class="form-label mb-1">Cliente (cuenta corriente)</label>
        <div class="ac-box"><input type="text" id="cliQ" class="form-control" placeholder="Nombre o código…" autocomplete="off"><div class="ac-list" id="cliList"></div></div>
        <input type="hidden" id="codcue"><div class="small text-muted mt-1" id="cliInfo"></div>
      </div>
      <div class="col-md-2"><label class="form-label mb-1">Operación</label><select id="codaux" class="form-select"></select></div>
      <div class="col-md-2"><label class="form-label mb-1">Punto de venta</label><select id="cipmov" class="form-select"></select></div>
      <div class="col-md-2"><label class="form-label mb-1">Emisión</label><input type="date" id="fexmov" class="form-control"></div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-md-2"><label class="form-label mb-1">Forma de pago</label><select id="codfdp" class="form-select"><option value="4">Cheques</option><option value="1">Efectivo</option><option value="5">Interdepósito</option></select></div>
      <div class="col-md-3" id="boxCbx" style="display:none"><label class="form-label mb-1">Cuenta bancaria</label><select id="codcbx" class="form-select"></select></div>
      <div class="col-md-2"><label class="form-label mb-1">Saldo operativo</label><input type="text" id="saldo" class="form-control rc-num" readonly></div>
      <div class="col-md-5"><label class="form-label mb-1">Detalle</label><input type="text" id="detmov" class="form-control"></div>
    </div>
  </div></div>

  <div class="card fc-card mb-2">
    <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-list-check me-1"></i>Referencias (comprobantes a cancelar)</span>
      <button type="button" id="btnAddRef" class="btn btn-sm btn-outline-light" disabled><i class="bi bi-plus-lg me-1"></i>Agregar comprobante</button></div>
    <div class="card-body p-0"><table class="table table-sm rc-grid mb-0">
      <thead><tr><th>Comprobante</th><th style="width:110px">Vencimiento</th><th class="rc-num" style="width:140px">Saldo</th><th class="rc-num" style="width:150px">A acreditar</th><th style="width:40px"></th></tr></thead>
      <tbody id="refBody"></tbody>
      <tfoot><tr class="fw-bold"><td colspan="3" class="text-end">Total a cancelar:</td><td class="rc-num" id="refTotal">0,00</td><td></td></tr></tfoot>
    </table></div>
  </div>

  <div class="row g-2">
    <div class="col-lg-5"><div class="card fc-card mb-2 h-100">
      <div class="card-header"><i class="bi bi-percent me-1"></i>Retenciones</div>
      <div class="card-body p-0"><table class="table table-sm rc-grid mb-0">
        <thead><tr><th>Tipo</th><th class="rc-num">Importe</th><th style="width:150px">Número</th></tr></thead>
        <tbody>
          <tr><td>I.I.B.B.</td><td><input class="form-control form-control-sm rc-num ret-imp" data-rt="1"></td><td><input class="form-control form-control-sm ret-num" data-rt="1" placeholder="Nº"></td></tr>
          <tr><td>Ganancias</td><td><input class="form-control form-control-sm rc-num ret-imp" data-rt="2"></td><td class="d-flex gap-1"><input class="form-control form-control-sm ret-gp" placeholder="Año" style="width:55px"><input class="form-control form-control-sm ret-gn" placeholder="Nº"><input class="form-control form-control-sm ret-rg" placeholder="Rég" style="width:55px"></td></tr>
          <tr><td>I.V.A.</td><td><input class="form-control form-control-sm rc-num ret-imp" data-rt="3"></td><td><input class="form-control form-control-sm ret-num" data-rt="3" placeholder="Nº"></td></tr>
          <tr><td>S.U.S.S.</td><td><input class="form-control form-control-sm rc-num ret-imp" data-rt="4"></td><td><input class="form-control form-control-sm ret-num" data-rt="4" placeholder="Nº"></td></tr>
        </tbody>
        <tfoot><tr class="fw-bold"><td class="text-end">Total ret.:</td><td class="rc-num" id="retTotal">0,00</td><td></td></tr></tfoot>
      </table></div>
    </div></div>
    <div class="col-lg-7" id="cardChq"><div class="card fc-card mb-2 h-100">
      <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-cash-coin me-1"></i>Cheques recibidos</span>
        <button type="button" id="btnAddChq" class="btn btn-sm btn-outline-light"><i class="bi bi-plus-lg me-1"></i>Agregar cheque</button></div>
      <div class="card-body p-0"><table class="table table-sm rc-grid mb-0">
        <thead><tr><th style="width:150px">Banco</th><th style="width:100px">Serie-Nº</th><th style="width:95px">Emisión</th><th style="width:95px">Acred.</th><th>Librador</th><th class="rc-num" style="width:120px">Importe</th><th style="width:30px"></th></tr></thead>
        <tbody id="chqBody"></tbody>
        <tfoot><tr class="fw-bold"><td colspan="5" class="text-end">Total cheques:</td><td class="rc-num" id="chqTotal">0,00</td><td></td></tr></tfoot>
      </table></div>
    </div></div>
  </div>

  <div class="card fc-card"><div class="card-body tot-bar">
    <div class="t"><div class="lbl">Efectivo</div><input type="number" step="0.01" id="efectivo" class="form-control form-control-sm rc-num" value="0"></div>
    <div class="t"><div class="lbl">Cheques</div><div class="val" id="tCheques">0,00</div></div>
    <div class="t"><div class="lbl">A cobrar</div><div class="val" id="tCobrar">0,00</div></div>
    <div class="t"><div class="lbl">Retenciones</div><div class="val" id="tRet">0,00</div></div>
    <div class="t" style="background:var(--fc-primary);color:#fff"><div class="lbl" style="color:#fff">Recibo</div><div class="val" id="tRecibo">0,00</div></div>
    <div class="t" id="boxDif" style="display:none;background:var(--bs-warning-bg-subtle)"><div class="lbl">Diferencia</div><div class="val" id="tDif">0,00</div></div>
  </div></div>
  <div class="text-danger small mt-2" id="rcErr"></div>
</div>

<!-- MODAL PENDIENTES -->
<div class="modal fade" id="modalPend" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-list-check me-2"></i>Comprobantes pendientes</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><table class="table table-sm table-hover w-100" id="grdPend"><thead><tr><th>Comprobante</th><th>Emisión</th><th>Vencimiento</th><th class="rc-num">Saldo</th></tr></thead></table></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL BUSCAR -->
<div class="modal fade" id="modalBuscar" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-search me-2"></i>Buscar recibo</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="row g-2 mb-2"><div class="col-md-5"><input type="text" id="recBuscarQ" class="form-control form-control-sm" placeholder="Cliente, CUIT o Nº de recibo"></div>
      <div class="col-md-2"><input type="date" id="recBuscarD" class="form-control form-control-sm"></div><div class="col-md-2"><input type="date" id="recBuscarH" class="form-control form-control-sm"></div>
      <div class="col-md-2"><button id="recBuscarGo" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Buscar</button></div></div>
    <table class="table table-sm table-hover w-100" id="grdRec"><thead><tr><th style="width:95px">Fecha</th><th style="width:150px">Comprobante</th><th>Cliente</th><th class="rc-num" style="width:130px">Total</th><th style="width:90px">Estado</th><th></th></tr></thead></table>
  </div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL DETALLE -->
<div class="modal fade" id="modalDet" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title" id="detTit"><i class="bi bi-receipt me-2"></i>Recibo</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="detBody"></div>
  <div class="modal-footer py-1"><button type="button" id="btnAnular" class="btn btn-sm btn-outline-danger me-auto"><i class="bi bi-x-octagon me-1"></i>Anular</button><button type="button" id="btnImprimir" class="btn btn-sm btn-primary"><i class="bi bi-printer me-1"></i>Imprimir</button><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<script>window.RC_MODO = '<?= h(auth_modo()) ?>';</script>
<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/recibos.js?v=3"></script>
'); ?>
