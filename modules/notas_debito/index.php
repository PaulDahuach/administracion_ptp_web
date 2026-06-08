<?php
/** Notas de Débito (deudores) — emisión electrónica (ND clase A, CAE de AFIP). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../config/afip.php';
auth_require_login();

if (db_readonly()) {
    module_head('Notas de Débito', 'bi-file-earmark-plus');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La emisión requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}

$modoAfip = AFIP_MODO;
$capa = (auth_modo() === 'capacitacion');
$btnLbl = $capa ? '<i class="bi bi-mortarboard me-1"></i>Grabar ND (capacitación)' : '<i class="bi bi-cloud-arrow-up me-1"></i>Emitir ND (AFIP)';
$toolbar = '<button id="btnEmitir" class="btn btn-success btn-sm">' . $btnLbl . '</button>'
         . ' <button id="btnImprimirHdr" class="btn btn-primary btn-sm" style="display:none"><i class="bi bi-printer me-1"></i>Imprimir</button>'
         . ' <button id="btnAnularHdr" class="btn btn-danger btn-sm" style="display:none"><i class="bi bi-x-octagon me-1"></i>Anular</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>';
module_head('Notas de Débito — Deudores', 'bi-file-earmark-plus', $toolbar);
?>
<style>
  .nd-num { text-align:right; font-variant-numeric:tabular-nums; }
  .ac-box { position:relative; }
  .ac-list { position:absolute; z-index:1080; left:0; right:0; top:100%; max-height:240px; overflow:auto; background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:.375rem; display:none; }
  .ac-list.show { display:block; }
  .ac-opt { padding:.25rem .6rem; cursor:pointer; font-size:.85rem; }
  .ac-opt.active, .ac-opt:hover { background:var(--fc-primary); color:#fff; }
  .tot-bar { display:flex; gap:1rem; flex-wrap:wrap; justify-content:flex-end; }
  .tot-bar .t { background:var(--bs-tertiary-bg); border-radius:.4rem; padding:.4rem .7rem; min-width:120px; }
  .tot-bar .t .lbl { font-size:.66rem; text-transform:uppercase; color:var(--bs-secondary-color); }
  .tot-bar .t .val { font-weight:700; font-variant-numeric:tabular-nums; }
  .nd-ro { font-variant-numeric:tabular-nums; font-weight:600; }
  .cae-box { font-family:monospace; }
</style>

<div class="fc-form" id="ndForm">
  <div class="card fc-card mb-2"><div class="card-body">
    <?php if ($capa): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-mortarboard me-1"></i>Modo <b>capacitación</b> — la ND se graba <b>sin CAE</b> (no fiscal).</div>
    <?php elseif ($modoAfip !== 'produccion'): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-cone-striped me-1"></i>AFIP en <b>homologación</b> (testing) — los CAE no son fiscales.</div><?php endif; ?>
    <div class="row g-2">
      <div class="col-auto" style="width:115px"><label class="form-label mb-1">Movimiento Nº</label><input id="nummov" class="form-control nd-ro" placeholder="(auto)" readonly></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1">Emisión</label><input type="date" id="fexmov" class="form-control"></div>
      <div class="col"><label class="form-label mb-1">Cliente (cuenta corriente)</label>
        <div class="ac-box"><input type="text" id="cliQ" class="form-control" placeholder="Nombre o código…" autocomplete="off"><div class="ac-list" id="cliList"></div></div>
        <input type="hidden" id="codcue"><div class="small text-muted mt-1" id="cliInfo"></div></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1">Saldo</label><input id="saldo" class="form-control nd-num" readonly></div>
      <div class="col-auto" style="width:200px"><label class="form-label mb-1">Nota de Débito Nº</label>
        <div class="d-flex gap-1"><input id="letra" class="form-control text-center px-1 nd-ro" style="flex:0 0 34px" value="A" readonly title="Clase">
          <input class="form-control text-center px-1 nd-ro" style="flex:0 0 46px" value="<?= str_pad((string) ($capa ? 9999 : AFIP_PTO_VTA), 4, '0', STR_PAD_LEFT) ?>" readonly title="Pto venta">
          <input id="cinmov" class="form-control nd-ro" placeholder="(AFIP)" readonly title="Nº"></div></div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-md-4"><label class="form-label mb-1">Concepto</label><select id="codaux" class="form-select"></select></div>
      <div class="col-md-2"><label class="form-label mb-1">Neto <span id="lblNeto">gravado</span></label><input type="number" step="0.01" id="netmov" class="form-control nd-num" value="0"></div>
      <div class="col"><label class="form-label mb-1">Detalle</label><input id="detmov" class="form-control" placeholder="Ej.: DIF DE COTIZ OC 7 619*77.9"></div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-auto" style="width:160px"><label class="form-label mb-1">Vencimiento <span class="text-muted small">(a debitar)</span></label><input type="date" id="fvxmov" class="form-control"></div>
      <div class="col"><label class="form-label mb-1">Factura asociada <span class="text-muted small">(opcional — referencia AFIP)</span></label>
        <select id="refFv" class="form-select" disabled><option value="">— ninguna —</option></select></div>
    </div>
    <div class="mt-2" id="caeWrap" style="display:none"><span class="badge bg-success">CAE <span class="cae-box" id="caeDisp"></span></span> <span class="text-muted small">vto <span id="caeVto"></span> · Comprobante Autorizado</span></div>
  </div></div>

  <div class="card fc-card"><div class="card-body tot-bar">
    <div class="t"><div class="lbl">Neto</div><div class="val" id="tNeto">0.00</div></div>
    <div class="t" id="boxIva"><div class="lbl">I.V.A. <span id="lblAli">21%</span></div><div class="val" id="tIva">0.00</div></div>
    <div class="t" id="boxPix" style="display:none"><div class="lbl">Percep. IIBB <span id="lblPix"></span></div><div class="val" id="tPix">0.00</div></div>
    <div class="t" style="background:var(--fc-primary);color:#fff"><div class="lbl" style="color:#fff">Total Débito</div><div class="val" id="tTotal">0.00</div></div>
  </div></div>
  <div class="text-danger small mt-2" id="ndErr"></div>
</div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/nd.js?v=3"></script>
'); ?>
