<?php
/** Listado de Parte Diario de Caja (Rpt IC Parte Diario Caja). Para una FECHA:
 *  - MOVIMIENTOS: imputaciones a CAJA (Rec Control.CACC_1) del día (ingresos/egresos/saldo corrido).
 *  - CHEQUES DE TERCEROS: movimientos de cheques en cartera (CACC_2) del día (ingresos/egresos).
 *  - CIERRE: registro de [Tbl Cierres] del día (efectivo/cheques anterior · diaria · actual · egr.cartera).
 *  Respeta el libro (modo). El CIERRE es el cierre guardado de esa fecha (si existe). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function f2($v) { return number_format((float) $v, 2, '.', ','); }
function f2z($v) { return ((float) $v == 0.0) ? '' : number_format((float) $v, 2, '.', ','); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function chq_serie($syn) { $s = trim((string) $syn); return (strlen($s) >= 2 && ctype_digit($s)) ? substr($s, 0, 1) . '-' . substr($s, 1) : $s; }
function comp($cic, $cii, $cip, $cin) { $c = trim((string) nz($cic, '')); if ($c === '') return ''; $i = trim((string) nz($cii, '')); return $c . ($i !== '' ? ' ' . $i : '') . ' ' . str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT); }

$rc = db_row("SELECT CACC_1, CACC_2, FECAPE FROM [Rec Control];");
$caja = trim((string) $rc['CACC_1']); $cart = trim((string) $rc['CACC_2']);
$hoyIso = date('Y-m-d');
if ($rc) { $f = fecha_serial($rc['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$fecIso = isset($_GET['fecha']) && $_GET['fecha'] !== '' ? $_GET['fecha'] : $hoyIso;
$sf = sp_serial($fecIso);

$lib = auth_libro_unico();
$est = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');

// MOVIMIENTOS de caja
$movs = db_query("SELECT M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DENMOV, M.DETMOV, MI.DEBMOV, MI.CREMOV
    FROM [Tbl Movimientos] AS M INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON M.NUMMOV=MI.NUMMOV
    WHERE M.FEXMOV=$sf AND MI.CODCUE='" . db_esc($caja) . "'$est ORDER BY M.NUMMOV;");
// CHEQUES de cartera (RIGHT JOIN como el legacy; sólo imputaciones con cheque)
$chqs = db_query("SELECT M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, MI.DEBMOV, MI.CREMOV, C.SYNCHQ, C.FEXCHQ, C.FAXCHQ, B.DENBAN
    FROM [Tbl Movimientos] AS M INNER JOIN (([Tbl Bancos] AS B RIGHT JOIN [Tbl Cheques] AS C ON B.CODBAN=C.CODBAN)
      RIGHT JOIN [Tbl Movimientos Imputaciones] AS MI ON C.CODCHQ=MI.CODCHQ) ON M.NUMMOV=MI.NUMMOV
    WHERE M.FEXMOV=$sf AND MI.CODCUE='" . db_esc($cart) . "' AND MI.CODCHQ Is Not Null$est ORDER BY M.NUMMOV;");
// CIERRE guardado
$cie = db_row("SELECT * FROM [Tbl Cierres] WHERE FECCIE=$sf;");

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Parte Diario de Caja', 'bi-cash-coin', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
?>
<link href="../../assets/css/listado.css?v=23" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid"><label>Fecha</label><input type="date" name="fecha" value="<?= h($fecIso) ?>" class="form-control form-control-sm"></div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">PARTE DIARIO DE CAJA</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>FECHA</span><span>:</span><span class="v"><?= h(fecha_serial($sf)) ?></span>
  </div>

  <table class="lst-tbl lst-jer">
    <thead><tr><th colspan="7" style="text-align:center">MOVIMIENTOS</th></tr>
      <tr><th>Movimiento</th><th>Comprobante</th><th>Cuenta Corriente</th><th>Detalle</th><th class="r">Ingresos</th><th class="r">Egresos</th><th class="r">Saldo</th></tr></thead>
    <colgroup><col style="width:2cm"><col style="width:2.6cm"><col style="width:4.2cm"><col style="width:4cm"><col style="width:2.2cm"><col style="width:2.2cm"><col style="width:2.2cm"></colgroup>
    <tbody>
      <?php $sM = 0; $iM = 0; $eM = 0; foreach ($movs as $m): $d = (float) nz($m['DEBMOV'], 0); $c = (float) nz($m['CREMOV'], 0); $sM += $d - $c; $iM += $d; $eM += $c; ?>
      <tr><td class="mono"><?= str_pad((string) (int) $m['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td><td class="mono"><?= h(comp($m['CICMOV'], $m['CIIMOV'], $m['CIPMOV'], $m['CINMOV'])) ?></td>
        <td><?= h(trim((string) nz($m['DENMOV'], ''))) ?></td><td><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
        <td class="r"><?= f2z($d) ?></td><td class="r"><?= f2z($c) ?></td><td class="r"><?= f2($sM) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$movs): ?><tr><td colspan="7" class="text-muted p-1">Sin movimientos de caja.</td></tr><?php endif; ?>
      <tr class="tot"><td colspan="4">TOTAL MOVIMIENTOS</td><td class="r"><?= f2($iM) ?></td><td class="r"><?= f2($eM) ?></td><td class="r"><?= f2($iM - $eM) ?></td></tr>
    </tbody>
  </table>

  <table class="lst-tbl lst-jer" style="margin-top:.6rem">
    <thead><tr><th colspan="8" style="text-align:center">CHEQUES DE TERCEROS</th></tr>
      <tr><th>Movimiento</th><th>Comprobante</th><th>Banco</th><th>Serie - Nº</th><th>Emisión</th><th>Acreditación</th><th class="r">Ingresos</th><th class="r">Egresos</th></tr></thead>
    <colgroup><col style="width:2cm"><col style="width:2.6cm"><col style="width:3.2cm"><col style="width:2.2cm"><col style="width:2cm"><col style="width:2.2cm"><col style="width:2.3cm"><col style="width:2.3cm"></colgroup>
    <tbody>
      <?php $iC = 0; $eC = 0; foreach ($chqs as $q): $d = (float) nz($q['DEBMOV'], 0); $c = (float) nz($q['CREMOV'], 0); $iC += $d; $eC += $c; ?>
      <tr><td class="mono"><?= str_pad((string) (int) $q['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td><td class="mono"><?= h(comp($q['CICMOV'], $q['CIIMOV'], $q['CIPMOV'], $q['CINMOV'])) ?></td>
        <td><?= h(trim((string) nz($q['DENBAN'], ''))) ?></td><td class="mono"><?= h(chq_serie($q['SYNCHQ'])) ?></td>
        <td><?= h(fecha_serial($q['FEXCHQ'])) ?></td><td><?= h(fecha_serial($q['FAXCHQ'])) ?></td>
        <td class="r"><?= f2z($d) ?></td><td class="r"><?= f2z($c) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$chqs): ?><tr><td colspan="8" class="text-muted p-1">Sin movimientos de cheques.</td></tr><?php endif; ?>
      <tr class="tot"><td colspan="6">TOTAL CHEQUES DE TERCEROS: <?= count($chqs) ?></td><td class="r"><?= f2($iC) ?></td><td class="r"><?= f2($eC) ?></td></tr>
    </tbody>
  </table>

  <?php if ($cie): $eftA = (float) nz($cie['EFTANT'], 0); $chqA = (float) nz($cie['CHQANT'], 0); $eftD = (float) nz($cie['EFTDIA'], 0); $chqD = (float) nz($cie['CHQDIA'], 0); $eftAc = (float) nz($cie['EFTACT'], 0); $chqAc = (float) nz($cie['CHQACT'], 0); ?>
  <div class="lst-boxes">
    <div class="box"><div class="bl">Efectivo Diario</div><div class="bv"><?= f2($eftD) ?></div></div>
    <div class="box"><div class="bl">Retenciones</div><div class="bv"><?= f2(nz($cie['TOTRET'], 0)) ?></div></div>
    <div class="box"><div class="bl">Interdepósitos</div><div class="bv"><?= f2(nz($cie['TOTIDP'], 0)) ?></div></div>
  </div>
  <table class="lst-tbl lst-jer" style="margin-top:.5rem">
    <thead><tr><th colspan="5" style="text-align:center">CIERRE</th></tr>
      <tr><th></th><th class="r">Efectivo</th><th class="r">Cheques</th><th class="r">Total</th><th class="r">Egresos Cartera</th></tr></thead>
    <tbody>
      <tr><td>Saldo Anterior</td><td class="r"><?= f2($eftA) ?></td><td class="r"><?= f2($chqA) ?></td><td class="r"><?= f2($eftA + $chqA) ?></td><td></td></tr>
      <tr><td>Diaria</td><td class="r"><?= f2($eftD) ?></td><td class="r"><?= f2($chqD) ?></td><td class="r"><?= f2($eftD + $chqD) ?></td><td class="r"><?= f2(nz($cie['EGRCAR'], 0)) ?></td></tr>
      <tr class="tot"><td>Saldo Actual</td><td class="r"><?= f2($eftAc) ?></td><td class="r"><?= f2($chqAc) ?></td><td class="r"><?= f2($eftAc + $chqAc) ?></td><td></td></tr>
    </tbody>
  </table>
  <?php else: ?>
  <div class="text-muted small" style="margin-top:.5rem">No hay cierre guardado para esta fecha (sección CIERRE no disponible).</div>
  <?php endif; ?>
</div>
<?php module_foot(); ?>
