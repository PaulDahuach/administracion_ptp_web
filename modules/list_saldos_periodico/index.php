<?php
/** Listado de Saldos Periódicos Cuentas Contables (Rpt IC Saldos Periodico).
 *  Como Saldos Actuales pero acotado a un PERÍODO (FEXMOV entre desde/hasta). Muestra sólo las
 *  cuentas con movimiento en el período + sus padres (roll-up jerárquico) + TOTALES. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function f2($v) { return number_format((float) $v, 2, '.', ','); }
function niv_count($r) { $n = 0; foreach (array('CN1CUE', 'CN2CUE', 'CN3CUE', 'CN4CUE', 'CN5CUE') as $c) if (trim((string) nz($r[$c], '')) !== '') $n++; return $n; }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }

// período: default = año de la fecha del sistema (FECAPE)
$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$anio = substr($hoyIso, 0, 4);
$desdeIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : ($anio . '-01-01');
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $hoyIso;
$sd = sp_serial($desdeIso); $sh = sp_serial($hastaIso);

$lib = auth_libro_unico();
$estCond = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');

// sumas del período por cuenta (imputaciones cuyo movimiento padre cae en el rango FEXMOV)
$sum = array();
foreach (db_query("SELECT MI.CODCUE AS CC, SUM(MI.DEBMOV) AS D, SUM(MI.CREMOV) AS C
    FROM [Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV = MI.NUMMOV
    WHERE M.FEXMOV >= $sd AND M.FEXMOV <= $sh$estCond GROUP BY MI.CODCUE;") as $s)
    $sum[trim((string) $s['CC'])] = array((float) nz($s['D'], 0), (float) nz($s['C'], 0));

$rows = db_query("SELECT CODCUE, CN1CUE, CN2CUE, CN3CUE, CN4CUE, CN5CUE, DENCUE, IMPCUE FROM [Tbl Cuentas Contables] ORDER BY CODCUE;");

// nodos + roll-up + set de cuentas a mostrar (las que tienen imputación en el período + sus ancestros)
$node = array(); $show = array();
foreach ($rows as $r) { $cc = trim((string) nz($r['CODCUE'], '')); $node[$cc] = array('deb' => 0.0, 'cre' => 0.0); }
$totD = 0; $totC = 0;
foreach ($rows as $r) {
    $cc = trim((string) nz($r['CODCUE'], '')); if (!isset($sum[$cc])) continue;
    $d = $sum[$cc][0]; $c = $sum[$cc][1]; $totD += $d; $totC += $c;
    foreach (array('CN1CUE', 'CN2CUE', 'CN3CUE', 'CN4CUE', 'CN5CUE') as $col) {
        $v = trim((string) nz($r[$col], ''));
        if ($v !== '' && isset($node[$v])) { $node[$v]['deb'] += $d; $node[$v]['cre'] += $c; $show[$v] = true; }
    }
}

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Saldos Periódicos', 'bi-calendar-range', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
?>
<link href="../../assets/css/listado.css?v=17" rel="stylesheet">
<form method="get" class="lst-filter no-print">
  <label>Desde</label><input type="date" name="desde" value="<?= h($desdeIso) ?>" class="form-control form-control-sm">
  <label>Hasta</label><input type="date" name="hasta" value="<?= h($hastaIso) ?>" class="form-control form-control-sm">
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">SALDOS PERIÓDICOS CUENTAS CONTABLES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?><br>PERÍODO: <?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?><?= $me ? '<br>USUARIO: ' . h($me) : '' ?></div>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:8.6cm"><col style="width:3.2cm"><col style="width:3.2cm"><col style="width:3.2cm"></colgroup>
    <thead>
      <tr><th rowspan="2">Cuenta</th><th colspan="3">Período</th></tr>
      <tr><th class="r">Débitos</th><th class="r">Créditos</th><th class="r">Saldo</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $cc = trim((string) nz($r['CODCUE'], '')); if (!isset($show[$cc])) continue;
        $niv = niv_count($r); $imp = ($r['IMPCUE'] === true || $r['IMPCUE'] == -1);
        $d = $node[$cc]['deb']; $c = $node[$cc]['cre']; ?>
      <tr class="<?= $imp ? '' : 'parent' ?>">
        <td><span class="cod" style="padding-left:<?= ($niv - 1) * 11 ?>px"><?= h(lo_niv($cc)) ?></span> <?= h(trim((string) nz($r['DENCUE'], ''))) ?></td>
        <td class="r"><?= f2($d) ?></td><td class="r"><?= f2($c) ?></td><td class="r"><?= f2($d - $c) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="tot"><td>TOTALES</td><td class="r"><?= f2($totD) ?></td><td class="r"><?= f2($totC) ?></td><td class="r"><?= f2($totD - $totC) ?></td></tr>
    </tbody>
  </table>
</div>
<?php module_foot(); ?>
