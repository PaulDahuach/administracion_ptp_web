<?php
/** Listado de Imputaciones Contables x Cuenta [Fecha Movimiento] Periódico
 *  (Rpt IC Imputaciones x Cuenta (Fecha Mov) Periodico). Mayor por cuenta sobre un RANGO de cuentas:
 *  por cada cuenta, las imputaciones del período (filtra por M.FEXMOV) con operación, comprobante
 *  interno (CICMOV..) + fecha comp. externa (CEFMOV), detalle, detalle de cheque (CODCHQ), centro de
 *  costo, debe/haber y SALDO CORRIDO desde 0 (periódico, sin saldo anterior) + SUBTOTAL por cuenta +
 *  TOTAL. Filtros: período (FEXMOV) + rango de cuenta + operación + centro + libro (modo). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function f2($v) { return number_format((float) $v, 2, '.', ','); }
function f2z($v) { return ((float) $v == 0.0) ? '' : number_format((float) $v, 2, '.', ','); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function chq_serie($syn) { $s = trim((string) $syn); return (strlen($s) >= 2 && ctype_digit($s)) ? substr($s, 0, 1) . '-' . substr($s, 1) : $s; }
function comp($cic, $cii, $cip, $cin) { $c = trim((string) nz($cic, '')); if ($c === '') return ''; $i = trim((string) nz($cii, '')); return $c . ($i !== '' ? ' ' . $i : '') . ' ' . str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT); }

$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$mesIni = substr($hoyIso, 0, 7) . '-01';
$desdeIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $mesIni;
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : date('Y-m-t', strtotime($mesIni));
$sd = sp_serial($desdeIso); $sh = sp_serial($hastaIso);

// cuentas (imputables → combos de rango) + nombres; centros + operaciones
$cuentas = array(); $impList = array();
foreach (db_query("SELECT CODCUE, DENCUE, IMPCUE FROM [Tbl Cuentas Contables] ORDER BY CODCUE;") as $a) {
    $cc = trim((string) nz($a['CODCUE'], '')); $cuentas[$cc] = trim((string) nz($a['DENCUE'], ''));
    if ($a['IMPCUE'] === true || $a['IMPCUE'] == -1) $impList[$cc] = lo_niv($cc) . '  ' . $cuentas[$cc];
}
$impKeys = array_keys($impList);
$desCue = (isset($_GET['descue']) && isset($impList[$_GET['descue']])) ? $_GET['descue'] : (count($impKeys) ? $impKeys[0] : '');
$hasCue = (isset($_GET['hascue']) && isset($impList[$_GET['hascue']])) ? $_GET['hascue'] : (count($impKeys) ? end($impKeys) : '');
if (strcmp($desCue, $hasCue) > 0) { $t = $desCue; $desCue = $hasCue; $hasCue = $t; }
$cdc = array(); foreach (db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo];") as $r) $cdc[(int) $r['CODCDC']] = trim((string) nz($r['DENCDC'], ''));
$operaciones = db_query("SELECT CODOPE, DENOPE FROM [Tbl Operaciones] ORDER BY DENOPE;");
$ope = isset($_GET['ope']) ? (int) $_GET['ope'] : 0;

$lib = auth_libro_unico();
$est = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');
$rng = "MI.CODCUE >= '" . db_esc($desCue) . "' AND MI.CODCUE <= '" . db_esc($hasCue) . "'";
$wOpe = $ope > 0 ? ' AND M.CODOPE=' . $ope : '';

// Imputaciones del período por FEXMOV, con operación + cheque (Bancos RIGHT JOIN (MI LEFT JOIN Cheques) como el legacy)
$rows = db_query("SELECT MI.CODCUE, M.FEXMOV, M.NUMMOV, MI.ORDMOV, MI.CONMOV, O.DENOPE,
    M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.CEFMOV, M.DETMOV,
    B.DENBAN AS CHQBAN, C.SYNCHQ, C.FEXCHQ, C.FAXCHQ, C.LIBCHQ, C.LOCCHQ, MI.CODCDC, MI.DEBMOV, MI.CREMOV
    FROM (([Tbl Operaciones] AS O INNER JOIN [Tbl Movimientos] AS M ON O.CODOPE=M.CODOPE)
      INNER JOIN (([Tbl Bancos] AS B RIGHT JOIN ([Tbl Movimientos Imputaciones] AS MI
        LEFT JOIN [Tbl Cheques] AS C ON MI.CODCHQ=C.CODCHQ) ON B.CODBAN=C.CODBAN)) ON M.NUMMOV=MI.NUMMOV)
    WHERE $rng AND M.FEXMOV >= $sd AND M.FEXMOV <= $sh$wOpe$est
    ORDER BY MI.CODCUE, M.FEXMOV, M.NUMMOV, MI.ORDMOV;");

$grp = array(); $seen = array();   // dedup por imputación = DISTINCTROW del legacy
foreach ($rows as $r) {
    $kk = $r['NUMMOV'] . '-' . $r['ORDMOV']; if (isset($seen[$kk])) continue; $seen[$kk] = 1;
    $cc = trim((string) nz($r['CODCUE'], '')); if (!isset($grp[$cc])) $grp[$cc] = array(); $grp[$cc][] = $r;
}
ksort($grp);

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Imputaciones x Cuenta (Fec. Mov.)', 'bi-book', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
function cue_opts($list, $sel) { $o = ''; foreach ($list as $k => $v) $o .= '<option value="' . h($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . h($v) . '</option>'; return $o; }
$tD = 0; $tH = 0; $tSal = 0;
?>
<link href="../../assets/css/listado.css?v=23" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid">
    <label>Desde</label><input type="date" name="desde" value="<?= h($desdeIso) ?>" class="form-control form-control-sm">
    <label>Hasta</label><input type="date" name="hasta" value="<?= h($hastaIso) ?>" class="form-control form-control-sm">
    <label>Cuenta Desde</label><select name="descue" class="form-select form-select-sm lst-cue"><?= cue_opts($impList, $desCue) ?></select>
    <label>Hasta</label><select name="hascue" class="form-select form-select-sm lst-cue"><?= cue_opts($impList, $hasCue) ?></select>
  </div>
  <select name="ope" class="form-select form-select-sm lst-cue"><option value="0">Todas las operaciones</option><?php foreach ($operaciones as $o): ?><option value="<?= (int) $o['CODOPE'] ?>"<?= ((int) $o['CODOPE'] === $ope) ? ' selected' : '' ?>><?= h(trim((string) $o['DENOPE'])) ?></option><?php endforeach; ?></select>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">IMPUTACIONES CONTABLES x CUENTA [FECHA MOVIMIENTO]</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTAS</span><span>:</span><span class="v"><?= h(lo_niv($desCue)) ?> - <?= h(lo_niv($hasCue)) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:1.6cm"><col style="width:1.5cm"><col style="width:2.2cm"><col style="width:2.2cm"><col style="width:2.7cm"><col style="width:3cm"><col style="width:1.5cm"><col style="width:1.8cm"><col style="width:1.8cm"><col style="width:1.9cm"></colgroup>
    <thead><tr><th>Fecha</th><th>Mov. Nº</th><th>Operación</th><th>Comprobante</th><th>Detalle</th><th>Cheque</th><th>Centro</th><th class="r">Debe</th><th class="r">Haber</th><th class="r">Saldo</th></tr></thead>
    <tbody>
      <?php foreach ($grp as $cc => $movs): $sal = 0; $sD = 0; $sH = 0; ?>
      <tr class="parent"><td colspan="10"><b><?= h(lo_niv($cc)) ?> <?= h(isset($cuentas[$cc]) ? $cuentas[$cc] : '') ?></b></td></tr>
      <?php foreach ($movs as $m):
        $d = (float) nz($m['DEBMOV'], 0); $c = (float) nz($m['CREMOV'], 0); $sal += $d - $c; $sD += $d; $sH += $c;
        $chq = trim((string) nz($m['CHQBAN'], '')); if ($chq !== '') { $chq .= ' ' . chq_serie($m['SYNCHQ']); $lb = trim((string) nz($m['LIBCHQ'], '')); if ($lb !== '') $chq .= ' · ' . $lb; }
        $cf = ($m['CEFMOV'] !== null && (int) $m['CEFMOV'] > 0) ? fecha_serial($m['CEFMOV']) : ''; ?>
      <tr>
        <td><?= h(fecha_serial($m['FEXMOV'])) ?></td><td class="mono"><?= str_pad((string) (int) $m['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td>
        <td><?= h(trim((string) nz($m['DENOPE'], ''))) ?></td>
        <td class="mono"><?= h(comp($m['CICMOV'], $m['CIIMOV'], $m['CIPMOV'], $m['CINMOV'])) ?><?= $cf !== '' ? ' <span class="text-muted">' . h($cf) . '</span>' : '' ?></td>
        <td><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
        <td><?= h($chq) ?></td>
        <td><?= h(isset($cdc[(int) nz($m['CODCDC'], 0)]) ? $cdc[(int) $m['CODCDC']] : '') ?></td>
        <td class="r"><?= f2z($d) ?></td><td class="r"><?= f2z($c) ?></td><td class="r"><?= f2($sal) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="tot"><td colspan="7">SUBTOTAL <?= h(lo_niv($cc)) ?> <?= h(isset($cuentas[$cc]) ? $cuentas[$cc] : '') ?>: <?= count($movs) ?></td><td class="r"><?= f2($sD) ?></td><td class="r"><?= f2($sH) ?></td><td class="r"><?= f2($sal) ?></td></tr>
      <?php $tD += $sD; $tH += $sH; $tSal += $sal; endforeach; ?>
      <?php if (!$grp): ?><tr><td colspan="10" class="text-muted p-2">Sin imputaciones en el filtro.</td></tr><?php endif; ?>
      <tr class="tot" style="border-top:2px solid #000"><td colspan="7"><b>TOTAL CUENTAS: <?= count($grp) ?></b></td><td class="r"><b><?= f2($tD) ?></b></td><td class="r"><b><?= f2($tH) ?></b></td><td class="r"><b><?= f2($tD - $tH) ?></b></td></tr>
    </tbody>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
