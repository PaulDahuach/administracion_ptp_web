<?php
/** Listado Movimientos Acreedores x Cuenta (Rpt CA Movimientos x Cuenta). Espejo del de deudores con
 *  CODORI='A': fecha = **CEFMOV** (fec. comprobante acreedor), comprobante = CEC/CEI/CEP/CEN, excluye
 *  remitos (CODOPE<>300). Saldo anterior = Σ(DEB−CRE) con CEFMOV<desde; saldo corrido en el período.
 *  NIVEL D/S/T. Modo doble-libro (auth_libro_unico). Negativo = le debemos al proveedor. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function mca_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }
function mca_f2($v) { return number_format((float) $v, 2, '.', ','); }

list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = mca_serial($desIso); $sh = mca_serial($hasIso);
$niv = isset($_GET['nivel']) ? strtoupper($_GET['nivel']) : 'D';
if (!in_array($niv, array('D', 'S', 'T'), true)) $niv = 'D';
$nivTxt = array('D' => 'Detalle', 'S' => 'Subtotal', 'T' => 'Total');

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND ESTMOV=True' : (($lib === 'capacitacion') ? ' AND ESTMOV=False' : '');

$den = array();
foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A';") as $c)
    $den[(int) $c['CODCUE']] = trim((string) nz($c['DENCUE'], ''));

$sant = array();
foreach (db_query("SELECT CODCUE, SUM(DEBMOV) AS D, SUM(CREMOV) AS C FROM [Tbl Movimientos]
    WHERE CODORI='A' AND CEFMOV < $sd$estW GROUP BY CODCUE;") as $r)
    $sant[(int) $r['CODCUE']] = (float) nz($r['D'], 0) - (float) nz($r['C'], 0);

$movs = array();
foreach (db_query("SELECT CODCUE, NUMMOV, CEFMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, DETMOV, DEBMOV, CREMOV
    FROM [Tbl Movimientos] WHERE CODORI='A' AND CODOPE<>300 AND CEFMOV >= $sd AND CEFMOV <= $sh$estW
    ORDER BY CODCUE, NUMMOV;") as $r) {
    $k = (int) $r['CODCUE'];
    if (!isset($movs[$k])) $movs[$k] = array();
    $movs[$k][] = $r;
}

$codes = array();
foreach ($sant as $k => $v) if ($v != 0.0) $codes[$k] = true;
foreach ($movs as $k => $v) $codes[$k] = true;
$codes = array_keys($codes);
usort($codes, function ($a, $b) use ($den) { return strcasecmp(isset($den[$a]) ? $den[$a] : '', isset($den[$b]) ? $den[$b] : ''); });

$TsAnt = 0.0; $TdebS = 0.0; $TcreS = 0.0; $Tcount = 0;
foreach ($codes as $k) {
    $TsAnt += isset($sant[$k]) ? $sant[$k] : 0.0;
    if (isset($movs[$k])) foreach ($movs[$k] as $m) { $TdebS += (float) nz($m['DEBMOV'], 0); $TcreS += (float) nz($m['CREMOV'], 0); }
    $Tcount++;
}
$TsAct = $TsAnt + $TdebS - $TcreS;

$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$showHdr = ($niv === 'D'); $showDet = ($niv === 'D'); $showSub = ($niv !== 'T');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Movimientos Acreedores x Cuenta', 'bi-journal-bookmark', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<style>
  @media print { @page { size: Letter portrait; margin: 12mm; } .lst-doc { width: auto; box-shadow: none; } }
  .mc-doc { font-family: "Univers Condensed", "Arial Narrow", sans-serif; }
  .mc-tbl { width: 18.8cm; border-collapse: collapse; table-layout: fixed; }
  .mc-tbl thead th { font-size: 7pt; font-weight: 400; text-align: center; vertical-align: middle;
    border: 1px solid #000; background: #c0c0c0; line-height: 1.05; padding: 1px 2px; }
  .mc-tbl tbody td { font-size: 8pt; height: .33cm; line-height: .33cm; padding: 0 2px; vertical-align: top; white-space: nowrap; }
  .mc-tbl td.w { white-space: normal; word-break: break-word; }
  .mc-tbl .r { text-align: right; } .mc-tbl .c { text-align: center; } .mc-tbl .l { text-align: left; }
  .mc-tbl tr.acc td { font-weight: 700; border-top: 1px solid #000; }
  .mc-tbl tr.sub td { font-weight: 700; border-top: 1px solid #999; }
  .mc-tbl tfoot td { font-size: 8pt; font-weight: 700; padding: 1px 2px; border-top: 1px solid #000; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
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

<div class="lst-doc mc-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">MOVIMIENTOS ACREEDORES x CUENTA</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
    <span>NIVEL</span><span>:</span><span class="v"><?= h($nivTxt[$niv]) ?></span>
  </div>
  <table class="mc-tbl">
    <colgroup>
      <col style="width:1.09cm"><col style="width:1.29cm"><col style="width:0.49cm"><col style="width:0.40cm">
      <col style="width:0.59cm"><col style="width:1.09cm"><col style="width:6.43cm"><col style="width:1.83cm">
      <col style="width:1.83cm"><col style="width:1.83cm"><col style="width:1.93cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">CUENTA<br>CORRIENTE</th>
        <th colspan="5">COMPROBANTE PROVEEDOR</th>
        <th rowspan="2">DETALLE</th>
        <th rowspan="2">SALDO<br>ANTERIOR</th>
        <th colspan="2">PERIODO</th>
        <th rowspan="2">SALDO<br>ACTUAL</th>
      </tr>
      <tr><th>FECHA</th><th>COD</th><th>IDE</th><th>PDV</th><th>NUMERO</th><th>DEBE</th><th>HABER</th></tr>
    </thead>
    <tbody>
      <?php foreach ($codes as $k):
        $sa = isset($sant[$k]) ? $sant[$k] : 0.0;
        $lst = isset($movs[$k]) ? $movs[$k] : array();
        $sdeb = 0.0; $scre = 0.0; foreach ($lst as $m) { $sdeb += (float) nz($m['DEBMOV'], 0); $scre += (float) nz($m['CREMOV'], 0); }
        $saf = $sa + $sdeb - $scre; ?>
        <?php if ($showHdr): ?>
        <tr class="acc">
          <td class="c"><?= (int) $k ?></td>
          <td class="l w" colspan="6"><?= h(isset($den[$k]) ? $den[$k] : '') ?></td>
          <td class="r"><?= mca_f2($sa) ?></td>
          <td></td><td></td>
          <td class="r"><?= mca_f2($sa) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($showDet): $run = $sa; foreach ($lst as $m):
          $deb = (float) nz($m['DEBMOV'], 0); $cre = (float) nz($m['CREMOV'], 0); $run += $deb - $cre;
          $pdv = (int) nz($m['CEPMOV'], 0); ?>
        <tr>
          <td></td>
          <td class="l"><?= h(fecha_serial($m['CEFMOV'])) ?></td>
          <td class="l"><?= h(trim((string) nz($m['CECMOV'], ''))) ?></td>
          <td class="l"><?= h(trim((string) nz($m['CEIMOV'], ''))) ?></td>
          <td class="l"><?= $pdv > 0 ? str_pad((string) $pdv, 4, '0', STR_PAD_LEFT) : '' ?></td>
          <td class="l"><?= str_pad((string) (int) nz($m['CENMOV'], 0), 8, '0', STR_PAD_LEFT) ?></td>
          <td class="l w"><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
          <td></td>
          <td class="r"><?= $deb > 0 ? mca_f2($deb) : '' ?></td>
          <td class="r"><?= $cre > 0 ? mca_f2($cre) : '' ?></td>
          <td class="r"><?= mca_f2($run) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        <?php if ($showSub): ?>
        <tr class="sub">
          <td class="l w" colspan="7">SUBTOTAL [<?= (int) $k ?>] - <?= h(isset($den[$k]) ? $den[$k] : '') ?>: <?= count($lst) ?></td>
          <td class="r"><?= mca_f2($sa) ?></td>
          <td class="r"><?= mca_f2($sdeb) ?></td>
          <td class="r"><?= mca_f2($scre) ?></td>
          <td class="r"><?= mca_f2($saf) ?></td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$codes): ?>
      <tr><td colspan="11" class="l" style="padding:.4cm">Sin cuentas con saldo ni movimientos en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="l" colspan="7">TOTAL CUENTAS CORRIENTES: <?= (int) $Tcount ?></td>
        <td class="r"><?= mca_f2($TsAnt) ?></td>
        <td class="r"><?= mca_f2($TdebS) ?></td>
        <td class="r"><?= mca_f2($TcreS) ?></td>
        <td class="r"><?= mca_f2($TsAct) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php module_foot(); ?>
