<?php
/** Facturas de Venta (deudores) — emisión con factura electrónica (CAE de AFIP). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../config/afip.php';
auth_require_login();

if (db_readonly()) {
    module_head('Facturas de Venta', 'bi-receipt');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La emisión de facturas requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}

$modoAfip = AFIP_MODO;
$capa = (auth_modo() === 'capacitacion');   // negro (ESTMOV=False): factura NO electrónica, sin CAE
$btnEmitirLbl = $capa ? '<i class="bi bi-mortarboard me-1"></i>Grabar factura (capacitación)' : '<i class="bi bi-cloud-arrow-up me-1"></i>Emitir factura (AFIP)';
$toolbar = '<button id="btnEmitir" class="btn btn-success btn-sm">' . $btnEmitirLbl . '</button>'
         . ' <button id="btnImprimirHdr" class="btn btn-primary btn-sm" style="display:none"><i class="bi bi-printer me-1"></i>Imprimir</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>'
         . ' <button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>';
module_head('Facturas de Venta — Deudores', 'bi-receipt', $toolbar);
?>
<style>
  .fv-grid th, .fv-grid td { font-size:.82rem; vertical-align:middle; }
  .fv-num { text-align:right; font-variant-numeric:tabular-nums; }
  .ac-box { position:relative; }
  .ac-list { position:absolute; z-index:1080; left:0; right:0; top:100%; max-height:240px; overflow:auto; background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:.375rem; display:none; }
  .ac-list.show { display:block; }
  .ac-opt { padding:.25rem .6rem; cursor:pointer; font-size:.85rem; }
  .ac-opt.active, .ac-opt:hover { background:var(--fc-primary); color:#fff; }
  .tot-bar { display:flex; gap:1rem; flex-wrap:wrap; justify-content:flex-end; }
  .tot-bar .t { background:var(--bs-tertiary-bg); border-radius:.4rem; padding:.4rem .7rem; min-width:120px; }
  .tot-bar .t .lbl { font-size:.66rem; text-transform:uppercase; color:var(--bs-secondary-color); }
  .tot-bar .t .val { font-weight:700; font-variant-numeric:tabular-nums; }
  .fv-ro { font-variant-numeric:tabular-nums; font-weight:600; }
  #grdRem tbody tr { cursor:pointer; }
  .cae-box { font-family:monospace; }
</style>

<div class="fc-form" id="fvForm">
  <div class="card fc-card mb-2"><div class="card-body">
    <?php if ($capa): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-mortarboard me-1"></i>Modo <b>capacitación</b> (negro) — la factura se graba <b>sin CAE</b> (no electrónica, no fiscal).</div>
    <?php elseif ($modoAfip !== 'produccion'): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-cone-striped me-1"></i>AFIP en <b>homologación</b> (testing) — los CAE no son fiscales.</div><?php endif; ?>
    <div class="row g-2">
      <div class="col-auto" style="width:115px"><label class="form-label mb-1">Movimiento Nº</label><input id="nummov" class="form-control fv-ro" placeholder="(auto)" readonly></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1">Emisión</label><input type="date" id="fexmov" class="form-control"></div>
      <div class="col"><label class="form-label mb-1">Cliente (cuenta corriente)</label>
        <div class="ac-box"><input type="text" id="cliQ" class="form-control" placeholder="Nombre o código…" autocomplete="off"><div class="ac-list" id="cliList"></div></div>
        <input type="hidden" id="codcue"><div class="small text-muted mt-1" id="cliInfo"></div></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1">Saldo</label><input id="saldo" class="form-control fv-num" readonly></div>
      <div class="col-auto" style="width:200px"><label class="form-label mb-1">Factura Nº</label>
        <div class="d-flex gap-1"><input id="letra" class="form-control text-center px-1 fv-ro" style="flex:0 0 34px" value="A" readonly title="Clase">
          <input class="form-control text-center px-1 fv-ro" style="flex:0 0 46px" value="<?= str_pad((string) ($capa ? 9999 : AFIP_PTO_VTA), 4, '0', STR_PAD_LEFT) ?>" readonly title="Pto venta">
          <input id="cinmov" class="form-control fv-ro" placeholder="(AFIP)" readonly title="Nº"></div></div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-md-3"><label class="form-label mb-1">Condición de venta</label><select id="codcdv" class="form-select"></select></div>
      <div class="col-md-3"><label class="form-label mb-1">Forma de pago</label><select id="codfdp" class="form-select"></select></div>
      <div class="col-md-2"><label class="form-label mb-1">Bonificación %</label><input type="number" step="0.01" id="pdcmov" class="form-control fv-num" value="0"></div>
      <div class="col"><label class="form-label mb-1">Detalle</label><input id="detmov" class="form-control"></div>
    </div>
    <div class="mt-2" id="caeWrap" style="display:none"><span class="badge bg-success">CAE <span class="cae-box" id="caeDisp"></span></span> <span class="text-muted small">vto <span id="caeVto"></span> · Comprobante Autorizado</span></div>
  </div></div>

  <!-- Productos a facturar (de remitos) -->
  <div class="card fc-card mb-2">
    <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-box-seam me-1"></i>Productos a facturar</span>
      <button type="button" id="btnAddRem" class="btn btn-sm btn-outline-light" disabled><i class="bi bi-truck me-1"></i>Agregar remito</button></div>
    <div class="card-body p-0 table-responsive"><table class="table table-sm fv-grid mb-0">
      <thead><tr><th class="fv-num" style="width:90px">Cantidad</th><th style="width:110px">Remito</th><th style="width:80px">P.T.P.</th><th>Código</th><th>Denominación</th><th class="fv-num" style="width:120px">Pr. Unitario</th><th style="width:80px">Alíc.</th><th class="fv-num" style="width:130px">Total</th><th style="width:36px"></th></tr></thead>
      <tbody id="prodBody"></tbody>
      <tfoot><tr class="fw-bold"><td colspan="7" class="text-end">Subtotal:</td><td class="fv-num" id="subTotal">0.00</td><td></td></tr></tfoot>
    </table></div>
  </div>

  <!-- Cheques (contado con cheque) -->
  <div class="card fc-card mb-2" id="cardChq" style="display:none">
    <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-cash-coin me-1"></i>Cheques recibidos (contado)</span>
      <button type="button" id="btnAddChq" class="btn btn-sm btn-outline-light"><i class="bi bi-plus-lg me-1"></i>Agregar cheque</button></div>
    <div class="card-body p-0 table-responsive"><table class="table table-sm fv-grid mb-0" style="min-width:900px">
      <thead><tr><th style="width:150px">Banco</th><th style="width:110px">Serie-Nº</th><th style="width:120px">Emisión</th><th style="width:120px">Acreditación</th><th>Librador</th><th style="width:120px">C.U.I.T.</th><th class="fv-num" style="width:120px">Importe</th><th style="width:30px"></th></tr></thead>
      <tbody id="chqBody"></tbody>
      <tfoot><tr class="fw-bold"><td colspan="6" class="text-end">Total cheques:</td><td class="fv-num" id="chqTotal">0.00</td><td></td></tr></tfoot>
    </table></div>
  </div>

  <div class="card fc-card"><div class="card-body tot-bar">
    <div class="t"><div class="lbl">Neto gravado</div><div class="val" id="tNeto">0.00</div></div>
    <div class="t"><div class="lbl">I.V.A. <span id="lblAli">21%</span></div><div class="val" id="tIva">0.00</div></div>
    <div class="t" id="boxSaf" style="display:none;background:#198754;color:#fff"><div class="lbl" style="color:#fff">Saldo a favor (aplica)</div><div class="val" id="tSaf">0.00</div></div>
    <div class="t" style="background:var(--fc-primary);color:#fff"><div class="lbl" style="color:#fff">Total Factura</div><div class="val" id="tTotal">0.00</div></div>
    <div class="t" id="boxCobrar" style="display:none"><div class="lbl">A cobrar</div><div class="val" id="tCobrar">0.00</div></div>
  </div></div>
  <div class="text-danger small mt-2" id="fvErr"></div>
</div>

<!-- MODAL REMITOS -->
<div class="modal fade" id="modalRem" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-truck me-2"></i>Remitos pendientes de facturar</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div id="remList"></div><div id="remVacio" class="text-muted text-center py-3" style="display:none">El cliente no tiene remitos pendientes de facturar.</div></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL BUSCAR -->
<div class="modal fade" id="modalBuscar" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-search me-2"></i>Buscar factura</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="row g-2 mb-2"><div class="col-md-5"><input type="text" id="bQ" class="form-control form-control-sm" placeholder="Cliente, CUIT, Nº o CAE"></div>
      <div class="col-md-2"><input type="date" id="bD" class="form-control form-control-sm"></div><div class="col-md-2"><input type="date" id="bH" class="form-control form-control-sm"></div>
      <div class="col-md-2"><button id="bGo" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Buscar</button></div></div>
    <table class="table table-sm table-hover w-100"><thead><tr><th style="width:95px">Fecha</th><th style="width:160px">Comprobante</th><th>Cliente</th><th class="fv-num" style="width:130px">Total</th><th style="width:140px">CAE</th><th style="width:90px">Estado</th></tr></thead><tbody id="bBody"></tbody></table>
    <div id="bVacio" class="text-muted text-center py-3" style="display:none">Sin resultados.</div>
  </div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/facturas.js?v=6"></script>
'); ?>
