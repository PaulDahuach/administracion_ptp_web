<?php
/** Listado de Movimientos Bancarios (Rpt IC Movimientos Bancarios).
 *  Ledger por cuenta bancaria (rango de cuenta): SALDO ANTERIOR (Σ DEB−CRE con FAXMOV<desde) + cada
 *  movimiento del período (por FAXMOV/acreditación) con débito/crédito, saldo corrido del período y
 *  saldo actual (anterior+corrido) + SUBTOTAL por cuenta + TOTAL. Detalle de cheque vía CODCHQ.
 *  Filtros: rango de cuenta (DESCUE/HASCUE) + período (FAXMOV) + libro (modo). */
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
$anio = substr($hoyIso, 0, 4);
$desdeIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : ($anio . '-01-01');
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $hoyIso;
$sd = sp_serial($desdeIso); $sh = sp_serial($hastaIso);

// rango de cuenta (default = las cuentas hoja bajo el grupo "BANCOS", como el Rpt)
$cuentas = array(); $impList = array(); $bancosCode = '';
foreach (db_query("SELECT CODCUE, DENCUE, IMPCUE FROM [Tbl Cuentas Contables] ORDER BY CODCUE;") as $a) {
    $cc = trim((string) nz($a['CODCUE'], '')); $den = trim((string) nz($a['DENCUE'], '')); $cuentas[$cc] = $den;
    $imp = ($a['IMPCUE'] === true || $a['IMPCUE'] == -1);
    if (!$imp && strtoupper($den) === 'BANCOS' && $bancosCode === '') $bancosCode = $cc;   // cuenta padre "BANCOS"
    if ($imp) $impList[$cc] = lo_niv($cc) . '  ' . $den;
}
$bankDefault = array();
if ($bancosCode !== '') foreach (array_keys($impList) as $cc) if (strpos($cc, $bancosCode) === 0) $bankDefault[] = $cc;
$defD = count($bankDefault) ? $bankDefault[0] : (count($impList) ? key($impList) : '');
$defH = count($bankDefault) ? end($bankDefault) : '';
$keys = array_keys($impList);
$desCue = (isset($_GET['descue']) && isset($impList[$_GET['descue']])) ? $_GET['descue'] : $defD;
$hasCue = (isset($_GET['hascue']) && isset($impList[$_GET['hascue']])) ? $_GET['hascue'] : ($defH !== '' ? $defH : (count($keys) ? end($keys) : ''));
if (strcmp($desCue, $hasCue) > 0) { $t = $desCue; $desCue = $hasCue; $hasCue = $t; }

$lib = auth_libro_unico();
$est = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');
$rng = "MI.CODCUE >= '" . db_esc($desCue) . "' AND MI.CODCUE <= '" . db_esc($hasCue) . "'";

// saldo anterior por cuenta (Σ DEB−CRE con FAXMOV < desde)
$ant = array();
foreach (db_query("SELECT MI.CODCUE AS CC, SUM(IIf(IsNull(MI.DEBMOV),0,MI.DEBMOV)-IIf(IsNull(MI.CREMOV),0,MI.CREMOV)) AS A
    FROM [Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV
    WHERE $rng AND MI.FAXMOV < $sd$est GROUP BY MI.CODCUE;") as $r) $ant[trim((string) $r['CC'])] = (float) nz($r['A'], 0);

// movimientos del período (por FAXMOV), con cheque (LEFT JOIN)
$rows = db_query("SELECT MI.CODCUE, MI.FAXMOV, B.DENBAN AS CHQBAN, C.SYNCHQ, C.FEXCHQ, C.LIBCHQ, C.LOCCHQ,
    M.NUMMOV, MI.ORDMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DETMOV, MI.DEBMOV, MI.CREMOV
    FROM [Tbl Movimientos] AS M INNER JOIN (([Tbl Bancos] AS B RIGHT JOIN [Tbl Cheques] AS C ON B.CODBAN=C.CODBAN)
      RIGHT JOIN [Tbl Movimientos Imputaciones] AS MI ON C.CODCHQ=MI.CODCHQ) ON M.NUMMOV=MI.NUMMOV
    WHERE MI.FAXMOV >= $sd AND MI.FAXMOV <= $sh AND $rng$est
    ORDER BY MI.CODCUE, MI.FAXMOV, M.NUMMOV, MI.ORDMOV;");

// agrupar por cuenta (preserva orden) + dedup por imputación (NUMMOV+ORDMOV) = DISTINCTROW del legacy
$grp = array(); $seen = array();
foreach ($rows as $r) { $kk = $r['NUMMOV'] . '-' . $r['ORDMOV']; if (isset($seen[$kk])) continue; $seen[$kk] = 1; $cc = trim((string) nz($r['CODCUE'], '')); if (!isset($grp[$cc])) $grp[$cc] = array(); $grp[$cc][] = $r; }
// cuentas a mostrar: TODAS las imputables del rango (aunque tengan 0, como el Rpt)
$shown = array();
foreach ($impList as $cc => $lbl) if (strcmp($cc, $desCue) >= 0 && strcmp($cc, $hasCue) <= 0) $shown[$cc] = $cuentas[$cc];
ksort($shown);

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Movimientos Bancarios', 'bi-bank', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
function cue_opts($list, $sel) { $o = ''; foreach ($list as $k => $v) $o .= '<option value="' . h($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . h($v) . '</option>'; return $o; }
$tA = 0; $tD = 0; $tH = 0; $tSact = 0;
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
    <div class="lst-tit">MOVIMIENTOS BANCARIOS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTAS</span><span>:</span><span class="v"><?= h(lo_niv($desCue)) ?> - <?= h(lo_niv($hasCue)) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer lst-mb">
    <colgroup><col style="width:1.7cm"><col style="width:4.6cm"><col style="width:2.5cm"><col style="width:2.4cm"><col style="width:2.1cm"><col style="width:2.1cm"><col style="width:2.3cm"><col style="width:2.3cm"></colgroup>
    <thead><tr><th>Fecha Acred.</th><th>Cheque</th><th>Mov. / Comprob.</th><th>Detalle</th><th class="r">Debe</th><th class="r">Haber</th><th class="r">Saldo Período</th><th class="r">Saldo Actual</th></tr></thead>
    <tbody>
      <?php foreach ($shown as $cc => $den):
        $a = isset($ant[$cc]) ? $ant[$cc] : 0; $movs = isset($grp[$cc]) ? $grp[$cc] : array();
        $sper = 0; $sact = $a; $sD = 0; $sH = 0; ?>
      <tr class="parent"><td colspan="6"><b><?= h(lo_niv($cc)) ?> <?= h($den) ?></b></td><td class="r">Anterior: <?= f2($a) ?></td><td class="r"><b><?= f2($a) ?></b></td></tr>
      <?php foreach ($movs as $m):
        $deb = (float) nz($m['DEBMOV'], 0); $hab = (float) nz($m['CREMOV'], 0); $sper += $deb - $hab; $sact = $a + $sper; $sD += $deb; $sH += $hab;
        $chq = trim((string) nz($m['CHQBAN'], '')); if ($chq !== '') { $chq .= ' ' . chq_serie($m['SYNCHQ']); $lb = trim((string) nz($m['LIBCHQ'], '')); if ($lb !== '') $chq .= ' · ' . $lb; $lo = trim((string) nz($m['LOCCHQ'], '')); if ($lo !== '') $chq .= ' (' . $lo . ')'; } ?>
      <tr>
        <td><?= h(fecha_serial($m['FAXMOV'])) ?></td>
        <td><?= h($chq) ?><?= ($chq !== '' && ($m['FEXCHQ'] !== null)) ? ' <span class="text-muted">em ' . h(fecha_serial($m['FEXCHQ'])) . '</span>' : '' ?></td>
        <td class="mono"><?= str_pad((string) (int) $m['NUMMOV'], 8, '0', STR_PAD_LEFT) ?> <?= h(comp($m['CICMOV'], $m['CIIMOV'], $m['CIPMOV'], $m['CINMOV'])) ?></td>
        <td><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
        <td class="r"><?= f2z($deb) ?></td><td class="r"><?= f2z($hab) ?></td><td class="r"><?= f2($sper) ?></td><td class="r"><?= f2($sact) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="tot"><td colspan="4">SUBTOTAL <?= h(lo_niv($cc)) ?> <?= h($den) ?>: <?= count($movs) ?></td><td class="r"><?= f2($sD) ?></td><td class="r"><?= f2($sH) ?></td><td class="r"><?= f2($sper) ?></td><td class="r"><?= f2($sact) ?></td></tr>
      <?php $tA += $a; $tD += $sD; $tH += $sH; $tSact += $sact; endforeach; ?>
      <?php if (!$shown): ?><tr><td colspan="8" class="text-muted p-2">Sin movimientos bancarios en el filtro.</td></tr><?php endif; ?>
      <tr class="tot" style="border-top:2px solid #000"><td colspan="4"><b>TOTAL CUENTAS: <?= count($shown) ?></b> · Anterior <?= f2($tA) ?></td><td class="r"><b><?= f2($tD) ?></b></td><td class="r"><b><?= f2($tH) ?></b></td><td class="r"><b><?= f2($tD - $tH) ?></b></td><td class="r"><b><?= f2($tSact) ?></b></td></tr>
    </tbody>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
