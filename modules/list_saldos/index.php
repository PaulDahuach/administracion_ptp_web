<?php
/** Listado de Saldos Actuales Cuentas Contables (Rpt IC Saldos Actuales).
 *  Por cuenta: OPERATIVO (débitos/créditos/saldo, sumas de imputaciones filtradas por libro) con
 *  roll-up jerárquico a los padres + última conciliación (SACCUE/UCDCUE/UCHCUE) + TOTALES.
 *  Filtro por rango de cuenta contable (DESCUE/HASCUE). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function f2($v) { return number_format((float) $v, 2, '.', ','); }
function niv_count($r) { $n = 0; foreach (array('CN1CUE', 'CN2CUE', 'CN3CUE', 'CN4CUE', 'CN5CUE') as $c) if (trim((string) nz($r[$c], '')) !== '') $n++; return $n; }
function cue_opts($list, $sel) { $o = ''; foreach ($list as $k => $v) $o .= '<option value="' . h($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . h($v) . '</option>'; return $o; }

// cuentas imputables (selector de rango) + rango elegido (DESCUE/HASCUE)
$impList = array();
foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Contables] WHERE IMPCUE=True ORDER BY CODCUE;") as $a) {
    $cc = trim((string) nz($a['CODCUE'], '')); $impList[$cc] = lo_niv($cc) . '  ' . trim((string) nz($a['DENCUE'], ''));
}
$keys = array_keys($impList);
$desCue = (isset($_GET['descue']) && isset($impList[$_GET['descue']])) ? $_GET['descue'] : (count($keys) ? $keys[0] : '');
$hasCue = (isset($_GET['hascue']) && isset($impList[$_GET['hascue']])) ? $_GET['hascue'] : (count($keys) ? end($keys) : '');
if (strcmp($desCue, $hasCue) > 0) { $t = $desCue; $desCue = $hasCue; $hasCue = $t; }

// libro según el modo (operativo=ESTMOV True, capacitación=False, integral=combinado)
$lib = auth_libro_unico();
$conds = array("MI.CODCUE >= '" . db_esc($desCue) . "'", "MI.CODCUE <= '" . db_esc($hasCue) . "'");
if ($lib === 'blanco') $conds[] = 'M.ESTMOV=True'; elseif ($lib === 'capacitacion') $conds[] = 'M.ESTMOV=False';
$where = ' WHERE ' . implode(' AND ', $conds);

// sumas por cuenta (imputaciones del libro, dentro del rango)
$sum = array();
foreach (db_query("SELECT MI.CODCUE AS CC, SUM(MI.DEBMOV) AS D, SUM(MI.CREMOV) AS C
    FROM [Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV = MI.NUMMOV$where
    GROUP BY MI.CODCUE;") as $s) $sum[trim((string) $s['CC'])] = array((float) nz($s['D'], 0), (float) nz($s['C'], 0));

$rows = db_query("SELECT CODCUE, CN1CUE, CN2CUE, CN3CUE, CN4CUE, CN5CUE, DENCUE, IMPCUE, SACCUE, UCDCUE, UCHCUE FROM [Tbl Cuentas Contables] ORDER BY CODCUE;");

// nodos + roll-up + set a mostrar (hojas en rango -aunque tengan 0- + sus ancestros)
$node = array(); $show = array();
foreach ($rows as $r) { $cc = trim((string) nz($r['CODCUE'], '')); $node[$cc] = array('deb' => 0.0, 'cre' => 0.0); }
$totD = 0; $totC = 0;
foreach ($rows as $r) {
    $cc = trim((string) nz($r['CODCUE'], ''));
    $imp = ($r['IMPCUE'] === true || $r['IMPCUE'] == -1);
    $inRange = ($cc !== '' && strcmp($cc, $desCue) >= 0 && strcmp($cc, $hasCue) <= 0);
    if ($imp && $inRange) foreach (array('CN1CUE', 'CN2CUE', 'CN3CUE', 'CN4CUE', 'CN5CUE') as $col) { $v = trim((string) nz($r[$col], '')); if ($v !== '') $show[$v] = true; }
    if (isset($sum[$cc])) {
        $d = $sum[$cc][0]; $c = $sum[$cc][1]; $totD += $d; $totC += $c;
        foreach (array('CN1CUE', 'CN2CUE', 'CN3CUE', 'CN4CUE', 'CN5CUE') as $col) { $v = trim((string) nz($r[$col], '')); if ($v !== '' && isset($node[$v])) { $node[$v]['deb'] += $d; $node[$v]['cre'] += $c; } }
    }
}

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Saldos Actuales', 'bi-clipboard-data', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
?>
<link href="../../assets/css/listado.css?v=21" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <span class="lst-grp"><label>Cuenta Desde</label><select name="descue" class="form-select form-select-sm lst-cue"><?= cue_opts($impList, $desCue) ?></select><label>Hasta</label><select name="hascue" class="form-select form-select-sm lst-cue"><?= cue_opts($impList, $hasCue) ?></select></span>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">SALDOS ACTUALES CUENTAS CONTABLES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTAS</span><span>:</span><span class="v"><?= h(lo_niv($desCue)) ?> - <?= h(lo_niv($hasCue)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup>
      <col style="width:6.8cm">
      <col style="width:3.0cm"><col style="width:3.0cm"><col style="width:3.0cm">
      <col style="width:1.4cm"><col style="width:1.2cm"><col style="width:1.0cm">
    </colgroup>
    <thead>
      <tr><th rowspan="2">Cuenta</th><th colspan="3">Operativo</th><th colspan="3">Última Conciliación</th></tr>
      <tr><th class="r">Débitos</th><th class="r">Créditos</th><th class="r">Saldo</th><th class="r">Saldo</th><th>Fecha</th><th>Tope</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $cc = trim((string) nz($r['CODCUE'], '')); if (!isset($show[$cc])) continue;
        $niv = niv_count($r); $imp = ($r['IMPCUE'] === true || $r['IMPCUE'] == -1);
        $d = $node[$cc]['deb']; $c = $node[$cc]['cre']; $sal = $d - $c;
        $fconc = ($r['UCDCUE'] !== null && $r['UCDCUE'] !== '') ? fecha_serial($r['UCDCUE']) : '';
        $tope = ($r['UCHCUE'] !== null && $r['UCHCUE'] !== '') ? fecha_serial($r['UCHCUE']) : ''; ?>
      <tr class="<?= $imp ? '' : 'parent' ?>">
        <td><span class="cod" style="padding-left:<?= ($niv - 1) * 11 ?>px"><?= h(lo_niv($cc)) ?></span> <?= h(trim((string) nz($r['DENCUE'], ''))) ?></td>
        <td class="r"><?= f2($d) ?></td>
        <td class="r"><?= f2($c) ?></td>
        <td class="r"><?= f2($sal) ?></td>
        <td class="r"><?= f2(nz($r['SACCUE'], 0)) ?></td>
        <td class="c"><?= h($fconc) ?></td>
        <td class="c"><?= h($tope) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="tot">
        <td>TOTALES</td>
        <td class="r"><?= f2($totD) ?></td><td class="r"><?= f2($totC) ?></td><td class="r"><?= f2($totD - $totC) ?></td>
        <td></td><td></td><td></td>
      </tr>
    </tbody>
  </table>
</div>
<?php module_foot(); ?>
