<?php
/** Listado de Pre-Conciliación (Rpt IC Pre-Conciliacion). Por cuenta conciliable (CONCUE=True) en
 *  rango: movimientos NO conciliados (CONMOV=False) con FEXMOV <= fecha de corte, saldo corrido
 *  desde la última conciliación (SACCUE/UCHCUE) + DEBE/HABER + SUBTOTAL por cuenta + TOTAL.
 *  Detalle de cheque vía CODCHQ. Filtros: rango de cuenta + hasta fecha + libro (modo). */
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
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $hoyIso;
$sh = sp_serial($hastaIso);

// cuentas conciliables (default = hojas bajo "BANCOS"); guarda SACCUE/UCHCUE
$cuentas = array(); $impList = array(); $sac = array(); $uch = array(); $conc = array(); $bancosCode = '';
foreach (db_query("SELECT CODCUE, DENCUE, IMPCUE, CONCUE, SACCUE, UCHCUE FROM [Tbl Cuentas Contables] ORDER BY CODCUE;") as $a) {
    $cc = trim((string) nz($a['CODCUE'], '')); $den = trim((string) nz($a['DENCUE'], '')); $cuentas[$cc] = $den;
    $imp = ($a['IMPCUE'] === true || $a['IMPCUE'] == -1); $conc[$cc] = ($a['CONCUE'] === true || $a['CONCUE'] == -1);
    $sac[$cc] = (float) nz($a['SACCUE'], 0); $uch[$cc] = $a['UCHCUE'];
    if (!$imp && strtoupper($den) === 'BANCOS' && $bancosCode === '') $bancosCode = $cc;
    if ($imp) $impList[$cc] = lo_niv($cc) . '  ' . $den;
}
$bankDefault = array();
if ($bancosCode !== '') foreach (array_keys($impList) as $cc) if (strpos($cc, $bancosCode) === 0) $bankDefault[] = $cc;
$impKeys = array_keys($impList);
$defD = count($bankDefault) ? $bankDefault[0] : (count($impKeys) ? $impKeys[0] : '');
$defH = count($bankDefault) ? end($bankDefault) : (count($impKeys) ? end($impKeys) : '');
$desCue = (isset($_GET['descue']) && isset($impList[$_GET['descue']])) ? $_GET['descue'] : $defD;
$hasCue = (isset($_GET['hascue']) && isset($impList[$_GET['hascue']])) ? $_GET['hascue'] : $defH;
if (strcmp($desCue, $hasCue) > 0) { $t = $desCue; $desCue = $hasCue; $hasCue = $t; }

$lib = auth_libro_unico();
$est = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');
$rng = "MI.CODCUE >= '" . db_esc($desCue) . "' AND MI.CODCUE <= '" . db_esc($hasCue) . "'";
$cdc = array(); foreach (db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo];") as $r) $cdc[(int) $r['CODCDC']] = trim((string) nz($r['DENCDC'], ''));

// movimientos no conciliados hasta la fecha (FEXMOV), con cheque (RIGHT JOIN como el legacy)
$rows = db_query("SELECT MI.CODCUE, M.FEXMOV, M.NUMMOV, MI.ORDMOV, MI.CODCDC, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DETMOV,
    B.DENBAN AS CHQBAN, C.SYNCHQ, C.FEXCHQ, C.FAXCHQ, C.LIBCHQ, C.LOCCHQ, MI.DEBMOV, MI.CREMOV
    FROM [Tbl Movimientos] AS M INNER JOIN (([Tbl Bancos] AS B RIGHT JOIN [Tbl Cheques] AS C ON B.CODBAN=C.CODBAN)
      RIGHT JOIN [Tbl Movimientos Imputaciones] AS MI ON C.CODCHQ=MI.CODCHQ) ON M.NUMMOV=MI.NUMMOV
    WHERE $rng AND M.FEXMOV <= $sh AND MI.CONMOV=False$est
    ORDER BY MI.CODCUE, M.FEXMOV, M.NUMMOV, MI.ORDMOV;");

$grp = array(); $seen = array();   // dedup por imputación (NUMMOV+ORDMOV) = DISTINCTROW del legacy
foreach ($rows as $r) { $kk = $r['NUMMOV'] . '-' . $r['ORDMOV']; if (isset($seen[$kk])) continue; $seen[$kk] = 1; $cc = trim((string) nz($r['CODCUE'], '')); if (!isset($grp[$cc])) $grp[$cc] = array(); $grp[$cc][] = $r; }
// mostrar cuentas conciliables del rango con movimientos no conciliados
$shown = array();
foreach ($impList as $cc => $lbl) if (isset($grp[$cc]) && (!isset($conc[$cc]) || $conc[$cc])) $shown[$cc] = $cuentas[$cc];
ksort($shown);

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Pre-Conciliación', 'bi-check2-square', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
function cue_opts($list, $sel) { $o = ''; foreach ($list as $k => $v) $o .= '<option value="' . h($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . h($v) . '</option>'; return $o; }
$tD = 0; $tH = 0; $tSac = 0; $tSal = 0;
?>
<link href="../../assets/css/listado.css?v=23" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid">
    <label>Hasta Fecha</label><input type="date" name="hasta" value="<?= h($hastaIso) ?>" class="form-control form-control-sm">
    <label>Cuenta Desde</label><select name="descue" class="form-select form-select-sm lst-cue"><?= cue_opts($impList, $desCue) ?></select>
    <label>Hasta</label><select name="hascue" class="form-select form-select-sm lst-cue"><?= cue_opts($impList, $hasCue) ?></select>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">PRE-CONCILIACIÓN</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTAS</span><span>:</span><span class="v"><?= h(lo_niv($desCue)) ?> - <?= h(lo_niv($hasCue)) ?></span>
    <span>HASTA FECHA</span><span>:</span><span class="v"><?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:1.7cm"><col style="width:1.5cm"><col style="width:2.5cm"><col style="width:2.3cm"><col style="width:3.4cm"><col style="width:3.2cm"><col style="width:2.1cm"><col style="width:2.1cm"><col style="width:2.2cm"></colgroup>
    <thead><tr><th>Fecha</th><th>Nº</th><th>Centro de Costo</th><th>Comprobante</th><th>Detalle</th><th>Cheque</th><th class="r">Debe</th><th class="r">Haber</th><th class="r">Saldo</th></tr></thead>
    <tbody>
      <?php foreach ($shown as $cc => $den):
        $s0 = isset($sac[$cc]) ? $sac[$cc] : 0; $sal = $s0; $sD = 0; $sH = 0; $movs = $grp[$cc]; ?>
      <tr class="parent"><td colspan="6"><b><?= h(lo_niv($cc)) ?> <?= h($den) ?></b> · Últ. concil.: <?= ($uch[$cc] !== null && $uch[$cc] !== '') ? h(fecha_serial($uch[$cc])) : '—' ?> (<?= f2($s0) ?>)</td><td class="r"></td><td class="r"></td><td class="r"><b><?= f2($s0) ?></b></td></tr>
      <?php foreach ($movs as $m):
        $d = (float) nz($m['DEBMOV'], 0); $c = (float) nz($m['CREMOV'], 0); $sal += $d - $c; $sD += $d; $sH += $c;
        $chq = trim((string) nz($m['CHQBAN'], '')); if ($chq !== '') { $chq .= ' ' . chq_serie($m['SYNCHQ']); $lb = trim((string) nz($m['LIBCHQ'], '')); if ($lb !== '') $chq .= ' · ' . $lb; } ?>
      <tr>
        <td><?= h(fecha_serial($m['FEXMOV'])) ?></td><td class="mono"><?= str_pad((string) (int) $m['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td>
        <td><?= h(isset($cdc[(int) nz($m['CODCDC'], 0)]) ? $cdc[(int) $m['CODCDC']] : '') ?></td>
        <td class="mono"><?= h(comp($m['CICMOV'], $m['CIIMOV'], $m['CIPMOV'], $m['CINMOV'])) ?></td>
        <td><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td><td><?= h($chq) ?></td>
        <td class="r"><?= f2z($d) ?></td><td class="r"><?= f2z($c) ?></td><td class="r"><?= f2($sal) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="tot"><td colspan="6">SUBTOTAL <?= h(lo_niv($cc)) ?> <?= h($den) ?>: <?= count($movs) ?></td><td class="r"><?= f2($sD) ?></td><td class="r"><?= f2($sH) ?></td><td class="r"><?= f2($sal) ?></td></tr>
      <?php $tD += $sD; $tH += $sH; $tSac += $s0; $tSal += $sal; endforeach; ?>
      <?php if (!$shown): ?><tr><td colspan="9" class="text-muted p-2">Sin movimientos no conciliados en el filtro.</td></tr><?php endif; ?>
      <tr class="tot" style="border-top:2px solid #000"><td colspan="6"><b>TOTAL CUENTAS: <?= count($shown) ?></b></td><td class="r"><b><?= f2($tD) ?></b></td><td class="r"><b><?= f2($tH) ?></b></td><td class="r"><b><?= f2($tSal) ?></b></td></tr>
    </tbody>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
