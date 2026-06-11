<?php
/** Listado de Gastos / Gastos Extraordinarios x Fecha Movimiento|Comprobante
 *  (Rpt IC Gastos x Fecha ... / Rpt IC Gastos Extraordinarios x Fecha ...). Imputaciones a las cuentas
 *  marcadas como gasto (GASCUE=True) o gasto extraordinario (GEXCUE=True), agrupadas por cuenta, en un
 *  período. Por imputación: fecha · mov.nº · comprobante (interno + fecha comp.) · detalle · centro ·
 *  debe/haber · saldo corrido (desde 0). SUBTOTAL por cuenta + TOTAL. ?tipo=gas|gex · ?fecha=mov|com. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function f2($v) { return number_format((float) $v, 2, '.', ','); }
function f2z($v) { return ((float) $v == 0.0) ? '' : number_format((float) $v, 2, '.', ','); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function comp($cic, $cii, $cip, $cin) { $c = trim((string) nz($cic, '')); if ($c === '') return ''; $i = trim((string) nz($cii, '')); return $c . ($i !== '' ? ' ' . $i : '') . ' ' . str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT); }

$tipo = (isset($_GET['tipo']) && $_GET['tipo'] === 'gex') ? 'gex' : 'gas';
$flag = $tipo === 'gex' ? 'GEXCUE' : 'GASCUE';
$titBase = $tipo === 'gex' ? 'GASTOS EXTRAORDINARIOS' : 'GASTOS';
$fecha = (isset($_GET['fecha']) && $_GET['fecha'] === 'com') ? 'com' : 'mov';
$dcol = $fecha === 'com' ? 'CEFMOV' : 'FEXMOV';
$ocol = $fecha === 'com' ? 'FEXMOV' : 'CEFMOV';

$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$mesIni = substr($hoyIso, 0, 7) . '-01';
$desdeIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $mesIni;
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : date('Y-m-t', strtotime($mesIni));
$sd = sp_serial($desdeIso); $sh = sp_serial($hastaIso);

$cuentas = array(); foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Contables];") as $a) $cuentas[trim((string) $a['CODCUE'])] = trim((string) nz($a['DENCUE'], ''));
$cdc = array(); foreach (db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo];") as $r) $cdc[(int) $r['CODCDC']] = trim((string) nz($r['DENCDC'], ''));

$lib = auth_libro_unico();
$est = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');

$rows = db_query("SELECT MI.CODCUE, M.FEXMOV, M.NUMMOV, MI.ORDMOV, MI.CONMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.CEFMOV, M.DETMOV, MI.CODCDC, MI.DEBMOV, MI.CREMOV
    FROM ([Tbl Movimientos] AS M INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON M.NUMMOV=MI.NUMMOV)
      INNER JOIN [Tbl Cuentas Contables] AS CC ON CC.CODCUE=MI.CODCUE
    WHERE CC.$flag=True AND M.$dcol >= $sd AND M.$dcol <= $sh$est
    ORDER BY MI.CODCUE, M.$dcol, M.NUMMOV, MI.ORDMOV;");

$grp = array(); $seen = array();
foreach ($rows as $r) {
    $kk = $r['NUMMOV'] . '-' . $r['ORDMOV']; if (isset($seen[$kk])) continue; $seen[$kk] = 1;
    $cc = trim((string) nz($r['CODCUE'], '')); if (!isset($grp[$cc])) $grp[$cc] = array(); $grp[$cc][] = $r;
}
ksort($grp);

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de ' . ($tipo === 'gex' ? 'Gastos Extraordinarios' : 'Gastos') . ' (Fec. ' . ($fecha === 'com' ? 'Com' : 'Mov') . '.)', 'bi-cash-stack', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$tD = 0; $tH = 0;
?>
<link href="../../assets/css/listado.css?v=23" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <input type="hidden" name="tipo" value="<?= h($tipo) ?>">
  <div class="lst-fgrid">
    <label>Desde</label><input type="date" name="desde" value="<?= h($desdeIso) ?>" class="form-control form-control-sm">
    <label>Hasta</label><input type="date" name="hasta" value="<?= h($hastaIso) ?>" class="form-control form-control-sm">
  </div>
  <select name="fecha" class="form-select form-select-sm" style="width:auto"><option value="mov"<?= $fecha === 'mov' ? ' selected' : '' ?>>Fecha Movimiento</option><option value="com"<?= $fecha === 'com' ? ' selected' : '' ?>>Fecha Comprobante</option></select>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit"><?= h($titBase) ?> x FECHA <?= $fecha === 'com' ? 'COMPROBANTE' : 'MOVIMIENTO' ?></div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:1.9cm"><col style="width:1.7cm"><col style="width:2.8cm"><col style="width:5.4cm"><col style="width:2.6cm"><col style="width:2cm"><col style="width:2cm"><col style="width:2.1cm"></colgroup>
    <thead><tr><th>Fecha</th><th>Mov. Nº</th><th>Comprobante</th><th>Detalle</th><th>Centro de Costo</th><th class="r">Debe</th><th class="r">Haber</th><th class="r">Saldo</th></tr></thead>
    <tbody>
      <?php foreach ($grp as $cc => $movs): $sal = 0; $sD = 0; $sH = 0; ?>
      <tr class="parent"><td colspan="8"><b><?= h(lo_niv($cc)) ?> <?= h(isset($cuentas[$cc]) ? $cuentas[$cc] : '') ?></b></td></tr>
      <?php foreach ($movs as $m):
        $d = (float) nz($m['DEBMOV'], 0); $c = (float) nz($m['CREMOV'], 0); $sal += $d - $c; $sD += $d; $sH += $c;
        $cf = ($m[$ocol] !== null && (int) $m[$ocol] > 0) ? fecha_serial($m[$ocol]) : ''; ?>
      <tr>
        <td><?= h(fecha_serial($m[$dcol])) ?></td><td class="mono"><?= str_pad((string) (int) $m['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td>
        <td class="mono"><?= h(comp($m['CICMOV'], $m['CIIMOV'], $m['CIPMOV'], $m['CINMOV'])) ?><?= $cf !== '' ? ' <span class="text-muted">' . h($cf) . '</span>' : '' ?></td>
        <td><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
        <td><?= h(isset($cdc[(int) nz($m['CODCDC'], 0)]) ? $cdc[(int) $m['CODCDC']] : '') ?></td>
        <td class="r"><?= f2z($d) ?></td><td class="r"><?= f2z($c) ?></td><td class="r"><?= f2($sal) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="tot"><td colspan="5">SUBTOTAL <?= h(lo_niv($cc)) ?> <?= h(isset($cuentas[$cc]) ? $cuentas[$cc] : '') ?>: <?= count($movs) ?></td><td class="r"><?= f2($sD) ?></td><td class="r"><?= f2($sH) ?></td><td class="r"><?= f2($sal) ?></td></tr>
      <?php $tD += $sD; $tH += $sH; endforeach; ?>
      <?php if (!$grp): ?><tr><td colspan="8" class="text-muted p-2">Sin gastos en el período.</td></tr><?php endif; ?>
      <tr class="tot" style="border-top:2px solid #000"><td colspan="5"><b>TOTAL CUENTAS: <?= count($grp) ?></b></td><td class="r"><b><?= f2($tD) ?></b></td><td class="r"><b><?= f2($tH) ?></b></td><td class="r"><b><?= f2($tD - $tH) ?></b></td></tr>
    </tbody>
  </table>
</div>
<?php module_foot(); ?>
