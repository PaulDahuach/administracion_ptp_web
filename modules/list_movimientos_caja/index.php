<?php
/** Listado Movimientos de Caja x Fecha (Rpt IC Movimientos de Caja x Fecha). Ledger de la cuenta CAJA
 *  por fecha: imputaciones a las cuentas contables CACC_1 (efectivo) / CACC_2 (cheque) / CACC_3* (inter-
 *  depósito, sólo CODOPE=480) de Rec Control. Por movimiento+forma de pago: ingresos (DEBE), egresos
 *  (HABER), interdepósito (separado, no mueve caja), saldo corrido. Período FEXMOV. Modo doble-libro
 *  (auth_libro_unico, por ESTMOV del movimiento padre). Forma de pago: EFECTIVO/CHEQUE/INTERDEPOSITO. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function cj_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }
function cj_f2($v) { return number_format((float) $v, 2, '.', ','); }

list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = cj_serial($desIso); $sh = cj_serial($hasIso);

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');

$rc = db_row("SELECT CACC_1, CACC_2, CACC_3 FROM [Rec Control];");
$c1 = trim((string) nz($rc['CACC_1'], '')); $c2 = trim((string) nz($rc['CACC_2'], '')); $c3 = trim((string) nz($rc['CACC_3'], ''));

$rows = db_query("SELECT M.FEXMOV, M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DENMOV, M.DETMOV,
    MI.CODCUE AS MC, MI.DEBMOV AS MD, MI.CREMOV AS MK
    FROM [Tbl Movimientos] AS M INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON M.NUMMOV = MI.NUMMOV
    WHERE M.FEXMOV >= $sd AND M.FEXMOV <= $sh$estW
      AND (MI.CODCUE='$c1' OR MI.CODCUE='$c2' OR (MI.CODCUE LIKE '$c3%' AND M.CODOPE=480))
    ORDER BY M.FEXMOV, M.NUMMOV, MI.ORDMOV;");

// agrupar por (movimiento, forma de pago), preservando el orden de aparición
$g = array(); $order = array();
foreach ($rows as $r) {
    $mc = trim((string) nz($r['MC'], '')); $deb = (float) nz($r['MD'], 0); $cre = (float) nz($r['MK'], 0);
    $imp = ($mc === $c1) ? 'EFECTIVO' : (($mc === $c2) ? 'CHEQUE' : 'INTERDEPOSITO');
    $k = (int) $r['NUMMOV'] . '|' . $imp;
    if (!isset($g[$k])) {
        $den = trim((string) nz($r['DENMOV'], '')); $det = trim((string) nz($r['DETMOV'], ''));
        $g[$k] = array(
            'FECHA' => fecha_serial($r['FEXMOV']),
            'DET'   => ($den !== '') ? $den : $det,
            'COD'   => trim((string) nz($r['CICMOV'], '')), 'ID' => trim((string) nz($r['CIIMOV'], '')),
            'PDV'   => (int) nz($r['CIPMOV'], 0), 'NRO' => (int) nz($r['CINMOV'], 0),
            'IMP'   => $imp, 'deb' => 0.0, 'cre' => 0.0, 'idp' => 0.0,
        );
        $order[] = $k;
    }
    if ($imp === 'INTERDEPOSITO') $g[$k]['idp'] += ($r['MD'] === null ? -$cre : $deb);
    else { $g[$k]['deb'] += $deb; $g[$k]['cre'] += $cre; }
}

$run = 0.0; $Tdeb = 0.0; $Tcre = 0.0; $Tidp = 0.0;
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Movimientos de Caja x Fecha', 'bi-cash-stack', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<style>
  @media print { @page { size: Letter portrait; margin: 12mm; } .lst-doc { width: auto; box-shadow: none; } }
  .cj-doc { font-family: "Univers Condensed", "Arial Narrow", sans-serif; }
  .cj-tbl { width: 18.8cm; border-collapse: collapse; table-layout: fixed; }
  .cj-tbl thead th { font-size: 7pt; font-weight: 400; text-align: center; vertical-align: middle;
    border: 1px solid #000; background: #c0c0c0; line-height: 1.05; padding: 1px 2px; }
  .cj-tbl tbody td { font-size: 8pt; height: .33cm; line-height: .33cm; padding: 0 2px; vertical-align: top; white-space: nowrap; }
  .cj-tbl td.w { white-space: normal; word-break: break-word; }
  .cj-tbl .r { text-align: right; } .cj-tbl .c { text-align: center; } .cj-tbl .l { text-align: left; }
  .cj-tbl tfoot td { font-size: 8pt; font-weight: 700; padding: 1px 2px; border-top: 1px solid #000; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>

<div class="lst-doc cj-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">MOVIMIENTOS DE CAJA x FECHA</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="cj-tbl">
    <colgroup>
      <col style="width:1.30cm"><col style="width:5.50cm"><col style="width:0.50cm"><col style="width:0.40cm">
      <col style="width:0.60cm"><col style="width:1.10cm"><col style="width:1.60cm"><col style="width:1.80cm">
      <col style="width:2.00cm"><col style="width:2.00cm"><col style="width:2.00cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">FECHA</th>
        <th rowspan="2">CUENTA CORRIENTE / DETALLE</th>
        <th colspan="4">COMPROBANTE</th>
        <th rowspan="2">FORMA<br>DE PAGO</th>
        <th rowspan="2">INGRESOS X<br>INTERDEPOSITO</th>
        <th colspan="3">CAJA</th>
      </tr>
      <tr><th>COD</th><th>IDE</th><th>PDV</th><th>NUMERO</th><th>INGRESOS</th><th>EGRESOS</th><th>SALDO</th></tr>
    </thead>
    <tbody>
      <?php foreach ($order as $k): $d = $g[$k];
        $run += $d['deb'] - $d['cre']; $Tdeb += $d['deb']; $Tcre += $d['cre']; $Tidp += $d['idp']; ?>
      <tr>
        <td class="l"><?= h($d['FECHA']) ?></td>
        <td class="l w"><?= h($d['DET']) ?></td>
        <td class="l"><?= h($d['COD']) ?></td>
        <td class="l"><?= h($d['ID']) ?></td>
        <td class="l"><?= $d['PDV'] > 0 ? str_pad((string) $d['PDV'], 4, '0', STR_PAD_LEFT) : '' ?></td>
        <td class="l"><?= str_pad((string) $d['NRO'], 8, '0', STR_PAD_LEFT) ?></td>
        <td class="l"><?= h($d['IMP']) ?></td>
        <td class="r"><?= $d['idp'] != 0.0 ? cj_f2($d['idp']) : '' ?></td>
        <td class="r"><?= $d['deb'] > 0 ? cj_f2($d['deb']) : '' ?></td>
        <td class="r"><?= $d['cre'] > 0 ? cj_f2($d['cre']) : '' ?></td>
        <td class="r"><?= cj_f2($run) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$order): ?>
      <tr><td colspan="11" class="l" style="padding:.4cm">Sin movimientos de caja en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="l" colspan="7">TOTAL MOVIMIENTOS: <?= count($order) ?></td>
        <td class="r"><?= cj_f2($Tidp) ?></td>
        <td class="r"><?= cj_f2($Tdeb) ?></td>
        <td class="r"><?= cj_f2($Tcre) ?></td>
        <td class="r"><?= cj_f2($run) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php module_foot(); ?>
