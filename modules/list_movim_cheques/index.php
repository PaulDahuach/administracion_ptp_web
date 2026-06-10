<?php
/** Listado de Movimientos de Cheques (Rpt IC Movimientos de Cheques).
 *  Agrupado por cheque (la imputación lleva CODCHQ): cabecera del cheque (banco/serie-nº/emisión/
 *  acreditación/librador/CUIT/localidad) + sus movimientos (comprobante, cuenta, centro, debe/haber).
 *  Filtros: banco + nº de cheque + período (FEXMOV) + libro (modo). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function f2($v) { return ($v === null || $v === '') ? '' : number_format((float) $v, 2, '.', ','); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function chq_serie($syn) { $s = trim((string) $syn); return (strlen($s) >= 2 && ctype_digit($s)) ? substr($s, 0, 1) . '-' . substr($s, 1) : $s; }
function comp($cic, $cii, $cip, $cin) { $c = trim((string) nz($cic, '')); if ($c === '') return ''; $i = trim((string) nz($cii, '')); return $c . ($i !== '' ? ' ' . $i : '') . ' ' . str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT); }

// período (default = año del sistema)
$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$anio = substr($hoyIso, 0, 4);
$desdeIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : ($anio . '-01-01');
$hastaIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $hoyIso;
$sd = sp_serial($desdeIso); $sh = sp_serial($hastaIso);
$ban = isset($_GET['banco']) ? (int) $_GET['banco'] : 0;
$num = isset($_GET['num']) ? trim($_GET['num']) : '';

// combo bancos (solo los que tienen cheques)
$bancos = db_query("SELECT B.CODBAN, B.DENBAN FROM [Tbl Bancos] AS B INNER JOIN [Tbl Cheques] AS C ON B.CODBAN=C.CODBAN GROUP BY B.CODBAN, B.DENBAN ORDER BY B.DENBAN;");
$cdc = array(); foreach (db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo];") as $r) $cdc[(int) $r['CODCDC']] = trim((string) nz($r['DENCDC'], ''));

$lib = auth_libro_unico();
$conds = array('MI.CODCHQ Is Not Null', "M.FEXMOV >= $sd", "M.FEXMOV <= $sh");
if ($ban > 0) $conds[] = 'C.CODBAN = ' . $ban;
if ($num !== '') $conds[] = "C.SYNCHQ Like '%" . db_esc($num) . "%'";
if ($lib === 'blanco') $conds[] = 'M.ESTMOV=True'; elseif ($lib === 'capacitacion') $conds[] = 'M.ESTMOV=False';
$where = implode(' AND ', $conds);

$rows = db_query("SELECT C.CODCHQ, B.DENBAN, C.SYNCHQ, C.FEXCHQ, C.FAXCHQ, C.LIBCHQ, C.CITCHQ, C.LOCCHQ,
    M.FEXMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DETMOV, MI.CODCUE, MI.CODCDC, MI.DEBMOV, MI.CREMOV
    FROM (([Tbl Cheques] AS C INNER JOIN [Tbl Bancos] AS B ON C.CODBAN=B.CODBAN)
      INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON C.CODCHQ=MI.CODCHQ)
      INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV
    WHERE $where ORDER BY B.DENBAN, C.SYNCHQ, M.FEXMOV, M.NUMMOV, MI.ORDMOV;");   // = los 5 BreakLevel del diseño (todos asc)

// agrupar por cheque + cuenta contable (niv + denom) requiere las cuentas
$cuentas = array(); foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Contables];") as $r) $cuentas[trim((string) $r['CODCUE'])] = trim((string) nz($r['DENCUE'], ''));
$grp = array();   // CODCHQ => ['chq'=>..., 'movs'=>[...]]
$totD = 0; $totH = 0;
foreach ($rows as $r) {
    $k = (int) $r['CODCHQ'];
    if (!isset($grp[$k])) $grp[$k] = array('chq' => $r, 'movs' => array());
    $cc = trim((string) nz($r['CODCUE'], ''));
    $grp[$k]['movs'][] = array(
        'fex' => fecha_serial($r['FEXMOV']),
        'comp' => comp($r['CICMOV'], $r['CIIMOV'], $r['CIPMOV'], $r['CINMOV']),
        'det' => trim((string) nz($r['DETMOV'], '')),
        'cuenta' => lo_niv($cc) . ' ' . (isset($cuentas[$cc]) ? $cuentas[$cc] : ''),
        'centro' => isset($cdc[(int) nz($r['CODCDC'], 0)]) ? $cdc[(int) $r['CODCDC']] : '',
        'deb' => $r['DEBMOV'], 'hab' => $r['CREMOV'],
    );
    $totD += (float) nz($r['DEBMOV'], 0); $totH += (float) nz($r['CREMOV'], 0);
}

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Movimientos de Cheques', 'bi-cash-coin', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$banName = 'Todos'; foreach ($bancos as $b) if ((int) $b['CODBAN'] === $ban) $banName = trim((string) $b['DENBAN']);
?>
<link href="../../assets/css/listado.css?v=23" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid">
    <label>Banco</label><select name="banco" class="form-select form-select-sm lst-cue"><option value="0">Todos</option><?php foreach ($bancos as $b): ?><option value="<?= (int) $b['CODBAN'] ?>"<?= ((int) $b['CODBAN'] === $ban) ? ' selected' : '' ?>><?= h(trim((string) $b['DENBAN'])) ?></option><?php endforeach; ?></select>
    <label>Nº Cheque</label><input name="num" value="<?= h($num) ?>" class="form-control form-control-sm" placeholder="(todos)">
    <label>Desde</label><input type="date" name="desde" value="<?= h($desdeIso) ?>" class="form-control form-control-sm">
    <label>Hasta</label><input type="date" name="hasta" value="<?= h($hastaIso) ?>" class="form-control form-control-sm">
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">MOVIMIENTOS DE CHEQUES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CHEQUE</span><span>:</span><span class="v"><?= h($banName) ?><?= $num !== '' ? ' ' . h($num) : '' ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:1.9cm"><col style="width:2.6cm"><col style="width:3.6cm"><col style="width:4.6cm"><col style="width:2.6cm"><col style="width:2.1cm"><col style="width:2.1cm"></colgroup>
    <thead><tr><th>Movimiento</th><th>Comprobante</th><th>Detalle</th><th>Cuenta</th><th>Centro de Costo</th><th class="r">Debe</th><th class="r">Haber</th></tr></thead>
    <tbody>
      <?php foreach ($grp as $g): $c = $g['chq']; ?>
      <tr class="parent"><td colspan="7" class="chq-h">
        <?= h(chq_serie($c['SYNCHQ'])) ?> · <?= h(trim((string) nz($c['DENBAN'], ''))) ?>
        · Emisión <?= h(fecha_serial($c['FEXCHQ'])) ?> · Acred. <?= h(fecha_serial($c['FAXCHQ'])) ?>
        · <?= h(trim((string) nz($c['LIBCHQ'], ''))) ?><?= trim((string) nz($c['CITCHQ'], '')) !== '' ? ' (' . h(trim((string) $c['CITCHQ'])) . ')' : '' ?>
        <?= trim((string) nz($c['LOCCHQ'], '')) !== '' ? '· ' . h(trim((string) $c['LOCCHQ'])) : '' ?>
      </td></tr>
      <?php foreach ($g['movs'] as $m): ?>
      <tr>
        <td><?= h($m['fex']) ?></td><td class="mono"><?= h($m['comp']) ?></td><td><?= h($m['det']) ?></td>
        <td><?= h($m['cuenta']) ?></td><td><?= h($m['centro']) ?></td>
        <td class="r"><?= f2($m['deb']) ?></td><td class="r"><?= f2($m['hab']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$grp): ?><tr><td colspan="7" class="text-muted p-2">Sin movimientos de cheques en el filtro.</td></tr><?php endif; ?>
      <tr class="tot"><td colspan="5">TOTAL CHEQUES: <?= count($grp) ?></td><td class="r"><?= f2($totD) ?></td><td class="r"><?= f2($totH) ?></td></tr>
    </tbody>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
