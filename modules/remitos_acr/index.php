<?php
/** Remitos Acreedores — registración de la mercadería recibida del proveedor (compromete stock, sin cta cte). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

if (db_readonly()) {
    module_head('Remitos Acreedores', 'bi-box-arrow-in-down');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La carga requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}

$capa = (auth_modo() === 'capacitacion');
$btnLbl = $capa ? '<i class="bi bi-mortarboard me-1"></i>Grabar remito (capacitación)' : '<i class="bi bi-save me-1"></i>Grabar remito';
$toolbar = '<button id="btnGrabar" class="btn btn-success btn-sm">' . $btnLbl . '</button>'
         . ' <button id="btnAnularHdr" class="btn btn-danger btn-sm" style="display:none"><i class="bi bi-x-octagon me-1"></i>Anular</button>'
         . ' <button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>';
module_head('Remitos Acreedores', 'bi-box-arrow-in-down', $toolbar);
?>
<style>
  .ra-num { text-align:right; font-variant-numeric:tabular-nums; }
  .ac-box { position:relative; }
  .ac-list { position:absolute; z-index:1080; left:0; right:0; top:100%; max-height:240px; overflow:auto; background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:.375rem; display:none; }
  .ac-list.show { display:block; }
  .ac-opt { padding:.25rem .6rem; cursor:pointer; font-size:.85rem; }
  .ac-opt.active, .ac-opt:hover { background:var(--fc-primary); color:#fff; }
  .ra-ro { font-variant-numeric:tabular-nums; font-weight:600; }
  #raForm .form-label { margin-bottom:.05rem; }
</style>

<div id="roBanner" class="alert alert-info py-1 px-2 small mb-2" style="display:none"></div>
<div class="fc-form" id="raForm">
  <div class="card fc-card mb-2"><div class="card-body">
    <?php if ($capa): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-mortarboard me-1"></i>Modo <b>capacitación</b> — el remito se graba en el libro de capacitación.</div><?php endif; ?>
    <div class="row g-2 align-items-end">
      <div class="col-auto" style="width:115px"><label class="form-label mb-1 small">Movimiento Nº</label><input id="nummov" class="form-control form-control-sm ra-ro" placeholder="(auto)" readonly></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1 small">Emisión</label><input type="date" id="fexmov" class="form-control form-control-sm"></div>
      <div class="col-auto" style="width:165px"><label class="form-label mb-1 small">Número</label>
        <div class="d-flex" style="gap:.25rem">
          <input id="cipmov" class="form-control form-control-sm ra-ro text-center px-1" style="width:50px" value="0000" readonly title="Punto de venta interno">
          <input id="cinmov" class="form-control form-control-sm ra-ro" placeholder="(auto)" readonly></div></div>
      <div class="col" style="min-width:260px"><label class="form-label mb-1 small">Cuenta corriente (proveedor)</label>
        <div class="ac-box"><input type="text" id="provQ" class="form-control form-control-sm" placeholder="Nombre o código…" autocomplete="off"><div class="ac-list" id="provList"></div></div>
        <input type="hidden" id="codcue"><div class="small text-muted mt-1" id="provInfo"></div></div>
    </div>
    <div class="row g-2 align-items-end mt-1">
      <div class="col-12"><div class="text-uppercase text-muted" style="font-size:.66rem;letter-spacing:.04em">Comprobante del proveedor</div></div>
      <div class="col-auto" style="width:90px"><label class="form-label mb-1 small">PDV</label><input type="number" id="cep" class="form-control form-control-sm ra-num" placeholder="PDV" value="0"></div>
      <div class="col-auto" style="width:135px"><label class="form-label mb-1 small">Número</label><input type="number" id="cen" class="form-control form-control-sm ra-num" placeholder="Número"></div>
      <div class="col-auto" style="width:145px"><label class="form-label mb-1 small">Emisión</label><input type="date" id="cef" class="form-control form-control-sm" title="Fecha del remito del proveedor"></div>
    </div>
  </div></div>

  <div class="card fc-card mb-2"><div class="card-body">
    <div class="text-uppercase text-muted mb-2" style="font-size:.7rem;letter-spacing:.04em"><i class="bi bi-box-seam me-1"></i>Productos — entrada de mercadería (compromete stock)</div>
    <div class="row g-2 align-items-end mb-2">
      <div class="col" style="min-width:220px"><label class="form-label mb-1 small">Producto</label>
        <div class="ac-box"><input type="text" id="prodQ" class="form-control form-control-sm" placeholder="Código o denominación… (sólo productos con stock)" autocomplete="off"><div class="ac-list" id="prodList"></div></div>
        <input type="hidden" id="prodCod"></div>
      <div class="col-auto" style="width:160px"><label class="form-label mb-1 small">Unidad de medida</label><select id="prodUdm" class="form-select form-select-sm"></select></div>
      <div class="col-auto" style="width:120px"><label class="form-label mb-1 small">Stock remitido</label><input id="prodExi" class="form-control form-control-sm ra-num" readonly title="Lo ya comprometido para este producto (referencia)"></div>
      <div class="col-auto" style="width:110px"><label class="form-label mb-1 small">Cantidad</label><input type="number" step="0.0001" id="prodCant" class="form-control form-control-sm ra-num"></div>
      <div class="col-auto"><button type="button" id="btnAddProd" class="btn btn-sm btn-outline-primary mt-3"><i class="bi bi-plus-lg"></i></button></div>
    </div>
    <table class="table table-sm mb-0"><thead><tr><th>Producto</th><th>Unidad de medida</th><th class="ra-num" style="width:130px">Stock remitido</th><th class="ra-num" style="width:110px">Cantidad</th><th style="width:32px"></th></tr></thead>
      <tbody id="prodBody"></tbody>
      <tfoot><tr class="fw-bold"><td class="text-end" colspan="3">TOTAL · <span id="prodCount">0</span> líneas</td><td class="ra-num" id="prodTot">0</td><td></td></tr></tfoot>
    </table>
  </div></div>

  <div class="text-danger small mt-2" id="raErr"></div>
</div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<!-- Modal: buscar / ver remitos acreedores -->
<div class="modal fade" id="modalBuscar" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2"><h6 class="modal-title mb-0"><i class="bi bi-search me-2"></i>Remitos acreedores</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2 align-items-end mb-2">
          <div class="col"><label class="form-label mb-1 small">Buscar (proveedor · número)</label><input id="bqQ" class="form-control form-control-sm" placeholder="Texto o número…" autocomplete="off"></div>
          <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">Desde</label><input type="date" id="bqDesde" class="form-control form-control-sm"></div>
          <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">Hasta</label><input type="date" id="bqHasta" class="form-control form-control-sm"></div>
          <div class="col-auto"><button type="button" id="btnBQ" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Buscar</button></div>
        </div>
        <div class="table-responsive" style="max-height:320px; overflow:auto">
          <table class="table table-sm table-hover mb-0"><thead><tr><th>Número</th><th>Fecha</th><th>Proveedor</th><th>Remito proveedor</th></tr></thead><tbody id="bqBody"></tbody></table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/remitos_acr.js?v=1"></script>
'); ?>
