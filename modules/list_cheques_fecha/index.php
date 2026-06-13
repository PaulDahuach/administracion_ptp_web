<?php
/** Listado de cheques agrupados por fecha de ACREDITACIÓN (Rpt IC Valores a Depositar /
 *  Rpt IC Cheques Diferidos a Devengar). ?tipo=vad (VADCHQ, a depositar) | dif (DIFCHQ, diferidos
 *  a devengar). Agrupa AÑO > MES > DÍA (de FAXCHQ) con subtotales + TOTAL CHEQUES. Filtro período
 *  (FAXCHQ) + libro (modo). El cheque se vincula vía la imputación (CODCHQ). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function f2($v) { return ($v === null || $v === '') ? '' : number_format((float) $v, 2, '.', ','); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }
function ser_ymd($serial) { if ($serial === null || $serial === '') return array(0, 0, 0); $d = new DateTime('1899-12-30'); $d->modify('+' . (int) $serial . ' days'); return array((int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j')); }
function chq_serie($syn) { $s = trim((string) $syn); return (strlen($s) >= 2 && ctype_digit($s)) ? substr($s, 0, 1) . '-' . substr($s, 1) : $s; }
$MESES = array(1 => 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE');

$tipo = (isset($_GET['tipo']) && $_GET['tipo'] === 'dif') ? 'dif' : 'vad';
$flag = $tipo === 'dif' ? 'DIFCHQ' : 'VADCHQ';
$titulo = $tipo === 'dif' ? 'CHEQUES DIFERIDOS A DEVENGAR' : 'VALORES A DEPOSITAR';

$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$anio = substr($hoyIso, 0, 4);
$desdeIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : ($anio . '-01-01');
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : ($anio . '-12-31');
$sd = sp_serial($desdeIso); $sh = sp_serial($hastaIso);

$lib = auth_libro_unico();
$est = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');

// cheques (dedup por cheque vía GROUP BY) con el flag, acreditación en el período, del libro
$rows = db_query("SELECT C.CODCHQ, C.FAXCHQ, C.FEXCHQ, B.DENBAN, C.SYNCHQ, C.LIBCHQ, C.CITCHQ, C.LOCCHQ, C.IMPCHQ
    FROM (([Tbl Bancos] AS B INNER JOIN [Tbl Cheques] AS C ON B.CODBAN=C.CODBAN)
      INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON C.CODCHQ=MI.CODCHQ)
      INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV
    WHERE C.$flag=True AND C.FAXCHQ >= $sd AND C.FAXCHQ <= $sh$est
    GROUP BY C.CODCHQ, C.FAXCHQ, C.FEXCHQ, B.DENBAN, C.SYNCHQ, C.LIBCHQ, C.CITCHQ, C.LOCCHQ, C.IMPCHQ
    ORDER BY C.FAXCHQ, B.DENBAN, C.SYNCHQ;");

// armar árbol año > mes > día
$tree = array(); $totGen = 0; $nChq = 0;
foreach ($rows as $r) {
    list($y, $m, $d) = ser_ymd($r['FAXCHQ']); $imp = (float) nz($r['IMPCHQ'], 0); $totGen += $imp; $nChq++;
    if (!isset($tree[$y])) $tree[$y] = array('t' => 0, 'mes' => array());
    if (!isset($tree[$y]['mes'][$m])) $tree[$y]['mes'][$m] = array('t' => 0, 'dia' => array());
    if (!isset($tree[$y]['mes'][$m]['dia'][$d])) $tree[$y]['mes'][$m]['dia'][$d] = array('t' => 0, 'chq' => array());
    $tree[$y]['t'] += $imp; $tree[$y]['mes'][$m]['t'] += $imp; $tree[$y]['mes'][$m]['dia'][$d]['t'] += $imp;
    $tree[$y]['mes'][$m]['dia'][$d]['chq'][] = $r;
}

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de ' . ($tipo === 'dif' ? 'Cheques Diferidos a Devengar' : 'Valores a Depositar'), 'bi-cash-stack', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
?>
<link href="../../assets/css/listado.css?v=24" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <input type="hidden" name="tipo" value="<?= h($tipo) ?>">
  <div class="lst-fgrid">
    <label>Desde</label><input type="date" name="desde" value="<?= h($desdeIso) ?>" class="form-control form-control-sm">
    <label>Hasta</label><input type="date" name="hasta" value="<?= h($hastaIso) ?>" class="form-control form-control-sm">
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit"><?= h($titulo) ?></div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:2cm"><col style="width:2cm"><col style="width:2.9cm"><col style="width:2.1cm"><col style="width:3.8cm"><col style="width:2.7cm"><col style="width:2.6cm"><col style="width:2.4cm"></colgroup>
    <thead><tr><th>Acreditación</th><th>Emisión</th><th>Banco</th><th>Serie - Nº</th><th>Librador</th><th>C.U.I.T.</th><th>Localidad</th><th class="r">Importe</th></tr></thead>
    <tbody>
      <?php foreach ($tree as $y => $ya): ?>
      <tr class="parent"><td colspan="7"><b><?= $y ?></b></td><td class="r"><b><?= f2($ya['t']) ?></b></td></tr>
        <?php foreach ($ya['mes'] as $m => $ma): ?>
        <tr class="parent"><td colspan="7"><span style="padding-left:11px"><?= h($MESES[$m]) ?></span></td><td class="r"><?= f2($ma['t']) ?></td></tr>
          <?php foreach ($ma['dia'] as $d => $da): ?>
          <tr class="parent"><td colspan="7"><span style="padding-left:22px"><?= str_pad((string) $d, 2, '0', STR_PAD_LEFT) ?></span></td><td class="r"><?= f2($da['t']) ?></td></tr>
            <?php foreach ($da['chq'] as $c): ?>
            <tr>
              <td><?= h(fecha_serial($c['FAXCHQ'])) ?></td><td><?= h(fecha_serial($c['FEXCHQ'])) ?></td>
              <td><?= h(trim((string) nz($c['DENBAN'], ''))) ?></td><td class="mono"><?= h(chq_serie($c['SYNCHQ'])) ?></td>
              <td><?= h(trim((string) nz($c['LIBCHQ'], ''))) ?></td><td class="mono"><?= h(trim((string) nz($c['CITCHQ'], ''))) ?></td>
              <td><?= h(trim((string) nz($c['LOCCHQ'], ''))) ?></td><td class="r"><?= f2($c['IMPCHQ']) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$tree): ?><tr><td colspan="8" class="text-muted p-2">Sin cheques en el período.</td></tr><?php endif; ?>
      <tr class="tot"><td colspan="7">TOTAL CHEQUES: <?= $nChq ?></td><td class="r"><?= f2($totGen) ?></td></tr>
    </tbody>
  </table>
</div>
<?php module_foot(); ?>
