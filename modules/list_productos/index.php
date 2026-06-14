<?php
/** Listado de Productos (Rpt SI Productos x Codigo / x Denominacion / x Nivel).
 *  ?orden=codigo|denom|nivel. Mismo dataset + filtros (Categoría/Rubro/Subrubro/Línea/Proveedor);
 *  difieren en orden y agrupación. Proveedor y Unidades/Factor = subreportes embebidos del legacy
 *  (Tbl Productos Proveedores → DENCUE · Tbl Productos Unidades → DENUDM+FCTPUM). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/stocklist.php';
auth_require_login();

$ordenes = array('codigo' => 'x Código', 'denom' => 'x Denominación', 'nivel' => 'x Nivel');
$orden = isset($_GET['orden']) && isset($ordenes[$_GET['orden']]) ? $_GET['orden'] : 'codigo';

$L = sl_lookups();
$p = sl_params();
$cols = array('cat' => 'CODCAT', 'rub' => 'CODRUB', 'sub' => 'CODSUB', 'lin' => 'CODLIN');
$where = '1=1' . sl_where($p, $cols) . sl_where_prv($p, '');
// sl_where_prv usa $palias.CODPRO; sin alias = "CODPRO" (tabla única)
$where = str_replace('.CODPRO', 'CODPRO', $where);

$sql_order = ($orden === 'denom') ? 'DENPRO, CODPRO' : 'CODPRO';
$prods = db_query("SELECT CODPRO, DENPRO, CODCAT, CODRUB, CODSUB, CODLIN, CODUDM FROM [Tbl Productos] WHERE $where ORDER BY $sql_order;");

// mapas de proveedores y unidades por producto (CODPRO es string)
$prv_sel = $p['prv'];
$provMap = array();
$pq = "SELECT PP.CODPRO, C.DENCUE FROM [Tbl Productos Proveedores] AS PP INNER JOIN [Tbl Cuentas Corrientes] AS C ON C.CODCUE=PP.CODCUE";
if ($prv_sel !== '') $pq .= " WHERE PP.CODCUE=" . (int) $prv_sel;
$pq .= " ORDER BY C.DENCUE;";
foreach (db_query($pq) as $r) { $k = (string) $r['CODPRO']; if (!isset($provMap[$k])) $provMap[$k] = array(); $provMap[$k][] = trim((string) nz($r['DENCUE'], '')); }

$uniMap = array();
foreach (db_query("SELECT PU.CODPRO, PU.CODUDM, PU.FCTPUM FROM [Tbl Productos Unidades] AS PU ORDER BY PU.FCTPUM;") as $r) {
    $k = (string) $r['CODPRO']; $u = (int) $r['CODUDM'];
    if (!isset($uniMap[$k])) $uniMap[$k] = array();
    $den = isset($L['udm'][$u]) ? $L['udm'][$u]['den'] : '';
    $uniMap[$k][] = $den . ' ' . number_format((float) nz($r['FCTPUM'], 0), 4, '.', '');
}
function prov_txt($map, $cod) { return isset($map[(string) $cod]) ? implode(', ', $map[(string) $cod]) : ''; }
function uni_txt($map, $cod) { return isset($map[(string) $cod]) ? implode('; ', $map[(string) $cod]) : ''; }

// para x Nivel: re-ordenar por DENCAT,DENRUB,DENSUB,DENLIN,DENPRO (los nombres salen de los lookups)
function den($L, $set, $code) { $c = (string) (int) $code; if (!isset($L[$set][$c])) return ''; $v = $L[$set][$c]; return is_array($v) ? $v['den'] : $v; }
if ($orden === 'nivel') {
    usort($prods, function ($a, $b) use ($L) {
        foreach (array(array('cat', 'CODCAT'), array('rub', 'CODRUB'), array('sub', 'CODSUB'), array('lin', 'CODLIN')) as $k) {
            $c = strcasecmp(den($L, $k[0], $a[$k[1]]), den($L, $k[0], $b[$k[1]]));
            if ($c) return $c;
        }
        return strcasecmp(trim((string) $a['DENPRO']), trim((string) $b['DENPRO']));
    });
}

$titulo = 'Listado de Productos ' . $ordenes[$orden];
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head($titulo, 'bi-box-seam', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <input type="hidden" name="orden" value="<?= h($orden) ?>">
  <div class="lst-fgrid3">
    <?= sl_select('cat', 'cat', $p['cat'], 'Categoría') ?>
    <?= sl_select('rub', 'rub', $p['rub'], 'Rubro') ?>
    <?= sl_select('sub', 'sub', $p['sub'], 'Subrubro') ?>
    <?= sl_select('lin', 'lin', $p['lin'], 'Línea') ?>
    <?= sl_select('prv', 'prv', $p['prv'], 'Proveedor') ?>
  </div>
  <button id="btnVer" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">PRODUCTOS <?= h(strtoupper($ordenes[$orden])) ?></div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>CATEGORÍA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'cat')) ?></span>
    <span>RUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'rub')) ?></span>
    <span>SUBRUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'sub')) ?></span>
    <span>LÍNEA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'lin')) ?></span>
    <span>PROVEEDOR</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'prv')) ?></span>
  </div>
  <?php if ($orden === 'nivel'): ?>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:3.4cm"><col style="width:2.6cm"><col style="width:2.6cm"><col style="width:2.0cm"><col style="width:4.4cm"><col style="width:1.4cm"><col style="width:3.0cm"></colgroup>
    <thead><tr><th>Categoría</th><th>Rubro</th><th>Subrubro</th><th>Línea</th><th>Denominación</th><th class="r">Código</th><th>Proveedor</th></tr></thead>
    <tbody>
      <?php $pc=$pr=$ps=$pl=null; foreach ($prods as $r):
        $cat=den($L,'cat',$r['CODCAT']); $rub=den($L,'rub',$r['CODRUB']); $sub=den($L,'sub',$r['CODSUB']); $lin=den($L,'lin',$r['CODLIN']);
        $sc=($cat!==$pc); $sr=$sc||($rub!==$pr); $ss=$sr||($sub!==$ps); $sl_=$ss||($lin!==$pl); ?>
      <tr>
        <td><?= $sc ? h($cat) : '' ?></td>
        <td><?= $sr ? h($rub) : '' ?></td>
        <td><?= $ss ? h($sub) : '' ?></td>
        <td><?= $sl_ ? h($lin) : '' ?></td>
        <td><?= h(trim((string) nz($r['DENPRO'], ''))) ?></td>
        <td class="r mono"><?= h(trim((string) nz($r['CODPRO'], ''))) ?></td>
        <td><?= h(prov_txt($provMap, $r['CODPRO'])) ?></td>
      </tr>
      <?php $pc=$cat;$pr=$rub;$ps=$sub;$pl=$lin; endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <table class="lst-tbl lst-jer">
    <?php if ($orden === 'denom'): ?>
    <colgroup><col style="width:4.2cm"><col style="width:2.8cm"><col style="width:2.2cm"><col style="width:2.2cm"><col style="width:1.6cm"><col style="width:1.3cm"><col style="width:2.6cm"><col style="width:2.3cm"></colgroup>
    <thead><tr><th>Denominación</th><th>Categoría</th><th>Rubro</th><th>Subrubro</th><th>Línea</th><th class="r">Código</th><th>Proveedor</th><th>Unidades / Factor</th></tr></thead>
    <tbody>
      <?php foreach ($prods as $r): ?>
      <tr>
        <td><?= h(trim((string) nz($r['DENPRO'], ''))) ?></td>
        <td><?= h(den($L,'cat',$r['CODCAT'])) ?></td>
        <td><?= h(den($L,'rub',$r['CODRUB'])) ?></td>
        <td><?= h(den($L,'sub',$r['CODSUB'])) ?></td>
        <td><?= h(den($L,'lin',$r['CODLIN'])) ?></td>
        <td class="r mono"><?= h(trim((string) nz($r['CODPRO'], ''))) ?></td>
        <td><?= h(prov_txt($provMap, $r['CODPRO'])) ?></td>
        <td><?= h(uni_txt($uniMap, $r['CODPRO'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php else: ?>
    <colgroup><col style="width:1.3cm"><col style="width:4.2cm"><col style="width:2.8cm"><col style="width:2.2cm"><col style="width:2.2cm"><col style="width:1.6cm"><col style="width:2.6cm"><col style="width:2.3cm"></colgroup>
    <thead><tr><th class="r">Código</th><th>Denominación</th><th>Categoría</th><th>Rubro</th><th>Subrubro</th><th>Línea</th><th>Proveedor</th><th>Unidades / Factor</th></tr></thead>
    <tbody>
      <?php foreach ($prods as $r): ?>
      <tr>
        <td class="r mono"><?= h(trim((string) nz($r['CODPRO'], ''))) ?></td>
        <td><?= h(trim((string) nz($r['DENPRO'], ''))) ?></td>
        <td><?= h(den($L,'cat',$r['CODCAT'])) ?></td>
        <td><?= h(den($L,'rub',$r['CODRUB'])) ?></td>
        <td><?= h(den($L,'sub',$r['CODSUB'])) ?></td>
        <td><?= h(den($L,'lin',$r['CODLIN'])) ?></td>
        <td><?= h(prov_txt($provMap, $r['CODPRO'])) ?></td>
        <td><?= h(uni_txt($uniMap, $r['CODPRO'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php endif; ?>
  </table>
  <?php endif; ?>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($prods) ?></div>
</div>
<?php module_foot(sl_combo_script()); ?>
