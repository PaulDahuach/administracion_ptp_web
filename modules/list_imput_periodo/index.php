<?php
/** Listado de Imputaciones Contables x Período [Fecha Comprobante] (Rpt IC Imputaciones x Periodo).
 *  Listado CRONOLÓGICO por movimiento (no por cuenta): cada movimiento del período (filtra por CEFMOV)
 *  con su cabecera (fecha · nº · operación · comprobante · detalle · debe) y sus imputaciones
 *  (cuenta · centro · cheque · debe · haber). TOTAL IMPUTACIONES (líneas) + ΣDebe/ΣHaber. Sin saldo
 *  corrido. Filtros: período (CEFMOV) + rango de cuenta + operación + libro (modo). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function f2($v) { return number_format((float) $v, 2, '.', ','); }
function f2z($v) { return ((float) $v == 0.0) ? '' : number_format((float) $v, 2, '.', ','); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }
function chq_serie($syn) { $s = trim((string) $syn); return (strlen($s) >= 2 && ctype_digit($s)) ? substr($s, 0, 1) . '-' . substr($s, 1) : $s; }
function comp($cic, $cii, $cip, $cin) { $c = trim((string) nz($cic, '')); if ($c === '') return ''; $i = trim((string) nz($cii, '')); return $c . ($i !== '' ? ' ' . $i : '') . ' ' . str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT); }

$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$mesIni = substr($hoyIso, 0, 7) . '-01';
$desdeIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $mesIni;
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : date('Y-m-t', strtotime($mesIni));
$sd = sp_serial($desdeIso); $sh = sp_serial($hastaIso);

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

$rows = db_query("SELECT MI.CODCUE, M.FEXMOV, M.NUMMOV, MI.ORDMOV, MI.CONMOV, O.DENOPE,
    M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.CEFMOV, M.DETMOV,
    B.DENBAN AS CHQBAN, C.SYNCHQ, C.FEXCHQ, C.FAXCHQ, C.LIBCHQ, C.LOCCHQ, MI.CODCDC, MI.DEBMOV, MI.CREMOV
    FROM (([Tbl Operaciones] AS O INNER JOIN [Tbl Movimientos] AS M ON O.CODOPE=M.CODOPE)
      INNER JOIN (([Tbl Bancos] AS B RIGHT JOIN ([Tbl Movimientos Imputaciones] AS MI
        LEFT JOIN [Tbl Cheques] AS C ON MI.CODCHQ=C.CODCHQ) ON B.CODBAN=C.CODBAN)) ON M.NUMMOV=MI.NUMMOV)
    WHERE $rng AND M.CEFMOV >= $sd AND M.CEFMOV <= $sh$wOpe$est
    ORDER BY M.CEFMOV, M.NUMMOV, MI.ORDMOV;");

// agrupar por movimiento (preserva orden cronológico), dedup imputación
$grp = array(); $seen = array(); $nImp = 0; $tD = 0; $tH = 0;
foreach ($rows as $r) {
    $kk = $r['NUMMOV'] . '-' . $r['ORDMOV']; if (isset($seen[$kk])) continue; $seen[$kk] = 1;
    $n = (int) $r['NUMMOV'];
    if (!isset($grp[$n])) $grp[$n] = array('h' => $r, 'lin' => array(), 'd' => 0);
    $grp[$n]['lin'][] = $r; $grp[$n]['d'] += (float) nz($r['DEBMOV'], 0);
    $nImp++; $tD += (float) nz($r['DEBMOV'], 0); $tH += (float) nz($r['CREMOV'], 0);
}

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Imputaciones x Período', 'bi-calendar-range', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
function cue_opts($list, $sel) { $o = ''; foreach ($list as $k => $v) $o .= '<option value="' . h($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . h($v) . '</option>'; return $o; }
?>
<link href="../../assets/css/listado.css?v=24" rel="stylesheet">
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
    <div class="lst-tit">IMPUTACIONES CONTABLES x PERÍODO [FECHA COMPROBANTE]</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTAS</span><span>:</span><span class="v"><?= h(lo_niv($desCue)) ?> - <?= h(lo_niv($hasCue)) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:4.2cm"><col style="width:2.4cm"><col style="width:3.6cm"><col style="width:2.1cm"><col style="width:2.1cm"></colgroup>
    <thead><tr><th>Cuenta</th><th>Centro de Costo</th><th>Cheque</th><th class="r">Debe</th><th class="r">Haber</th></tr></thead>
    <tbody>
      <?php foreach ($grp as $n => $g): $hh = $g['h']; ?>
      <tr class="parent chq-h"><td colspan="5">
        <?= h(fecha_serial($hh['CEFMOV'])) ?> · <b><?= str_pad((string) $n, 8, '0', STR_PAD_LEFT) ?></b> · <?= h(trim((string) nz($hh['DENOPE'], ''))) ?>
        · <span class="mono"><?= h(comp($hh['CICMOV'], $hh['CIIMOV'], $hh['CIPMOV'], $hh['CINMOV'])) ?></span>
        <?= trim((string) nz($hh['DETMOV'], '')) !== '' ? '· ' . h(trim((string) $hh['DETMOV'])) : '' ?>
        &nbsp;— Debe <?= f2($g['d']) ?>
      </td></tr>
      <?php foreach ($g['lin'] as $m):
        $cc = trim((string) nz($m['CODCUE'], ''));
        $chq = trim((string) nz($m['CHQBAN'], '')); if ($chq !== '') { $chq .= ' ' . chq_serie($m['SYNCHQ']); $lb = trim((string) nz($m['LIBCHQ'], '')); if ($lb !== '') $chq .= ' · ' . $lb; } ?>
      <tr>
        <td><?= h(lo_niv($cc)) ?> <?= h(isset($cuentas[$cc]) ? $cuentas[$cc] : '') ?></td>
        <td><?= h(isset($cdc[(int) nz($m['CODCDC'], 0)]) ? $cdc[(int) $m['CODCDC']] : '') ?></td>
        <td><?= h($chq) ?></td>
        <td class="r"><?= f2z($m['DEBMOV']) ?></td><td class="r"><?= f2z($m['CREMOV']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$grp): ?><tr><td colspan="5" class="text-muted p-2">Sin imputaciones en el período.</td></tr><?php endif; ?>
      <tr class="tot" style="border-top:2px solid #000"><td colspan="3"><b>TOTAL IMPUTACIONES: <?= $nImp ?></b></td><td class="r"><b><?= f2($tD) ?></b></td><td class="r"><b><?= f2($tH) ?></b></td></tr>
    </tbody>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
