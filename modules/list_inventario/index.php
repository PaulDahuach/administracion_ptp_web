<?php
/** Listado de Inventario (Rpt SI Inventario Actual / Periodico). ?tipo=actual|periodico.
 *  - Actual: foto del Stock cacheado (EXISTK/RMCSTK/RMVSTK) valorizado a COSPRO. Agrupa
 *    Unidad > Categoría > Rubro > Producto. Filtros Categoría/Rubro/Sucursal.
 *  - Periódico: existencia reconstruida de los movimientos hasta una fecha (Σ(INGMOV−EGRMOV)×FCTMOV,
 *    CEFMOV<=fecha), valorizada. Agrupa Unidad > Sucursal > Categoría > Rubro > Subrubro > Línea >
 *    Producto con %/Dep y %/Tot. Filtros Categoría/Rubro/Subrubro/Línea/Sucursal + fecha + libro (ESTMOV). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/stocklist.php';
auth_require_login();

function f2($v) { return number_format((float) $v, 2, '.', ','); }
function f4($v) { return number_format((float) $v, 4, '.', ','); }
function pct($v) { return number_format((float) $v, 2, '.', ''); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

$tipo = (isset($_GET['tipo']) && $_GET['tipo'] === 'periodico') ? 'periodico' : 'actual';
$L = sl_lookups();
$p = sl_params();

/** Agrupador recursivo: agrupa $rows por la cadena $levels (cada uno = ['code','label']),
 *  sumando $sumKeys, y llama a $emit(depth, code, label, agg, parentVal) por nodo y
 *  $leaf($row) por producto hoja. Devuelve el agregado del subárbol. */
function inv_render($rows, $levels, $depth, $sumKeys, $emit, $leaf, $parentVal) {
    if ($depth >= count($levels)) { foreach ($rows as $r) $leaf($r); return; }
    $lv = $levels[$depth];
    $groups = array();
    foreach ($rows as $r) { $k = $lv['code']($r); if (!isset($groups[$k])) $groups[$k] = array('label' => $lv['label']($r), 'rows' => array()); $groups[$k]['rows'][] = $r; }
    foreach ($groups as $code => $g) {
        $agg = array(); foreach ($sumKeys as $sk) $agg[$sk] = 0.0;
        foreach ($g['rows'] as $r) foreach ($sumKeys as $sk) $agg[$sk] += (float) $r[$sk];
        $emit($depth, $code, $g['label'], $agg, $parentVal);
        $pv = isset($agg['val']) ? $agg['val'] : 0.0;
        inv_render($g['rows'], $levels, $depth + 1, $sumKeys, $emit, $leaf, $pv);
    }
}

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Inventario ' . ucfirst($tipo), 'bi-clipboard-data', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');

if ($tipo === 'actual') {
    $cols = array('cat' => 'P.CODCAT', 'rub' => 'P.CODRUB', 'suc' => 'S.CODSUC');
    $where = '1=1' . sl_where($p, $cols);
    $det = db_query("SELECT S.CODPRO, P.DENPRO, S.EXISTK, S.RMCSTK, S.RMVSTK, P.COSPRO, P.CODUDM, P.CODCAT, P.CODRUB, P.CODMON, P.FUCPRO, S.CODSUC
        FROM [Tbl Productos] AS P INNER JOIN [Tbl Stock] AS S ON P.CODPRO=S.CODPRO WHERE $where;");
    // valores por fila
    $rows = array();
    foreach ($det as $r) {
        $rmc = (float) nz($r['RMCSTK'], 0); $exi = (float) nz($r['EXISTK'], 0); $rmv = (float) nz($r['RMVSTK'], 0);
        $fis = $exi + $rmc; $dsp = $fis - $rmv; $cos = (float) nz($r['COSPRO'], 0);
        $r['rmc'] = $rmc; $r['exi'] = $exi; $r['fis'] = $fis; $r['rmv'] = $rmv; $r['dsp'] = $dsp;
        $r['vfis'] = $cos * $fis; $r['vdsp'] = $cos * $dsp; $r['_cos'] = $cos;
        $rows[] = $r;
    }
    usort($rows, function ($a, $b) use ($L) {
        $c = strcasecmp(udm_den($L, $a['CODUDM']), udm_den($L, $b['CODUDM'])); if ($c) return $c;
        $c = strcasecmp(den2($L, 'cat', $a['CODCAT']), den2($L, 'cat', $b['CODCAT'])); if ($c) return $c;
        $c = strcasecmp(den2($L, 'rub', $a['CODRUB']), den2($L, 'rub', $b['CODRUB'])); if ($c) return $c;
        return strcasecmp(trim((string) $a['DENPRO']), trim((string) $b['DENPRO']));
    });
    $levels = array(
        array('code' => function ($r) { return 'U' . (int) $r['CODUDM']; }, 'label' => function ($r) use ($L) { return udm_den($L, $r['CODUDM']); }),
        array('code' => function ($r) { return 'C' . (int) $r['CODCAT']; }, 'label' => function ($r) use ($L) { return str_pad((string) (int) $r['CODCAT'], 2, '0', STR_PAD_LEFT) . ' ' . den2($L, 'cat', $r['CODCAT']); }),
        array('code' => function ($r) { return 'R' . (int) $r['CODRUB']; }, 'label' => function ($r) use ($L) { return str_pad((string) (int) $r['CODRUB'], 2, '0', STR_PAD_LEFT) . ' ' . den2($L, 'rub', $r['CODRUB']); }),
    );
    $sumKeys = array('rmc','exi','fis','rmv','dsp','vfis','vdsp','val');
    foreach ($rows as &$rr) { $rr['val'] = $rr['vfis']; } unset($rr);
    ?>
    <link href="../../assets/css/listado.css?v=26" rel="stylesheet">
    <form method="get" class="lst-filter no-print" data-bs-theme="light">
      <input type="hidden" name="tipo" value="actual">
      <div class="lst-fgrid3">
        <?= sl_select('cat', 'cat', $p['cat'], 'Categoría') ?>
        <?= sl_select('rub', 'rub', $p['rub'], 'Rubro') ?>
        <?= sl_select('suc', 'suc', $p['suc'], 'Sucursal') ?>
      </div>
      <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
    </form>
    <div class="lst-doc lst-doc-wide">
      <div class="lst-head">
        <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
        <div class="lst-tit">INVENTARIO ACTUAL</div>
        <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
      </div>
      <div class="lst-params">
        <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
        <span>CATEGORÍA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'cat')) ?></span>
        <span>RUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'rub')) ?></span>
        <span>SUCURSAL</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'suc')) ?></span>
      </div>
      <table class="lst-tbl lst-jer">
        <colgroup><col style="width:5.4cm"><col style="width:1.6cm"><col style="width:1.6cm"><col style="width:1.6cm"><col style="width:1.7cm"><col style="width:1.6cm"><col style="width:1.8cm"><col style="width:1.6cm"><col style="width:1.9cm"><col style="width:1.9cm"></colgroup>
        <thead>
          <tr><th rowspan="2">Nivel / Producto</th><th colspan="5">Unidades</th><th colspan="2">Última Compra</th><th colspan="2">Valorizado</th></tr>
          <tr><th class="r">RM Compras</th><th class="r">Existentes</th><th class="r">Físicas</th><th class="r">Comprom.</th><th class="r">Disponibles</th><th class="r">Fecha</th><th class="r">Costo</th><th class="r">Físicas</th><th class="r">Disponibles</th></tr>
        </thead>
        <tbody>
          <?php
          $emit = function ($depth, $code, $label, $agg, $pv) {
              echo '<tr class="parent"><td><span style="padding-left:' . ($depth * 14) . 'px">' . h($label) . '</span></td>';
              echo '<td class="r mono">' . f2($agg['rmc']) . '</td><td class="r mono">' . f2($agg['exi']) . '</td><td class="r mono">' . f2($agg['fis']) . '</td><td class="r mono">' . f2($agg['rmv']) . '</td><td class="r mono">' . f2($agg['dsp']) . '</td>';
              echo '<td></td><td></td><td class="r mono">' . f2($agg['vfis']) . '</td><td class="r mono">' . f2($agg['vdsp']) . '</td></tr>';
          };
          $leaf = function ($r) use ($L) {
              $sim = isset($L['mon'][(string) $r['CODMON']]) ? $L['mon'][(string) $r['CODMON']] : '';
              echo '<tr><td><span style="padding-left:42px" class="mono">' . h(trim((string) $r['CODPRO'])) . '</span> ' . h(trim((string) nz($r['DENPRO'], ''))) . '</td>';
              echo '<td class="r mono">' . f2($r['rmc']) . '</td><td class="r mono">' . f2($r['exi']) . '</td><td class="r mono">' . f2($r['fis']) . '</td><td class="r mono">' . f2($r['rmv']) . '</td><td class="r mono">' . f2($r['dsp']) . '</td>';
              echo '<td class="r">' . h(fecha_serial($r['FUCPRO'])) . '</td><td class="r mono">' . h($sim) . ' ' . f2($r['_cos']) . '</td><td class="r mono">' . f2($r['vfis']) . '</td><td class="r mono">' . f2($r['vdsp']) . '</td></tr>';
          };
          inv_render($rows, $levels, 0, $sumKeys, $emit, $leaf, 0.0);
          // total
          $T = array('rmc'=>0,'exi'=>0,'fis'=>0,'rmv'=>0,'dsp'=>0,'vfis'=>0,'vdsp'=>0);
          foreach ($rows as $r) foreach ($T as $k => $v) $T[$k] += $r[$k];
          ?>
          <tr class="tot"><td>TOTAL INVENTARIO:</td>
            <td class="r mono"><?= f2($T['rmc']) ?></td><td class="r mono"><?= f2($T['exi']) ?></td><td class="r mono"><?= f2($T['fis']) ?></td><td class="r mono"><?= f2($T['rmv']) ?></td><td class="r mono"><?= f2($T['dsp']) ?></td>
            <td></td><td></td><td class="r mono"><?= f2($T['vfis']) ?></td><td class="r mono"><?= f2($T['vdsp']) ?></td></tr>
        </tbody>
      </table>
    </div>
    <?php
} else {
    // ── PERIÓDICO ──
    $hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : date('Y-m-d');
    $sh = sp_serial($hasIso);
    $lib = auth_libro_unico();
    $estW = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');
    // existencia por producto+sucursal hasta la fecha (CEFMOV null o <= corte)
    $exMap = array();
    foreach (db_query("SELECT MoS.CODPRO, MoS.CODSUC, SUM(MoS.INGMOV*MoS.FCTMOV) AS ING, SUM(MoS.EGRMOV*MoS.FCTMOV) AS EGR
        FROM [Tbl Movimientos Stock] AS MoS INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MoS.NUMMOV
        WHERE MoS.STKMOV=True AND (M.CEFMOV Is Null OR M.CEFMOV <= $sh)$estW
        GROUP BY MoS.CODPRO, MoS.CODSUC;") as $r) {
        $k = (string) $r['CODPRO'] . '|' . (int) $r['CODSUC'];
        $exMap[$k] = (float) nz($r['ING'], 0) - (float) nz($r['EGR'], 0);
    }
    // maestro de productos con Stock (para clasificación + costo); existencia del mapa
    $cols = array('cat' => 'P.CODCAT', 'rub' => 'P.CODRUB', 'sub' => 'P.CODSUB', 'lin' => 'P.CODLIN', 'suc' => 'S.CODSUC');
    $where = '1=1' . sl_where($p, $cols);
    $rows = array();
    foreach (db_query("SELECT P.CODPRO, P.DENPRO, P.COSPRO, P.CODUDM, P.CODCAT, P.CODRUB, P.CODSUB, P.CODLIN, P.CODMON, P.FUCPRO, S.CODSUC
        FROM [Tbl Productos] AS P INNER JOIN [Tbl Stock] AS S ON P.CODPRO=S.CODPRO WHERE $where;") as $r) {
        $k = (string) $r['CODPRO'] . '|' . (int) $r['CODSUC'];
        $ex = isset($exMap[$k]) ? $exMap[$k] : 0.0;
        if ($ex == 0.0) continue; // el legacy sólo lista lo que tuvo movimiento (INNER por Movimientos)
        $cos = (float) nz($r['COSPRO'], 0);
        $r['ex'] = $ex; $r['val'] = $cos * $ex; $r['_cos'] = $cos;
        $rows[] = $r;
    }
    $grand = 0.0; foreach ($rows as $r) $grand += $r['val'];
    usort($rows, function ($a, $b) use ($L) {
        foreach (array(array('udm','CODUDM'), array('suc','CODSUC'), array('cat','CODCAT'), array('rub','CODRUB'), array('sub','CODSUB'), array('lin','CODLIN')) as $lv) {
            $c = strcasecmp(anyden($L, $lv[0], $a[$lv[1]]), anyden($L, $lv[0], $b[$lv[1]])); if ($c) return $c;
        }
        return strcasecmp(trim((string) $a['DENPRO']), trim((string) $b['DENPRO']));
    });
    $levels = array(
        array('code' => function ($r) { return 'U' . (int) $r['CODUDM']; }, 'label' => function ($r) use ($L) { return udm_den($L, $r['CODUDM']); }),
        array('code' => function ($r) { return 'S' . (int) $r['CODSUC']; }, 'label' => function ($r) use ($L) { return (int) $r['CODSUC'] . ' ' . anyden($L, 'suc', $r['CODSUC']); }),
        array('code' => function ($r) { return 'C' . (int) $r['CODCAT']; }, 'label' => function ($r) use ($L) { return (int) $r['CODCAT'] . ' ' . anyden($L, 'cat', $r['CODCAT']); }),
        array('code' => function ($r) { return 'R' . (int) $r['CODRUB']; }, 'label' => function ($r) use ($L) { return (int) $r['CODRUB'] . ' ' . anyden($L, 'rub', $r['CODRUB']); }),
        array('code' => function ($r) { return 'B' . (int) $r['CODSUB']; }, 'label' => function ($r) use ($L) { return (int) $r['CODSUB'] . ' ' . anyden($L, 'sub', $r['CODSUB']); }),
        array('code' => function ($r) { return 'L' . (int) $r['CODLIN']; }, 'label' => function ($r) use ($L) { return (int) $r['CODLIN'] . ' ' . anyden($L, 'lin', $r['CODLIN']); }),
    );
    $sumKeys = array('ex','val');
    ?>
    <link href="../../assets/css/listado.css?v=26" rel="stylesheet">
    <form method="get" class="lst-filter no-print" data-bs-theme="light">
      <input type="hidden" name="tipo" value="periodico">
      <div class="lst-fgrid3">
        <?= sl_select('cat', 'cat', $p['cat'], 'Categoría') ?>
        <?= sl_select('rub', 'rub', $p['rub'], 'Rubro') ?>
        <?= sl_select('sub', 'sub', $p['sub'], 'Subrubro') ?>
        <?= sl_select('lin', 'lin', $p['lin'], 'Línea') ?>
        <?= sl_select('suc', 'suc', $p['suc'], 'Sucursal') ?>
        <span class="lst-fpair"><label>A Fecha</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
      </div>
      <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
    </form>
    <div class="lst-doc lst-doc-wide">
      <div class="lst-head">
        <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
        <div class="lst-tit">INVENTARIO PERIÓDICO</div>
        <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
      </div>
      <div class="lst-params">
        <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
        <span>A FECHA</span><span>:</span><span class="v"><?= h(fecha_serial($sh)) ?></span>
        <span>CATEGORÍA</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'cat')) ?></span>
        <span>RUBRO</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'rub')) ?></span>
      </div>
      <table class="lst-tbl lst-jer">
        <colgroup><col style="width:7.8cm"><col style="width:2.0cm"><col style="width:2.2cm"><col style="width:2.6cm"><col style="width:2.6cm"><col style="width:1.6cm"><col style="width:1.6cm"></colgroup>
        <thead><tr><th>Nivel / Producto</th><th class="r">U.Compra Fecha</th><th class="r">Costo</th><th class="r">Existencia Física</th><th class="r">Total Valorizado</th><th class="r">%/Dep</th><th class="r">%/Tot</th></tr></thead>
        <tbody>
          <?php
          $emit = function ($depth, $code, $label, $agg, $pv) use ($grand) {
              $val = $agg['val'];
              $pd = ($depth >= 2 && $pv > 0) ? pct($val / $pv * 100) : '';
              $pt = ($grand > 0) ? pct($val / $grand * 100) : '';
              echo '<tr class="parent"><td><span style="padding-left:' . ($depth * 13) . 'px">' . h($label) . '</span></td>';
              echo '<td></td><td></td><td class="r mono">' . f4($agg['ex']) . '</td><td class="r mono">' . f2($val) . '</td><td class="r mono">' . $pd . '</td><td class="r mono">' . $pt . '</td></tr>';
          };
          $leaf = function ($r) use ($L, $grand) {
              $sim = isset($L['mon'][(string) $r['CODMON']]) ? $L['mon'][(string) $r['CODMON']] : '';
              $pt = ($grand > 0) ? pct($r['val'] / $grand * 100) : '';
              echo '<tr><td><span style="padding-left:78px" class="mono">' . h(trim((string) $r['CODPRO'])) . '</span> ' . h(trim((string) nz($r['DENPRO'], ''))) . '</td>';
              echo '<td class="r">' . h(fecha_serial($r['FUCPRO'])) . '</td><td class="r mono">' . h($sim) . ' ' . f2($r['_cos']) . '</td><td class="r mono">' . f4($r['ex']) . '</td><td class="r mono">' . f2($r['val']) . '</td><td class="r mono">' . $pt . '</td><td class="r mono">' . $pt . '</td></tr>';
          };
          inv_render($rows, $levels, 0, $sumKeys, $emit, $leaf, 0.0);
          $Tex = 0.0; $Tval = 0.0; foreach ($rows as $r) { $Tex += $r['ex']; $Tval += $r['val']; }
          ?>
          <tr class="tot"><td>TOTAL INVENTARIO:</td><td></td><td></td><td class="r mono"><?= f4($Tex) ?></td><td class="r mono"><?= f2($Tval) ?></td><td></td><td></td></tr>
        </tbody>
      </table>
    </div>
    <?php
}
module_foot(sl_combo_script());

function udm_den($L, $c) { $k = (string) (int) $c; return isset($L['udm'][$k]) ? $L['udm'][$k]['den'] : ''; }
function den2($L, $set, $c) { $k = (string) (int) $c; if (!isset($L[$set][$k])) return ''; $v = $L[$set][$k]; return is_array($v) ? $v['den'] : $v; }
function anyden($L, $set, $c) { if ($set === 'suc') { $k = (string) (int) $c; return isset($L['suc'][$k]) ? $L['suc'][$k] : ''; } if ($set === 'udm') return udm_den($L, $c); return den2($L, $set, $c); }
