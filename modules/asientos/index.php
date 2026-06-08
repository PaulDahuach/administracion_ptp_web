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
$cbxOpts = '<option value="">— cuenta bancaria —</option>';   // CODCBX: cuentas bancarias (Depósito Bancario)
foreach (db_query("SELECT CODCBX, DENCBX FROM [Tbl Cuentas Bancarias] ORDER BY DENCBX;") as $b)
    $cbxOpts .= '<option value="' . (int) $b['CODCBX'] . '">' . htmlspecialchars(trim((string) nz($b['DENCBX'], ''))) . '</option>';

$capa = (auth_modo() === 'capacitacion');
$btnLbl = $capa ? '<i class="bi bi-mortarboard me-1"></i>Grabar asiento (capacitación)' : '<i class="bi bi-save me-1"></i>Grabar asiento';
$toolbar = '<button id="btnGrabar" class="btn btn-success btn-sm">' . $btnLbl . '</button>'
         . ' <button id="btnAnularHdr" class="btn btn-danger btn-sm" style="display:none"><i class="bi bi-x-octagon me-1"></i>Anular</button>'
         . ' <button id="btnConstancia" class="btn btn-outline-light btn-sm" style="display:none"><i class="bi bi-printer me-1"></i>Constancia</button>'
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
  #compCard .imp-grp { border:1px solid var(--bs-border-color); border-radius:.45rem; padding:.4rem .65rem .55rem; }
  #compCard .imp-grp-h { font-size:.66rem; text-transform:uppercase; letter-spacing:.03em; color:var(--bs-secondary-color); font-weight:600; margin-bottom:.35rem; }
</style>

<div id="roBanner" class="alert alert-info py-1 px-2 small mb-2" style="display:none"></div>
<div class="fc-form" id="asForm" data-keynav data-keynav-submit="#btnGrabar" data-vadpref="<?= htmlspecialchars($vadPref, ENT_QUOTES) ?>" data-bankpref="<?= htmlspecialchars($bankPref, ENT_QUOTES) ?>" data-difpref="<?= htmlspecialchars($difPref, ENT_QUOTES) ?>" data-iva-acct="<?= htmlspecialchars($ivaAcct, ENT_QUOTES) ?>">
  <div class="card fc-card mb-2"><div class="card-body">
    <?php if ($capa): ?><div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-mortarboard me-1"></i>Modo <b>capacitación</b> — el asiento se graba en el libro de capacitación.</div><?php endif; ?>
    <div class="row g-2 align-items-end">
      <div class="col-auto" style="width:105px"><label class="form-label mb-1 small">Movimiento Nº</label><input id="nummov" class="form-control form-control-sm as-ro" placeholder="(auto)" readonly></div>
      <div class="col-auto" style="width:135px"><label class="form-label mb-1 small">Emisión</label><input type="date" id="fexmov" class="form-control form-control-sm"></div>
      <div class="col-auto" style="width:210px"><label class="form-label mb-1 small">Operación</label><select id="codope" class="form-select form-select-sm"><?= $opOpts ?></select></div>
      <div class="col-auto" style="width:200px"><label class="form-label mb-1 small">Tipo (auxiliar)</label><select id="compAux" class="form-select form-select-sm" disabled></select></div>
      <div class="col" style="min-width:175px"><label class="form-label mb-1 small">Cuenta corriente</label>
        <div class="ac-box"><input type="text" id="asCueQ" class="form-control form-control-sm" autocomplete="off" disabled placeholder="(según operación)"><div class="ac-list" id="asCueList"></div></div>
        <input type="hidden" id="asCue"></div>
      <div class="col-auto" style="width:185px"><label class="form-label mb-1 small">Cuenta bancaria</label><select id="asCbx" class="form-select form-select-sm" disabled><?= $cbxOpts ?></select></div>
      <div class="col-auto" style="width:175px"><label class="form-label mb-1 small">Modelo</label><select id="asMod" class="form-select form-select-sm" disabled><option value="">—</option></select></div>
    </div>
    <div class="row g-2 align-items-end mt-1">
      <div class="col-auto"><label class="form-label mb-1 small">Comprobante interno</label>
        <div class="d-flex align-items-end" style="gap:.25rem">
          <input id="cicmov" class="form-control form-control-sm as-ro text-center px-1" style="width:46px" readonly title="Tipo (CICMOV)">
          <input id="ciimov" class="form-control form-control-sm as-ro text-center px-1" style="width:36px" readonly title="Letra (CIIMOV)">
          <input id="cipmov" class="form-control form-control-sm as-ro text-center px-1" style="width:46px" readonly title="Punto de venta (CIPMOV)">
          <input id="cinmov" class="form-control form-control-sm as-ro" style="width:95px" placeholder="(auto)" readonly title="Número (CINMOV)">
        </div></div>
      <div class="col-auto pb-1"><div class="d-flex flex-column" style="gap:.05rem">
        <div class="form-check m-0 small"><input class="form-check-input" type="checkbox" id="chkPrn" checked disabled><label class="form-check-label" for="chkPrn">Imprime</label></div>
        <div class="form-check m-0 small"><input class="form-check-input" type="checkbox" id="chkNum" checked disabled><label class="form-check-label" for="chkNum">Numera auto</label></div>
      </div></div>
      <div class="col-auto border-start ps-2" id="compExtRow" style="display:none"><label class="form-label mb-1 small">Comprobante externo (proveedor)</label>
        <div class="d-flex flex-wrap align-items-end" style="gap:.25rem">
          <input id="compCit" class="form-control form-control-sm" style="width:128px" placeholder="C.U.I.T." autocomplete="off" title="C.U.I.T. (CITMOV)">
          <select id="compCri" class="form-select form-select-sm" style="width:148px" title="Categoría IVA (CODCRI)"><?= $criOpts ?></select>
          <input id="compDen" class="form-control form-control-sm" style="width:180px" placeholder="Denominación" autocomplete="off" title="Denominación (DENMOV)">
          <select id="compCec" class="form-select form-select-sm" style="width:60px" title="Código (CECMOV)"><option value="FC">FC</option><option value="NC">NC</option><option value="ND">ND</option><option value="T">T</option></select>
          <select id="compCei" class="form-select form-select-sm" style="width:52px" title="Letra (CEIMOV)"><option>A</option><option>B</option><option>C</option><option>M</option></select>
          <input type="number" id="compCep" class="form-control form-control-sm as-num" style="width:58px" placeholder="PDV" value="0" title="Punto de venta (CEPMOV)">
          <input type="number" id="compCen" class="form-control form-control-sm as-num" style="width:95px" placeholder="Número" title="Número (CENMOV)">
          <input type="date" id="compCef" class="form-control form-control-sm" style="width:128px" title="Emisión (CEFMOV)">
          <input type="date" id="compFix" class="form-control form-control-sm" style="width:128px" title="Imputación IVA (FIXMOV)">
        </div></div>
    </div>
  </div></div>

  <div class="card fc-card mb-2" id="compCard"><div class="card-body">
    <div class="d-flex flex-wrap align-items-start" style="gap:.85rem">
      <div style="width:200px"><label class="form-label mb-1 small">Detalle</label><textarea id="detmov" class="form-control form-control-sm" rows="3" placeholder="Detalle del asiento (memo)"></textarea></div>
      <div class="flex-wrap align-items-start" id="compIvaRow" style="gap:.85rem; display:none">
      <div class="imp-grp">
        <div class="imp-grp-h">Gravado</div>
        <div class="d-flex align-items-end" style="gap:.4rem">
          <div style="width:110px"><label class="form-label mb-1 small">Neto</label><input type="number" step="0.01" id="compNet1" class="form-control form-control-sm as-num"></div>
          <div style="width:62px"><label class="form-label mb-1 small">Alíc.%</label><input type="number" step="0.01" id="compAli1" class="form-control form-control-sm as-num" value="<?= $aliDef ?>"></div>
          <div style="width:100px"><label class="form-label mb-1 small">I.V.A.</label><input id="compIva1" class="form-control form-control-sm as-num as-ro" readonly></div>
        </div>
        <a href="#" id="toggleAli2" class="small d-inline-block mt-1" style="text-decoration:none"><i class="bi bi-plus-square me-1"></i>2ª alícuota</a>
        <div id="compRow2" class="mt-1" style="display:none; gap:.4rem; align-items:flex-end">
          <div style="width:135px"><input type="number" step="0.01" id="compNet2" class="form-control form-control-sm as-num" title="Neto gravado 2"></div>
          <div style="width:70px"><input type="number" step="0.01" id="compAli2" class="form-control form-control-sm as-num" value="10.5"></div>
          <div style="width:120px"><input id="compIva2" class="form-control form-control-sm as-num as-ro" readonly></div>
        </div>
      </div>
      <div class="imp-grp">
        <div class="imp-grp-h">No gravado</div>
        <div style="width:130px"><label class="form-label mb-1 small">Importe</label><input type="number" step="0.01" id="compNog" class="form-control form-control-sm as-num"></div>
      </div>
      <div class="imp-grp">
        <div class="imp-grp-h">Percep. I.V.A.</div>
        <div class="d-flex align-items-end" style="gap:.4rem">
          <div style="width:65px"><label class="form-label mb-1 small">%</label><input type="number" step="0.01" id="compAp1" class="form-control form-control-sm as-num"></div>
          <div style="width:100px"><label class="form-label mb-1 small">$</label><input type="number" step="0.01" id="compIp1" class="form-control form-control-sm as-num"></div>
        </div>
      </div>
      <div class="imp-grp">
        <div class="imp-grp-h">Percep. Ingresos Brutos</div>
        <div class="d-flex align-items-end" style="gap:.4rem">
          <div style="width:65px"><label class="form-label mb-1 small">%</label><input type="number" step="0.01" id="compAp2" class="form-control form-control-sm as-num"></div>
          <div style="width:100px"><label class="form-label mb-1 small">$</label><input type="number" step="0.01" id="compIp2" class="form-control form-control-sm as-num"></div>
        </div>
      </div>
      </div><!-- /compIvaRow (cajas de IVA, gateadas) -->
      <div class="imp-grp ms-auto" style="border-color:var(--fc-primary)">
        <div class="imp-grp-h" style="color:var(--fc-primary)">Total comprobante</div>
        <div class="d-flex align-items-end" style="gap:.45rem">
          <div style="width:118px"><input id="compTot" class="form-control form-control-sm as-num as-ro fw-bold" readonly></div>
          <button type="button" id="btnImpIva" class="btn btn-sm btn-outline-secondary" title="Agrega a la imputación la línea de IVA crédito fiscal"><i class="bi bi-calculator me-1"></i>Imputar IVA</button>
        </div>
      </div>
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
    <div class="mb-2"><button type="button" id="btnAddImp" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Agregar línea</button></div>
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
<script src="assets/js/asientos.js?v=16"></script>
'); ?>
