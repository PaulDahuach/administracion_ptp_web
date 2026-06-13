<?php
/** Listado de Stocks Mínimos (Rpt SI Stocks Minimos). Productos con fila de Stock, agrupados por
 *  Rubro. Columnas: Código · Denominación · Sucursal · Stock Mínimo (MINSTK). Filtros
 *  Categoría/Rubro/Subrubro/Línea/Sucursal/Proveedor. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/stocklist.php';
auth_require_login();

function f4($v) { return number_format((float) $v, 4, '.', ','); }

$L = sl_lookups();
$p = sl_params();
$cols = array('cat' => 'P.CODCAT', 'rub' => 'P.CODRUB', 'sub' => 'P.CODSUB', 'lin' => 'P.CODLIN', 'suc' => 'S.CODSUC');
$where = '1=1' . sl_where($p, $cols) . sl_where_prv($p, 'P');
$rows = db_query("SELECT P.CODPRO, P.CODRUB, P.DENPRO, S.CODSUC, S.MINSTK
    FROM [Tbl Productos] AS P INNER JOIN [Tbl Stock] AS S ON P.CODPRO=S.CODPRO
    WHERE $where ORDER BY P.CODRUB, P.CODPRO;");

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Stocks Mínimos', 'bi-bezier2', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <?= sl_select('cat', 'cat', $p['cat'], 'Categoría') ?>
    <?= sl_select('rub', 'rub', $p['rub'], 'Rubro') ?>
    <?= sl_select('sub', 'sub', $p['sub'], 'Subrubro') ?>
    <?= sl_select('lin', 'lin', $p['lin'], 'Línea') ?>
    <?= sl_select('suc', 'suc', $p['suc'], 'Sucursal') ?>
    <?= sl_select('prv', 'prv', $p['prv'], 'Proveedor') ?>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">STOCKS MÍNIMOS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>CATEGORÍA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'cat')) ?></span>
    <span>RUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'rub')) ?></span>
    <span>SUBRUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'sub')) ?></span>
    <span>LÍNEA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'lin')) ?></span>
    <span>SUCURSAL</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'suc')) ?></span>
    <span>PROVEEDOR</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'prv')) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:2.0cm"><col style="width:9.5cm"><col style="width:4.0cm"><col style="width:3.0cm"></colgroup>
    <thead><tr><th class="r">Código</th><th>Denominación</th><th>Sucursal</th><th class="r">Stock Mínimo</th></tr></thead>
    <tbody>
      <?php $prub = null; foreach ($rows as $r): $rub = (int) $r['CODRUB'];
        if ($rub !== $prub): $prub = $rub; ?>
      <tr class="parent"><td class="mono"><?= str_pad((string) $rub, 5, '0', STR_PAD_LEFT) ?></td><td colspan="3"><?= h(isset($L['rub'][(string) $rub]) ? $L['rub'][(string) $rub] : '') ?></td></tr>
      <?php endif; ?>
      <tr>
        <td class="r mono"><?= h(trim((string) nz($r['CODPRO'], ''))) ?></td>
        <td><?= h(trim((string) nz($r['DENPRO'], ''))) ?></td>
        <td><?= h(isset($L['suc'][(string) (int) $r['CODSUC']]) ? $L['suc'][(string) (int) $r['CODSUC']] : '') ?></td>
        <td class="r mono"><?= f4($r['MINSTK']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL PRODUCTOS: <?= count($rows) ?></div>
</div>
<?php module_foot(sl_combo_script()); ?>
