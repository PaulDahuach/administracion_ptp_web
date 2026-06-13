<?php
/** Listado de Cuentas Corrientes Deudoras (Rpt CD Cuentas x ...). Mismo dataset, 6 órdenes:
 *  ?orden=denom (plano) | categoria | condicion | localidad | vendedor | zona (agrupados).
 *  Detalle = DENOMINACIÓN + CÓDIGO por denominación; los agrupados anteponen una cabecera de grupo
 *  (código + nombre, + % descuento en categoría). Agrupación/orden en PHP (evita GROUP de ACE). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$ordenes = array(
    'denom'     => array('lbl' => 'x Denominación', 'tit' => 'x DENOMINACION'),
    'categoria' => array('lbl' => 'x Categoría', 'tit' => 'x CATEGORIA', 'gf' => 'CODCAT', 'lk' => 'Tbl Categorias Cuentas Corrientes', 'lw' => "CODORI='D'", 'pk' => 'CODCAT', 'den' => 'DENCAT', 'extra' => 'LDPCAT', 'gby' => 'code', 'glabel' => 'Categoría', 'gright' => '% Descuento'),
    'condicion' => array('lbl' => 'x Condición de Venta', 'tit' => 'x CONDICION DE VENTA', 'gf' => 'CODCDV', 'lk' => 'Tbl Condiciones de Venta', 'pk' => 'CODCDV', 'den' => 'DENCDV', 'gby' => 'code', 'glabel' => 'Condición de Venta'),
    'localidad' => array('lbl' => 'x Localidad', 'tit' => 'x LOCALIDAD', 'gf' => 'CODLOC', 'lk' => 'Tbl Localidades', 'pk' => 'CODLOC', 'den' => 'DENLOC', 'code' => 'CPXLOC', 'gby' => 'den', 'glabel' => 'Localidad'),
    'vendedor'  => array('lbl' => 'x Vendedor', 'tit' => 'x VENDEDOR', 'gf' => 'CODVEN', 'lk' => 'Tbl Vendedores', 'pk' => 'CODVEN', 'den' => 'DENVEN', 'gby' => 'den', 'glabel' => 'Vendedor'),
    'zona'      => array('lbl' => 'x Zona', 'tit' => 'x ZONA', 'gf' => 'CODZON', 'lk' => 'Tbl Zonas', 'pk' => 'CODZON', 'den' => 'DENZON', 'gby' => 'den', 'glabel' => 'Zona'),
);
$orden = isset($_GET['orden']) && isset($ordenes[$_GET['orden']]) ? $_GET['orden'] : 'denom';
$cfg = $ordenes[$orden];
$grouped = isset($cfg['gf']);

$cuentas = db_query("SELECT CODCUE, DENCUE, CODCAT, CODCDV, CODLOC, CODVEN, CODZON
    FROM [Tbl Cuentas Corrientes] WHERE CODORI='D' ORDER BY DENCUE;");

$groups = array(); $keys = array(); $lkmap = array();
if ($grouped) {
    $sel = $cfg['pk'] . ', ' . $cfg['den'];
    if (isset($cfg['extra'])) $sel .= ', ' . $cfg['extra'];
    if (isset($cfg['code']))  $sel .= ', ' . $cfg['code'];
    $lkw = isset($cfg['lw']) ? ' WHERE ' . $cfg['lw'] : '';
    foreach (db_query("SELECT $sel FROM [" . $cfg['lk'] . "]$lkw;") as $r) {
        $k = (int) $r[$cfg['pk']];
        $lkmap[$k] = array(
            'den'   => trim((string) nz($r[$cfg['den']], '')),
            'extra' => isset($cfg['extra']) ? $r[$cfg['extra']] : null,
            'code'  => isset($cfg['code']) ? trim((string) nz($r[$cfg['code']], '')) : str_pad((string) $k, 8, '0', STR_PAD_LEFT),
        );
    }
    // INNER JOIN del reporte legacy: la cuenta sólo aparece si su grupo existe en la tabla de lookup
    // (ej. x Vendedor: las cuentas sin vendedor —CODVEN=0— no figuran).
    foreach ($cuentas as $c) {
        $gk = (int) nz($c[$cfg['gf']], 0);
        if (!isset($lkmap[$gk])) continue;
        if (!isset($groups[$gk])) $groups[$gk] = array();
        $groups[$gk][] = $c;
    }
    $keys = array_keys($groups);
    usort($keys, function ($a, $b) use ($cfg, $lkmap) {
        if ($cfg['gby'] === 'den') {
            $da = isset($lkmap[$a]) ? $lkmap[$a]['den'] : '~';
            $db = isset($lkmap[$b]) ? $lkmap[$b]['den'] : '~';
            return strcasecmp($da, $db);
        }
        return $a - $b;
    });
}

$titulo = 'Cuentas Corrientes Deudoras ' . $cfg['lbl'];
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head($titulo, 'bi-person-vcard', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<style>
  /* pixel-perfect Rpt CD Cuentas x ... — fuentes EXACTAS del .report (Univers Condensed):
     caption 7pt · group-by 9pt BOLD · detalle 9pt. Alineaciones del .report. SIN truncar
     (overflow visible, nada de ellipsis: si algo no entra, mejor que sobresalga a que se corte). */
  .lst-tbl.cd-grp { font-size: 9pt; }
  .lst-tbl.cd-grp thead th { font-size: 7pt; text-align: left; }
  .lst-tbl.cd-grp thead th.c { text-align: center; }
  .lst-tbl.cd-grp tbody td { font-size: 9pt; height: .36cm; line-height: .36cm; white-space: nowrap; overflow: visible; text-overflow: clip; }
  .lst-tbl.cd-grp tbody tr.parent td { font-weight: 700; }
  /* celdas de encabezado SIN label (col del código de grupo / % descuento en la 2ª fila): sin recuadro */
  .lst-tbl.cd-grp thead th.nb { border: 0; background: transparent; }
  /* x Denominación (plana): captions 8pt CENTRADOS (el .report usa 8pt al=2, no 7pt) */
  .lst-tbl.cd-flat thead th { font-size: 8pt; text-align: center; }
</style>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">CUENTAS CORRIENTES DEUDORAS <?= h($cfg['tit']) ?></div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <?php
  // Geometría pixel-perfect del .report (cm; total 19.0). col1=código de grupo · col2=denominación ·
  // col3=código cuenta · col4=% descuento (sólo Categoría). El detalle se indenta (empieza en col2).
  $geom = array(
      'categoria' => array('c1' => 1.40, 'cod' => 1.60, 'ext' => 1.60),
      'condicion' => array('c1' => 1.40, 'cod' => 1.40, 'ext' => 0.0),
      'localidad' => array('c1' => 0.90, 'cod' => 1.40, 'ext' => 0.0),
      'vendedor'  => array('c1' => 1.40, 'cod' => 1.40, 'ext' => 0.0),
      'zona'      => array('c1' => 1.40, 'cod' => 1.40, 'ext' => 0.0),
  );
  $g = $grouped ? $geom[$orden] : null;
  $hasExt = $grouped && $g['ext'] > 0;
  $cden = $grouped ? (19.0 - $g['c1'] - $g['cod'] - $g['ext']) : 17.60;
  ?>
  <table class="lst-tbl lst-tight cd-grp<?= $grouped ? '' : ' cd-flat' ?>" style="table-layout:fixed; width:19.0cm">
    <colgroup>
      <?php if ($grouped): ?>
      <col style="width:<?= number_format($g['c1'], 2) ?>cm"><col style="width:<?= number_format($cden, 2) ?>cm"><col style="width:<?= number_format($g['cod'], 2) ?>cm"><?php if ($hasExt): ?><col style="width:<?= number_format($g['ext'], 2) ?>cm"><?php endif; ?>
      <?php else: ?>
      <col style="width:17.60cm"><col style="width:1.40cm">
      <?php endif; ?>
    </colgroup>
    <thead>
      <?php if ($grouped): ?>
      <tr><th colspan="3"><?= h($cfg['glabel']) ?></th><?php if ($hasExt): ?><th class="c" rowspan="2" style="vertical-align:top"><?= h($cfg['gright']) ?></th><?php endif; ?></tr>
      <tr><th class="nb"></th><th>Denominación</th><th class="c">Código</th></tr>
      <?php else: ?>
      <tr><th>Denominación</th><th class="c">Código</th></tr>
      <?php endif; ?>
    </thead>
    <tbody>
      <?php if (!$grouped): ?>
      <?php foreach ($cuentas as $c): ?>
      <tr><td><?= h(trim((string) nz($c['DENCUE'], ''))) ?></td><td class="r mono"><?= str_pad((string) (int) $c['CODCUE'], 8, '0', STR_PAD_LEFT) ?></td></tr>
      <?php endforeach; ?>
      <?php else: ?>
      <?php foreach ($keys as $gk):
        $gcode = isset($lkmap[$gk]) ? $lkmap[$gk]['code'] : str_pad((string) $gk, 8, '0', STR_PAD_LEFT);
        $gden  = isset($lkmap[$gk]) ? $lkmap[$gk]['den'] : '(Sin asignar)';
        $gextra = ($hasExt && isset($lkmap[$gk]) && $lkmap[$gk]['extra'] !== null) ? number_format((float) $lkmap[$gk]['extra'], 2, '.', ',') : ''; ?>
      <tr class="parent">
        <td class="mono"><?= h($gcode) ?></td>
        <td colspan="2"><?= h($gden) ?></td>
        <?php if ($hasExt): ?><td class="r mono"><?= h($gextra) ?></td><?php endif; ?>
      </tr>
      <?php foreach ($groups[$gk] as $c): ?>
      <tr>
        <td></td>
        <td><?= h(trim((string) nz($c['DENCUE'], ''))) ?></td>
        <td class="r mono"><?= str_pad((string) (int) $c['CODCUE'], 8, '0', STR_PAD_LEFT) ?></td>
        <?php if ($hasExt): ?><td></td><?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  <?php $tEnt = $grouped ? array_sum(array_map('count', $groups)) : count($cuentas); ?>
  <div class="lst-tot">TOTAL ENTIDADES: <?= $tEnt ?></div>
</div>
<?php module_foot(); ?>
