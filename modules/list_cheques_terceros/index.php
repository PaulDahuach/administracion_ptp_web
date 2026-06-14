<?php
/** Listado Cheques de Terceros x Fecha (Rpt IC Cheques de Terceros x Fecha Acreditacion / Entrada).
 *  Ciclo de vida de cada cheque de terceros: imputación de INGRESO a cartera (CACC_2=cheque, DEB>0 →
 *  fecha entrada + endosante) + EGRESO (CRE>0 → fecha salida + destino). ?orden=acred (período/orden por
 *  FAXCHQ) | entrada (por FEXMOV del ingreso). Modo doble-libro (auth_libro_unico por ESTMOV del ingreso).
 *  En cartera = cheques sin egreso distinto. Validado vs PDF (combinado): 44 cheques / 16.490.953,88. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function ct_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }
function ct_f2($v) { return number_format((float) $v, 2, '.', ','); }
function ct_syn($s) { $s = trim((string) $s); return (strlen($s) > 1) ? substr($s, 0, 1) . '-' . substr($s, 1) : $s; }   // serie-número

$orden = (isset($_GET['orden']) && $_GET['orden'] === 'entrada') ? 'entrada' : 'acred';
list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = ct_serial($desIso); $sh = ct_serial($hasIso);

$lib = auth_libro_unico();   // '' integral (combinado) · 'blanco'/'capacitacion'
$c2 = trim((string) nz(db_row("SELECT CACC_2 FROM [Rec Control];")['CACC_2'], '11103'));

// INGRESO por cheque (a cartera): imputación a CACC_2 con DEB>0 → entrada + endosante + ESTMOV
$ing = array();
foreach (db_query("SELECT MI.CODCHQ, M.NUMMOV, M.FEXMOV, M.DENMOV, M.DETMOV, M.ESTMOV, O.DENOPE
    FROM ([Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON MI.NUMMOV=M.NUMMOV)
      LEFT JOIN [Tbl Operaciones] AS O ON O.CODOPE=M.CODOPE
    WHERE MI.CODCUE='$c2' AND MI.DEBMOV>0 AND MI.CODCHQ IS NOT NULL;") as $r) {
    $k = (int) $r['CODCHQ']; if (isset($ing[$k])) continue;
    $end = trim((string) nz($r['DENMOV'], '')); if ($end === '') $end = trim((string) nz($r['DETMOV'], ''));
    if ($end === '') $end = trim((string) nz($r['DENOPE'], ''));
    $ing[$k] = array('NUM' => (int) $r['NUMMOV'], 'FEX' => $r['FEXMOV'], 'END' => $end, 'EST' => (bool) $r['ESTMOV']);
}
// EGRESO por cheque (salida): imputación a CACC_2 con CRE>0 → salida + destino (comprobante)
$egr = array();
foreach (db_query("SELECT MI.CODCHQ, M.NUMMOV, M.FEXMOV, M.CICMOV, M.CIPMOV, M.CINMOV, M.DENMOV, M.DETMOV, CB.DENCBX
    FROM ([Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON MI.NUMMOV=M.NUMMOV)
      LEFT JOIN [Tbl Cuentas Bancarias] AS CB ON CB.CODCBX=M.CODCBX
    WHERE MI.CODCUE='$c2' AND MI.CREMOV>0 AND MI.CODCHQ IS NOT NULL;") as $r) {
    $k = (int) $r['CODCHQ']; if (isset($egr[$k])) continue;
    $dst = trim((string) nz($r['DENMOV'], '')); if ($dst === '') $dst = trim((string) nz($r['DETMOV'], ''));
    if ($dst === '') $dst = trim((string) nz($r['DENCBX'], ''));
    $egr[$k] = array('NUM' => (int) $r['NUMMOV'], 'FEX' => $r['FEXMOV'],
        'CIC' => trim((string) nz($r['CICMOV'], '')), 'CIP' => (int) nz($r['CIPMOV'], 0),
        'CIN' => (int) nz($r['CINMOV'], 0), 'DST' => $dst);
}

// cheques + banco
$rowsOut = array(); $cartCnt = 0; $cartAmt = 0.0; $totCnt = 0; $totAmt = 0.0;
foreach (db_query("SELECT Chq.CODCHQ, Chq.IMPCHQ, Chq.FAXCHQ, Chq.SYNCHQ, B.DENBAN
    FROM [Tbl Cheques] AS Chq LEFT JOIN [Tbl Bancos] AS B ON B.CODBAN=Chq.CODBAN;") as $c) {
    $k = (int) $c['CODCHQ'];
    if (!isset($ing[$k])) continue;                        // debe haber entrado a cartera
    $in = $ing[$k];
    if ($lib === 'blanco' && !$in['EST']) continue;         // filtro doble-libro por ESTMOV del ingreso
    if ($lib === 'capacitacion' && $in['EST']) continue;
    $eg = isset($egr[$k]) ? $egr[$k] : null;
    $tieneSalida = ($eg && $eg['NUM'] != $in['NUM']);        // egreso real (mov distinto al ingreso)
    // fecha primaria del orden/filtro
    $primFex = ($orden === 'entrada') ? (int) $in['FEX'] : (int) nz($c['FAXCHQ'], 0);
    if ($primFex < $sd || $primFex > $sh) continue;
    $imp = (float) nz($c['IMPCHQ'], 0);
    $totCnt++; $totAmt += $imp;
    if (!$tieneSalida) { $cartCnt++; $cartAmt += $imp; }
    $rowsOut[] = array(
        'PRIM' => $primFex, 'FENT' => (int) $in['FEX'], 'FACR' => (int) nz($c['FAXCHQ'], 0),
        'FSAL' => $tieneSalida ? (int) $eg['FEX'] : 0, 'END' => $in['END'],
        'BAN' => trim((string) nz($c['DENBAN'], '')), 'SYN' => trim((string) nz($c['SYNCHQ'], '')), 'IMP' => $imp,
        'ECIC' => $tieneSalida ? $eg['CIC'] : '', 'ECIP' => $tieneSalida ? $eg['CIP'] : 0,
        'ECIN' => $tieneSalida ? $eg['CIN'] : 0, 'EDST' => $tieneSalida ? $eg['DST'] : '',
    );
}
usort($rowsOut, function ($a, $b) { if ($a['PRIM'] !== $b['PRIM']) return $a['PRIM'] - $b['PRIM']; return 0; });

$esAcr = ($orden === 'acred');
$titulo = $esAcr ? 'CHEQUES DE TERCEROS x FECHA DE ACREDITACION' : 'CHEQUES DE TERCEROS x FECHA DE ENTRADA';
$lblPrim = $esAcr ? 'FECHA ACREDITACION' : 'FECHA ENTRADA';
$lblSec  = $esAcr ? 'FECHA ENTRADA' : 'FECHA ACREDITACION';
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Cheques de Terceros x Fecha', 'bi-cash-coin', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<style>
  @media print { @page { size: Letter landscape; margin: 10mm; } .lst-doc { width: auto; box-shadow: none; } }
  .lst-doc.ct-page { width: 277mm; min-height: 200mm; }
  .ct-doc { font-family: "Univers Condensed", "Arial Narrow", sans-serif; }
  .ct-tbl { width: 25.5cm; border-collapse: collapse; table-layout: fixed; }
  .ct-tbl thead th { font-size: 7pt; font-weight: 400; text-align: center; vertical-align: middle;
    border: 1px solid #000; background: #c0c0c0; line-height: 1.05; padding: 1px 2px; }
  .ct-tbl tbody td { font-size: 8pt; height: .34cm; line-height: .34cm; padding: 0 2px; vertical-align: top; white-space: nowrap; }
  .ct-tbl td.w { white-space: normal; word-break: break-word; }
  .ct-tbl .r { text-align: right; } .ct-tbl .c { text-align: center; } .ct-tbl .l { text-align: left; }
  .ct-tbl tfoot td { font-size: 8pt; font-weight: 700; padding: 2px; border-top: 1px solid #000; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <input type="hidden" name="orden" value="<?= h($orden) ?>">
  <div class="lst-fgrid3">
    <span class="lst-fpair"><label>Orden</label>
      <select name="orden" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="acred"<?= $esAcr ? ' selected' : '' ?>>x Fecha de Acreditación</option>
        <option value="entrada"<?= !$esAcr ? ' selected' : '' ?>>x Fecha de Entrada</option>
      </select></span>
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>

<div class="lst-doc lst-doc-wide ct-page ct-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit"><?= h($titulo) ?></div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO [<?= $esAcr ? 'ACREDITACION' : 'ENTRADA' ?>]</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="ct-tbl">
    <colgroup>
      <col style="width:1.5cm"><col style="width:4.6cm"><col style="width:2.4cm"><col style="width:1.5cm">
      <col style="width:2.0cm"><col style="width:1.5cm"><col style="width:1.5cm"><col style="width:0.6cm">
      <col style="width:0.7cm"><col style="width:1.4cm"><col style="width:4.3cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2"><?= h($lblPrim) ?></th>
        <th rowspan="2">ENDOSANTE / DETALLE</th>
        <th rowspan="2">BANCO</th>
        <th rowspan="2">SERIE - N&ordm;</th>
        <th rowspan="2">IMPORTE</th>
        <th rowspan="2"><?= h($lblSec) ?></th>
        <th rowspan="2">FECHA<br>SALIDA</th>
        <th colspan="4">DESTINO</th>
      </tr>
      <tr><th>COD</th><th>PDV</th><th>NUMERO</th><th>ENDOSO / DETALLE</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rowsOut as $r):
        $fEnt = $r['FENT'] ? fecha_serial($r['FENT']) : ''; $fAcr = $r['FACR'] ? fecha_serial($r['FACR']) : '';
        $sec = $esAcr ? $fEnt : $fAcr; ?>
      <tr>
        <td class="c"><?= h(fecha_serial($r['PRIM'])) ?></td>
        <td class="l w"><?= h($r['END']) ?></td>
        <td class="l"><?= h($r['BAN']) ?></td>
        <td class="c"><?= h(ct_syn($r['SYN'])) ?></td>
        <td class="r"><?= ct_f2($r['IMP']) ?></td>
        <td class="c"><?= h($sec) ?></td>
        <td class="c"><?= $r['FSAL'] ? h(fecha_serial($r['FSAL'])) : '' ?></td>
        <td class="c"><?= h($r['ECIC']) ?></td>
        <td class="c"><?= $r['ECIP'] > 0 ? str_pad((string) $r['ECIP'], 4, '0', STR_PAD_LEFT) : '' ?></td>
        <td class="c"><?= $r['ECIN'] > 0 ? str_pad((string) $r['ECIN'], 8, '0', STR_PAD_LEFT) : '' ?></td>
        <td class="l w"><?= h($r['EDST']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rowsOut): ?>
      <tr><td colspan="11" class="l" style="padding:.4cm">Sin cheques de terceros en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="l" colspan="4">TOTAL CHEQUES: <?= (int) $cartCnt ?> / <?= (int) $totCnt ?></td>
        <td class="r">TOTAL EN CARTERA: <?= ct_f2($cartAmt) ?> / <?= ct_f2($totAmt) ?></td>
        <td colspan="6"></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php module_foot(); ?>
