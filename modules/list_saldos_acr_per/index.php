<?php
/** Listado de Saldos Acreedores Periódicos / Históricos (Rpt CA Saldos Periodico / Historico).
 *  ?hist=1 → histórico (agrega Saldo Anterior + Saldo Actual). Por cuenta acreedora, débitos/créditos/
 *  saldo del período (CEFMOV between desde/hasta), CODORI='A', modo doble-libro (auth_libro_unico).
 *  Filtros: período + cuenta (código, opcional). Ordenado por denominación; totales al pie. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function f2($v) { return number_format((float) $v, 2, '.', ','); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

$hist = !empty($_GET['hist']);

// período (default = año de la fecha del sistema)
// Período predeterminado = Rec Control.DESFEC/HASFEC (período guardado del sistema).
list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = sp_serial($desIso); $sh = sp_serial($hasIso);
$cue = isset($_GET['cue']) && ctype_digit((string) $_GET['cue']) ? (int) $_GET['cue'] : '';

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND ESTMOV=True' : (($lib === 'capacitacion') ? ' AND ESTMOV=False' : '');
$cueW = ($cue !== '') ? ' AND CODCUE=' . $cue : '';

// movimientos del período
$per = array();
foreach (db_query("SELECT CODCUE, SUM(DEBMOV) AS D, SUM(CREMOV) AS C FROM [Tbl Movimientos]
    WHERE CODORI='A'$estW$cueW AND CEFMOV >= $sd AND CEFMOV <= $sh GROUP BY CODCUE;") as $r)
    $per[(int) $r['CODCUE']] = array('d' => (float) nz($r['D'], 0), 'c' => (float) nz($r['C'], 0));

// saldo anterior (sólo histórico)
$ant = array();
if ($hist) {
    foreach (db_query("SELECT CODCUE, SUM(DEBMOV) AS D, SUM(CREMOV) AS C FROM [Tbl Movimientos]
        WHERE CODORI='A'$estW$cueW AND CEFMOV < $sd GROUP BY CODCUE;") as $r)
        $ant[(int) $r['CODCUE']] = (float) nz($r['D'], 0) - (float) nz($r['C'], 0);
}

// nombres
$den = array();
foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A';") as $c)
    $den[(int) $c['CODCUE']] = trim((string) nz($c['DENCUE'], ''));
$provList = $den; asort($provList, SORT_STRING | SORT_FLAG_CASE);   // para el selector de Proveedor

// set a mostrar: período (periodico) o período+anterior (historico)
$codes = array_keys($per);
if ($hist) foreach ($ant as $k => $v) if (round($v, 2) != 0.0 && !in_array($k, $codes, true)) $codes[] = $k;
usort($codes, function ($a, $b) use ($den) { return strcasecmp(isset($den[$a]) ? $den[$a] : '', isset($den[$b]) ? $den[$b] : ''); });

$tAnt = 0.0; $tD = 0.0; $tC = 0.0; $tSal = 0.0; $tAct = 0.0;
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$titulo = $hist ? 'Saldos Acreedores Históricos' : 'Saldos Acreedores Periódicos';
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de ' . $titulo, 'bi-calendar-range', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<style>
  /* Proveedor ancho en su propia fila; Desde · Hasta debajo (mismo layout que Resumen de Cuenta) */
  .lst-fgrid3 .lst-fpair.rc-prov { grid-column: 1 / -1; grid-template-columns: 6.5rem 1fr; }
  .lst-fgrid3 .lst-fpair.rc-prov > select,
  .lst-fgrid3 .lst-fpair.rc-prov > .iwk-combo,
  .lst-fgrid3 .lst-fpair.rc-prov .iwk-combo-input { width: 100%; max-width: 34rem; }
  .sp-tbl thead th { text-align: center; vertical-align: middle; }   /* encabezados centrados (legacy align=2) */
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <?php if ($hist): ?><input type="hidden" name="hist" value="1"><?php endif; ?>
  <div class="lst-fgrid3">
    <span class="lst-fpair rc-prov"><label>Proveedor</label>
      <select name="cue" class="form-select form-select-sm">
        <option value="">— Todos —</option>
        <?php foreach ($provList as $pc => $pn): ?>
        <option value="<?= (int) $pc ?>"<?= ($cue !== '' && (int) $pc === (int) $cue) ? ' selected' : '' ?>><?= h($pn) ?> (<?= str_pad((string) (int) $pc, 5, '0', STR_PAD_LEFT) ?>)</option>
        <?php endforeach; ?>
      </select></span>
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc<?= $hist ? ' lst-doc-wide' : '' ?>">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit"><?= h(strtoupper($titulo)) ?></div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTA</span><span>:</span><span class="v"><?= ($cue !== '' && isset($den[$cue])) ? h($den[$cue]) : '' ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer sp-tbl"<?= $hist ? ' style="table-layout:fixed; width:19.0cm"' : '' ?>>
    <?php if ($hist): ?>
    <colgroup><col style="width:8.2cm"><col style="width:1.0cm"><col style="width:1.95cm"><col style="width:1.95cm"><col style="width:1.95cm"><col style="width:1.95cm"><col style="width:2.0cm"></colgroup>
    <?php endif; ?>
    <thead>
      <?php if ($hist): ?>
      <!-- Histórico (Rpt CA Saldos Historico): Cuenta Corriente | Saldo Operativo → Anterior · Período(Déb·Créd·Saldo) · Actual -->
      <tr>
        <th rowspan="3" colspan="2">Cuenta Corriente</th>
        <th colspan="5">Saldo Operativo</th>
      </tr>
      <tr>
        <th rowspan="2">Anterior</th>
        <th colspan="3">Período</th>
        <th rowspan="2">Actual</th>
      </tr>
      <tr><th>Débitos</th><th>Créditos</th><th>Saldo</th></tr>
      <?php else: ?>
      <!-- Periódico (Rpt CA Saldos Periodico): Cuenta Corriente | Saldo Operativo (Déb·Créd·Saldo) -->
      <tr>
        <th rowspan="2" colspan="2">Cuenta Corriente</th>
        <th colspan="3">Saldo Operativo</th>
      </tr>
      <tr><th>Débitos</th><th>Créditos</th><th>Saldo</th></tr>
      <?php endif; ?>
    </thead>
    <tbody>
      <?php foreach ($codes as $cc):
        $a = $hist && isset($ant[$cc]) ? $ant[$cc] : 0.0;
        $d = isset($per[$cc]) ? $per[$cc]['d'] : 0.0;
        $c = isset($per[$cc]) ? $per[$cc]['c'] : 0.0;
        $sal = $d - $c; $act = $a + $sal;
        $tAnt += $a; $tD += $d; $tC += $c; $tSal += $sal; $tAct += $act; ?>
      <tr>
        <td><?= h(isset($den[$cc]) ? $den[$cc] : '') ?></td>
        <td class="r mono"><?= (int) $cc ?></td>
        <?php if ($hist): ?><td class="r mono"><?= f2($a) ?></td><?php endif; ?>
        <td class="r mono"><?= f2($d) ?></td><td class="r mono"><?= f2($c) ?></td><td class="r mono"><?= f2($sal) ?></td>
        <?php if ($hist): ?><td class="r mono"><?= f2($act) ?></td><?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php if (!count($codes)): ?>
      <tr><td colspan="<?= $hist ? 7 : 5 ?>" class="text-muted">Sin movimientos en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot><tr class="tot">
      <td colspan="2">TOTAL ENTIDADES: <?= count($codes) ?></td>
      <?php if ($hist): ?><td class="r mono"><?= f2($tAnt) ?></td><?php endif; ?>
      <td class="r mono"><?= f2($tD) ?></td><td class="r mono"><?= f2($tC) ?></td><td class="r mono"><?= f2($tSal) ?></td>
      <?php if ($hist): ?><td class="r mono"><?= f2($tAct) ?></td><?php endif; ?>
    </tr></tfoot>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
