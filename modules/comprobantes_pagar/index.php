<?php
/** Comprobantes a Pagar (acreedores) — registración de la factura del proveedor (sin AFIP). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

if (db_readonly()) {
    module_head('Comprobantes a Pagar', 'bi-receipt-cutoff');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La carga requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}

$ivaCta = trim((string) nz(db_row("SELECT CACC_D FROM [Rec Control];")['CACC_D'], ''));   // cuenta IVA Crédito Fiscal
$capa = (auth_modo() === 'capacitacion');
$btnLbl = $capa ? '<i class="bi bi-mortarboard me-1"></i>Grabar CP (capacitación)' : '<i class="bi bi-save me-1"></i>Grabar comprobante';
$toolbar = '<button id="btnGrabar" class="btn btn-success btn-sm">' . $btnLbl . '</button>'
         . ' <button id="btnAnularHdr" class="btn btn-danger btn-sm" style="display:none"><i class="bi bi-x-octagon me-1"></i>Anular</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>';
module_head('Comprobantes a Pagar — Acreedores', 'bi-receipt-cutoff', $toolbar);
?>
<style>
  .cp-num { text-align:right; font-variant-numeric:tabular-nums; }
  .ac-box { position:relative; }
  .ac-list { position:absolute; z-index:1080; left:0; right:0; top:100%; max-height:240px; overflow:auto; background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:.375rem; display:none; }
  .ac-list.show { display:block; }
  .ac-opt { padding:.25rem .6rem; cursor:pointer; font-size:.85rem; }
  .ac-opt.active, .ac-opt:hover { background:var(--fc-primary); color:#fff; }
  .tot-bar { display:flex; gap:1rem; flex-wrap:wrap; justify-content:flex-end; }
  .tot-bar .t { background:var(--bs-tertiary-bg); border-radius:.4rem; padding:.4rem .7rem; min-width:120px; }
  .tot-bar .t .lbl { font-size:.66rem; text-transform:uppercase; color:var(--bs-secondary-color); }
  .tot-bar .t .val { font-weight:700; font-variant-numeric:tabular-nums; }
  .cp-ro { font-variant-numeric:tabular-nums; font-weight:600; }
</style>

<div class="fc-form" id="cpForm" data-ivacta="<?= htmlspecialchars($ivaCta, ENT_QUOTES) ?>">
  <div class="card fc-card mb-2"><div class="card-body">
    <?php if ($capa): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-mortarboard me-1"></i>Modo <b>capacitación</b> (negro) — se graba en el libro de capacitación.</div><?php endif; ?>
    <div class="row g-2">
      <div class="col-auto" style="width:115px"><label class="form-label mb-1">Movimiento Nº</label><input id="nummov" class="form-control cp-ro" placeholder="(auto)" readonly></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1">Emisión</label><input type="date" id="fexmov" class="form-control"></div>
      <div class="col"><label class="form-label mb-1">Proveedor (cuenta corriente)</label>
        <div class="ac-box"><input type="text" id="provQ" class="form-control" placeholder="Nombre o código…" autocomplete="off"><div class="ac-list" id="provList"></div></div>
        <input type="hidden" id="codcue"><div class="small text-muted mt-1" id="provInfo"></div></div>
      <div class="col-auto" style="width:150px"><label class="form-label mb-1">Saldo (le debemos)</label><input id="saldo" class="form-control cp-num" readonly></div>
      <div class="col-auto" style="width:120px"><label class="form-label mb-1">Nuestro Nº</label><input id="cinmov" class="form-control cp-ro" placeholder="(auto)" readonly></div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-12"><label class="form-label mb-1 text-muted small">Comprobante del proveedor</label></div>
      <div class="col-auto" style="width:90px"><select id="cec" class="form-select"><option value="FC">FC</option><option value="NC">NC</option><option value="ND">ND</option></select></div>
      <div class="col-auto" style="width:70px"><select id="cei" class="form-select"><option>A</option><option>B</option><option>C</option><option>M</option></select></div>
      <div class="col-auto" style="width:90px"><input type="number" id="cep" class="form-control cp-num" placeholder="PDV" value="0"></div>
      <div class="col-auto" style="width:130px"><input type="number" id="cen" class="form-control cp-num" placeholder="Número"></div>
      <div class="col-auto" style="width:150px"><input type="date" id="cef" class="form-control" title="Fecha del comprobante"></div>
      <div class="col-auto" style="width:120px"><input type="number" step="0.0001" id="cotmov" class="form-control cp-num" value="1" title="Cotización u$s (para productos en dólares)" placeholder="Cotiz. u$s"></div>
      <div class="col"><input id="detmov" class="form-control" placeholder="Detalle (opcional)"></div>
    </div>
  </div></div>

  <div class="card fc-card mb-2"><div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">Neto gravado</label><input type="number" step="0.01" id="netmov" class="form-control cp-num" value="0"></div>
      <div class="col-auto" style="width:80px"><label class="form-label mb-1 small">Alíc. %</label><input type="number" step="0.01" id="alimov" class="form-control cp-num" value="21"></div>
      <div class="col-auto" style="width:130px"><label class="form-label mb-1 small">I.V.A.</label><input id="irimov" class="form-control cp-num" readonly value="0.00"></div>
      <div class="col-auto px-2 text-muted" style="align-self:center">+</div>
      <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">Neto gravado 2</label><input type="number" step="0.01" id="net2mov" class="form-control cp-num" value="0"></div>
      <div class="col-auto" style="width:80px"><label class="form-label mb-1 small">Alíc. %</label><input type="number" step="0.01" id="ali2mov" class="form-control cp-num" value="10.5"></div>
      <div class="col-auto" style="width:130px"><label class="form-label mb-1 small">I.V.A.</label><input id="iri2mov" class="form-control cp-num" readonly value="0.00"></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1 small">No gravado</label><input type="number" step="0.01" id="nogmov" class="form-control cp-num" value="0"></div>
    </div>
    <div class="row g-2 align-items-end mt-1">
      <div class="col-auto" style="width:110px"><label class="form-label mb-1 small">Perc. IVA %</label><input type="number" step="0.01" id="ap1mov" class="form-control cp-num" value="0"></div>
      <div class="col-auto" style="width:130px"><label class="form-label mb-1 small">Perc. IVA $</label><input type="number" step="0.01" id="ip1mov" class="form-control cp-num" value="0"></div>
      <div class="col-auto px-2"></div>
      <div class="col-auto" style="width:110px"><label class="form-label mb-1 small">Perc. IIBB %</label><input type="number" step="0.01" id="ap2mov" class="form-control cp-num" value="0"></div>
      <div class="col-auto" style="width:130px"><label class="form-label mb-1 small">Perc. IIBB $</label><input type="number" step="0.01" id="ip2mov" class="form-control cp-num" value="0"></div>
      <div class="col-auto ms-auto"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="conProd"><label class="form-check-label small" for="conProd">Con productos (entra a stock)</label></div></div>
    </div>
  </div></div>

  <!-- Productos (entra a stock) — sólo con el toggle -->
  <div class="card fc-card mb-2" id="cardProd" style="display:none">
    <div class="card-header"><i class="bi bi-box-seam me-1"></i>Productos (entra a stock) <span class="small text-muted">— el neto gravado sale de estas líneas</span></div>
    <div class="card-body">
      <div class="row g-2 align-items-end mb-2">
        <div class="col" style="min-width:190px"><label class="form-label mb-1 small">Producto</label>
          <div class="ac-box"><input type="text" id="prodQ" class="form-control form-control-sm" placeholder="Código o denominación…" autocomplete="off"><div class="ac-list" id="prodList"></div></div>
          <input type="hidden" id="prodCod"></div>
        <div class="col-auto" style="width:76px"><label class="form-label mb-1 small">Moneda</label><select id="prodMon" class="form-select form-select-sm"><option value="P">$</option><option value="D">u$s</option></select></div>
        <div class="col-auto" style="width:90px"><label class="form-label mb-1 small">Cantidad</label><input type="number" step="0.01" id="prodCant" class="form-control form-control-sm cp-num"></div>
        <div class="col-auto" style="width:105px"><label class="form-label mb-1 small" id="lblCos">Costo $</label><input type="number" step="0.0001" id="prodCos" class="form-control form-control-sm cp-num"></div>
        <div class="col-auto" style="width:100px"><label class="form-label mb-1 small" id="lblLis">Lista $</label><input type="number" step="0.0001" id="prodLis" class="form-control form-control-sm cp-num" value="0"></div>
        <div class="col-auto" style="width:72px"><label class="form-label mb-1 small">Factor</label><input type="number" step="0.0001" id="prodFct" class="form-control form-control-sm cp-num" value="1"></div>
        <div class="col-auto" style="width:72px"><label class="form-label mb-1 small">Bonif %</label><input type="number" step="0.01" id="prodBon" class="form-control form-control-sm cp-num" value="0"></div>
        <div class="col-auto" style="width:88px"><label class="form-label mb-1 small">Flete</label><input type="number" step="0.0001" id="prodFlt" class="form-control form-control-sm cp-num" value="0"></div>
        <div class="col-auto"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="prodStk" checked><label class="form-check-label small" for="prodStk">Stock</label></div></div>
        <div class="col-auto"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="prodApv"><label class="form-check-label small" for="prodApv" title="Actualizar el precio de venta manteniendo el margen">Act.P.Vta</label></div></div>
        <div class="col-auto"><button type="button" id="btnAddProd" class="btn btn-sm btn-outline-primary mt-3"><i class="bi bi-plus-lg"></i></button></div>
      </div>
      <table class="table table-sm mb-0"><thead><tr><th>Producto</th><th>Mon</th><th class="cp-num" style="width:78px">Cant</th><th class="cp-num" style="width:95px">Costo</th><th class="cp-num" style="width:100px">Costo $</th><th class="cp-num" style="width:65px">Bonif</th><th style="width:46px">Stk</th><th class="cp-num" style="width:115px">Neto</th><th style="width:32px"></th></tr></thead><tbody id="prodBody"></tbody></table>
    </div>
  </div>

  <!-- Remitos del proveedor pendientes de facturar -->
  <div class="card fc-card mb-2" id="cardRem" style="display:none">
    <div class="card-header"><i class="bi bi-truck me-1"></i>Remitos del proveedor pendientes <span class="small text-muted">— tildá los que factura este comprobante (descomprometen stock)</span></div>
    <div class="card-body"><div id="remList" class="small"></div></div>
  </div>

  <!-- Imputación contable (Debe) — multi-fila -->
  <div class="card fc-card mb-2">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="bi bi-diagram-3 me-1"></i>Imputación contable (Debe)</span>
      <span class="small">Imputado <b id="impSum" class="cp-num">0.00</b> / Total <b id="impTot" class="cp-num">0.00</b> <span id="impOk"></span></span>
    </div>
    <div class="card-body">
      <div class="row g-2 align-items-end mb-2">
        <div class="col"><label class="form-label mb-1 small">Cuenta</label>
          <div class="ac-box"><input type="text" id="impCtaQ" class="form-control form-control-sm" placeholder="Código o denominación…" autocomplete="off"><div class="ac-list" id="impCtaList"></div></div>
          <input type="hidden" id="impCta"></div>
        <div class="col-md-3"><label class="form-label mb-1 small">Centro de costo</label><select id="impCdc" class="form-select form-select-sm"></select></div>
        <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">Debe</label><input type="number" step="0.01" id="impDeb" class="form-control form-control-sm cp-num"></div>
        <div class="col-auto"><button type="button" id="btnAddImp" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Agregar</button></div>
        <div class="col-auto"><button type="button" id="btnSugIva" class="btn btn-sm btn-outline-secondary" title="Agrega la fila de IVA Crédito Fiscal por el IVA del comprobante">+ IVA Crédito</button></div>
      </div>
      <table class="table table-sm mb-0"><thead><tr><th>Cuenta</th><th>Centro de costo</th><th class="cp-num" style="width:160px">Debe</th><th style="width:36px"></th></tr></thead><tbody id="impBody"></tbody></table>
    </div>
  </div>

  <!-- Vencimientos (a pagar) — multi-fila -->
  <div class="card fc-card mb-2">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span><i class="bi bi-calendar-event me-1"></i>Vencimientos (a pagar)</span>
      <span class="small">Vencimientos <b id="vtoSum" class="cp-num">0.00</b> / Total <b id="vtoTot" class="cp-num">0.00</b> <span id="vtoOk"></span></span>
    </div>
    <div class="card-body">
      <div class="row g-2 align-items-end mb-2">
        <div class="col-auto" style="width:180px"><label class="form-label mb-1 small">Fecha</label><input type="date" id="vtoFx" class="form-control form-control-sm"></div>
        <div class="col-auto" style="width:160px"><label class="form-label mb-1 small">A pagar</label><input type="number" step="0.01" id="vtoImp" class="form-control form-control-sm cp-num"></div>
        <div class="col-auto"><button type="button" id="btnAddVto" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Agregar</button></div>
      </div>
      <table class="table table-sm mb-0"><thead><tr><th>Fecha</th><th class="cp-num" style="width:160px">A pagar</th><th style="width:36px"></th></tr></thead><tbody id="vtoBody"></tbody></table>
    </div>
  </div>

  <div class="card fc-card"><div class="card-body tot-bar">
    <div class="t"><div class="lbl">Neto</div><div class="val" id="tNeto">0.00</div></div>
    <div class="t"><div class="lbl">I.V.A.</div><div class="val" id="tIva">0.00</div></div>
    <div class="t"><div class="lbl">No gravado</div><div class="val" id="tNog">0.00</div></div>
    <div class="t"><div class="lbl">Percepciones</div><div class="val" id="tPerc">0.00</div></div>
    <div class="t" style="background:var(--fc-primary);color:#fff"><div class="lbl" style="color:#fff">Total a Pagar</div><div class="val" id="tTotal">0.00</div></div>
  </div></div>
  <div class="text-danger small mt-2" id="cpErr"></div>
</div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/cp.js?v=6"></script>
'); ?>
