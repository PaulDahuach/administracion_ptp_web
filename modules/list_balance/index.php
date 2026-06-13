<?php
/** Listado de Balance de Sumas y Saldos (Rpt IC Balance de Sumas y Saldos).
 *  Por cuenta (jerárquico, roll-up): SALDO ANTERIOR (Σ DEB−CRE antes del período) · PERÍODO
 *  (Débitos/Créditos/Saldo en el rango FEXMOV) · SALDO ACTUAL (anterior + saldo período). SIN INICUE
 *  (cierra: Σ Debe = Σ Haber, Σ Saldo ≈ 0). Filtros: período + rango de cuenta contable. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function f2($v) { return number_format((float) $v, 2, '.', ','); }
function niv_count($r) { $n = 0; foreach (array('CN1CUE', 'CN2CUE', 'CN3CUE', 'CN4CUE', 'CN5CUE') as $c) if (trim((string) nz($r[$c], '')) !== '') $n++; return $n; }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }
function cue_opts($list, $sel) { $o = ''; foreach ($list as $k => $v) $o .= '<option value="' . h($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . h($v) . '</option>'; return $o; }

// período (default = año de la fecha del sistema)
$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$anio = substr($hoyIso, 0, 4);
$desdeIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : ($anio . '-01-01');
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : ($anio . '-12-31');
$sd = sp_serial($desdeIso); $sh = sp_serial($hastaIso);

// rango de cuenta (DESCUE/HASCUE)
$impList = array();
foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Contables] WHERE IMPCUE=True ORDER BY CODCUE;") as $a) {
    $cc = trim((string) nz($a['CODCUE'], '')); $impList[$cc] = lo_niv($cc) . '  ' . trim((string) nz($a['DENCUE'], ''));
}
$keys = array_keys($impList);
$desCue = (isset($_GET['descue']) && isset($impList[$_GET['descue']])) ? $_GET['descue'] : (count($keys) ? $keys[0] : '');
$hasCue = (isset($_GET['hascue']) && isset($impList[$_GET['hascue']])) ? $_GET['hascue'] : (count($keys) ? end($keys) : '');
if (strcmp($desCue, $hasCue) > 0) { $t = $desCue; $desCue = $hasCue; $hasCue = $t; }

$lib = auth_libro_unico();
$est = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');
$rng = "MI.CODCUE >= '" . db_esc($desCue) . "' AND MI.CODCUE <= '" . db_esc($hasCue) . "'";
$base = " FROM [Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV = MI.NUMMOV WHERE ";

// SALDO ANTERIOR (FEXMOV < desde) + PERÍODO (desde..hasta)
$ant = array(); $per = array();
foreach (db_query("SELECT MI.CODCUE AS CC, SUM(MI.DEBMOV) AS D, SUM(MI.CREMOV) AS C$base M.FEXMOV < $sd AND $rng$est GROUP BY MI.CODCUE;") as $s)
    $ant[trim((string) $s['CC'])] = (float) nz($s['D'], 0) - (float) nz($s['C'], 0);
foreach (db_query("SELECT MI.CODCUE AS CC, SUM(MI.DEBMOV) AS D, SUM(MI.CREMOV) AS C$base M.FEXMOV >= $sd AND M.FEXMOV <= $sh AND $rng$est GROUP BY MI.CODCUE;") as $s)
    $per[trim((string) $s['CC'])] = array((float) nz($s['D'], 0), (float) nz($s['C'], 0));

$rows = db_query("SELECT CODCUE, CN1CUE, CN2CUE, CN3CUE, CN4CUE, CN5CUE, DENCUE, IMPCUE FROM [Tbl Cuentas Contables] ORDER BY CODCUE;");

// nodos + roll-up + set a mostrar (hojas en rango + ancestros) + flag de actividad
$node = array(); $show = array(); $act = array();
foreach ($rows as $r) { $cc = trim((string) nz($r['CODCUE'], '')); $node[$cc] = array('a' => 0.0, 'd' => 0.0, 'c' => 0.0); }
$tA = 0; $tD = 0; $tC = 0;
foreach ($rows as $r) {
    $cc = trim((string) nz($r['CODCUE'], ''));
    $imp = ($r['IMPCUE'] === true || $r['IMPCUE'] == -1);
    $inRange = ($cc !== '' && strcmp($cc, $desCue) >= 0 && strcmp($cc, $hasCue) <= 0);
    $a = isset($ant[$cc]) ? $ant[$cc] : null;
    $pd = isset($per[$cc]) ? $per[$cc][0] : null;
    $pc = isset($per[$cc]) ? $per[$cc][1] : null;
    $cols = array('CN1CUE', 'CN2CUE', 'CN3CUE', 'CN4CUE', 'CN5CUE');
    if ($imp && $inRange) foreach ($cols as $col) { $v = trim((string) nz($r[$col], '')); if ($v !== '') $show[$v] = true; }
    if ($a !== null || $pd !== null) {
        $tA += (float) $a; $tD += (float) $pd; $tC += (float) $pc;
        foreach ($cols as $col) { $v = trim((string) nz($r[$col], '')); if ($v !== '' && isset($node[$v])) { $node[$v]['a'] += (float) $a; $node[$v]['d'] += (float) $pd; $node[$v]['c'] += (float) $pc; $act[$v] = true; } }
    }
}

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Balance de Sumas y Saldos', 'bi-journals', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
?>
<link href="../../assets/css/listado.css?v=24" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid">
    <label>Desde</label><input type="date" name="desde" value="<?= h($desdeIso) ?>" class="form-control form-control-sm">
    <label>Hasta</label><input type="date" name="hasta" value="<?= h($hastaIso) ?>" class="form-control form-control-sm">
    <label>Cuenta Desde</label><select name="descue" class="form-select form-select-sm lst-cue"><?= cue_opts($impList, $desCue) ?></select>
    <label>Hasta</label><select name="hascue" class="form-select form-select-sm lst-cue"><?= cue_opts($impList, $hasCue) ?></select>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">BALANCE DE SUMAS Y SALDOS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTAS</span><span>:</span><span class="v"><?= h(lo_niv($desCue)) ?> - <?= h(lo_niv($hasCue)) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup>
      <col style="width:5.0cm">
      <col style="width:2.9cm"><col style="width:2.9cm"><col style="width:2.9cm"><col style="width:2.6cm"><col style="width:2.9cm">
    </colgroup>
    <thead>
      <tr><th rowspan="2">Cuenta</th><th rowspan="2" class="r">Saldo Anterior</th><th colspan="3">Período</th><th rowspan="2" class="r">Saldo Actual</th></tr>
      <tr><th class="r">Débitos</th><th class="r">Créditos</th><th class="r">Saldo</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $cc = trim((string) nz($r['CODCUE'], '')); if (!isset($show[$cc])) continue;
        $niv = niv_count($r); $imp = ($r['IMPCUE'] === true || $r['IMPCUE'] == -1);
        $on = isset($act[$cc]);
        $a = $node[$cc]['a']; $d = $node[$cc]['d']; $c = $node[$cc]['c']; $ps = $d - $c; $sa = $a + $ps; ?>
      <tr class="<?= $imp ? '' : 'parent' ?>">
        <td><span class="cod" style="padding-left:<?= ($niv - 1) * 11 ?>px"><?= h(lo_niv($cc)) ?></span> <?= h(trim((string) nz($r['DENCUE'], ''))) ?></td>
        <td class="r"><?= $on ? f2($a) : '' ?></td>
        <td class="r"><?= $on ? f2($d) : '' ?></td>
        <td class="r"><?= $on ? f2($c) : '' ?></td>
        <td class="r"><?= $on ? f2($ps) : '' ?></td>
        <td class="r"><?= $on ? f2($sa) : '' ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="tot">
        <td>TOTALES</td>
        <td class="r"><?= f2($tA) ?></td><td class="r"><?= f2($tD) ?></td><td class="r"><?= f2($tC) ?></td>
        <td class="r"><?= f2($tD - $tC) ?></td><td class="r"><?= f2($tA + $tD - $tC) ?></td>
      </tr>
    </tbody>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
