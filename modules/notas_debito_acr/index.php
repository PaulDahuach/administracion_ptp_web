<?php
/** Notas de Débito Acreedoras — registración de la ND del proveedor (Frm CA Debitos, sin AFIP). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

if (db_readonly()) {
    module_head('Notas de Débito Acreedoras', 'bi-receipt-cutoff');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La carga requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}

$ivaCta = trim((string) nz(db_row("SELECT CACC_D FROM [Rec Control];")['CACC_D'], ''));   // cuenta IVA Crédito Fiscal
$capa = (auth_modo() === 'capacitacion');
$btnLbl = $capa ? '<i class="bi bi-mortarboard me-1"></i>Grabar ND (capacitación)' : '<i class="bi bi-save me-1"></i>Grabar nota de débito';
$toolbar = '<button id="btnGrabar" class="btn btn-success btn-sm">' . $btnLbl . '</button>'
         . ' <button id="btnAnularHdr" class="btn btn-danger btn-sm" style="display:none"><i class="bi bi-x-octagon me-1"></i>Anular</button>'
         . ' <button id="btnBuscarCP" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>';
module_head('Notas de Débito Acreedoras', 'bi-receipt-cutoff', $toolbar);
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
  /* compresión vertical + cards colapsables */
  #cpForm .card { margin-bottom:.45rem !important; }
  #cpForm .card-body { padding:.5rem .7rem; }
  #cpForm .card-header { padding:.3rem .7rem; cursor:pointer; user-select:none; }
  #cpForm .card-header::before { content:"\25BE"; display:inline-block; margin-right:.45rem; transition:transform .15s; color:var(--bs-secondary-color); }
  #cpForm .card-header.collapsed::before { transform:rotate(-90deg); }
  #cpForm .form-label { margin-bottom:.05rem; font-size:.72rem; }
  #cpForm .form-control, #cpForm .form-select { padding-top:.15rem; padding-bottom:.15rem; min-height:calc(1.3em + .3rem); height:auto; font-size:.82rem; }
  #cpForm .imp-grp { padding:.3rem .55rem .4rem; }
  #cpForm .imp-grp-h { margin-bottom:.2rem; }
  #cpForm .table-sm > :not(caption) > * > * { padding:.2rem .4rem; }
  /* subforms en 2 columnas como el legacy (izq ≈24% · der ≈76%) */
  #cpSubforms > .cp-col-left { flex:0 0 24%; max-width:24%; min-width:268px; }
  #cpSubforms > .cp-col-right { flex:1 1 auto; min-width:0; }
  @media (max-width:991px) { #cpSubforms > .cp-col-left, #cpSubforms > .cp-col-right { flex:1 1 100%; max-width:100%; } }
</style>

<div id="roBanner" class="alert alert-info py-1 px-2 small mb-2" style="display:none"></div>
<div class="fc-form" id="cpForm" data-ivacta="<?= htmlspecialchars($ivaCta, ENT_QUOTES) ?>">
  <div class="card fc-card mb-2"><div class="card-body">
    <?php if ($capa): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-mortarboard me-1"></i>Modo <b>capacitación</b> — se graba en el libro de capacitación.</div><?php endif; ?>
    <div class="row g-2">
      <div class="col-auto" style="width:105px"><label class="form-label mb-1">Movimiento Nº</label><input id="nummov" class="form-control cp-ro" placeholder="(auto)" readonly></div>
      <div class="col-auto" style="width:130px"><label class="form-label mb-1">Emisión</label><input type="date" id="fexmov" class="form-control"></div>
      <div class="col-auto" style="width:152px"><label class="form-label mb-1">Número</label>
        <div class="d-flex" style="gap:.25rem">
          <input id="cipmov" class="form-control cp-ro text-center px-1" style="width:46px" value="0000" readonly title="Punto de venta interno">
          <input id="cinmov" class="form-control cp-ro" placeholder="(auto)" readonly></div></div>
      <div class="col"><label class="form-label mb-1">Cuenta corriente (proveedor)</label>
        <div class="ac-box"><input type="text" id="provQ" class="form-control" placeholder="Nombre o código…" autocomplete="off"><div class="ac-list" id="provList"></div></div>
        <input type="hidden" id="codcue"><div class="small text-muted mt-1" id="provInfo"></div></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1">Saldo Anticipos</label><input id="sancue" class="form-control cp-num" readonly></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1">Saldo Operativo</label><input id="saldo" class="form-control cp-num" readonly></div>
    </div>
    <div class="row g-2 mt-2 align-items-end">
      <div class="col-12"><div class="imp-grp-h" style="margin-bottom:.15rem">Comprobante del proveedor</div></div>
      <div class="col-auto" style="width:78px"><label class="form-label mb-1 small">Código</label><select id="cec" class="form-select form-select-sm"><option value="FC">FC</option><option value="NC">NC</option><option value="ND">ND</option></select></div>
      <div class="col-auto" style="width:62px"><label class="form-label mb-1 small">Letra</label><select id="cei" class="form-select form-select-sm"><option>A</option><option>B</option><option>C</option><option>M</option></select></div>
      <div class="col-auto" style="width:80px"><label class="form-label mb-1 small">PDV</label><input type="number" id="cep" class="form-control form-control-sm cp-num" placeholder="PDV" value="0"></div>
      <div class="col-auto" style="width:120px"><label class="form-label mb-1 small">Número</label><input type="number" id="cen" class="form-control form-control-sm cp-num" placeholder="Número"></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1 small">Emisión</label><input type="date" id="cef" class="form-control form-control-sm" title="Fecha del comprobante del proveedor"></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1 small">Imputación I.V.A.</label><input type="date" id="fixmov" class="form-control form-control-sm" title="Fecha de imputación del I.V.A."></div>
      <div class="col"><label class="form-label mb-1 small">Detalle</label><input id="detmov" class="form-control form-control-sm" placeholder="(opcional)"></div>
    </div>
  </div></div>

  <style>
    .imp-grp { border:1px solid var(--bs-border-color); border-radius:.45rem; padding:.4rem .65rem .55rem; }
    .imp-grp-h { font-size:.66rem; text-transform:uppercase; letter-spacing:.03em; color:var(--bs-secondary-color); font-weight:600; margin-bottom:.35rem; }
    #toggle493 { text-decoration:none; }
  </style>
  <div class="card fc-card mb-2"><div class="card-body">
    <div class="d-flex flex-wrap align-items-start" style="gap:1.1rem">
      <!-- GRAVADO (con 2ª alícuota colapsable: Neto D.493/01) -->
      <div class="imp-grp">
        <div class="imp-grp-h">Gravado</div>
        <div class="d-flex align-items-end" style="gap:.4rem">
          <div style="width:140px"><label class="form-label mb-1 small">Neto</label><input type="number" step="0.01" id="netmov" class="form-control form-control-sm cp-num" value="0"></div>
          <div style="width:70px"><label class="form-label mb-1 small">Alíc.%</label><input type="number" step="0.01" id="alimov" class="form-control form-control-sm cp-num" value="21"></div>
          <div style="width:120px"><label class="form-label mb-1 small">I.V.A.</label><input id="irimov" class="form-control form-control-sm cp-num" readonly value="0.00"></div>
        </div>
        <a href="#" id="toggle493" class="small d-inline-block mt-1"><i class="bi bi-plus-square me-1"></i>Neto D.493/01 (2ª alícuota)</a>
        <div id="row493" class="mt-1" style="display:none; gap:.4rem; align-items:flex-end">
          <div style="width:140px"><input type="number" step="0.01" id="net2mov" class="form-control form-control-sm cp-num" value="0" title="Neto D.493/01"></div>
          <div style="width:70px"><input type="number" step="0.01" id="ali2mov" class="form-control form-control-sm cp-num" value="10.5"></div>
          <div style="width:120px"><input id="iri2mov" class="form-control form-control-sm cp-num" readonly value="0.00"></div>
        </div>
      </div>
      <!-- NO GRAVADO -->
      <div class="imp-grp">
        <div class="imp-grp-h">No gravado</div>
        <div style="width:130px"><label class="form-label mb-1 small">Importe</label><input type="number" step="0.01" id="nogmov" class="form-control form-control-sm cp-num" value="0"></div>
      </div>
      <!-- PERCEPCION I.V.A. -->
      <div class="imp-grp">
        <div class="imp-grp-h">Percep. I.V.A.</div>
        <div class="d-flex align-items-end" style="gap:.4rem">
          <div style="width:68px"><label class="form-label mb-1 small">%</label><input type="number" step="0.01" id="ap1mov" class="form-control form-control-sm cp-num" value="0"></div>
          <div style="width:120px"><label class="form-label mb-1 small">$</label><input type="number" step="0.01" id="ip1mov" class="form-control form-control-sm cp-num" value="0"></div>
        </div>
      </div>
      <!-- PERCEPCION INGRESOS BRUTOS -->
      <div class="imp-grp">
        <div class="imp-grp-h">Percep. Ingresos Brutos</div>
        <div class="d-flex align-items-end" style="gap:.4rem">
          <div style="width:68px"><label class="form-label mb-1 small">%</label><input type="number" step="0.01" id="ap2mov" class="form-control form-control-sm cp-num" value="0"></div>
          <div style="width:120px"><label class="form-label mb-1 small">$</label><input type="number" step="0.01" id="ip2mov" class="form-control form-control-sm cp-num" value="0"></div>
        </div>
      </div>
      <!-- TOTAL del comprobante (editable: permite cargar el total real del proveedor aunque difiera de la discriminación) -->
      <div class="imp-grp ms-auto" style="border-color:var(--fc-primary)">
        <div class="imp-grp-h" style="color:var(--fc-primary)">Total comprobante</div>
        <div style="width:150px"><input type="number" step="0.01" id="totmov" class="form-control form-control-sm cp-num" style="font-weight:700" value="0"></div>
        <div class="small mt-1" style="min-height:1.1em"><span class="text-warning" id="totWarn"></span> <a href="#" id="totReset" class="d-none" title="Recalcular como suma de subtotales">↻ recalcular</a></div>
      </div>
    </div>
  </div></div>

  <!-- Subforms en 2 columnas como el legacy: izquierda ≈24% (Remitos·Anticipos·Vencimientos), derecha ≈76% (Productos·Imputación) -->
  <div id="cpSubforms" class="d-flex flex-wrap align-items-start" style="gap:.5rem">
    <div class="cp-col-left">

      <!-- Referencias: comprobantes del proveedor que se debitan (la ND reduce lo que le debemos) -->
      <div class="card fc-card mb-2">
        <div class="card-header d-flex align-items-center">
          <span><i class="bi bi-link-45deg me-1"></i>Referencias <span class="small text-muted">— comprobantes a debitar</span></span>
          <span class="small ms-auto">Ref <b id="refSum" class="cp-num">0.00</b> · Exced.→antic. <b id="refExc" class="cp-num">0.00</b></span>
        </div>
        <div class="card-body">
          <div id="refList" class="small text-muted">Elegí un proveedor para ver sus comprobantes pendientes.</div>
        </div>
      </div>

    </div>
    <div class="cp-col-right">

      <!-- Productos (entra a stock) -->
      <div class="card fc-card mb-2" id="cardProd" style="display:none">
        <div class="card-header"><i class="bi bi-box-seam me-1"></i>Productos (entra a stock) <span class="small text-muted">— el neto gravado sale de estas líneas</span></div>
        <div class="card-body">
          <div class="row g-2 align-items-end mb-2">
            <div class="col" style="min-width:160px"><label class="form-label mb-1 small">Producto</label>
              <div class="ac-box"><input type="text" id="prodQ" class="form-control form-control-sm" placeholder="Código o denominación…" autocomplete="off"><div class="ac-list" id="prodList"></div></div>
              <input type="hidden" id="prodCod"><input type="hidden" id="prodFct" value="1"></div>
            <div class="col-auto" style="width:96px"><label class="form-label mb-1 small">Cód.Prv.</label><input type="text" id="prodExt" class="form-control form-control-sm" autocomplete="off" title="Código del proveedor para este producto (EXTPRO)"></div>
            <div class="col-auto" style="width:62px"><label class="form-label mb-1 small">Mon</label><select id="prodMon" class="form-select form-select-sm"><option value="P">$</option><option value="D">u$s</option></select></div>
            <div class="col-auto" style="width:78px"><label class="form-label mb-1 small">Flete</label><input type="number" step="0.0001" id="prodFlt" class="form-control form-control-sm cp-num" value="0"></div>
            <div class="col-auto" style="width:90px"><label class="form-label mb-1 small" id="lblCos">Costo</label><input type="number" step="0.0001" id="prodCos" class="form-control form-control-sm cp-num"></div>
            <div class="col-auto" style="width:88px"><label class="form-label mb-1 small" id="lblLis">Lista</label><input type="number" step="0.0001" id="prodLis" class="form-control form-control-sm cp-num" value="0"></div>
            <div class="col-auto" style="width:60px"><label class="form-label mb-1 small">Bon %</label><input type="number" step="0.01" id="prodBon" class="form-control form-control-sm cp-num" value="0"></div>
            <div class="col-auto" style="width:78px"><label class="form-label mb-1 small">Cantidad</label><input type="number" step="0.01" id="prodCant" class="form-control form-control-sm cp-num"></div>
            <div class="col-auto"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="prodApv"><label class="form-check-label small" for="prodApv" title="Actualizar Precio de Venta">P</label></div></div>
            <div class="col-auto"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="prodStk" checked><label class="form-check-label small" for="prodStk">Stk</label></div></div>
            <div class="col-auto"><button type="button" id="btnAddProd" class="btn btn-sm btn-outline-primary mt-3"><i class="bi bi-plus-lg"></i></button></div>
          </div>
          <div class="table-responsive">
          <table class="table table-sm mb-0" style="white-space:nowrap"><thead><tr><th>Producto</th><th>Cód.Prv.</th><th style="width:26px" title="Declara (DECPRO)">D</th><th>Unidad</th><th>Mon</th><th class="cp-num" style="width:62px">Flete</th><th class="cp-num" style="width:80px">Costo</th><th class="cp-num" style="width:84px">Costo $</th><th class="cp-num" style="width:74px">Lista</th><th class="cp-num" style="width:54px">Bon %</th><th class="cp-num" style="width:64px">Cant</th><th style="width:24px" title="Act.P.Vta">P</th><th class="cp-num" style="width:96px">Neto</th><th style="width:34px">Stk</th><th style="width:28px"></th></tr></thead><tbody id="prodBody"></tbody></table>
          </div>
        </div>
      </div>

      <!-- Imputación contable (Debe) — multi-fila -->
      <div class="card fc-card mb-2">
        <div class="card-header d-flex align-items-center">
          <span><i class="bi bi-diagram-3 me-1"></i>Imputación contable (a acreditar) <span class="small text-muted">— revierte gasto + IVA</span></span>
          <span class="small ms-auto">Imputado <b id="impSum" class="cp-num">0.00</b> / Total <b id="impTot" class="cp-num">0.00</b> <span id="impOk"></span></span>
        </div>
        <div class="card-body">
          <div class="row g-2 align-items-end mb-2">
            <div class="col"><label class="form-label mb-1 small">Cuenta</label>
              <div class="ac-box"><input type="text" id="impCtaQ" class="form-control form-control-sm" placeholder="Código o denominación…" autocomplete="off"><div class="ac-list" id="impCtaList"></div></div>
              <input type="hidden" id="impCta"></div>
            <div class="col-md-3"><label class="form-label mb-1 small">Centro de costo</label><select id="impCdc" class="form-select form-select-sm"></select></div>
            <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">A acreditar</label><input type="number" step="0.01" id="impDeb" class="form-control form-control-sm cp-num"></div>
            <div class="col-auto"><button type="button" id="btnAddImp" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> Agregar</button></div>
            <div class="col-auto"><button type="button" id="btnSugIva" class="btn btn-sm btn-outline-secondary" title="Agrega la fila de IVA Crédito Fiscal por el IVA del comprobante">+ IVA Crédito</button></div>
          </div>
          <table class="table table-sm mb-0"><thead><tr><th>Cuenta</th><th>Centro de costo</th><th class="cp-num" style="width:160px">A acreditar</th><th style="width:36px"></th></tr></thead><tbody id="impBody"></tbody></table>
        </div>
      </div>

    </div>
  </div>

  <div class="text-danger small mt-2" id="cpErr"></div>
</div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<!-- Modal: buscar / ver Comprobantes a Pagar emitidos -->
<div class="modal fade" id="modalBuscarCP" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2"><h6 class="modal-title mb-0"><i class="bi bi-search me-2"></i>Notas de Débito Acreedoras emitidas</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2 align-items-end mb-2">
          <div class="col"><label class="form-label mb-1 small">Buscar (proveedor · CUIT · número)</label><input id="bqQ" class="form-control form-control-sm" placeholder="Texto o número…" autocomplete="off"></div>
          <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">Desde</label><input type="date" id="bqDesde" class="form-control form-control-sm"></div>
          <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">Hasta</label><input type="date" id="bqHasta" class="form-control form-control-sm"></div>
          <div class="col-auto"><button type="button" id="btnBQ" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Buscar</button></div>
        </div>
        <div class="table-responsive" style="max-height:280px; overflow:auto">
          <table class="table table-sm table-hover mb-0"><thead><tr><th>Número</th><th>Fecha</th><th>Proveedor</th><th>Comp. proveedor</th><th class="text-end">Total</th></tr></thead><tbody id="bqBody"></tbody></table>
        </div>
        <div id="bqDetalle" class="mt-2"></div>
      </div>
    </div>
  </div>
</div>

<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/nd_acr.js?v=1"></script>
'); ?>
