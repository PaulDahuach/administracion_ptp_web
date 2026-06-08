<?php
/**
 * Constancia de Imputación — reproduce el legacy "Rpt IC Constancia Imputacion". Para asientos
 * internos (CODORI='I'): membrete de la empresa + datos del movimiento (Nº/fecha/operación/detalle)
 * + la tabla del asiento (imputación · banco · serie-nº de cheque · DEBE · HABER) + firma.
 * Uso: ?nummov=N  (&print=1 para auto-imprimir). Hoja Carta, Univers Condensed (como el resto).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
auth_require_login();

$num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
$h = db_row("SELECT M.NUMMOV, M.CINMOV, M.FEXMOV, M.DETMOV, M.ESTMOV, M.ANUMOV, M.CODOPE, O.DENOPE
    FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Operaciones] AS O ON M.CODOPE=O.CODOPE
    WHERE M.NUMMOV=$num AND M.CODORI='I';");
if (!$h) { http_response_code(404); echo 'Movimiento no encontrado'; exit; }
$estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
$lib = auth_libro_unico();
if (($lib === 'blanco' && !$estTrue) || ($lib === 'capacitacion' && $estTrue)) { http_response_code(403); echo 'No disponible en este libro'; exit; }

// Imputaciones del asiento (cuenta + cheque, si lo hay)
$lineas = array(); $sumD = 0.0; $sumH = 0.0;
foreach (db_query("SELECT I.CODCUE, I.DEBMOV, I.CREMOV, I.CODCHQ, C.DENCUE
    FROM [Tbl Movimientos Imputaciones] AS I LEFT JOIN [Tbl Cuentas Contables] AS C ON I.CODCUE=C.CODCUE
    WHERE I.NUMMOV=$num ORDER BY I.ORDMOV;") as $i) {
    $banco = ''; $serie = '';
    if ((int) nz($i['CODCHQ'], 0) > 0) {
        $cq = db_row("SELECT C.SYNCHQ, B.DENBAN FROM [Tbl Cheques] AS C LEFT JOIN [Tbl Bancos] AS B ON C.CODBAN=B.CODBAN WHERE C.CODCHQ=" . (int) $i['CODCHQ'] . ";");
        if ($cq) { $banco = trim((string) nz($cq['DENBAN'], '')); $serie = trim((string) nz($cq['SYNCHQ'], '')); }
    }
    $d = round((float) nz($i['DEBMOV'], 0), 2); $hh = round((float) nz($i['CREMOV'], 0), 2);
    $sumD += $d; $sumH += $hh;
    $lineas[] = array('cue' => trim((string) nz($i['CODCUE'], '')) . ' · ' . trim((string) nz($i['DENCUE'], '')),
        'banco' => $banco, 'serie' => $serie, 'deb' => $d, 'cre' => $hh);
}

$nroCons = str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
$libLbl  = $estTrue ? 'Operativo' : 'Capacitación';
$anulado = ($h['ANUMOV'] === true || $h['ANUMOV'] == -1);
$EMP = array('nom' => 'PROCESADORA TEXTIL PARQUE S.A.', 'dir' => 'RUTA 32 KM. 1.5 - PARQUE INDUSTRIAL',
    'loc' => '(2700) - Pergamino Bs. As.', 'cuit' => '30-70838113-2', 'ib' => '30-70838113-2', 'ini' => '06/2003');

function pe($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function nm($v) { return number_format((float) $v, 2, '.', ','); }
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Constancia de Imputación Nº <?= pe($nroCons) ?></title>
<style>
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed.woff') ?>') format('woff'); font-weight:normal; font-style:normal; font-display:swap; }
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff') ?>') format('woff'); font-weight:bold; font-style:normal; font-display:swap; }
  * { box-sizing:border-box; }
  body { background:#e9edf2; color:#000; margin:0; font-family:'Univers Condensed',"Arial Narrow","Liberation Sans Narrow",Arial,sans-serif; font-size:12px; }
  .hoja { background:#fff; width:216mm; min-height:279mm; margin:10px auto; padding:14mm 16mm; box-shadow:0 2px 12px rgba(0,0,0,.2); display:flex; flex-direction:column; }
  .foot { margin-top:auto; }
  .top { display:flex; justify-content:space-between; align-items:flex-start; }
  .emp .nom { font-size:24px; font-weight:bold; line-height:1; }
  .emp .ln { font-size:12px; }
  .emp .fz { font-size:11px; margin-top:6px; line-height:1.35; }
  .doc { text-align:right; font-size:13px; font-weight:bold; line-height:1.3; }
  .doc .big { font-size:15px; }
  .sec { font-size:12px; margin-top:24px; margin-bottom:3px; font-weight:bold; }
  table.dl { border-collapse:collapse; width:100%; }
  table.dl td { font-size:12px; font-weight:bold; padding:1px 0; vertical-align:top; line-height:1.45; }
  table.dl td.lbl { width:140px; white-space:nowrap; }
  table.dl td.sep { width:14px; }
  /* Tabla del asiento */
  table.as { border-collapse:collapse; width:100%; margin-top:6px; }
  table.as th { font-size:11px; border-bottom:1.5px solid #000; padding:3px 5px; text-align:left; }
  table.as td { font-size:12px; padding:2px 5px; border-bottom:.5px solid #bbb; vertical-align:top; }
  table.as th.r, table.as td.r { text-align:right; }
  table.as tfoot td { font-weight:bold; border-top:1.5px solid #000; border-bottom:none; padding-top:4px; }
  .money { display:inline-block; min-width:14px; }
  .anul { color:#b00; font-weight:bold; border:1.5px solid #b00; padding:1px 8px; font-size:13px; }
  .firma { border:1px solid #000; margin-top:14px; height:38mm; display:flex; align-items:flex-end; justify-content:space-around; padding:0 0 7px; }
  .firma .fb { width:42%; text-align:center; }
  .firma .fl { border-top:1px solid #000; margin:0 8%; } .firma .ft { font-weight:bold; font-size:10px; padding-top:3px; }
  .bar { position:sticky; top:0; background:#1e293b; color:#fff; padding:.5rem 1rem; display:flex; gap:1rem; align-items:center; font-family:system-ui; }
  .bar button { padding:.35rem .9rem; border:0; border-radius:.35rem; background:#2563eb; color:#fff; cursor:pointer; }
  @media print { .bar { display:none; } body { background:#fff; } .hoja { box-shadow:none; margin:0; width:auto; min-height:auto; padding:0; } @page { size:letter portrait; margin:14mm 16mm; } }
</style></head><body>
<div class="bar"><button onclick="window.print()">🖨 Imprimir</button><span>Constancia de Imputación <b>Nº <?= pe($nroCons) ?></b> · Mov. <?= pe((int) $h['NUMMOV']) ?> · <?= pe(trim(nz($h['DENOPE'], ''))) ?></span></div>

<div class="hoja">
  <!-- ── Membrete ── -->
  <div class="top">
    <div class="emp">
      <div class="nom"><?= pe($EMP['nom']) ?></div>
      <div class="ln"><?= pe($EMP['dir']) ?> · <?= pe($EMP['loc']) ?></div>
      <div class="fz">C.U.I.T. Nº <?= pe($EMP['cuit']) ?> · ING. BRUTOS C.M. <?= pe($EMP['ib']) ?> · INICIO DE ACTIVIDADES <?= pe($EMP['ini']) ?></div>
    </div>
    <div class="doc">
      <div class="big">CONSTANCIA DE IMPUTACION</div>
      <div>Nº <?= pe($nroCons) ?></div>
      <div>FECHA DE EMISION: <?= pe(fecha_serial($h['FEXMOV'])) ?></div>
      <div style="font-weight:normal;font-size:10px">REIMPRESION <?= pe(date('d/m/Y')) ?></div>
    </div>
  </div>

  <!-- ── Datos del movimiento ── -->
  <div class="sec">Datos del Movimiento<?php if ($anulado): ?> &nbsp; <span class="anul">ANULADO</span><?php endif; ?></div>
  <table class="dl">
    <tr><td class="lbl">MOVIMIENTO Nº</td><td class="sep">:</td><td><?= pe(str_pad((string) (int) $h['NUMMOV'], 8, '0', STR_PAD_LEFT)) ?></td></tr>
    <tr><td class="lbl">OPERACION</td><td class="sep">:</td><td><?= pe(trim(nz($h['DENOPE'], ''))) ?></td></tr>
    <tr><td class="lbl">LIBRO</td><td class="sep">:</td><td><?= pe($libLbl) ?></td></tr>
    <?php if (trim((string) nz($h['DETMOV'], '')) !== ''): ?><tr><td class="lbl">DETALLE</td><td class="sep">:</td><td><?= pe(trim(nz($h['DETMOV'], ''))) ?></td></tr><?php endif; ?>
  </table>

  <!-- ── Asiento (imputación) ── -->
  <table class="as">
    <thead><tr><th>IMPUTACION</th><th>BANCO</th><th>SERIE - Nº</th><th class="r" style="width:110px">DEBE</th><th class="r" style="width:110px">HABER</th></tr></thead>
    <tbody>
    <?php foreach ($lineas as $l): ?>
      <tr><td><?= pe($l['cue']) ?></td><td><?= pe($l['banco']) ?></td><td><?= pe($l['serie']) ?></td>
        <td class="r"><?= $l['deb'] > 0 ? nm($l['deb']) : '' ?></td><td class="r"><?= $l['cre'] > 0 ? nm($l['cre']) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="3" class="r">TOTAL</td><td class="r">$ <span class="money"><?= nm($sumD) ?></span></td><td class="r">$ <span class="money"><?= nm($sumH) ?></span></td></tr></tfoot>
  </table>

  <!-- ── Pie ── -->
  <div class="foot">
    <div class="firma">
      <div class="fb"><div class="fl"></div><div class="ft">FIRMA</div></div>
      <div class="fb"><div class="fl"></div><div class="ft">ACLARACION</div></div>
    </div>
  </div>
</div>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 250); });</script><?php endif; ?>
</body></html>
