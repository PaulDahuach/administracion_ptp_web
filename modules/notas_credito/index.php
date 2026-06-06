<?php
/** Notas de Crédito (deudores) — emisión electrónica (NC clase A, CAE de AFIP). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../config/afip.php';
auth_require_login();

if (db_readonly()) {
    module_head('Notas de Crédito', 'bi-file-earmark-minus');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La emisión requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}

$modoAfip = AFIP_MODO;
$capa = (auth_modo() === 'capacitacion');
$btnLbl = $capa ? '<i class="bi bi-mortarboard me-1"></i>Grabar NC (capacitación)' : '<i class="bi bi-cloud-arrow-up me-1"></i>Emitir NC (AFIP)';
$toolbar = '<button id="btnEmitir" class="btn btn-success btn-sm">' . $btnLbl . '</button>'
         . ' <button id="btnImprimirHdr" class="btn btn-primary btn-sm" style="display:none"><i class="bi bi-printer me-1"></i>Imprimir</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>';
module_head('Notas de Crédito — Deudores', 'bi-file-earmark-minus', $toolbar);
?>
<style>
  .nc-grid th, .nc-grid td { font-size:.82rem; vertical-align:middle; }
  .nc-num { text-align:right; font-variant-numeric:tabular-nums; }
  .ac-box { position:relative; }
  .ac-list { position:absolute; z-index:1080; left:0; right:0; top:100%; max-height:240px; overflow:auto; background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:.375rem; display:none; }
  .ac-list.show { display:block; }
  .ac-opt { padding:.25rem .6rem; cursor:pointer; font-size:.85rem; }
  .ac-opt.active, .ac-opt:hover { background:var(--fc-primary); color:#fff; }
  .tot-bar { display:flex; gap:1rem; flex-wrap:wrap; justify-content:flex-end; }
  .tot-bar .t { background:var(--bs-tertiary-bg); border-radius:.4rem; padding:.4rem .7rem; min-width:120px; }
  .tot-bar .t .lbl { font-size:.66rem; text-transform:uppercase; color:var(--bs-secondary-color); }
  .tot-bar .t .val { font-weight:700; font-variant-numeric:tabular-nums; }
  .nc-ro { font-variant-numeric:tabular-nums; font-weight:600; }
  .cae-box { font-family:monospace; }
</style>

<div class="fc-form" id="ncForm">
  <div class="card fc-card mb-2"><div class="card-body">
    <?php if ($capa): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-mortarboard me-1"></i>Modo <b>capacitación</b> (negro) — la NC se graba <b>sin CAE</b> (no fiscal).</div>
    <?php elseif ($modoAfip !== 'produccion'): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-cone-striped me-1"></i>AFIP en <b>homologación</b> (testing) — los CAE no son fiscales.</div><?php endif; ?>
    <div class="row g-2">
      <div class="col-auto" style="width:115px"><label class="form-label mb-1">Movimiento Nº</label><input id="nummov" class="form-control nc-ro" placeholder="(auto)" readonly></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1">Emisión</label><input type="date" id="fexmov" class="form-control"></div>
      <div class="col"><label class="form-label mb-1">Cliente (cuenta corriente)</label>
        <div class="ac-box"><input type="text" id="cliQ" class="form-control" placeholder="Nombre o código…" autocomplete="off"><div class="ac-list" id="cliList"></div></div>
        <input type="hidden" id="codcue"><div class="small text-muted mt-1" id="cliInfo"></div></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1">Saldo</label><input id="saldo" class="form-control nc-num" readonly></div>
      <div class="col-auto" style="width:200px"><label class="form-label mb-1">Nota de Crédito Nº</label>
        <div class="d-flex gap-1"><input id="letra" class="form-control text-center px-1 nc-ro" style="flex:0 0 34px" value="A" readonly title="Clase">
          <input class="form-control text-center px-1 nc-ro" style="flex:0 0 46px" value="<?= str_pad((string) ($capa ? 9999 : AFIP_PTO_VTA), 4, '0', STR_PAD_LEFT) ?>" readonly title="Pto venta">
          <input id="cinmov" class="form-control nc-ro" placeholder="(AFIP)" readonly title="Nº"></div></div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-md-4"><label class="form-label mb-1">Concepto</label><select id="codaux" class="form-select"></select></div>
      <div class="col-md-2"><label class="form-label mb-1">Neto <span id="lblNeto">gravado</span></label><input type="number" step="0.01" id="netmov" class="form-control nc-num" value="0"></div>
      <div class="col"><label class="form-label mb-1">Detalle</label><input id="detmov" class="form-control" placeholder="Ej.: BONIF PRONTO PAGO FAC 6079"></div>
    </div>
    <div class="mt-2" id="caeWrap" style="display:none"><span class="badge bg-success">CAE <span class="cae-box" id="caeDisp"></span></span> <span class="text-muted small">vto <span id="caeVto"></span> · Comprobante Autorizado</span></div>
  </div></div>

  <!-- Referencias: FV/ND que acredita -->
  <div class="card fc-card mb-2">
    <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-link-45deg me-1"></i>Comprobantes que acredita (referencias)</span>
      <button type="button" id="btnAddRef" class="btn btn-sm btn-outline-light" disabled><i class="bi bi-plus-lg me-1"></i>Agregar referencia</button></div>
    <div class="card-body p-0 table-responsive"><table class="table table-sm nc-grid mb-0">
      <thead><tr><th style="width:170px">Comprobante</th><th style="width:110px">Vencimiento</th><th>Detalle</th><th class="nc-num" style="width:130px">Saldo</th><th class="nc-num" style="width:140px">A acreditar</th><th style="width:36px"></th></tr></thead>
      <tbody id="refBody"></tbody>
      <tfoot><tr class="fw-bold"><td colspan="4" class="text-end">Total referencias:</td><td class="nc-num" id="refTotal">0.00</td><td></td></tr></tfoot>
    </table></div>
    <div class="card-footer small text-muted">Las referencias deben sumar el total de la NC. El saldo no aplicado queda como crédito a favor del cliente.</div>
  </div>

  <div class="card fc-card"><div class="card-body tot-bar">
    <div class="t"><div class="lbl">Neto</div><div class="val" id="tNeto">0.00</div></div>
    <div class="t" id="boxIva"><div class="lbl">I.V.A. <span id="lblAli">21%</span></div><div class="val" id="tIva">0.00</div></div>
    <div class="t" style="background:var(--fc-primary);color:#fff"><div class="lbl" style="color:#fff">Total Crédito</div><div class="val" id="tTotal">0.00</div></div>
  </div></div>
  <div class="text-danger small mt-2" id="ncErr"></div>
</div>

<!-- MODAL REFERENCIAS -->
<div class="modal fade" id="modalRef" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Comprobantes pendientes del cliente</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><table class="table table-sm table-hover mb-0"><thead><tr><th>Comprobante</th><th>Emisión</th><th>Vencimiento</th><th>Detalle</th><th class="nc-num">Saldo</th><th></th></tr></thead><tbody id="pendBody"></tbody></table>
    <div id="pendVacio" class="text-muted text-center py-3" style="display:none">El cliente no tiene comprobantes pendientes.</div></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/nc.js?v=1"></script>
'); ?>
