<?php
/** Listado de Saldos Deudores (Rpt CD Saldos) — el "Actuales" imprimible de Deudores.
 *  Agrupado por CATEGORÍA: por cuenta con saldo operativo ≠ 0 (ΣDEBMOV−ΣCREMOV, CODORI='D'),
 *  fecha última operación (FUOCUE) y % de incidencia (Categ = saldo/total categoría, Total =
 *  saldo/total general). Cabecera de categoría = nombre + total de categoría + % sobre el total.
 *  Respeta el modo doble-libro (auth_libro_unico → ESTMOV). Geometría/fuentes fieles al .report. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function f2($v) { return number_format((float) $v, 2, '.', ','); }
function pct($num, $den) { return ($den == 0.0) ? '0.00' : number_format($num / $den * 100, 2, '.', ','); }

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND ESTMOV=True' : (($lib === 'capacitacion') ? ' AND ESTMOV=False' : '');

// Parámetro "Saldos > 0,00" (legacy): tildado oculta las cuentas con saldo 0 (sólo las que tienen
// movimiento pendiente). Default tildado (como el PDF). Sin tildar → todas las cuentas con movimientos.
$submitted = isset($_GET['f']);
$soloNoCero = $submitted ? isset($_GET['solo']) : true;

// saldo operativo + fecha última operación (= MAX(FEXMOV) real, como qryFUO; NO el FUOCUE cacheado) por cuenta
$agg = array();
foreach (db_query("SELECT CODCUE, SUM(DEBMOV) AS D, SUM(CREMOV) AS C, MAX(FEXMOV) AS FUO FROM [Tbl Movimientos] WHERE CODORI='D'$estW GROUP BY CODCUE;") as $r)
    $agg[(int) $r['CODCUE']] = array('sop' => (float) nz($r['D'], 0) - (float) nz($r['C'], 0), 'fuo' => $r['FUO']);

// maestro (denominación, categoría) — sólo cuentas con saldo ≠ 0
$cta = array();
foreach (db_query("SELECT CODCUE, DENCUE, CODCAT FROM [Tbl Cuentas Corrientes] WHERE CODORI='D';") as $c) {
    $cc = (int) $c['CODCUE'];
    if (!isset($agg[$cc])) continue;
    if ($soloNoCero && round($agg[$cc]['sop'], 2) == 0.0) continue;
    $cta[$cc] = array('den' => trim((string) nz($c['DENCUE'], '')), 'fuo' => $agg[$cc]['fuo'], 'cat' => (int) nz($c['CODCAT'], 0), 'sop' => $agg[$cc]['sop']);
}

// nombres de categoría
$catName = array();
foreach (db_query("SELECT CODCAT, DENCAT FROM [Tbl Categorias Cuentas Corrientes] WHERE CODORI='D';") as $r)
    $catName[(int) $r['CODCAT']] = trim((string) nz($r['DENCAT'], ''));

// agrupar por categoría (orden por nombre de categoría; detalle por denominación)
$groups = array();
foreach ($cta as $cc => $v) { $groups[$v['cat']][] = $cc; }
$catKeys = array_keys($groups);
usort($catKeys, function ($a, $b) use ($catName) { return strcasecmp(isset($catName[$a]) ? $catName[$a] : '~', isset($catName[$b]) ? $catName[$b] : '~'); });
foreach ($groups as $cat => &$ccs) { usort($ccs, function ($a, $b) use ($cta) { return strcasecmp($cta[$a]['den'], $cta[$b]['den']); }); }
unset($ccs);

$catTot = array(); $grand = 0.0; $nTot = 0;
foreach ($groups as $cat => $ccs) { $t = 0.0; foreach ($ccs as $cc) $t += $cta[$cc]['sop']; $catTot[$cat] = $t; $grand += $t; $nTot += count($ccs); }

$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Saldos Deudores', 'bi-cash-stack', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<style>
  /* Rpt CD Saldos — fuentes del .report: captions 7pt centrados · detalle 9pt · categoría 9pt bold. Sin truncar. */
  .lst-tbl.sd-tbl { font-size: 9pt; }
  .lst-tbl.sd-tbl thead th { font-size: 7pt; text-align: center; vertical-align: middle; }
  .lst-tbl.sd-tbl tbody td { font-size: 9pt; height: .36cm; line-height: .36cm; white-space: nowrap; overflow: visible; text-overflow: clip; }
  .lst-tbl.sd-tbl tbody tr.parent td { font-weight: 700; }
  .lst-tbl.sd-tbl .c { text-align: center; } .lst-tbl.sd-tbl .r { text-align: right; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <input type="hidden" name="f" value="1">
  <label style="display:inline-flex;align-items:center;gap:.35rem;font-weight:600;color:#333;font-size:.82rem;">
    <input type="checkbox" name="solo" value="1"<?= $soloNoCero ? ' checked' : '' ?>> Saldos &ne; 0,00
  </label>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">SALDOS DEUDORES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params"><span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span></div>
  <table class="lst-tbl lst-tight sd-tbl" style="table-layout:fixed; width:19.0cm">
    <colgroup><col style="width:4.00cm"><col style="width:7.00cm"><col style="width:1.40cm"><col style="width:1.60cm"><col style="width:2.00cm"><col style="width:1.02cm"><col style="width:1.98cm"></colgroup>
    <thead>
      <tr>
        <th rowspan="2">Categoría</th>
        <th rowspan="2">Denominación</th>
        <th rowspan="2">Código</th>
        <th rowspan="2">Fecha Última Operación</th>
        <th rowspan="2">Saldo Operativo</th>
        <th colspan="2">% Incidencia</th>
      </tr>
      <tr><th>Categ</th><th>Total</th></tr>
    </thead>
    <tbody>
      <?php foreach ($catKeys as $cat):
        $ct = $catTot[$cat]; ?>
      <tr class="parent">
        <td colspan="4"><?= h(isset($catName[$cat]) ? $catName[$cat] : '(Sin categoría)') ?></td>
        <td class="r mono"><?= f2($ct) ?></td>
        <td colspan="2" class="r mono"><?= pct($ct, $grand) ?></td>
      </tr>
      <?php foreach ($groups[$cat] as $cc): $v = $cta[$cc]; ?>
      <tr>
        <td></td>
        <td><?= h($v['den']) ?></td>
        <td class="c mono"><?= str_pad((string) $cc, 8, '0', STR_PAD_LEFT) ?></td>
        <td class="c"><?= h(fecha_serial($v['fuo'])) ?></td>
        <td class="r mono"><?= f2($v['sop']) ?></td>
        <td class="r mono"><?= pct($v['sop'], $ct) ?></td>
        <td class="r mono"><?= pct($v['sop'], $grand) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="tot">
      <td colspan="4">TOTAL: <?= $nTot ?></td>
      <td class="r mono"><?= f2($grand) ?></td>
      <td colspan="2"></td>
    </tr></tfoot>
  </table>
</div>
<?php module_foot(); ?>
