<?php
/** Listado de Imputaciones Contables x Operación (Rpt IC Imputaciones x Operacion).
 *  Movimientos agrupados por OPERACIÓN (Tbl Operaciones.DENOPE) en un período, con total por
 *  operación + TOTAL general. Columnas: fecha (FEXMOV) · mov.nº (NUMMOV) · comprobante EXTERNO
 *  (CECMOV/CEIMOV/CEPMOV/CENMOV) · fecha comp. (CEFMOV) · detalle (DETMOV) · total (TOTMOV).
 *  Filtros: período (FEXMOV) + operación (opcional) + libro (modo). Orden = DENOPE, FEXMOV, NUMMOV. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function f2($v) { return number_format((float) $v, 2, '.', ','); }
function f2z($v) { return ((float) $v == 0.0) ? '0.00' : number_format((float) $v, 2, '.', ','); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }
function comp_ext($cod, $ide, $pdv, $num) {
    $c = trim((string) nz($cod, '')); if ($c === '') return '';
    $i = trim((string) nz($ide, ''));
    return $c . ($i !== '' ? ' ' . $i : '') . ' ' . str_pad((string) (int) nz($pdv, 0), 4, '0', STR_PAD_LEFT) . ' ' . str_pad((string) (int) nz($num, 0), 8, '0', STR_PAD_LEFT);
}

$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$mesIni = substr($hoyIso, 0, 7) . '-01';                              // 1º del mes del sistema
$mesFin = date('Y-m-t', strtotime($mesIni));                          // último del mes
$desdeIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $mesIni;
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $mesFin;
$sd = sp_serial($desdeIso); $sh = sp_serial($hastaIso);
$ope = isset($_GET['ope']) ? (int) $_GET['ope'] : 0;

$operaciones = db_query("SELECT CODOPE, DENOPE FROM [Tbl Operaciones] ORDER BY DENOPE;");
$lib = auth_libro_unico();
$est = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');
$wOpe = $ope > 0 ? ' AND M.CODOPE=' . $ope : '';

$rows = db_query("SELECT O.DENOPE, M.FEXMOV, M.NUMMOV, M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV, M.CEFMOV, M.DETMOV, M.TOTMOV
    FROM [Tbl Operaciones] AS O INNER JOIN [Tbl Movimientos] AS M ON O.CODOPE=M.CODOPE
    WHERE M.FEXMOV >= $sd AND M.FEXMOV <= $sh$wOpe$est
    ORDER BY O.DENOPE, M.FEXMOV, M.NUMMOV;");

// agrupar por operación (preserva orden DENOPE/FEXMOV/NUMMOV)
$grp = array(); $totGen = 0; $nMov = 0;
foreach ($rows as $r) {
    $d = trim((string) nz($r['DENOPE'], '(sin operación)'));
    if (!isset($grp[$d])) $grp[$d] = array('t' => 0, 'movs' => array());
    $grp[$d]['t'] += (float) nz($r['TOTMOV'], 0); $grp[$d]['movs'][] = $r;
    $totGen += (float) nz($r['TOTMOV'], 0); $nMov++;
}

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Imputaciones x Operación', 'bi-journal-text', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$opeName = 'Todas'; foreach ($operaciones as $o) if ((int) $o['CODOPE'] === $ope) $opeName = trim((string) $o['DENOPE']);
?>
<link href="../../assets/css/listado.css?v=24" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid">
    <label>Desde</label><input type="date" name="desde" value="<?= h($desdeIso) ?>" class="form-control form-control-sm">
    <label>Hasta</label><input type="date" name="hasta" value="<?= h($hastaIso) ?>" class="form-control form-control-sm">
    <label>Operación</label><select name="ope" class="form-select form-select-sm lst-cue"><option value="0">Todas</option><?php foreach ($operaciones as $o): ?><option value="<?= (int) $o['CODOPE'] ?>"<?= ((int) $o['CODOPE'] === $ope) ? ' selected' : '' ?>><?= h(trim((string) $o['DENOPE'])) ?></option><?php endforeach; ?></select>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">IMPUTACIONES CONTABLES x OPERACIÓN</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>OPERACIÓN</span><span>:</span><span class="v"><?= h($opeName) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:1.9cm"><col style="width:1.7cm"><col style="width:3.4cm"><col style="width:1.9cm"><col style="width:7.2cm"><col style="width:2.6cm"></colgroup>
    <thead><tr><th>Fecha</th><th>Mov. Nº</th><th>Comprobante</th><th>Fecha Comp.</th><th>Detalle</th><th class="r">Total</th></tr></thead>
    <tbody>
      <?php foreach ($grp as $den => $g): ?>
      <tr class="parent"><td colspan="5"><b><?= h(mb_strtoupper($den, 'UTF-8')) ?></b></td><td class="r"><b><?= f2($g['t']) ?></b></td></tr>
      <?php foreach ($g['movs'] as $m): ?>
      <tr>
        <td><?= h(fecha_serial($m['FEXMOV'])) ?></td>
        <td class="mono"><?= str_pad((string) (int) $m['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td>
        <td class="mono"><?= h(comp_ext($m['CECMOV'], $m['CEIMOV'], $m['CEPMOV'], $m['CENMOV'])) ?></td>
        <td><?= h(fecha_serial($m['CEFMOV'])) ?></td>
        <td><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
        <td class="r"><?= f2z($m['TOTMOV']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$grp): ?><tr><td colspan="6" class="text-muted p-2">Sin movimientos en el período.</td></tr><?php endif; ?>
      <tr class="tot" style="border-top:2px solid #000"><td colspan="5"><b>TOTAL (<?= $nMov ?> movimientos)</b></td><td class="r"><b><?= f2($totGen) ?></b></td></tr>
    </tbody>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
