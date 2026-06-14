<?php
/** Listado de Saldos Operaciones Deudores (Rpt CD Saldos Operaciones). Movimientos deudores con saldo
 *  pendiente (SDOMOV<>0) en el período (FEXMOV), agrupados por cuenta: comprobante propio (CIC/CII/CIP/
 *  CIN), débitos/créditos/saldo, A COBRAR (SDOMOV>0) y A PAGAR (−SDOMOV si SDOMOV<0). NIVEL D/S/T.
 *  Filtros: cuenta (opcional) + período. Modo doble-libro (auth_libro_unico). Geometría/fuentes fieles. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function f2($v) { return number_format((float) $v, 2, '.', ','); }
function n2($v) { return ((float) $v == 0.0) ? '' : f2($v); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

// Período predeterminado = Rec Control.DESFEC/HASFEC (el período guardado del sistema), para todos los listados.
list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = sp_serial($desIso); $sh = sp_serial($hasIso);
$cue = isset($_GET['cue']) && ctype_digit((string) $_GET['cue']) ? (int) $_GET['cue'] : '';

$niv = isset($_GET['nivel']) ? strtoupper($_GET['nivel']) : 'D';
if (!in_array($niv, array('D', 'S', 'T'), true)) $niv = 'D';
$nivTxt = array('D' => 'Detalle', 'S' => 'Subtotal', 'T' => 'Total');

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND ESTMOV=True' : (($lib === 'capacitacion') ? ' AND ESTMOV=False' : '');
$cueW = ($cue !== '') ? ' AND CODCUE=' . $cue : '';

$movs = db_query("SELECT NUMMOV, CODCUE, FEXMOV, CICMOV, CIIMOV, CIPMOV, CINMOV, DETMOV, DEBMOV, CREMOV, SDOMOV
    FROM [Tbl Movimientos] WHERE CODORI='D' AND SDOMOV<>0 AND FEXMOV >= $sd AND FEXMOV <= $sh$estW$cueW
    ORDER BY CODCUE, NUMMOV;");

$den = array();
foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='D';") as $c)
    $den[(int) $c['CODCUE']] = trim((string) nz($c['DENCUE'], ''));
$provList = $den; asort($provList, SORT_STRING | SORT_FLAG_CASE);

$grupos = array();
foreach ($movs as $m) { $k = (int) $m['CODCUE']; if (!isset($grupos[$k])) $grupos[$k] = array(); $grupos[$k][] = $m; }
uksort($grupos, function ($a, $b) use ($den) { return strcasecmp(isset($den[$a]) ? $den[$a] : '', isset($den[$b]) ? $den[$b] : ''); });

$tD = 0.0; $tC = 0.0; $tSal = 0.0; $tAco = 0.0; $tApa = 0.0; $tN = 0;
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Saldos Operaciones Deudores', 'bi-arrow-left-right', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<style>
  /* Rpt CD Saldos Operaciones — fuentes del .report: captions 7pt · detalle 8pt · grupo 8pt bold. Sin truncar. */
  .lst-tbl.so-cd { font-size: 8pt; }
  .lst-tbl.so-cd thead th { font-size: 7pt; text-align: center; vertical-align: middle; }
  .lst-tbl.so-cd tbody td { font-size: 8pt; height: .34cm; line-height: .34cm; white-space: nowrap; overflow: visible; text-overflow: clip; }
  .lst-tbl.so-cd tbody tr.parent td { font-weight: 700; }
  .lst-tbl.so-cd .c { text-align: center; } .lst-tbl.so-cd .r { text-align: right; }
  .lst-tbl.so-cd thead th.r { text-align: right; } .lst-tbl.so-cd thead th.l { text-align: left; }
  /* sólo en NIVEL Detalle: línea negra que separa los grupos (en Subtotal/Total se omite, como el VBA) */
  .lst-tbl.so-cd.dmode tbody tr.parent td { border-top: 1px solid #000; }
  .lst-tbl.so-cd tfoot tr.tot td { border-top: 1px solid #000; }   /* línea antes del total general */
  /* Cuenta ancha en su propia fila; Desde · Hasta · Nivel debajo (layout de Acreedores) */
  .lst-fgrid3 .lst-fpair.rc-prov { grid-column: 1 / -1; grid-template-columns: 6.5rem 1fr; }
  .lst-fgrid3 .lst-fpair.rc-prov > select,
  .lst-fgrid3 .lst-fpair.rc-prov > .iwk-combo,
  .lst-fgrid3 .lst-fpair.rc-prov .iwk-combo-input { width: 100%; max-width: 34rem; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair rc-prov"><label>Cuenta</label>
      <select name="cue" class="form-select form-select-sm">
        <option value="">— Todas —</option>
        <?php foreach ($provList as $pc => $pn): ?>
        <option value="<?= (int) $pc ?>"<?= ($cue !== '' && (int) $pc === (int) $cue) ? ' selected' : '' ?>><?= h($pn) ?> (<?= str_pad((string) (int) $pc, 5, '0', STR_PAD_LEFT) ?>)</option>
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
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">SALDOS OPERACIONES DEUDORES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTA</span><span>:</span><span class="v"><?= ($cue !== '' && isset($den[$cue])) ? h($den[$cue]) : '' ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
    <span>NIVEL</span><span>:</span><span class="v"><?= h($nivTxt[$niv]) ?></span>
  </div>
  <table class="lst-tbl lst-tight so-cd<?= $niv === 'D' ? ' dmode' : '' ?>" style="table-layout:fixed; width:19.0cm">
    <colgroup>
      <col style="width:1.95cm"><col style="width:1.10cm"><col style="width:1.30cm">
      <col style="width:.50cm"><col style="width:.50cm"><col style="width:.60cm"><col style="width:1.20cm">
      <col style="width:1.35cm">
      <col style="width:2.10cm"><col style="width:2.10cm"><col style="width:2.10cm"><col style="width:2.10cm"><col style="width:2.10cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">Cuenta Corriente</th>
        <th rowspan="2">N° Mov</th>
        <th rowspan="2">Emisión</th>
        <th colspan="4">Comprobante</th>
        <th class="r"># Comprob.</th>
        <th rowspan="2">Débitos</th><th rowspan="2">Créditos</th><th rowspan="2">Saldo</th>
        <th rowspan="2">A Cobrar</th><th rowspan="2">A Pagar</th>
      </tr>
      <tr><th>Cod</th><th>Ide</th><th>PDV</th><th>Número</th><th class="l">Detalle</th></tr>
    </thead>
    <tbody>
      <?php foreach ($grupos as $cc => $ms):
        $gd=0;$gc=0;$gs=0;$ga=0;$gp=0;
        foreach ($ms as $m) { $sdo=(float)nz($m['SDOMOV'],0); $gd+=(float)nz($m['DEBMOV'],0); $gc+=(float)nz($m['CREMOV'],0); $gs+=$sdo; $ga+=($sdo>0?$sdo:0); $gp+=($sdo<0?-$sdo:0); }
        $tD+=$gd;$tC+=$gc;$tSal+=$gs;$tAco+=$ga;$tApa+=$gp; $tN+=count($ms);
        if ($niv !== 'T'): ?>
      <tr class="parent">
        <td colspan="7"><?= h(isset($den[$cc]) ? $den[$cc] : '') ?></td>
        <td class="r"><?= count($ms) ?></td>
        <td class="r mono"><?= f2($gd) ?></td><td class="r mono"><?= f2($gc) ?></td><td class="r mono"><?= f2($gs) ?></td><td class="r mono"><?= f2($ga) ?></td><td class="r mono"><?= f2($gp) ?></td>
      </tr>
      <?php endif;
      if ($niv === 'D') foreach ($ms as $m):
        $deb=(float)nz($m['DEBMOV'],0); $cre=(float)nz($m['CREMOV'],0); $sdo=(float)nz($m['SDOMOV'],0);
        $aco=$sdo>0?$sdo:0; $apa=$sdo<0?-$sdo:0;
        $cip=(int)nz($m['CIPMOV'],0); $cin=(int)nz($m['CINMOV'],0); ?>
      <tr>
        <td></td>
        <td class="mono"><?= str_pad((string)(int)$m['NUMMOV'],8,'0',STR_PAD_LEFT) ?></td>
        <td class="c mono"><?= h(fecha_serial($m['FEXMOV'])) ?></td>
        <td class="mono"><?= h(trim((string)nz($m['CICMOV'],''))) ?></td>
        <td class="c mono"><?= h(trim((string)nz($m['CIIMOV'],''))) ?></td>
        <td class="c mono"><?= $cip ? str_pad((string)$cip,4,'0',STR_PAD_LEFT) : '' ?></td>
        <td class="c mono"><?= $cin ? str_pad((string)$cin,8,'0',STR_PAD_LEFT) : '' ?></td>
        <td><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
        <td class="r mono"><?= f2($deb) ?></td><td class="r mono"><?= f2($cre) ?></td><td class="r mono"><?= f2($sdo) ?></td>
        <td class="r mono"><?= n2($aco) ?></td><td class="r mono"><?= n2($apa) ?></td>
      </tr>
      <?php endforeach;
      endforeach; ?>
      <?php if (!count($grupos)): ?>
      <tr><td colspan="13" class="text-muted">Sin operaciones con saldo en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <?php if (count($grupos)): ?>
    <tfoot><tr class="tot">
      <td colspan="7">TOTAL</td>
      <td class="r mono"><?= $tN ?></td>
      <td class="r mono"><?= f2($tD) ?></td><td class="r mono"><?= f2($tC) ?></td><td class="r mono"><?= f2($tSal) ?></td><td class="r mono"><?= f2($tAco) ?></td><td class="r mono"><?= f2($tApa) ?></td>
    </tr></tfoot>
    <?php endif; ?>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
