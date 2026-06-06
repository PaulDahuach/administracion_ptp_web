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
         . ' <button id="btnAnularHdr" class="btn btn-danger btn-sm" style="display:none"><i class="bi bi-x-octagon me-1"></i>Anular recibo</button>'
         . ' <button id="btnImprimirHdr" class="btn btn-primary btn-sm" style="display:none"><i class="bi bi-printer me-1"></i>Imprimir</button>'
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
  .rc-ro { font-variant-numeric:tabular-nums; font-weight:600; letter-spacing:.3px; }
  #nummov:not([value=""]), #cinmov:not([value=""]) { color:var(--fc-primary); }
  .rc-line1 { display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-start; }
  .rc-ret-lbl { font-size:.66rem; text-transform:uppercase; color:var(--bs-secondary-color); font-weight:600; margin-bottom:.15rem; }
  .rc-ret { padding:0 .2rem; }
  .rc-toggle { cursor:pointer; user-select:none; }
  .rc-chev { transition:transform .15s ease; }
  .rc-toggle[aria-expanded="false"] .rc-chev { transform:rotate(-90deg); }
</style>

<div class="fc-form" id="rcForm" data-keynav data-keynav-submit="#btnGuardar">
  <div class="card fc-card mb-2"><div class="card-body">
    <!-- Línea 1 (orden del legacy): Mov Nº · Emisión · PDV · Nº Recibo · Cliente · Saldo · Operación -->
    <div class="rc-line1">
      <div style="width:115px"><label class="form-label mb-1">Movimiento Nº</label><input id="nummov" class="form-control rc-ro" placeholder="(auto)" readonly></div>
      <div style="width:135px"><label class="form-label mb-1">Emisión</label><input type="date" id="fexmov" class="form-control" readonly></div>
      <div style="width:185px" id="boxPdv"><label class="form-label mb-1">Número</label>
        <div class="d-flex gap-1">
          <input id="cipmovDisp" class="form-control rc-ro text-center px-1" style="flex:0 0 52px" readonly title="Punto de venta (4 díg.)">
          <input id="cinmov" class="form-control rc-ro" placeholder="(auto)" readonly title="Nº de recibo (8 díg.)">
        </div>
        <input type="hidden" id="cipmov">
      </div>
      <div style="flex:1 1 220px">
        <label class="form-label mb-1">Cliente (cuenta corriente)</label>
        <div class="ac-box"><input type="text" id="cliQ" class="form-control" placeholder="Nombre o código…" autocomplete="off"><div class="ac-list" id="cliList"></div></div>
        <input type="hidden" id="codcue">
        <div class="small text-muted mt-1" id="cliInfo"></div>
      </div>
      <div style="width:135px"><label class="form-label mb-1">Saldo operativo</label><input type="text" id="saldo" class="form-control rc-num" readonly></div>
      <div style="width:150px"><label class="form-label mb-1">Operación</label><select id="codaux" class="form-select" disabled data-nocombo></select></div>
    </div>
    <!-- Línea 2: Detalle + Forma de pago + Cuenta bancaria (a la derecha de Detalle) -->
    <div class="row g-2 mt-1">
      <div class="col-md-6"><label class="form-label mb-1">Detalle</label><input type="text" id="detmov" class="form-control"></div>
      <div class="col-md-2"><label class="form-label mb-1">Forma de pago</label><select id="codfdp" class="form-select"><option value="4">Cheques</option><option value="1">Efectivo</option><option value="5">Interdepósito</option></select></div>
      <div class="col-md-4" id="boxCbx"><label class="form-label mb-1">Cuenta bancaria</label><select id="codcbx" class="form-select" disabled></select></div>
    </div>
  </div></div>

  <!-- RETENCIONES (colapsable; Imput. IVA abre el grupo) -->
  <div class="card fc-card mb-2">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="rc-toggle" data-bs-toggle="collapse" data-bs-target="#bodyRet" aria-expanded="true" role="button"><i class="bi bi-chevron-down rc-chev me-2"></i><i class="bi bi-percent me-1"></i>Retenciones</span>
      <span class="small text-muted">Total: <b class="rc-num text-body" id="retTotal">0.00</b></span>
    </div>
    <div class="collapse show" id="bodyRet"><div class="card-body">
    <div class="d-flex gap-3 flex-wrap align-items-end">
      <div style="width:135px"><label class="form-label mb-1">Imput. I.V.A.</label><input type="date" id="fixmov" class="form-control form-control-sm"></div>
      <div class="rc-ret"><div class="rc-ret-lbl">I.I.B.B.</div><div class="d-flex gap-1">
        <input type="number" step="0.01" class="form-control form-control-sm rc-num ret-imp" data-rt="1" placeholder="Importe" style="width:95px">
        <input class="form-control form-control-sm ret-num" data-rt="1" placeholder="Nº" style="width:90px"></div></div>
      <div class="rc-ret"><div class="rc-ret-lbl">Ganancias</div><div class="d-flex gap-1">
        <input type="number" step="0.01" class="form-control form-control-sm rc-num ret-imp" data-rt="2" placeholder="Importe" style="width:95px">
        <input class="form-control form-control-sm ret-gp" placeholder="Año" style="width:58px">
        <input class="form-control form-control-sm ret-gn" placeholder="Nº" style="width:82px">
        <select class="form-select form-select-sm ret-rg" style="width:135px"><option value="">Régimen…</option></select></div></div>
      <div class="rc-ret"><div class="rc-ret-lbl">I.V.A.</div><div class="d-flex gap-1">
        <input type="number" step="0.01" class="form-control form-control-sm rc-num ret-imp" data-rt="3" placeholder="Importe" style="width:95px">
        <input class="form-control form-control-sm ret-num" data-rt="3" placeholder="Nº" style="width:90px"></div></div>
      <div class="rc-ret"><div class="rc-ret-lbl">S.U.S.S.</div><div class="d-flex gap-1">
        <input type="number" step="0.01" class="form-control form-control-sm rc-num ret-imp" data-rt="4" placeholder="Importe" style="width:95px">
        <input class="form-control form-control-sm ret-num" data-rt="4" placeholder="Nº" style="width:90px"></div></div>
    </div>
  </div></div></div>

  <div class="card fc-card mb-2">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="rc-toggle" data-bs-toggle="collapse" data-bs-target="#bodyRef" aria-expanded="true" role="button"><i class="bi bi-chevron-down rc-chev me-2"></i><i class="bi bi-list-check me-1"></i>Referencias (comprobantes a cancelar)</span>
      <button type="button" id="btnAddRef" class="btn btn-sm btn-outline-light" disabled><i class="bi bi-plus-lg me-1"></i>Agregar comprobante</button></div>
    <div class="collapse show" id="bodyRef"><div class="card-body p-0"><table class="table table-sm rc-grid mb-0">
      <thead><tr><th>Comprobante</th><th style="width:110px">Vencimiento</th><th class="rc-num" style="width:140px">Saldo</th><th class="rc-num" style="width:150px">A acreditar</th><th style="width:40px"></th></tr></thead>
      <tbody id="refBody"></tbody>
      <tfoot><tr class="fw-bold"><td colspan="3" class="text-end">Total a cancelar:</td><td class="rc-num" id="refTotal">0.00</td><td></td></tr></tfoot>
    </table></div></div>
  </div>

  <div class="card fc-card mb-2" id="cardChq">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span class="rc-toggle" data-bs-toggle="collapse" data-bs-target="#bodyChq" aria-expanded="true" role="button"><i class="bi bi-chevron-down rc-chev me-2"></i><i class="bi bi-cash-coin me-1"></i>Cheques recibidos</span>
      <button type="button" id="btnAddChq" class="btn btn-sm btn-outline-light"><i class="bi bi-plus-lg me-1"></i>Agregar cheque</button></div>
    <div class="collapse show" id="bodyChq"><div class="card-body p-0"><table class="table table-sm rc-grid mb-0">
      <thead><tr><th style="width:220px">Banco</th><th style="width:130px">Serie-Nº</th><th style="width:130px">Emisión</th><th style="width:130px">Acred.</th><th>Librador</th><th class="rc-num" style="width:150px">Importe</th><th style="width:40px"></th></tr></thead>
      <tbody id="chqBody"></tbody>
      <tfoot><tr class="fw-bold"><td colspan="5" class="text-end">Total cheques:</td><td class="rc-num" id="chqTotal">0.00</td><td></td></tr></tfoot>
    </table></div></div>
  </div>

  <div class="card fc-card"><div class="card-body tot-bar">
    <div class="t" id="boxEfe"><div class="lbl" id="lblEfe">Efectivo</div><div class="val" id="tEfectivo">0.00</div><input type="number" step="0.01" id="efectivo" class="form-control form-control-sm rc-num" value="0" style="display:none"></div>
    <div class="t" id="boxChq"><div class="lbl">Cheques</div><div class="val" id="tCheques">0.00</div></div>
    <div class="t"><div class="lbl">Retenciones</div><div class="val" id="tRet">0.00</div></div>
    <div class="t" id="boxCobrar"><div class="lbl">A cobrar</div><div class="val" id="tCobrar">0.00</div></div>
    <div class="t" style="background:var(--fc-primary);color:#fff"><div class="lbl" style="color:#fff">Recibo</div><div class="val" id="tRecibo">0.00</div></div>
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
<script src="assets/js/recibos.js?v=18"></script>
'); ?>
