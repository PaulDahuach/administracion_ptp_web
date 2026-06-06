<?php
/** Órdenes de Pago (acreedores) — Carga. CODOPE=340, CICMOV=OP. Espejo de Recibos. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

if (db_readonly()) {
    module_head('Órdenes de Pago', 'bi-cash-stack');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La emisión de órdenes de pago requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}

$toolbar = '<button id="btnGuardar" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Grabar orden</button>'
         . ' <button id="btnAnularHdr" class="btn btn-danger btn-sm" style="display:none"><i class="bi bi-x-octagon me-1"></i>Anular orden</button>'
         . ' <button id="btnImprimirHdr" class="btn btn-primary btn-sm" style="display:none"><i class="bi bi-printer me-1"></i>Imprimir</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>'
         . ' <button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>';
module_head('Órdenes de Pago — Acreedores', 'bi-cash-stack', $toolbar);
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
  .op-grid th, .op-grid td { font-size:.82rem; vertical-align:middle; }
  .op-grid input, .op-grid select { font-size:.82rem; }
  .op-num { text-align:right; font-variant-numeric:tabular-nums; }
  .ac-box { position:relative; }
  .ac-list { position:absolute; z-index:1080; left:0; right:0; top:100%; max-height:240px; overflow:auto; background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:.375rem; display:none; }
  .ac-list.show { display:block; }
  .ac-opt { padding:.25rem .6rem; cursor:pointer; font-size:.85rem; }
  .ac-opt.active, .ac-opt:hover { background:var(--fc-primary); color:#fff; }
  .tot-bar { display:flex; gap:1rem; flex-wrap:wrap; }
  .tot-bar .t { background:var(--bs-tertiary-bg); border-radius:.4rem; padding:.4rem .7rem; min-width:120px; }
  .tot-bar .t .lbl { font-size:.66rem; text-transform:uppercase; color:var(--bs-secondary-color); }
  .tot-bar .t .val { font-weight:700; font-variant-numeric:tabular-nums; }
  .rc-line1 { display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-start; }
  .rc-ro { font-variant-numeric:tabular-nums; font-weight:600; }
  .rc-ret-lbl { font-size:.66rem; text-transform:uppercase; color:var(--bs-secondary-color); font-weight:600; margin-bottom:.15rem; }
  #grdOp tbody tr, #grdPend tbody tr, #grdCart tbody tr { cursor:pointer; }
</style>

<div class="fc-form" id="opForm" data-keynav data-keynav-submit="#btnGuardar">
  <div class="card fc-card mb-2"><div class="card-body">
    <div class="rc-line1">
      <div style="width:115px"><label class="form-label mb-1">Movimiento Nº</label><input id="nummov" class="form-control rc-ro" placeholder="(auto)" readonly></div>
      <div style="width:135px"><label class="form-label mb-1">Emisión</label><input type="date" id="fexmov" class="form-control" readonly></div>
      <div style="width:160px"><label class="form-label mb-1">Número</label>
        <div class="d-flex gap-1"><input class="form-control rc-ro text-center px-1" style="flex:0 0 46px" value="0000" readonly title="PDV">
          <input id="cinmov" class="form-control rc-ro" placeholder="(auto)" readonly title="Nº orden"></div>
        <input type="hidden" id="cipmov"></div>
      <div style="flex:1 1 220px">
        <label class="form-label mb-1">Proveedor (cuenta corriente)</label>
        <div class="ac-box"><input type="text" id="provQ" class="form-control" placeholder="Nombre o código…" autocomplete="off"><div class="ac-list" id="provList"></div></div>
        <input type="hidden" id="codcue"><div class="small text-muted mt-1" id="provInfo"></div>
      </div>
      <div style="width:135px"><label class="form-label mb-1">Saldo operativo</label><input type="text" id="saldo" class="form-control op-num" readonly></div>
      <div style="width:150px"><label class="form-label mb-1">Operación</label><select id="codaux" class="form-select" disabled data-nocombo></select></div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-md-6"><label class="form-label mb-1">Detalle</label><input type="text" id="detmov" class="form-control"></div>
      <div class="col-md-3"><label class="form-label mb-1">Forma de pago</label><select id="codfdp" class="form-select"><option value="4">Cheques</option><option value="1">Efectivo</option><option value="5">Interdepósito</option></select></div>
      <div class="col-md-3"><label class="form-label mb-1">Imput. I.V.A.</label><input type="date" id="fixmov" class="form-control"></div>
    </div>
  </div></div>

  <!-- Referencias -->
  <div class="card fc-card mb-2">
    <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-list-check me-1"></i>Comprobantes a pagar</span>
      <button type="button" id="btnAddRef" class="btn btn-sm btn-outline-light" disabled><i class="bi bi-plus-lg me-1"></i>Agregar comprobante</button></div>
    <div class="card-body p-0"><table class="table table-sm op-grid mb-0">
      <thead><tr><th>Comprobante</th><th style="width:110px">Vencimiento</th><th class="op-num" style="width:140px">Saldo</th><th class="op-num" style="width:150px">A debitar</th><th style="width:40px"></th></tr></thead>
      <tbody id="refBody"></tbody>
      <tfoot><tr class="fw-bold"><td colspan="3" class="text-end">Total a pagar:</td><td class="op-num" id="refTotal">0.00</td><td></td></tr></tfoot>
    </table></div>
  </div>

  <!-- Cheques -->
  <div class="card fc-card mb-2" id="cardChq">
    <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-cash-coin me-1"></i>Cheques entregados</span>
      <span><button type="button" id="btnAddCart" class="btn btn-sm btn-outline-light"><i class="bi bi-wallet2 me-1"></i>De cartera</button>
      <button type="button" id="btnAddChq" class="btn btn-sm btn-outline-light"><i class="bi bi-plus-lg me-1"></i>Cheque propio</button></span></div>
    <div class="card-body p-0"><table class="table table-sm op-grid mb-0">
      <thead><tr><th>Cuenta / Banco</th><th style="width:110px">Serie-Nº</th><th style="width:95px">Emisión</th><th style="width:95px">Acred.</th><th>Librador</th><th class="op-num" style="width:130px">Importe</th><th style="width:30px"></th></tr></thead>
      <tbody id="chqBody"></tbody>
      <tfoot><tr class="fw-bold"><td colspan="5" class="text-end">Total cheques:</td><td class="op-num" id="chqTotal">0.00</td><td></td></tr></tfoot>
    </table></div>
  </div>

  <div class="card fc-card"><div class="card-body d-flex justify-content-between flex-wrap gap-3 align-items-end">
    <!-- Retención IIBB (al pie, izquierda) — alícuota del régimen del proveedor / padrón ARBA -->
    <div class="d-flex gap-3 align-items-end">
      <div style="width:150px"><div class="rc-ret-lbl">Base (neto)</div><input type="number" step="0.01" id="rip" class="form-control form-control-sm op-num" value="0"></div>
      <div style="width:80px"><div class="rc-ret-lbl">Alícuota %</div><input type="number" step="0.01" id="arb" class="form-control form-control-sm op-num" value="0"></div>
      <div style="width:150px"><div class="rc-ret-lbl">Ret. Ing. Brutos <span id="retReg" class="text-info"></span></div><div class="fw-bold op-num" id="rix" style="font-size:1.05rem">0.00</div></div>
      <div style="width:110px"><div class="rc-ret-lbl">Nº Constancia</div><input id="rinDisp" class="form-control form-control-sm rc-ro" placeholder="(auto)" readonly></div>
      <input type="hidden" id="codrri">
    </div>
    <!-- Subtotales (al pie, derecha) -->
    <div class="tot-bar">
      <div class="t" id="boxEfe"><div class="lbl" id="lblEfeOp">Efectivo</div><div class="val" id="tEfectivo">0.00</div><input type="number" step="0.01" id="efectivo" class="form-control form-control-sm op-num" value="0" style="display:none"></div>
      <div class="t"><div class="lbl">Cheques</div><div class="val" id="tCheques">0.00</div></div>
      <div class="t"><div class="lbl">Neto a pagar</div><div class="val" id="tNeto">0.00</div></div>
      <div class="t" style="background:var(--fc-primary);color:#fff"><div class="lbl" style="color:#fff">Orden de Pago</div><div class="val" id="tTotal">0.00</div></div>
    </div>
  </div></div>
  <div class="text-danger small mt-2" id="opErr"></div>
</div>

<!-- MODAL PENDIENTES -->
<div class="modal fade" id="modalPend" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-list-check me-2"></i>Comprobantes pendientes</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><table class="table table-sm table-hover w-100" id="grdPend"><thead><tr><th>Comprobante</th><th>Emisión</th><th>Vencimiento</th><th class="op-num">Saldo</th></tr></thead></table></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL CARTERA -->
<div class="modal fade" id="modalCart" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-wallet2 me-2"></i>Cheques en cartera</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><table class="table table-sm table-hover w-100" id="grdCart"><thead><tr><th>Banco</th><th>Serie-Nº</th><th>Acred.</th><th>Librador</th><th class="op-num">Importe</th></tr></thead></table></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL BUSCAR -->
<div class="modal fade" id="modalBuscar" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-search me-2"></i>Buscar orden de pago</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="row g-2 mb-2"><div class="col-md-5"><input type="text" id="opBuscarQ" class="form-control form-control-sm" placeholder="Proveedor, CUIT o Nº de orden"></div>
      <div class="col-md-2"><input type="date" id="opBuscarD" class="form-control form-control-sm"></div><div class="col-md-2"><input type="date" id="opBuscarH" class="form-control form-control-sm"></div>
      <div class="col-md-2"><button id="opBuscarGo" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Buscar</button></div></div>
    <table class="table table-sm table-hover w-100" id="grdOp"><thead><tr><th style="width:95px">Fecha</th><th style="width:150px">Comprobante</th><th>Proveedor</th><th class="op-num" style="width:130px">Total</th><th style="width:90px">Estado</th><th></th></tr></thead></table>
  </div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<script>window.OP_MODO = '<?= h(auth_modo()) ?>';</script>
<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/ordenes_pago.js?v=3"></script>
'); ?>
