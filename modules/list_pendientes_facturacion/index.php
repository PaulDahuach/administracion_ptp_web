<?php
/** Listado de Operaciones Pendientes de Facturación (Rpt CD Operaciones Pendientes de Facturacion).
 *  Remitos de venta (CODOPE=410) con SRPMOV=True (no facturados aún), agrupados por Cuenta → Movimiento
 *  (remito) → Producto. Columnas: N° Mov · Comprobante (PDV/Número/Emisión) · Detalle (Producto/O.Corte/
 *  O.Proceso) · Unidad · Cantidad (Remitido/Facturado/Pendiente) · Valorizado Neto $. NIVEL D/S/T.
 *  Filtros: Cuenta (opcional) + período (FEXMOV, default rec_periodo). Modo doble-libro (auth_libro_unico).
 *  qryRemitido = EGRMOV (o −SVCMOV si null) · qryFacturado = CFVMOV · qryPendiente = rem−fac ·
 *  qryTot = PUNMOV·qryPendiente. Geometría/fuentes fieles al .report (twips÷567, portrait Letter). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function pf_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }
function pf_q($v) { return money((float) $v, 2); }   // formato Standard (2 dec, es-AR)
function pf_ord($v) { $n = (int) nz($v, 0); return ($n > 0) ? str_pad((string) $n, 8, '0', STR_PAD_LEFT) : ''; }

// período predeterminado = Rec Control.DESFEC/HASFEC
list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = pf_serial($desIso); $sh = pf_serial($hasIso);
$cue = isset($_GET['cue']) && ctype_digit((string) $_GET['cue']) ? (int) $_GET['cue'] : 0;

$niv = isset($_GET['nivel']) ? strtoupper($_GET['nivel']) : 'D';
if (!in_array($niv, array('D', 'S', 'T'), true)) $niv = 'D';
$nivTxt = array('D' => 'Detalle', 'S' => 'Subtotal', 'T' => 'Total');

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');
$cueW = $cue ? ' AND M.CODCUE=' . $cue : '';

$rows = db_query("SELECT M.NUMMOV, M.CIPMOV, M.CINMOV, M.FEXMOV, CC.DENCUE, M.DETMOV,
    MS.ORDMOV, MS.CODPRO, MS.DENMOV, MS.ODPMOV, MS.ODCMOV, U.DENUDM,
    MS.EGRMOV, MS.SVCMOV, MS.CFVMOV, MS.PUNMOV
    FROM ([Tbl Unidades de Medida] AS U INNER JOIN (([Tbl Cuentas Corrientes] AS CC
      INNER JOIN [Tbl Movimientos] AS M ON CC.CODCUE = M.CODCUE)
      INNER JOIN [Tbl Movimientos Stock] AS MS ON M.NUMMOV = MS.NUMMOV)
      ON U.CODUDM = MS.CODUDM)
    WHERE M.FEXMOV >= $sd AND M.FEXMOV <= $sh AND M.CODOPE=410 AND M.SRPMOV=True$estW$cueW
    ORDER BY CC.DENCUE, M.NUMMOV, MS.ORDMOV;");

// agrupar: cuenta → movimiento (remito) → productos
$acc = array(); $grand = 0.0; $totCount = 0;
foreach ($rows as $r) {
    $den = trim((string) nz($r['DENCUE'], ''));
    $num = (int) $r['NUMMOV'];
    if (!isset($acc[$den])) $acc[$den] = array('movs' => array(), 'sub' => 0.0);
    if (!isset($acc[$den]['movs'][$num])) {
        $acc[$den]['movs'][$num] = array(
            'NUMMOV' => $num,
            'PDV'    => str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT),
            'NUMERO' => str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
            'FEXMOV' => fecha_serial($r['FEXMOV']),
            'DETMOV' => trim((string) nz($r['DETMOV'], '')),
            'prods'  => array(), 'sub' => 0.0,
        );
        $totCount++;
    }
    $egr = $r['EGRMOV'];
    $rem = ($egr !== null && $egr !== '') ? (float) $egr : -(float) nz($r['SVCMOV'], 0);
    $fac = (float) nz($r['CFVMOV'], 0);
    $pen = $rem - $fac;
    $tot = (float) nz($r['PUNMOV'], 0) * $pen;
    $cod = trim((string) nz($r['CODPRO'], ''));
    $dnp = trim((string) nz($r['DENMOV'], ''));
    $acc[$den]['movs'][$num]['prods'][] = array(
        'PROD' => trim($cod . ' ' . $dnp),
        'ODC'  => pf_ord($r['ODCMOV']), 'ODP' => pf_ord($r['ODPMOV']),
        'UDM'  => trim((string) nz($r['DENUDM'], '')),
        'REM'  => $rem, 'FAC' => $fac, 'PEN' => $pen, 'TOT' => $tot,
    );
    $acc[$den]['movs'][$num]['sub'] += $tot;
    $acc[$den]['sub'] += $tot;
    $grand += $tot;
}

$showAcc = ($niv !== 'T');
$showMov = ($niv !== 'T');
$showDet = ($niv === 'D');
$sep     = ($niv === 'D');   // Linea0 (separador entre cuentas) sólo en Detalle

$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$cueDen = '';
if ($cue) { $r = db_row("SELECT DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$cue AND CODORI='D';"); $cueDen = $r ? trim((string) nz($r['DENCUE'], '')) : ''; }
$prov = db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='D' ORDER BY DENCUE;");

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Operaciones Pendientes de Facturación', 'bi-clipboard-x', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<style>
  @media print { @page { size: Letter portrait; margin: 12mm; } .lst-doc { width: auto; box-shadow: none; } }
  /* Rpt CD Operaciones Pendientes de Facturacion — portrait Letter (216mm, interior 19,0cm). Tabla 18,8cm. */
  .pf-doc { font-family: "Univers Condensed", "Arial Narrow", sans-serif; }
  .pf-tbl { width: 18.8cm; border-collapse: collapse; table-layout: fixed; }
  .pf-tbl thead th { font-size: 7pt; font-weight: 400; text-align: center; vertical-align: middle;
    border: 1px solid #000; background: #c0c0c0; line-height: 1.05; padding: 1px 2px; }
  .pf-tbl tbody td { font-size: 8pt; height: .34cm; line-height: .34cm; padding: 0 2px; vertical-align: top;
    white-space: nowrap; overflow: visible; text-overflow: clip; }
  .pf-tbl td.pr { white-space: normal; word-break: break-word; }
  .pf-tbl .r { text-align: right; } .pf-tbl .c { text-align: center; } .pf-tbl .l { text-align: left; }
  .pf-tbl tbody tr.acc td { font-weight: 700; }
  .pf-tbl tbody tr.mov td { font-weight: 700; }
  .pf-tbl tbody tr.acc.sepline td { border-top: 1px solid #000; }   /* Linea0: separador entre cuentas (Detalle) */
  .pf-tbl tfoot td { font-size: 8pt; font-weight: 700; padding: 1px 2px; border-top: 1px solid #000; }
  .lst-fgrid3 .lst-fpair.rc-prov { grid-column: 1 / -1; grid-template-columns: 6.5rem 1fr; }
  .lst-fgrid3 .lst-fpair.rc-prov > select,
  .lst-fgrid3 .lst-fpair.rc-prov > .iwk-combo,
  .lst-fgrid3 .lst-fpair.rc-prov .iwk-combo-input { width: 100%; max-width: 34rem; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair rc-prov"><label>Cuenta Corriente</label>
      <select name="cue" class="form-select form-select-sm">
        <option value="">— Todas —</option>
        <?php foreach ($prov as $c): $cc = (int) $c['CODCUE']; ?>
        <option value="<?= $cc ?>"<?= $cc === $cue ? ' selected' : '' ?>><?= h(trim((string) nz($c['DENCUE'], ''))) ?> (<?= str_pad((string) $cc, 5, '0', STR_PAD_LEFT) ?>)</option>
        <?php endforeach; ?>
      </select></span>
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Nivel</label>
      <select name="nivel" class="form-select form-select-sm">
        <option value="D"<?= $niv === 'D' ? ' selected' : '' ?>>Detalle</option>
        <option value="S"<?= $niv === 'S' ? ' selected' : '' ?>>Subtotal</option>
        <option value="T"<?= $niv === 'T' ? ' selected' : '' ?>>Total</option>
      </select></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>

<div class="lst-doc pf-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">OPERACIONES PENDIENTES DE FACTURACION</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTA</span><span>:</span><span class="v"><?= $cue ? str_pad((string) $cue, 5, '0', STR_PAD_LEFT) . ' ' . h($cueDen) : 'TODAS' ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
    <span>NIVEL</span><span>:</span><span class="v"><?= h($nivTxt[$niv]) ?></span>
  </div>

  <table class="pf-tbl">
    <colgroup>
      <col style="width:1.29cm"><col style="width:1.14cm"><col style="width:0.64cm"><col style="width:1.19cm">
      <col style="width:1.34cm"><col style="width:4.65cm"><col style="width:1.19cm"><col style="width:1.34cm">
      <col style="width:0.89cm"><col style="width:1.04cm"><col style="width:1.29cm"><col style="width:1.48cm"><col style="width:1.32cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">CUENTA<br>CORRIENTE</th>
        <th rowspan="2">N&ordm; MOV</th>
        <th colspan="3">COMPROBANTE</th>
        <th colspan="3">DETALLE</th>
        <th rowspan="2">UNIDAD</th>
        <th colspan="3">CANTIDAD</th>
        <th rowspan="2">VALORIZADO<br>NETO $</th>
      </tr>
      <tr>
        <th>PDV</th><th>NUMERO</th><th>EMISION</th>
        <th>PRODUCTO</th><th>O. CORTE</th><th>O. PROCESO</th>
        <th>REMITIDO</th><th>FACTURADO</th><th>PENDIENTE</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$acc): ?>
      <tr><td colspan="13" class="l" style="padding:.4cm">Sin operaciones pendientes de facturación en el período.</td></tr>
      <?php else: $primero = true; foreach ($acc as $den => $a): ?>
        <?php if ($showAcc): ?>
        <tr class="acc<?= ($sep && !$primero) ? ' sepline' : '' ?>">
          <td colspan="12" class="l"><?= h($den) ?></td>
          <td class="r"><?= pf_q($a['sub']) ?></td>
        </tr>
        <?php endif; $primero = false; ?>
        <?php foreach ($a['movs'] as $m): ?>
          <?php if ($showMov): ?>
          <tr class="mov">
            <td></td>
            <td class="r"><?= str_pad((string) $m['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td>
            <td class="r"><?= h($m['PDV']) ?></td>
            <td class="r"><?= h($m['NUMERO']) ?></td>
            <td class="c"><?= h($m['FEXMOV']) ?></td>
            <td colspan="3" class="l"><?= h($m['DETMOV']) ?></td>
            <td></td><td></td><td></td><td></td>
            <td class="r"><?= pf_q($m['sub']) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($showDet): foreach ($m['prods'] as $p): ?>
          <tr>
            <td colspan="5"></td>
            <td class="l pr"><?= h($p['PROD']) ?></td>
            <td class="r"><?= h($p['ODC']) ?></td>
            <td class="r"><?= h($p['ODP']) ?></td>
            <td class="l"><?= h($p['UDM']) ?></td>
            <td class="r"><?= pf_q($p['REM']) ?></td>
            <td class="r"><?= pf_q($p['FAC']) ?></td>
            <td class="r"><?= pf_q($p['PEN']) ?></td>
            <td class="r"><?= pf_q($p['TOT']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        <?php endforeach; ?>
      <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="l">TOTAL</td>
        <td class="r"><?= (int) $totCount ?></td>
        <td colspan="10"></td>
        <td class="r"><?= pf_q($grand) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
