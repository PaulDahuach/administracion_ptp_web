<?php
/**
 * Impresión de FACTURA DE VENTA — reproduce el comprobante legacy (ref `fc-a-0003-00006101.pdf`).
 * Hoja Carta (.hoja), Univers Condensed self-hosteada. Membrete con la letra (A/B Código 01/06) +
 * datos del cliente + grilla de productos + totales por alícuota + CAE ("Comprobante Autorizado").
 * Uso: ?nummov=N  (&print=1 para auto-imprimir).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
auth_require_login();

$num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
$h = db_row("SELECT * FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=420;");
if (!$h) { http_response_code(404); echo 'Factura no encontrada'; exit; }

$loc = db_row("SELECT L.CPXLOC, L.DENLOC, P.DENPRO FROM [Tbl Localidades] AS L LEFT JOIN [Tbl Provincias] AS P ON L.CODPRO=P.CODPRO WHERE L.CODLOC=" . (int) nz($h['CODLOC'], 0) . ";");
$cri = db_row("SELECT DENCRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($h['CODCRI'], 0) . ";");
$cdv = db_row("SELECT DENCDV FROM [Tbl Condiciones de Venta] WHERE CODCDV=" . (int) nz($h['CODCDV'], 0) . ";");
$fdp = db_row("SELECT DENFDP FROM [Tbl Formas de Pago] WHERE CODFDP=" . (int) nz($h['CODFDP'], 0) . ";");

// Productos (Tbl Movimientos Stock) + nº de remito (MRVMOV → CINMOV)
$prods = array();
foreach (db_query("SELECT ORDMOV, MRVMOV, CODPRO, DENMOV, EGRMOV, PUNMOV, ODCMOV, PDLMOV, ODPMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num ORDER BY ORDMOV;") as $s) {
    $rem = '';
    if ($s['MRVMOV'] !== null && $s['MRVMOV'] !== '') { $rm = db_row("SELECT CINMOV FROM [Tbl Movimientos] WHERE NUMMOV=" . (int) $s['MRVMOV'] . ";"); if ($rm) $rem = str_pad((string) (int) nz($rm['CINMOV'], 0), 8, '0', STR_PAD_LEFT); }
    $cant = round((float) nz($s['EGRMOV'], 0), 2); $pun = round((float) nz($s['PUNMOV'], 0), 2);
    $prods[] = array('cant' => $cant, 'rem' => $rem, 'ptp' => (int) nz($s['PDLMOV'], 0), 'odc' => (int) nz($s['ODCMOV'], 0), 'odp' => (int) nz($s['ODPMOV'], 0),
        'cod' => trim((string) nz($s['CODPRO'], '')), 'den' => trim((string) nz($s['DENMOV'], '')), 'pun' => $pun, 'tot' => round($cant * $pun, 2));
}
// IVA por alícuota
$ivas = db_query("SELECT ALIMOV, NETMOV, IRIMOV FROM [Tbl Movimientos IVA] WHERE NUMMOV=$num ORDER BY ALIMOV;");

$letra = strtoupper(trim((string) nz($h['CIIMOV'], 'A')));
$codAfip = array('A' => '01', 'B' => '06', 'C' => '11')[$letra]; if (!$codAfip) $codAfip = '01';
$comp  = str_pad((string) (int) nz($h['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
$total = round((float) nz($h['TOTMOV'], 0), 2);
$pix   = round((float) nz($h['PIXMOV'], 0), 2);
$mov8  = str_pad((string) $num, 8, '0', STR_PAD_LEFT);

$EMP = array('nom' => 'PROCESADORA TEXTIL PARQUE S.A.', 'dir' => 'RUTA 32 KM. 1.5 - PARQUE INDUSTRIAL',
    'loc' => '(2700) - Pergamino Bs. As.', 'tel' => '(02477) 42-0710', 'cuit' => '30-70838113-2', 'ib' => '30-70838113-2', 'ini' => '06/2003');

function pe($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function nm($v) { return number_format((float) $v, 2, '.', ','); }
function fv_letras($n) {
    $n = round((float) $n, 2); $ent = (int) floor($n); $cen = (int) round(($n - $ent) * 100);
    $u = array('', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE');
    $dec = array('', '', 'VEINTI', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA');
    $cent = array('', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS');
    $tres = function ($x) use ($u, $dec, $cent) { $x = (int) $x; if ($x == 0) return ''; if ($x == 100) return 'CIEN'; $c = (int) ($x / 100); $r = $x % 100; $s = $cent[$c];
        if ($r > 0) { if ($s !== '') $s .= ' '; if ($r <= 20) $s .= $u[$r]; elseif ($r < 30) $s .= 'VEINTI' . $u[$r - 20]; else { $d = (int) ($r / 10); $un = $r % 10; $s .= $dec[$d] . ($un ? ' Y ' . $u[$un] : ''); } } return trim($s); };
    if ($ent == 0) $p = 'CERO'; else { $mi = (int) ($ent / 1000000); $mil = (int) (($ent % 1000000) / 1000); $re = $ent % 1000; $p = '';
        if ($mi > 0) $p .= ($mi == 1 ? 'UN MILLON' : $tres($mi) . ' MILLONES') . ' '; if ($mil > 0) $p .= ($mil == 1 ? 'MIL' : $tres($mil) . ' MIL') . ' '; $p .= $tres($re); $p = trim($p); }
    return 'SON PESOS: ' . $p . ' CON ' . str_pad((string) $cen, 2, '0', STR_PAD_LEFT) . '/100.-';
}
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Factura <?= pe($letra . ' ' . $comp) ?></title>
<style>
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed.woff') ?>') format('woff'); font-weight:normal; font-style:normal; font-display:swap; }
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff') ?>') format('woff'); font-weight:bold; font-style:normal; font-display:swap; }
  * { box-sizing:border-box; }
  body { background:#e9edf2; color:#000; margin:0; font-family:'Univers Condensed',"Arial Narrow",Arial,sans-serif; font-size:11px; }
  .hoja { background:#fff; width:216mm; min-height:279mm; margin:10px auto; padding:10mm 12mm; box-shadow:0 2px 12px rgba(0,0,0,.2); display:flex; flex-direction:column; }
  .foot { margin-top:auto; }
  .num { text-align:right; font-variant-numeric:tabular-nums; }
  /* Membrete con la letra al centro */
  .top { display:flex; border:1px solid #000; }
  .top .emp { flex:1 1 0; padding:6px 8px; }
  .top .emp .nom { font-size:23px; font-weight:bold; line-height:1; }
  .top .emp .det { font-size:10px; font-weight:bold; line-height:1.25; margin-top:18px; text-align:center; }
  .top .emp .iva { font-size:11px; font-weight:bold; margin-top:10px; }
  .top .letra { width:60px; border-left:1px solid #000; border-right:1px solid #000; display:flex; flex-direction:column; align-items:center; justify-content:flex-start; padding-top:6px; }
  .top .letra .L { font-size:34px; font-weight:bold; line-height:.9; }
  .top .letra .cod { font-size:8px; }
  .top .doc { width:230px; padding:6px 8px; font-size:11px; font-weight:bold; }
  .top .doc .cab { text-align:center; } .top .doc .cab .c1 { font-size:9px; } .top .doc .cab .c2 { font-size:14px; }
  .top .doc .nro { font-size:17px; font-weight:bold; margin:3px 0; }
  .top .doc table { width:100%; font-size:10px; } .top .doc td.v { text-align:right; }
  /* Cliente */
  .cli { border:1px solid #000; border-top:0; padding:5px 8px; font-size:11px; }
  .cli .r { display:flex; gap:6px; margin:1px 0; } .cli .r .lbl { width:70px; } .cli .r b { font-weight:bold; }
  .cli .r .lbl2 { margin-left:30px; }
  .cv { border:1px solid #000; border-top:0; padding:3px 8px; font-size:11px; }
  /* Tabla productos */
  table.g { width:100%; border-collapse:collapse; margin-top:8px; }
  table.g th, table.g td { border:1px solid #000; padding:1px 5px; font-size:10.5px; line-height:1.3; }
  table.g th { background:#C0C0C0; font-weight:bold; font-size:9px; text-align:center; }
  table.g td.num { text-align:right; } table.g td.c { text-align:center; }
  .gwrap { flex:1 1 auto; }
  /* Pie */
  .sonpesos { font-size:11px; font-weight:bold; margin-top:8px; }
  .tot { width:100%; border-collapse:collapse; margin-top:6px; }
  .tot th, .tot td { border:1px solid #000; padding:2px 6px; font-size:10.5px; }
  .tot th { background:#C0C0C0; font-size:9px; text-align:center; }
  .tot td.num { text-align:right; font-variant-numeric:tabular-nums; } .tot .big { font-size:15px; font-weight:bold; }
  .cae { border:1px solid #000; border-top:0; padding:4px 8px; display:flex; justify-content:space-between; font-size:11px; }
  .cae .auth { font-weight:bold; } .cae .r b { font-weight:bold; }
  .bar { position:sticky; top:0; background:#1e293b; color:#fff; padding:.5rem 1rem; display:flex; gap:1rem; align-items:center; font-family:system-ui; }
  .bar button { padding:.35rem .9rem; border:0; border-radius:.35rem; background:#2563eb; color:#fff; cursor:pointer; }
  @media print { .bar { display:none; } body { background:#fff; } .hoja { box-shadow:none; margin:0; width:auto; min-height:auto; padding:0; } @page { size:letter portrait; margin:10mm 12mm; } }
</style></head><body>
<div class="bar"><button onclick="window.print()">🖨 Imprimir</button><span>Factura <b><?= pe($letra . ' ' . $comp) ?></b> · <?= pe(trim(nz($h['DENMOV'], ''))) ?> · <?= nm($total) ?></span></div>

<div class="hoja">
  <!-- Membrete -->
  <div class="top">
    <div class="emp">
      <div class="nom"><?= pe($EMP['nom']) ?></div>
      <div class="det"><?= pe($EMP['dir']) ?><br><?= pe($EMP['loc']) ?><br><?= pe($EMP['tel']) ?></div>
      <div class="iva">I.V.A. RESPONSABLE INSCRIPTO</div>
    </div>
    <div class="letra"><div class="L"><?= pe($letra) ?></div><div class="cod">Código <?= pe($codAfip) ?></div></div>
    <div class="doc">
      <div class="cab"><div class="c1">COMPROBANTE</div><div class="c2">FACTURA</div></div>
      <div class="nro">Nº <?= pe($comp) ?></div>
      <table>
        <tr><td>FECHA:</td><td class="v"><?= pe(fecha_serial($h['FEXMOV'])) ?></td></tr>
        <tr><td>C.U.I.T. Nº</td><td class="v"><?= pe($EMP['cuit']) ?></td></tr>
        <tr><td>ING. BRUTOS</td><td class="v"><?= pe($EMP['ib']) ?></td></tr>
        <tr><td>INICIO DE ACTIVIDADES</td><td class="v"><?= pe($EMP['ini']) ?></td></tr>
        <tr><td>MOVIMIENTO Nº</td><td class="v"><?= pe($mov8) ?></td></tr>
      </table>
    </div>
  </div>

  <!-- Cliente -->
  <div class="cli">
    <div class="r"><span class="lbl">Señor:</span><b><?= pe(trim(nz($h['DENMOV'], ''))) ?></b></div>
    <div class="r"><span class="lbl">Domicilio:</span><b><?= pe(trim(nz($h['DCXMOV'], ''))) ?></b><span class="lbl2"><?= pe(trim(nz($h['DNXMOV'], ''))) ?></span>
      <span class="lbl2">Localidad:</span><b><?= pe(trim('(' . nz($loc['CPXLOC'], '') . ') ' . nz($loc['DENLOC'], '') . ($loc && nz($loc['DENPRO'], '') ? ' - ' . nz($loc['DENPRO'], '') : ''))) ?></b></div>
    <div class="r"><span class="lbl">I.V.A.:</span><b><?= pe($cri ? trim(nz($cri['DENCRI'], '')) : '') ?></b><span class="lbl2">C.U.I.T. Nro.:</span><b><?= pe(trim(nz($h['CITMOV'], ''))) ?></b></div>
  </div>
  <div class="cv">Condiciones de Venta:&nbsp; <b><?= pe($cdv ? trim(nz($cdv['DENCDV'], '')) : '') ?></b> &nbsp;·&nbsp; <b><?= pe($fdp ? trim(nz($fdp['DENFDP'], '')) : '') ?></b></div>

  <!-- Productos -->
  <div class="gwrap"><table class="g">
    <thead><tr><th style="width:60px">CANTIDAD</th><th style="width:62px">REMITO</th><th style="width:54px">P.T.P. Nº</th><th style="width:54px">O. CORTE</th><th style="width:58px">O. PROCESO</th><th style="width:70px">CODIGO</th><th>DENOMINACION</th><th style="width:80px">PR. UNITARIO</th><th style="width:95px">TOTAL</th></tr></thead>
    <tbody>
    <?php foreach ($prods as $p): ?>
      <tr><td class="num"><?= nm($p['cant']) ?></td><td class="c"><?= pe($p['rem']) ?></td><td class="c"><?= pe($p['ptp'] ?: '') ?></td><td class="c"><?= pe($p['odc'] ? str_pad((string) $p['odc'], 8, '0', STR_PAD_LEFT) : '') ?></td><td class="c"><?= pe($p['odp'] ? str_pad((string) $p['odp'], 8, '0', STR_PAD_LEFT) : '') ?></td><td><?= pe($p['cod']) ?></td><td><?= pe($p['den']) ?></td><td class="num"><?= nm($p['pun']) ?></td><td class="num"><?= nm($p['tot']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <!-- Pie -->
  <?php $netoTot = 0; foreach ($ivas as $iv) $netoTot += (float) nz($iv['NETMOV'], 0); $netoTot = round($netoTot, 2); $nIva = max(1, count($ivas)); ?>
  <div class="foot">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:8px;">
      <div class="sonpesos"><?= pe(fv_letras($total)) ?></div>
      <table class="tot" style="width:auto"><tr><th style="width:80px">SUBTOTAL</th></tr><tr><td class="num" style="width:120px"><?= nm($netoTot) ?></td></tr></table>
    </div>
    <table class="tot">
      <thead><tr><th colspan="2">SUBTOTAL</th><th colspan="2">I.V.A. INSC.</th><th colspan="2">PERC.II.BB. BS.AS</th><th style="width:120px">TOTAL</th></tr></thead>
      <tbody>
      <?php $r = 0; foreach ($ivas as $iv): $a = round((float) nz($iv['ALIMOV'], 0), 2); $r++; ?>
        <tr>
          <td style="width:50px"><?= nm($a) ?> %</td><td class="num" style="width:115px"><?= nm($iv['NETMOV']) ?></td>
          <td style="width:50px"><?= nm($a) ?> %</td><td class="num" style="width:115px"><?= nm($iv['IRIMOV']) ?></td>
          <td style="width:50px"><?= $r == 1 ? '0.00 %' : '' ?></td><td class="num" style="width:90px"><?= $r == 1 ? nm($pix) : '' ?></td>
          <?php if ($r == 1): ?><td rowspan="<?= $nIva ?>" class="num big"><?= nm($total) ?></td><?php endif; ?>
        </tr>
      <?php endforeach; if (!count($ivas)): ?>
        <tr><td>0.00 %</td><td class="num"><?= nm($netoTot) ?></td><td>0.00 %</td><td class="num">0.00</td><td>0.00 %</td><td class="num"><?= nm($pix) ?></td><td class="num big"><?= nm($total) ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <div class="cae">
      <span class="auth">Comprobante Autorizado</span>
      <span class="r">C.A.E.: <b><?= pe(trim(nz($h['CAEMOV'], ''))) ?></b> &nbsp; FECHA DE VTO: <b><?= pe($h['FVCMOV'] ? fecha_serial($h['FVCMOV']) : '') ?></b></span>
    </div>
  </div>
</div>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 250); });</script><?php endif; ?>
</body></html>
