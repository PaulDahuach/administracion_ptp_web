<?php
/** Listado de Reposiciones (Rpt SI Reposiciones). Productos cuyo Stock Mínimo supera al Disponible
 *  (EXISTK+RMCSTK−RMVSTK). Agrupado por Rubro/Sucursal. Reposición = Mínimo − Disponible; Total $ =
 *  Reposición × Costo × (cotización si la moneda es dólar). Filtros Categoría/Rubro/Subrubro/Línea +
 *  cotización del dólar (txtNewCot del legacy). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/stocklist.php';
auth_require_login();

function f4($v) { return number_format((float) $v, 4, '.', ','); }
function f2($v) { return number_format((float) $v, 2, '.', ','); }

$L = sl_lookups();
$p = sl_params();
$cot = isset($_GET['cot']) && is_numeric($_GET['cot']) ? (float) $_GET['cot'] : 1.0;

$cols = array('cat' => 'P.CODCAT', 'rub' => 'P.CODRUB', 'sub' => 'P.CODSUB', 'lin' => 'P.CODLIN');
$where = '1=1' . sl_where($p, $cols);
$rows = db_query("SELECT P.CODRUB, P.CODPRO, P.DENPRO, P.FUCPRO, P.CODMON, P.COSPRO, S.CODSUC, S.MINSTK,
    S.EXISTK, S.RMCSTK, S.RMVSTK FROM [Tbl Productos] AS P INNER JOIN [Tbl Stock] AS S ON P.CODPRO=S.CODPRO
    WHERE $where AND S.MINSTK > S.EXISTK + S.RMCSTK - S.RMVSTK ORDER BY P.CODRUB, S.CODSUC, P.DENPRO;");

$totG = 0.0;
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Reposiciones', 'bi-arrow-repeat', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <?= sl_select('cat', 'cat', $p['cat'], 'Categoría') ?>
    <?= sl_select('rub', 'rub', $p['rub'], 'Rubro') ?>
    <?= sl_select('sub', 'sub', $p['sub'], 'Subrubro') ?>
    <?= sl_select('lin', 'lin', $p['lin'], 'Línea') ?>
    <span class="lst-fpair"><label>Cotiz. u$s</label><input type="number" step="0.0001" name="cot" value="<?= h(rtrim(rtrim(number_format($cot, 4, '.', ''), '0'), '.')) ?>" class="form-control form-control-sm"></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">REPOSICIONES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>CATEGORÍA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'cat')) ?></span>
    <span>RUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'rub')) ?></span>
    <span>SUBRUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'sub')) ?></span>
    <span>LÍNEA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'lin')) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:5.6cm"><col style="width:2.0cm"><col style="width:2.0cm"><col style="width:2.0cm"><col style="width:2.0cm"><col style="width:2.0cm"><col style="width:2.4cm"></colgroup>
    <thead>
      <tr><th rowspan="2">Producto</th><th colspan="2">Última Compra</th><th colspan="3">Stock</th><th rowspan="2" class="r">Total $</th></tr>
      <tr><th class="r">Fecha</th><th class="r">Costo</th><th class="r">Mínimo</th><th class="r">Disponible</th><th class="r">Reposición</th></tr>
    </thead>
    <tbody>
      <?php $prub = $psuc = null; foreach ($rows as $r):
        $rub = (int) $r['CODRUB']; $suc = (int) $r['CODSUC'];
        if ($rub !== $prub || $suc !== $psuc):
          $prub = $rub; $psuc = $suc; ?>
      <tr class="parent"><td colspan="7"><?= h(isset($L['rub'][(string) $rub]) ? $L['rub'][(string) $rub] : '') ?> · <?= h(isset($L['suc'][(string) $suc]) ? $L['suc'][(string) $suc] : '') ?></td></tr>
      <?php endif;
        $disp = (float) nz($r['EXISTK'], 0) + (float) nz($r['RMCSTK'], 0) - (float) nz($r['RMVSTK'], 0);
        $rep = (float) nz($r['MINSTK'], 0) - $disp;
        $cos = (float) nz($r['COSPRO'], 0);
        $tot = $rep * $cos * (((string) $r['CODMON'] === 'D') ? $cot : 1);
        $totG += $tot;
        $sim = isset($L['mon'][(string) $r['CODMON']]) ? $L['mon'][(string) $r['CODMON']] : ''; ?>
      <tr>
        <td><?= h(trim((string) nz($r['DENPRO'], ''))) ?></td>
        <td class="r"><?= h(fecha_serial($r['FUCPRO'])) ?></td>
        <td class="r mono"><?= h($sim) ?> <?= f4($cos) ?></td>
        <td class="r mono"><?= f4($r['MINSTK']) ?></td>
        <td class="r mono"><?= f4($disp) ?></td>
        <td class="r mono"><?= f4($rep) ?></td>
        <td class="r mono"><?= f2($tot) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!count($rows)): ?>
      <tr><td colspan="7" class="text-muted">No hay productos por debajo del stock mínimo.</td></tr>
      <?php endif; ?>
    </tbody>
    <?php if (count($rows)): ?>
    <tfoot><tr class="tot"><td colspan="6">TOTAL</td><td class="r mono"><?= f2($totG) ?></td></tr></tfoot>
    <?php endif; ?>
  </table>
  <div class="lst-tot">TOTAL PRODUCTOS: <?= count($rows) ?></div>
</div>
<?php module_foot(sl_combo_script()); ?>
