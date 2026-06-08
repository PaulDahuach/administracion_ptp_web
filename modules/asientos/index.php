<?php
/** Asientos contables manuales (Imputaciones Contables) — carga de asiento manual (Fase 1). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

if (db_readonly()) {
    module_head('Imputaciones Contables', 'bi-journal-bookmark-fill');
    echo '<div class="alert alert-warning">El sistema está en modo <b>sólo lectura</b>. La carga requiere <code>mode=readwrite</code>.</div>';
    module_foot(); exit;
}

$opOpts = '<option value="">— elegí la operación —</option>';
foreach (db_query("SELECT CODOPE, DENOPE FROM [Tbl Operaciones] WHERE CODORI='I' ORDER BY DENOPE;") as $o)
    $opOpts .= '<option value="' . (int) $o['CODOPE'] . '">' . htmlspecialchars(trim((string) nz($o['DENOPE'], ''))) . '</option>';
$cdcOpts = '';
foreach (db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo] ORDER BY DENCDC;") as $c)
    $cdcOpts .= '<option value="' . (int) $c['CODCDC'] . '">' . htmlspecialchars(trim((string) nz($c['DENCDC'], ''))) . '</option>';
$banOpts = '<option value="">— banco —</option>';
foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos] ORDER BY DENBAN;") as $b)
    $banOpts .= '<option value="' . (int) $b['CODBAN'] . '">' . htmlspecialchars(trim((string) nz($b['DENBAN'], ''))) . '</option>';
$_rc = db_row("SELECT CACC_2, CACC_3, CACC_V, CACC_D, ALIIVA FROM [Rec Control];");
$vadPref  = trim((string) nz($_rc['CACC_2'], ''));   // prefijo "valores a depositar" (cheques de terceros)
$bankPref = trim((string) nz($_rc['CACC_3'], ''));   // prefijo cuentas bancarias (cheque propio)
$difPref  = trim((string) nz($_rc['CACC_V'], ''));   // prefijo cuentas posdatados (cheque diferido)
$ivaAcct  = trim((string) nz($_rc['CACC_D'], ''));   // cuenta IVA crédito fiscal (para imputar el IVA de la OP Contado)
$aliDef   = (float) nz($_rc['ALIIVA'], 21);          // alícuota IVA por defecto
$criOpts = '<option value="">— categoría IVA —</option>';
foreach (db_query("SELECT CODCRI, DENCRI FROM [Tbl Categorias Responsabilidad IVA] ORDER BY CODCRI;") as $c)
    $criOpts .= '<option value="' . (int) $c['CODCRI'] . '">' . htmlspecialchars(trim((string) nz($c['DENCRI'], ''))) . '</option>';

$capa = (auth_modo() === 'capacitacion');
$btnLbl = $capa ? '<i class="bi bi-mortarboard me-1"></i>Grabar asiento (capacitación)' : '<i class="bi bi-save me-1"></i>Grabar asiento';
$toolbar = '<button id="btnGrabar" class="btn btn-success btn-sm">' . $btnLbl . '</button>'
         . ' <button id="btnAnularHdr" class="btn btn-danger btn-sm" style="display:none"><i class="bi bi-x-octagon me-1"></i>Anular</button>'
         . ' <button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>'
         . ' <button id="btnNuevo" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nuevo</button>';
module_head('Imputaciones Contables', 'bi-journal-bookmark-fill', $toolbar);
?>
<style>
  .as-num { text-align:right; font-variant-numeric:tabular-nums; }
  .ac-box { position:relative; }
  .ac-list { position:absolute; z-index:1080; left:0; right:0; top:100%; max-height:240px; overflow:auto; background:var(--bs-body-bg); border:1px solid var(--bs-border-color); border-radius:.375rem; display:none; }
  .ac-list.show { display:block; }
  .ac-opt { padding:.25rem .6rem; cursor:pointer; font-size:.85rem; }
  .ac-opt.active, .ac-opt:hover { background:var(--fc-primary); color:#fff; }
  .as-ro { font-variant-numeric:tabular-nums; font-weight:600; }
  #asForm .form-label { margin-bottom:.05rem; }
</style>

<div id="roBanner" class="alert alert-info py-1 px-2 small mb-2" style="display:none"></div>
<div class="fc-form" id="asForm" data-vadpref="<?= htmlspecialchars($vadPref, ENT_QUOTES) ?>" data-bankpref="<?= htmlspecialchars($bankPref, ENT_QUOTES) ?>" data-difpref="<?= htmlspecialchars($difPref, ENT_QUOTES) ?>" data-iva-acct="<?= htmlspecialchars($ivaAcct, ENT_QUOTES) ?>">
  <div class="card fc-card mb-2"><div class="card-body">
    <?php if ($capa): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-mortarboard me-1"></i>Modo <b>capacitación</b> — el asiento se graba en el libro de capacitación.</div><?php endif; ?>
    <div class="row g-2 align-items-end">
      <div class="col-auto" style="width:115px"><label class="form-label mb-1 small">Movimiento Nº</label><input id="nummov" class="form-control form-control-sm as-ro" placeholder="(auto)" readonly></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1 small">Emisión</label><input type="date" id="fexmov" class="form-control form-control-sm"></div>
      <div class="col-auto" style="width:115px"><label class="form-label mb-1 small">Número</label><input id="cinmov" class="form-control form-control-sm as-ro" placeholder="(auto)" readonly></div>
      <div class="col-auto" style="min-width:230px"><label class="form-label mb-1 small">Operación</label><select id="codope" class="form-select form-select-sm"><?= $opOpts ?></select></div>
      <div class="col" style="min-width:200px"><label class="form-label mb-1 small">Detalle</label><input id="detmov" class="form-control form-control-sm" placeholder="Detalle del asiento" autocomplete="off"></div>
    </div>
  </div></div>

  <div class="card fc-card mb-2" id="compCard" style="display:none"><div class="card-body">
    <div class="text-uppercase text-muted mb-2" style="font-size:.7rem;letter-spacing:.04em"><i class="bi bi-receipt me-1"></i>Comprobante del proveedor (con IVA)</div>
    <div class="row g-2 align-items-end mb-2">
      <div class="col-auto" style="width:215px"><label class="form-label mb-1 small">Tipo de operación</label><select id="compAux" class="form-select form-select-sm"></select></div>
      <div class="col-auto" style="width:65px"><label class="form-label mb-1 small">Comp.</label><input id="compCec" class="form-control form-control-sm text-uppercase" value="FC" maxlength="2"></div>
      <div class="col-auto" style="width:55px"><label class="form-label mb-1 small">Letra</label><input id="compCei" class="form-control form-control-sm text-uppercase" maxlength="1"></div>
      <div class="col-auto" style="width:80px"><label class="form-label mb-1 small">PDV</label><input type="number" id="compCep" class="form-control form-control-sm as-num" value="0"></div>
      <div class="col-auto" style="width:120px"><label class="form-label mb-1 small">Número</label><input type="number" id="compCen" class="form-control form-control-sm as-num"></div>
      <div class="col-auto" style="width:140px"><label class="form-label mb-1 small">Fecha comp.</label><input type="date" id="compCef" class="form-control form-control-sm"></div>
    </div>
    <div class="row g-2 align-items-end mb-2">
      <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">CUIT</label><input id="compCit" class="form-control form-control-sm" placeholder="00-00000000-0" autocomplete="off"></div>
      <div class="col" style="min-width:200px"><label class="form-label mb-1 small">Denominación</label><input id="compDen" class="form-control form-control-sm" placeholder="Razón social del proveedor" autocomplete="off"></div>
      <div class="col-auto" style="width:185px"><label class="form-label mb-1 small">Categoría IVA</label><select id="compCri" class="form-select form-select-sm"><?= $criOpts ?></select></div>
    </div>
    <div class="row g-2 align-items-end mb-1" id="compIvaRow">
      <div class="col-auto" style="width:125px"><label class="form-label mb-1 small">Neto gravado 1</label><input type="number" step="0.01" id="compNet1" class="form-control form-control-sm as-num"></div>
      <div class="col-auto" style="width:85px"><label class="form-label mb-1 small">Alíc. %</label><input type="number" step="0.01" id="compAli1" class="form-control form-control-sm as-num" value="<?= $aliDef ?>"></div>
      <div class="col-auto" style="width:120px"><label class="form-label mb-1 small">IVA 1</label><input id="compIva1" class="form-control form-control-sm as-num as-ro" readonly></div>
      <div class="col-auto" style="width:125px"><label class="form-label mb-1 small">Neto gravado 2</label><input type="number" step="0.01" id="compNet2" class="form-control form-control-sm as-num"></div>
      <div class="col-auto" style="width:85px"><label class="form-label mb-1 small">Alíc. %</label><input type="number" step="0.01" id="compAli2" class="form-control form-control-sm as-num"></div>
      <div class="col-auto" style="width:120px"><label class="form-label mb-1 small">IVA 2</label><input id="compIva2" class="form-control form-control-sm as-num as-ro" readonly></div>
    </div>
    <div class="row g-2 align-items-end">
      <div class="col-auto" style="width:125px"><label class="form-label mb-1 small">No gravado</label><input type="number" step="0.01" id="compNog" class="form-control form-control-sm as-num"></div>
      <div class="col-auto" style="width:125px"><label class="form-label mb-1 small">Percep. IVA</label><input type="number" step="0.01" id="compIp1" class="form-control form-control-sm as-num"></div>
      <div class="col-auto" style="width:125px"><label class="form-label mb-1 small">Percep. IIBB</label><input type="number" step="0.01" id="compIp2" class="form-control form-control-sm as-num"></div>
      <div class="col-auto" style="width:145px"><label class="form-label mb-1 small">Total comprobante</label><input id="compTot" class="form-control form-control-sm as-num as-ro fw-bold" readonly></div>
      <div class="col-auto"><button type="button" id="btnImpIva" class="btn btn-sm btn-outline-secondary mt-3" title="Agrega a la imputación las líneas de IVA crédito fiscal y percepciones"><i class="bi bi-calculator me-1"></i>Imputar IVA/percep.</button></div>
    </div>
  </div></div>

  <div class="card fc-card mb-2"><div class="card-body">
    <div class="text-uppercase text-muted mb-2" style="font-size:.7rem;letter-spacing:.04em"><i class="bi bi-list-columns-reverse me-1"></i>Imputaciones (asiento)</div>
    <div class="row g-2 align-items-end mb-2">
      <div class="col" style="min-width:230px"><label class="form-label mb-1 small">Cuenta contable</label>
        <div class="ac-box"><input type="text" id="asCtaQ" class="form-control form-control-sm" placeholder="Código o denominación… (imputables)" autocomplete="off"><div class="ac-list" id="asCtaList"></div></div>
        <input type="hidden" id="asCta"></div>
      <div class="col-auto" style="width:185px"><label class="form-label mb-1 small">Centro de costo</label><select id="asCdc" class="form-select form-select-sm"><?= $cdcOpts ?></select></div>
      <div class="col-auto" style="width:120px"><label class="form-label mb-1 small">Debe</label><input type="number" step="0.01" id="asDebe" class="form-control form-control-sm as-num"></div>
      <div class="col-auto" style="width:120px"><label class="form-label mb-1 small">Haber</label><input type="number" step="0.01" id="asHaber" class="form-control form-control-sm as-num"></div>
      <div class="col-auto"><button type="button" id="btnAddImp" class="btn btn-sm btn-outline-primary mt-3"><i class="bi bi-plus-lg"></i></button></div>
    </div>
    <div class="row g-2 align-items-end mb-2" id="chqRow" style="display:none">
      <div class="col-12"><div class="text-uppercase text-muted" id="chqRowLabel" style="font-size:.64rem;letter-spacing:.04em"></div></div>
      <div class="col-auto" style="width:200px" id="chqColBan"><label class="form-label mb-1 small">Banco</label><select id="chqBan" class="form-select form-select-sm"><?= $banOpts ?></select></div>
      <div class="col-auto" style="width:200px;display:none" id="chqColBanInfo"><label class="form-label mb-1 small">Banco (de la cuenta)</label><div id="chqBanInfo" class="form-control form-control-sm as-ro"></div></div>
      <div class="col-auto" style="width:120px"><label class="form-label mb-1 small">Número</label><input id="chqSyn" class="form-control form-control-sm" autocomplete="off"></div>
      <div class="col-auto" style="width:135px"><label class="form-label mb-1 small">Emisión</label><input type="date" id="chqFde" class="form-control form-control-sm"></div>
      <div class="col-auto" style="width:70px" id="chqColPlz"><label class="form-label mb-1 small">Plaza</label><input type="number" id="chqPlz" class="form-control form-control-sm as-num" value="0"></div>
      <div class="col-auto" style="width:135px"><label class="form-label mb-1 small">Acreditación</label><input type="date" id="chqFda" class="form-control form-control-sm"></div>
      <div class="col" style="min-width:140px" id="chqColLib"><label class="form-label mb-1 small">Librador</label><input id="chqLib" class="form-control form-control-sm" autocomplete="off"></div>
      <div class="col-auto" style="width:130px" id="chqColCit"><label class="form-label mb-1 small">CUIT</label><input id="chqCit" class="form-control form-control-sm" autocomplete="off"></div>
      <div class="col-auto" style="width:130px" id="chqColLoc"><label class="form-label mb-1 small">Localidad</label><input id="chqLoc" class="form-control form-control-sm" autocomplete="off"></div>
      <div class="col-auto"><span id="chqEstado" class="badge bg-secondary mt-3" style="display:none"></span></div>
    </div>
    <table class="table table-sm mb-0"><thead><tr><th>Cuenta contable</th><th>Centro de costo</th><th class="as-num" style="width:140px">Debe</th><th class="as-num" style="width:140px">Haber</th><th style="width:32px"></th></tr></thead>
      <tbody id="impBody"></tbody>
      <tfoot>
        <tr class="fw-bold"><td colspan="2" class="text-end">TOTALES</td><td class="as-num" id="totDebe">0</td><td class="as-num" id="totHaber">0</td><td></td></tr>
        <tr><td colspan="2" class="text-end small text-muted">Diferencia (Debe − Haber)</td><td class="as-num" id="totDif">0</td><td id="balInd"></td><td></td></tr>
      </tfoot>
    </table>
  </div></div>

  <div class="text-danger small mt-2" id="asErr"></div>
</div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<!-- Modal: buscar / ver asientos -->
<div class="modal fade" id="modalBuscar" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2"><h6 class="modal-title mb-0"><i class="bi bi-search me-2"></i>Asientos contables</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2 align-items-end mb-2">
          <div class="col"><label class="form-label mb-1 small">Buscar (detalle · número)</label><input id="bqQ" class="form-control form-control-sm" placeholder="Texto o número…" autocomplete="off"></div>
          <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">Desde</label><input type="date" id="bqDesde" class="form-control form-control-sm"></div>
          <div class="col-auto" style="width:150px"><label class="form-label mb-1 small">Hasta</label><input type="date" id="bqHasta" class="form-control form-control-sm"></div>
          <div class="col-auto"><button type="button" id="btnBQ" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Buscar</button></div>
        </div>
        <div class="table-responsive" style="max-height:320px; overflow:auto">
          <table class="table table-sm table-hover mb-0"><thead><tr><th>Número</th><th>Fecha</th><th>Operación</th><th>Detalle</th><th class="text-end">Total</th></tr></thead><tbody id="bqBody"></tbody></table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/asientos.js?v=5"></script>
'); ?>
