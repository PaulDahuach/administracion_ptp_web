<?php
/** Performance — visor de tiempos (logs/perf-*.csv) para encontrar cuellos de botella.
 *  Agrega por módulo, lista los requests más lentos y las queries lentas. Solo admin. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_admin();

$LOGS = __DIR__ . '/../../logs';
$desde = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : date('Y-m-d', strtotime('-30 days'));
$hasta = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : date('Y-m-d');
$umbral = isset($_GET['umbral']) ? max(0, (int) $_GET['umbral']) : 3000;   // ms para marcar request "lento"

function ms($v) { $v = (float) $v; return $v >= 1000 ? number_format($v / 1000, 2, '.', ',') . ' s' : round($v) . ' ms'; }
function pct($a, $b) { return $b > 0 ? round($a * 100 / $b) . '%' : '—'; }
function perf_norm($sql) { $s = preg_replace("/'[^']*'/", "'?'", (string) $sql); $s = preg_replace('/-?\d+(\.\d+)?/', '#', $s); return trim(preg_replace('/\s+/', ' ', $s)); }

// ── leer resumen por request ──
$reqs = array();
foreach (glob($LOGS . '/perf-2*.csv') as $f) {
    $fh = @fopen($f, 'r'); if (!$fh) continue;
    while (($c = fgetcsv($fh)) !== false) {
        if (count($c) < 11) continue;
        $d = substr($c[0], 0, 10); if ($d < $desde || $d > $hasta) continue;
        $reqs[] = array('fecha' => $c[0], 'user' => $c[1], 'host' => $c[2], 'mod' => $c[3], 'ajax' => (int) $c[4],
            'php' => (float) $c[5], 'db' => (float) $c[6], 'conn' => (float) $c[7], 'n' => (int) $c[8], 'slow' => (float) $c[9], 'slowsql' => $c[10]);
    }
    fclose($fh);
}
// ── leer queries lentas (detalle) ──
$slows = array();
foreach (glob($LOGS . '/perf-slow-2*.csv') as $f) {
    $fh = @fopen($f, 'r'); if (!$fh) continue;
    while (($c = fgetcsv($fh)) !== false) {
        if (count($c) < 7) continue;
        $d = substr($c[0], 0, 10); if ($d < $desde || $d > $hasta) continue;
        $slows[] = array('fecha' => $c[0], 'user' => $c[1], 'mod' => $c[2], 'type' => $c[3], 'ms' => (float) $c[4], 'rows' => (int) $c[5], 'sql' => $c[6]);
    }
    fclose($fh);
}

// agregados
$N = count($reqs); $sumPhp = 0; $maxPhp = 0; $nLentos = 0; $allPhp = array();
$porMod = array();
foreach ($reqs as $r) {
    $sumPhp += $r['php']; if ($r['php'] > $maxPhp) $maxPhp = $r['php']; if ($r['php'] >= $umbral) $nLentos++; $allPhp[] = $r['php'];
    $m = $r['mod']; if (!isset($porMod[$m])) $porMod[$m] = array('n' => 0, 'sum' => 0, 'max' => 0, 'db' => 0, 'lentos' => 0);
    $porMod[$m]['n']++; $porMod[$m]['sum'] += $r['php']; $porMod[$m]['db'] += $r['db'];
    if ($r['php'] > $porMod[$m]['max']) $porMod[$m]['max'] = $r['php'];
    if ($r['php'] >= $umbral) $porMod[$m]['lentos']++;
}
sort($allPhp); $p95 = $N ? $allPhp[(int) floor($N * 0.95)] : 0; if ($p95 === null) $p95 = $maxPhp;
// ordenar módulos por tiempo máximo desc
uasort($porMod, function ($a, $b) { return $b['max'] > $a['max'] ? 1 : ($b['max'] < $a['max'] ? -1 : 0); });
// requests más lentos (top 25)
usort($reqs, function ($a, $b) { return $b['php'] > $a['php'] ? 1 : ($b['php'] < $a['php'] ? -1 : 0); });
$topReq = array_slice($reqs, 0, 25);
// queries lentas agrupadas por patrón
$qpat = array();
foreach ($slows as $s) { $k = perf_norm($s['sql']); if (!isset($qpat[$k])) $qpat[$k] = array('n' => 0, 'sum' => 0, 'max' => 0, 'mod' => $s['mod'], 'ej' => $s['sql']); $qpat[$k]['n']++; $qpat[$k]['sum'] += $s['ms']; if ($s['ms'] > $qpat[$k]['max']) $qpat[$k]['max'] = $s['ms']; }
uasort($qpat, function ($a, $b) { return $b['max'] > $a['max'] ? 1 : ($b['max'] < $a['max'] ? -1 : 0); });

$toolbar = '<a class="btn btn-outline-light btn-sm" href="?desde=' . h($desde) . '&hasta=' . h($hasta) . '"><i class="bi bi-arrow-clockwise me-1"></i>Refrescar</a>';
module_head('Performance', 'bi-speedometer2', $toolbar);
?>
<style>
  .pf-kpi { font-size:1.5rem; font-weight:700; line-height:1; }
  .pf-sql { font-family:monospace; font-size:.72rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:0; }
  .pf-tbl td, .pf-tbl th { font-size:.8rem; }
  .pf-bad { color:var(--bs-danger); font-weight:700; }
  .pf-warn { color:#b8860b; font-weight:600; }
</style>

<form method="get" class="card fc-card"><div class="card-body row g-2 align-items-end">
  <div class="col-auto"><label class="form-label mb-0 small">Desde</label><input type="date" name="desde" value="<?= h($desde) ?>" class="form-control form-control-sm"></div>
  <div class="col-auto"><label class="form-label mb-0 small">Hasta</label><input type="date" name="hasta" value="<?= h($hasta) ?>" class="form-control form-control-sm"></div>
  <div class="col-auto"><label class="form-label mb-0 small">Umbral lento (ms)</label><input type="number" name="umbral" value="<?= $umbral ?>" class="form-control form-control-sm" style="width:7rem"></div>
  <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrar</button></div>
</div></form>

<div class="row g-2 my-1">
  <div class="col"><div class="card fc-card h-100"><div class="card-body text-center"><div class="pf-kpi"><?= number_format($N, 0, '', '.') ?></div><div class="small text-muted">Requests</div></div></div></div>
  <div class="col"><div class="card fc-card h-100"><div class="card-body text-center"><div class="pf-kpi"><?= $N ? ms($sumPhp / $N) : '—' ?></div><div class="small text-muted">Promedio</div></div></div></div>
  <div class="col"><div class="card fc-card h-100"><div class="card-body text-center"><div class="pf-kpi"><?= ms($p95) ?></div><div class="small text-muted">P95 (95% entran en)</div></div></div></div>
  <div class="col"><div class="card fc-card h-100"><div class="card-body text-center"><div class="pf-kpi pf-bad"><?= ms($maxPhp) ?></div><div class="small text-muted">Peor caso</div></div></div></div>
  <div class="col"><div class="card fc-card h-100"><div class="card-body text-center"><div class="pf-kpi <?= $nLentos ? 'pf-warn' : '' ?>"><?= $nLentos ?></div><div class="small text-muted">Lentos (≥<?= $umbral ?>ms) · <?= pct($nLentos, $N) ?></div></div></div></div>
</div>

<div class="card fc-card mb-2"><div class="card-header"><span><i class="bi bi-window-stack me-1"></i>Por módulo (ordenado por peor caso)</span></div>
  <div class="card-body p-0"><table class="table table-sm pf-tbl mb-0"><thead><tr><th>Módulo</th><th class="text-end">Requests</th><th class="text-end">Prom.</th><th class="text-end">Peor</th><th class="text-end">Prom. DB</th><th class="text-end">Lentos</th></tr></thead><tbody>
    <?php foreach ($porMod as $m => $a): ?>
    <tr><td><?= h($m) ?></td><td class="text-end"><?= $a['n'] ?></td><td class="text-end"><?= ms($a['sum'] / $a['n']) ?></td>
      <td class="text-end <?= $a['max'] >= $umbral ? 'pf-bad' : '' ?>"><?= ms($a['max']) ?></td>
      <td class="text-end"><?= ms($a['db'] / $a['n']) ?></td>
      <td class="text-end"><?= $a['lentos'] ? '<span class="pf-warn">' . $a['lentos'] . '</span>' : '0' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$porMod): ?><tr><td colspan="6" class="text-muted p-2">Sin datos en el período. Navegá algunos módulos y refrescá.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="card fc-card mb-2"><div class="card-header"><span><i class="bi bi-database-exclamation me-1"></i>Queries lentas (≥<?= perf_slow_threshold() ?>ms, por patrón)</span></div>
  <div class="card-body p-0"><table class="table table-sm pf-tbl mb-0" style="table-layout:fixed"><thead><tr><th style="width:6rem">Módulo</th><th class="text-end" style="width:5rem">Veces</th><th class="text-end" style="width:6rem">Prom.</th><th class="text-end" style="width:6rem">Peor</th><th>SQL (patrón)</th></tr></thead><tbody>
    <?php foreach (array_slice($qpat, 0, 30, true) as $k => $a): ?>
    <tr><td><?= h($a['mod']) ?></td><td class="text-end"><?= $a['n'] ?></td><td class="text-end"><?= ms($a['sum'] / $a['n']) ?></td><td class="text-end pf-bad"><?= ms($a['max']) ?></td><td class="pf-sql" title="<?= h($a['ej']) ?>"><?= h($k) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$qpat): ?><tr><td colspan="5" class="text-muted p-2">Ninguna query superó el umbral. 🎉</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="card fc-card"><div class="card-header"><span><i class="bi bi-clock-history me-1"></i>Requests más lentos (top 25)</span></div>
  <div class="card-body p-0"><table class="table table-sm pf-tbl mb-0" style="table-layout:fixed"><thead><tr><th style="width:10rem">Fecha</th><th>Módulo</th><th style="width:5rem">Usuario</th><th style="width:7rem">Máquina</th><th class="text-end" style="width:5rem">Total</th><th class="text-end" style="width:5rem">DB</th><th class="text-end" style="width:3rem">Qs</th></tr></thead><tbody>
    <?php foreach ($topReq as $r): ?>
    <tr><td class="small"><?= h($r['fecha']) ?></td><td><?= h($r['mod']) ?><?= $r['ajax'] ? ' <span class="badge bg-secondary">ajax</span>' : '' ?></td><td><?= h($r['user']) ?></td><td class="small"><?= h($r['host'] ? $r['host'] : '') ?></td>
      <td class="text-end <?= $r['php'] >= $umbral ? 'pf-bad' : '' ?>"><?= ms($r['php']) ?></td><td class="text-end"><?= ms($r['db']) ?></td><td class="text-end"><?= $r['n'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$topReq): ?><tr><td colspan="7" class="text-muted p-2">Sin datos.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php module_foot(); ?>
