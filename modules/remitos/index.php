<?php
/** Remitos deudores — Carga (escritura). Primer transaccional. CODOPE=410, CICMOV=RV. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

if (db_readonly()) {
    module_head('Remitos', 'bi-truck');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La emisión de remitos requiere <code>mode=readwrite</code>.</div>';
    module_foot();
    exit;
}

$toolbar = '<button id="btnGuardar" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Grabar remito</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>'
         . ' <button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>';
module_head('Remitos — Deudores', 'bi-truck', $toolbar);
?>
<link href="<?= bu('/modules/abm/assets/css/abm.css') ?>" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
  #grdRem tbody tr { cursor:pointer; }
  #grdRem td, #grdRem th { font-variant-numeric:tabular-nums; }
  .rem-grid th, .rem-grid td { font-size:.85rem; vertical-align:middle; }
  .rem-grid input { font-size:.85rem; }
  .rem-num { text-align:right; font-variant-numeric:tabular-nums; }
  .ac-box { position:relative; }
  .ac-list { position:absolute; z-index:1080; left:0; right:0; top:100%; max-height:240px; overflow:auto;
      background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:.375rem; display:none; }
  .ac-list.show { display:block; }
  .ac-opt { padding:.25rem .6rem; cursor:pointer; font-size:.85rem; }
  .ac-opt.active, .ac-opt:hover { background:var(--fc-primary); color:#fff; }
</style>

<div class="fc-form" id="remForm" data-keynav data-keynav-submit="#btnGuardar">
  <div class="card fc-card mb-2"><div class="card-body">
    <div class="row g-2">
      <div class="col-md-5">
        <label class="form-label mb-1">Cliente (cuenta corriente)</label>
        <div class="ac-box">
          <input type="text" id="cliQ" class="form-control" placeholder="Nombre o código…" autocomplete="off" data-nocombo>
          <div class="ac-list" id="cliList"></div>
        </div>
        <input type="hidden" id="codcue">
        <div class="small text-muted mt-1" id="cliInfo"></div>
      </div>
      <div class="col-md-2" id="boxPdv">
        <label class="form-label mb-1">Punto de venta</label>
        <select id="cipmov" class="form-select"></select>
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1">Emisión</label>
        <input type="date" id="fexmov" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label mb-1">Facturación (desde)</label>
        <input type="date" id="frvmov" class="form-control">
      </div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-md-2"><label class="form-label mb-1">Bonificación %</label><input type="text" id="pdcmov" class="form-control rem-num" readonly></div>
      <div class="col-md-2"><label class="form-label mb-1">Saldo cta cte</label><input type="text" id="saldo" class="form-control rem-num" readonly></div>
      <div class="col-md-2"><label class="form-label mb-1">Cód. Postal/Dest.</label><input type="number" id="coddst" class="form-control" value="1"></div>
      <div class="col-md-2"><label class="form-label mb-1">Cotización u$s</label><input type="number" step="0.01" id="cotmov" class="form-control rem-num" placeholder="Si hay prod. en u$s"></div>
      <div class="col-md-2"><label class="form-label mb-1">Valor declarado</label><input type="number" step="0.01" id="vdxmov" class="form-control rem-num" value="0"></div>
      <div class="col-md-2"><label class="form-label mb-1">Detalle</label><input type="text" id="detmov" class="form-control"></div>
    </div>
  </div></div>

  <div class="card fc-card"><div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="bi bi-box-seam me-1"></i>Productos</span>
      <button type="button" id="btnAddLn" class="btn btn-sm btn-outline-light"><i class="bi bi-plus-lg me-1"></i>Agregar producto</button>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm rem-grid mb-0">
        <thead><tr>
          <th style="width:150px">Código</th><th>Denominación</th><th style="width:90px">Unidad</th>
          <th style="width:90px">O.Corte</th><th style="width:95px">O.Proceso</th><th style="width:85px">PTP</th>
          <th style="width:110px" class="rem-num">Cantidad</th><th style="width:120px" class="rem-num">P.U. Neto</th>
          <th style="width:130px" class="rem-num">Total</th><th style="width:40px"></th>
        </tr></thead>
        <tbody id="lnBody"></tbody>
        <tfoot><tr class="fw-bold"><td colspan="8" class="text-end">Total remito:</td><td class="rem-num" id="grTotal">0,00</td><td></td></tr></tfoot>
      </table>
    </div>
  </div>
  <div class="text-danger small mt-2" id="remErr"></div>
</div>

<!-- MODAL BUSCAR -->
<div class="modal fade" id="modalBuscar" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-search me-2"></i>Buscar remito</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="row g-2 align-items-end mb-2">
      <div class="col-md-5"><input type="text" id="remBuscarQ" class="form-control form-control-sm" placeholder="Cliente, CUIT o Nº de remito" autocomplete="off"></div>
      <div class="col-md-2"><input type="date" id="remBuscarD" class="form-control form-control-sm" title="Desde"></div>
      <div class="col-md-2"><input type="date" id="remBuscarH" class="form-control form-control-sm" title="Hasta"></div>
      <div class="col-md-2"><button id="remBuscarGo" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Buscar</button></div>
    </div>
    <table class="table table-sm table-hover w-100" id="grdRem"><thead><tr>
      <th style="width:95px">Fecha</th><th style="width:150px">Comprobante</th><th>Cliente</th>
      <th class="text-end" style="width:130px">Total</th><th style="width:120px">Estado</th><th></th>
    </tr></thead></table>
  </div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL DETALLE -->
<div class="modal fade" id="modalDet" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title" id="detTit"><i class="bi bi-truck me-2"></i>Remito</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="detBody"></div>
  <div class="modal-footer py-1"><button type="button" id="btnImprimir" class="btn btn-sm btn-primary"><i class="bi bi-printer me-1"></i>Imprimir</button><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<script>window.REM_MODO = '<?= h(auth_modo()) ?>';</script>
<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/remitos.js?v=6"></script>
'); ?>
