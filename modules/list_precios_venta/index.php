<?php
/** Listado de Precios de Venta (Rpt SI Precios de Venta). Matriz: por producto el precio BASE (PLVPRO)
 *  + una columna por categoría de cliente (Tbl Categorias Cuentas Corrientes CODORI='D'):
 *  Precio = PLVPRO − PLVPRO×LDPCAT/100. Agrupado por Rubro. Filtros Categoría/Rubro/Subrubro/Línea. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/stocklist.php';
auth_require_login();

function f4($v) { return number_format((float) $v, 4, '.', ','); }

$L = sl_lookups();
$p = sl_params();

// El legacy reserva SIEMPRE 10 columnas de precio (códigos 01..10), una por cada slot de categoría
// de cliente CODCAT 5..14 (columna i → CODCAT i+4). Las categorías inexistentes quedan en blanco
// (encabezado y valor vacíos), tal cual el Rpt SI Precios de Venta. Porta Precio_1..Precio_10.
$catMap = array();
foreach (db_query("SELECT CODCAT, DENCAT, LDPCAT FROM [Tbl Categorias Cuentas Corrientes] WHERE CODORI='D';") as $c)
    $catMap[(int) $c['CODCAT']] = array('den' => trim((string) nz($c['DENCAT'], '')), 'ldp' => (float) nz($c['LDPCAT'], 0));
$catCols = array();
for ($i = 1; $i <= 10; $i++) {
    $cod = 4 + $i; // CODCAT 5..14
    $catCols[] = isset($catMap[$cod])
        ? array('code' => $i, 'den' => $catMap[$cod]['den'], 'ldp' => $catMap[$cod]['ldp'], 'on' => true)
        : array('code' => $i, 'den' => '', 'ldp' => null, 'on' => false);
}

// Columna BASE (PLVPRO) opcional, porta el chkCosPro del Menú legacy. Default mostrada; se oculta
// destildándola. El marcador 'f' distingue "primera carga" (default tildado) de "form enviado".
$showBase = isset($_GET['f']) ? isset($_GET['base']) : true;

$cols = array('cat' => 'P.CODCAT', 'rub' => 'P.CODRUB', 'sub' => 'P.CODSUB', 'lin' => 'P.CODLIN');
$where = '1=1' . sl_where($p, $cols);
$rows = db_query("SELECT P.CODPRO, P.CODRUB, P.DENPRO, P.CODMON, P.PLVPRO
    FROM [Tbl Productos] AS P WHERE $where ORDER BY P.CODRUB, P.DENPRO;");

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Precios de Venta', 'bi-cash', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <input type="hidden" name="f" value="1">
  <div class="lst-fgrid3">
    <?= sl_select('cat', 'cat', $p['cat'], 'Categoría') ?>
    <?= sl_select('rub', 'rub', $p['rub'], 'Rubro') ?>
    <?= sl_select('sub', 'sub', $p['sub'], 'Subrubro') ?>
    <?= sl_select('lin', 'lin', $p['lin'], 'Línea') ?>
    <div class="form-check"><input type="checkbox" class="form-check-input" id="chkBase" name="base" value="1"<?= $showBase ? ' checked' : '' ?>><label class="form-check-label" for="chkBase">Mostrar Base</label></div>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<style>
  /* Precios de Venta es apaisado (como el Rpt legacy): hoja Carta horizontal (279×216mm) */
  @page { size: Letter landscape; margin: 10mm 12mm; }
  .lst-doc.lst-land { width: 279mm; min-height: 216mm; }
  @media print { .lst-doc.lst-land { width: auto; min-height: 0; } }
</style>
<div class="lst-doc lst-doc-wide lst-land">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">PRECIOS DE VENTA</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>CATEGORÍA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'cat')) ?></span>
    <span>RUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'rub')) ?></span>
    <span>SUBRUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'sub')) ?></span>
    <span>LÍNEA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'lin')) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup>
      <col style="width:4.6cm"><col style="width:1.3cm"><?php if ($showBase): ?><col style="width:1.9cm"><?php endif; ?>
      <?php foreach ($catCols as $c): ?><col style="width:1.85cm"><?php endforeach; ?>
    </colgroup>
    <thead>
      <tr>
        <th rowspan="4">Producto</th><th rowspan="4" class="r">Código</th><?php if ($showBase): ?><th rowspan="4" class="r">Base</th><?php endif; ?>
        <th colspan="10">NETO + IVA</th>
      </tr>
      <tr>
        <?php foreach ($catCols as $c): ?><th class="r"><?= h($c['den']) ?></th><?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($catCols as $c): ?><th class="r"><?= $c['on'] ? number_format($c['ldp'], 2, '.', '') . '%' : '' ?></th><?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($catCols as $c): ?><th class="r"><?= str_pad((string) $c['code'], 2, '0', STR_PAD_LEFT) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php $prub = null; foreach ($rows as $r): $rub = (int) $r['CODRUB'];
        if ($rub !== $prub): $prub = $rub; ?>
      <tr class="parent"><td colspan="<?= 2 + ($showBase ? 1 : 0) + count($catCols) ?>"><?= h(isset($L['rub'][(string) $rub]) ? $L['rub'][(string) $rub] : '') ?></td></tr>
      <?php endif;
        $plv = (float) nz($r['PLVPRO'], 0);
        $sim = isset($L['mon'][(string) $r['CODMON']]) ? $L['mon'][(string) $r['CODMON']] : ''; ?>
      <tr>
        <td><?= h(trim((string) nz($r['DENPRO'], ''))) ?></td>
        <td class="r mono"><?= h(trim((string) nz($r['CODPRO'], ''))) ?></td>
        <?php if ($showBase): ?><td class="r mono"><?= h($sim) ?> <?= f4($plv) ?></td><?php endif; ?>
        <?php foreach ($catCols as $c): ?>
        <td class="r mono"><?= $c['on'] ? f4($plv - $plv * $c['ldp'] / 100) : '' ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL PRODUCTOS: <?= count($rows) ?></div>
</div>
<?php module_foot(sl_combo_script()); ?>
