<?php
/**
 * Impresión de NOTA DE CRÉDITO — réplica del reporte legacy `Rpt CD Creditos NF FE` (mismo diseño que la
 * factura, posiciones en mm desde los twips del diseñador: 1 cm = 567 twips). Difiere de la FV en: título
 * "NOTA DE CREDITO", letra Código 03/08, grilla de 4 columnas (CANTIDAD | DETALLE | PR.UNITARIO | TOTAL) con
 * el CONCEPTO/DETALLE (bonificación) o los productos (devolución) + la(s) FV referenciada(s).
 * Uso: ?nummov=N  (&print=1 para auto-imprimir).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
auth_require_login();

$num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
$h = db_row("SELECT * FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=460;");
if (!$h) { http_response_code(404); echo 'Nota de crédito no encontrada'; exit; }
$estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1); $lib = auth_libro_unico();
if (($lib === 'blanco' && !$estTrue) || ($lib === 'negro' && $estTrue)) { http_response_code(403); echo 'NC no disponible en este libro'; exit; }

$loc = db_row("SELECT L.CPXLOC, L.DENLOC, P.DENPRO FROM [Tbl Localidades] AS L LEFT JOIN [Tbl Provincias] AS P ON L.CODPRO=P.CODPRO WHERE L.CODLOC=" . (int) nz($h['CODLOC'], 0) . ";");
$cri = db_row("SELECT DENCRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($h['CODCRI'], 0) . ";");
$cdv = db_row("SELECT DENCDV FROM [Tbl Condiciones de Venta] WHERE CODCDV=" . (int) nz($h['CODCDV'], 0) . ";");
$fdp = db_row("SELECT DENFDP FROM [Tbl Formas de Pago] WHERE CODFDP=" . (int) nz($h['CODFDP'], 0) . ";");

// Concepto (Tbl Operaciones Auxiliares, CODOPE=460) + detalle (DETMOV del header)
$aux = db_row("SELECT DENAUX FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=460 AND CODAUX=" . (int) nz($h['CODAUX'], 0) . ";");
$concepto = $aux ? trim((string) nz($aux['DENAUX'], '')) : '';
$detmov = trim((string) nz($h['DETMOV'], ''));

// Productos (DEVOLUCION): Tbl Movimientos Stock — cantidad = reingreso a stock (INGMOV) o servicio (SVCMOV)
$prods = array();
foreach (db_query("SELECT ORDMOV, CODPRO, DENMOV, INGMOV, SVCMOV, EGRMOV, PUNMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num ORDER BY ORDMOV;") as $s) {
    $cant = (float) nz($s['INGMOV'], 0); if ($cant == 0) $cant = abs((float) nz($s['SVCMOV'], 0)); if ($cant == 0) $cant = (float) nz($s['EGRMOV'], 0);
    $pun = round((float) nz($s['PUNMOV'], 0), 2);
    $prods[] = array('cant' => round($cant, 2), 'den' => trim((string) nz($s['DENMOV'], '')), 'pun' => $pun, 'tot' => round($cant * $pun, 2));
}

// FV(s) referenciada(s) (Tbl Movimientos Referencias → Tbl Movimientos)
$refs = array();
foreach (db_query("SELECT REFMOV, IMPMOV FROM [Tbl Movimientos Referencias] WHERE NUMMOV=$num;") as $r) {
    $fv = db_row("SELECT CICMOV, CIIMOV, CIPMOV, CINMOV FROM [Tbl Movimientos] WHERE NUMMOV=" . (int) nz($r['REFMOV'], 0) . ";");
    if ($fv) $refs[] = array('comp' => trim((string) nz($fv['CICMOV'], '')) . ' ' . trim((string) nz($fv['CIIMOV'], '')) . ' '
        . str_pad((string) (int) nz($fv['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($fv['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        'imp' => round((float) nz($r['IMPMOV'], 0), 2));
}

// IVA por alícuota
$ivas = db_query("SELECT ALIMOV, NETMOV, IRIMOV FROM [Tbl Movimientos IVA] WHERE NUMMOV=$num ORDER BY ALIMOV;");
$netoTot = 0; foreach ($ivas as $iv) $netoTot += (float) nz($iv['NETMOV'], 0); $netoTot = round($netoTot, 2);

$letra = strtoupper(trim((string) nz($h['CIIMOV'], 'A')));
$codAfip = ($letra === 'A') ? '03' : (($letra === 'B') ? '08' : '03');   // NC: A=03, B=08
$comp  = str_pad((string) (int) nz($h['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
$total = round((float) nz($h['TOTMOV'], 0), 2);
$pix   = round((float) nz($h['PIXMOV'], 0), 2);
$mov8  = str_pad((string) $num, 8, '0', STR_PAD_LEFT);

$EMP = array('nom' => 'PROCESADORA TEXTIL PARQUE S.A.', 'dir' => 'RUTA 32 KM. 1.5 - PARQUE INDUSTRIAL',
    'loc' => '(2700) - Pergamino Bs. As.', 'tel' => '(02477) 42-0710', 'cuit' => '30-70838113-2', 'ib' => '30-70838113-2', 'ini' => '06/2003');

function pe($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function nm($v) { return number_format((float) $v, 2, '.', ','); }
function tw($t) { return number_format($t / 56.69, 2, '.', '') . 'mm'; }   // twips → mm (1 cm = 567 twips)
function nc_letras($n) {
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
// QR de AFIP (RG 4892): NC clase A = tipoCmp 3 (B = 8). El reporte legacy lo dejaba como placeholder y fallaba.
$qrUrl = '';
if (trim((string) nz($h['CAEMOV'], '')) !== '') {
    $qrData = array('ver' => 1,
        'fecha' => (new DateTime('1899-12-30'))->modify('+' . (int) nz($h['FEXMOV'], 0) . ' days')->format('Y-m-d'),
        'cuit' => (int) preg_replace('/\D/', '', $EMP['cuit']), 'ptoVta' => (int) nz($h['CIPMOV'], 0),
        'tipoCmp' => ($letra === 'A') ? 3 : (($letra === 'B') ? 8 : 13), 'nroCmp' => (int) nz($h['CINMOV'], 0),
        'importe' => round((float) $total, 2), 'moneda' => 'PES', 'ctz' => 1,
        'tipoDocRec' => (int) nz($h['CODDOC'], 80), 'nroDocRec' => (int) preg_replace('/\D/', '', (string) nz($h['CITMOV'], '0')),
        'tipoCodAut' => 'E', 'codAut' => (int) preg_replace('/\D/', '', (string) nz($h['CAEMOV'], '0')));
    $qrUrl = 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode(json_encode($qrData));
}
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Nota de Crédito <?= pe($letra . ' ' . $comp) ?></title>
<style>
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed.woff') ?>') format('woff'); font-weight:normal; font-style:normal; font-display:swap; }
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff') ?>') format('woff'); font-weight:bold; font-style:normal; font-display:swap; }
  * { box-sizing:border-box; }
  body { background:#e9edf2; color:#000; margin:0; font-family:'Univers Condensed',"Arial Narrow",Arial,sans-serif; font-size:11px; }
  .hoja { background:#fff; width:216mm; min-height:279mm; margin:10px auto; padding:10mm 22.4mm; box-shadow:0 2px 12px rgba(0,0,0,.2); display:flex; flex-direction:column; }
  .foot { margin-top:0; }
  .num { text-align:right; font-variant-numeric:tabular-nums; }
  /* Membrete — réplica del reporte legacy (mismo que FV): posiciones absolutas en mm desde los twips */
  .top { position:relative; width:100%; height:81.53mm; }
  .top .box { position:absolute; border:1px solid #333; }
  .top .ab { position:absolute; line-height:1.05; white-space:nowrap; overflow:hidden; }
  .top .lbl { font-size:8pt; font-family:Verdana,"DejaVu Sans",Arial,sans-serif; }
  .top .v9  { font-size:9pt; } .top .v9b { font-size:9pt; font-weight:bold; } .top .v8 { font-size:8pt; }
  .top .nom  { font-size:20pt; font-weight:bold; text-align:center; line-height:1; }
  .top .ver  { font-size:8pt; text-align:center; font-family:Verdana,"DejaVu Sans",Arial,sans-serif; }
  .top .comp { font-size:7pt; font-weight:bold; text-align:center; font-family:Verdana,"DejaVu Sans",Arial,sans-serif; }
  .top .fact { font-size:12pt; font-weight:bold; text-align:center; }
  .top .nrolbl { font-size:14pt; font-weight:bold; text-align:right; }
  .top .nroval { font-size:14pt; font-weight:bold; text-align:center; }
  .top .fbold { font-size:10pt; font-weight:bold; }
  .top .f8 { font-size:8pt; }
  .top .r { text-align:right; }
  .top .letrabox { position:absolute; border:2px solid #000; box-shadow:1.8px 1.8px 0 #000; background:#fff; }
  .top .Lt  { font-size:22pt; font-weight:bold; text-align:center; line-height:1; }
  .top .cod { font-size:8pt; text-align:center; }
  /* Productos / concepto — 4 columnas (CANTIDAD | DETALLE | PR.UNITARIO | TOTAL): solo el header lleva borde */
  .gwrap { min-height:11.887cm; border-left:1px solid #333; border-right:1px solid #333; border-bottom:1px solid #333; }
  table.g { width:100%; border-collapse:collapse; table-layout:fixed; }
  table.g th, table.g td { padding:0.4mm 1mm; font-size:8pt; line-height:1.3; overflow:hidden; white-space:nowrap; }
  table.g thead th { border-bottom:1px solid #333; height:0.501cm; vertical-align:middle; }
  table.g thead th.b { border-right:1px solid #333; }
  table.g th { font-size:6pt; font-weight:normal; text-align:center; font-family:Verdana,"DejaVu Sans",Arial,sans-serif; }
  table.g tbody td { border:0; }
  table.g td.num { text-align:right; } table.g td.c { text-align:center; } table.g td.det b { font-weight:bold; }
  /* Footer (ReportFooter4) — réplica absoluta en mm desde los twips (idéntico a la FV) */
  .ft { position:relative; width:100%; height:6.358cm; }
  .ft .box { position:absolute; border:1px solid #333; }
  .ft .ab { position:absolute; line-height:1.1; white-space:nowrap; overflow:hidden; }
  .ft .u8 { font-size:8pt; } .ft .ve8 { font-size:8pt; font-family:Verdana,"DejaVu Sans",Arial,sans-serif; }
  .ft .ve7 { font-size:7pt; font-family:Verdana,"DejaVu Sans",Arial,sans-serif; }
  .ft .big { font-size:14pt; font-weight:bold; } .ft .b7 { font-weight:bold; }
  .ft .r { text-align:right; } .ft .c { text-align:center; }
  .ft .qr { position:absolute; width:27mm; height:27mm; }
  .ft .qr img, .ft .qr canvas { width:100% !important; height:100% !important; display:block; }
  .bar { position:sticky; top:0; background:#1e293b; color:#fff; padding:.5rem 1rem; display:flex; gap:1rem; align-items:center; font-family:system-ui; }
  .bar button { padding:.35rem .9rem; border:0; border-radius:.35rem; background:#2563eb; color:#fff; cursor:pointer; }
  @media print { .bar { display:none; } body { background:#fff; } .hoja { box-shadow:none; margin:0; width:auto; min-height:auto; padding:0; } @page { size:letter portrait; margin:10mm 22.4mm; } }
</style></head><body>
<div class="bar"><button onclick="window.print()">🖨 Imprimir</button><span>Nota de Crédito <b><?= pe($letra . ' ' . $comp) ?></b> · <?= pe(trim(nz($h['DENMOV'], ''))) ?> · <?= nm($total) ?></span></div>

<div class="hoja">
  <!-- Membrete (idéntico a la FV; cambia FACTURA→NOTA DE CREDITO y Código 03) -->
  <div class="top">
    <div class="box" style="left:0;top:0;width:<?= tw(6011) ?>;height:<?= tw(2498) ?>;"></div>
    <div class="ab nom" style="left:0;top:<?= tw(30) ?>;width:<?= tw(5990) ?>;"><?= pe($EMP['nom']) ?></div>
    <div class="ab ver" style="left:<?= tw(30) ?>;top:<?= tw(1320) ?>;width:<?= tw(5959) ?>;"><?= pe($EMP['dir']) ?></div>
    <div class="ab ver" style="left:<?= tw(30) ?>;top:<?= tw(1545) ?>;width:<?= tw(5959) ?>;"><?= pe($EMP['loc']) ?></div>
    <div class="ab ver" style="left:<?= tw(30) ?>;top:<?= tw(1770) ?>;width:<?= tw(5959) ?>;"><?= pe($EMP['tel']) ?></div>
    <div class="ab ver" style="left:<?= tw(30) ?>;top:<?= tw(2220) ?>;width:<?= tw(5959) ?>;">I.V.A. RESPONSABLE INSCRIPTO</div>
    <div class="letrabox" style="left:<?= tw(5190) ?>;top:<?= tw(885) ?>;width:<?= tw(680) ?>;height:<?= tw(905) ?>;"></div>
    <div class="ab Lt" style="left:<?= tw(5242) ?>;top:<?= tw(956) ?>;width:<?= tw(581) ?>;"><?= pe($letra) ?></div>
    <div class="ab cod" style="left:<?= tw(5235) ?>;top:<?= tw(1560) ?>;width:<?= tw(575) ?>;">Código <?= pe($codAfip) ?></div>
    <div class="box" style="left:<?= tw(6015) ?>;top:0;width:<?= tw(3686) ?>;height:<?= tw(227) ?>;"></div>
    <div class="ab comp" style="left:<?= tw(6015) ?>;top:<?= tw(15) ?>;width:<?= tw(3686) ?>;">COMPROBANTE</div>
    <div class="box" style="left:<?= tw(6015) ?>;top:<?= tw(225) ?>;width:<?= tw(3686) ?>;height:<?= tw(377) ?>;"></div>
    <div class="ab fact" style="left:<?= tw(6015) ?>;top:<?= tw(255) ?>;width:<?= tw(3686) ?>;">NOTA DE CREDITO</div>
    <div class="box" style="left:<?= tw(6015) ?>;top:<?= tw(600) ?>;width:<?= tw(3686) ?>;height:<?= tw(510) ?>;"></div>
    <div class="ab nrolbl" style="left:<?= tw(6080) ?>;top:<?= tw(681) ?>;width:<?= tw(382) ?>;">Nº</div>
    <div class="ab nroval" style="left:<?= tw(6476) ?>;top:<?= tw(676) ?>;width:<?= tw(3130) ?>;"><?= pe($comp) ?></div>
    <div class="box" style="left:<?= tw(6015) ?>;top:<?= tw(1110) ?>;width:<?= tw(3686) ?>;height:<?= tw(1388) ?>;"></div>
    <div class="ab fbold" style="left:<?= tw(6080) ?>;top:<?= tw(1189) ?>;width:<?= tw(1167) ?>;">FECHA:</div>
    <div class="ab fbold r" style="left:<?= tw(7250) ?>;top:<?= tw(1189) ?>;width:<?= tw(2397) ?>;"><?= pe(fecha_serial($h['FEXMOV'])) ?></div>
    <div class="ab f8" style="left:<?= tw(6086) ?>;top:<?= tw(1474) ?>;width:<?= tw(2158) ?>;">C.U.I.T. Nº</div>
    <div class="ab f8 r" style="left:<?= tw(8250) ?>;top:<?= tw(1474) ?>;width:<?= tw(1400) ?>;"><?= pe($EMP['cuit']) ?></div>
    <div class="ab f8" style="left:<?= tw(6080) ?>;top:<?= tw(1654) ?>;width:<?= tw(2173) ?>;">ING. BRUTOS</div>
    <div class="ab f8 r" style="left:<?= tw(8253) ?>;top:<?= tw(1654) ?>;width:<?= tw(1385) ?>;"><?= pe($EMP['ib']) ?></div>
    <div class="ab f8" style="left:<?= tw(6080) ?>;top:<?= tw(1834) ?>;width:<?= tw(2173) ?>;">INICIO DE ACTIVIDADES</div>
    <div class="ab f8 r" style="left:<?= tw(8253) ?>;top:<?= tw(1834) ?>;width:<?= tw(1385) ?>;"><?= pe($EMP['ini']) ?></div>
    <div class="ab f8" style="left:<?= tw(6080) ?>;top:<?= tw(2014) ?>;width:<?= tw(2173) ?>;">MOVIMIENTO Nº</div>
    <div class="ab f8 r" style="left:<?= tw(8255) ?>;top:<?= tw(2015) ?>;width:<?= tw(1385) ?>;"><?= pe($mov8) ?></div>
    <!-- Cliente -->
    <div class="box" style="left:0;top:<?= tw(2552) ?>;width:<?= tw(9696) ?>;height:<?= tw(1503) ?>;"></div>
    <div class="ab lbl" style="left:<?= tw(75) ?>;top:<?= tw(2835) ?>;width:<?= tw(1069) ?>;">Señor:</div>
    <div class="ab v9b" style="left:<?= tw(1134) ?>;top:<?= tw(2835) ?>;width:<?= tw(5839) ?>;"><?= pe(trim(nz($h['DENMOV'], ''))) ?></div>
    <div class="ab lbl" style="left:<?= tw(75) ?>;top:<?= tw(3315) ?>;width:<?= tw(1069) ?>;">Domicilio:</div>
    <div class="ab v9"  style="left:<?= tw(1140) ?>;top:<?= tw(3315) ?>;width:<?= tw(2920) ?>;"><?= pe(trim(nz($h['DCXMOV'], ''))) ?></div>
    <div class="ab v9"  style="left:<?= tw(4116) ?>;top:<?= tw(3315) ?>;width:<?= tw(535) ?>;"><?= pe(trim(nz($h['DNXMOV'], ''))) ?></div>
    <div class="ab lbl" style="left:<?= tw(5550) ?>;top:<?= tw(3315) ?>;width:<?= tw(979) ?>;">Localidad:</div>
    <div class="ab v9"  style="left:<?= tw(6527) ?>;top:<?= tw(3315) ?>;width:<?= tw(2935) ?>;"><?= pe(trim('(' . nz($loc['CPXLOC'], '') . ') - ' . nz($loc['DENLOC'], '') . ($loc && nz($loc['DENPRO'], '') ? ' - ' . nz($loc['DENPRO'], '') : ''))) ?></div>
    <div class="ab lbl" style="left:<?= tw(75) ?>;top:<?= tw(3765) ?>;width:<?= tw(1069) ?>;">I.V.A.:</div>
    <div class="ab v9"  style="left:<?= tw(1140) ?>;top:<?= tw(3765) ?>;width:<?= tw(2920) ?>;"><?= pe($cri ? trim(nz($cri['DENCRI'], '')) : '') ?></div>
    <div class="ab lbl" style="left:<?= tw(5550) ?>;top:<?= tw(3765) ?>;width:<?= tw(1264) ?>;">C.U.I.T. Nro.:</div>
    <div class="ab v8"  style="left:<?= tw(6810) ?>;top:<?= tw(3765) ?>;width:<?= tw(1644) ?>;"><?= pe(trim(nz($h['CITMOV'], ''))) ?></div>
    <!-- Condiciones -->
    <div class="box" style="left:0;top:<?= tw(4139) ?>;width:<?= tw(9696) ?>;height:<?= tw(483) ?>;"></div>
    <div class="ab lbl" style="left:<?= tw(75) ?>;top:<?= tw(4275) ?>;width:<?= tw(2014) ?>;">Condiciones de Venta:</div>
    <div class="ab v9"  style="left:<?= tw(2085) ?>;top:<?= tw(4275) ?>;width:<?= tw(1630) ?>;"><?= pe($cdv ? trim(nz($cdv['DENCDV'], '')) : '') ?></div>
    <div class="ab v9"  style="left:<?= tw(3775) ?>;top:<?= tw(4275) ?>;width:<?= tw(180) ?>;text-align:center;">-</div>
    <div class="ab v9"  style="left:<?= tw(4015) ?>;top:<?= tw(4275) ?>;width:<?= tw(1435) ?>;"><?= pe($fdp ? trim(nz($fdp['DENFDP'], '')) : '') ?></div>
  </div>

  <!-- Detalle: 4 columnas (CANTIDAD | DETALLE | PR.UNITARIO | TOTAL) -->
  <div class="gwrap"><table class="g">
    <thead><tr><th class="b" style="width:<?= tw(1140) ?>">CANTIDAD</th><th class="b">DETALLE</th><th class="b" style="width:<?= tw(1080) ?>">PR. UNITARIO</th><th style="width:<?= tw(1644) ?>">TOTAL</th></tr></thead>
    <tbody>
    <?php if (count($prods)): // DEVOLUCION ?>
      <?php foreach ($prods as $p): ?>
        <tr><td class="num"><?= nm($p['cant']) ?></td><td><?= pe($p['den']) ?></td><td class="num"><?= nm($p['pun']) ?></td><td class="num"><?= nm($p['tot']) ?></td></tr>
      <?php endforeach; ?>
    <?php else: // BONIFICACION (concepto) ?>
      <tr><td></td><td class="det"><b>CONCEPTO:</b> <?= pe($concepto) ?></td><td></td><td class="num"><?= nm($netoTot) ?></td></tr>
      <?php if ($detmov !== ''): ?><tr><td></td><td class="det"><b>DETALLE:</b> <?= pe($detmov) ?></td><td></td><td></td></tr><?php endif; ?>
    <?php endif; ?>
    <?php foreach ($refs as $rf): ?>
      <tr><td></td><td class="det">s/ <?= pe($rf['comp']) ?></td><td></td><td></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <!-- Pie (ReportFooter4) — réplica absoluta (idéntico a la FV) -->
  <?php
  $abi  = isset($h['ABIMOV']) ? (float) nz($h['ABIMOV'], 0) : 0;
  $idg  = isset($h['IDGMOV']) ? (float) nz($h['IDGMOV'], 0) : 0;
  $nog  = isset($h['NOGMOV']) ? (float) nz($h['NOGMOV'], 0) : 0;
  $ard  = isset($h['ARDMOV']) ? (float) nz($h['ARDMOV'], 0) : 0;
  $apim = isset($h['APIMOV']) ? (float) nz($h['APIMOV'], 0) : 0;
  $ivv = array_values($ivas); $iv1 = isset($ivv[0]) ? $ivv[0] : null; $iv2 = isset($ivv[1]) ? $ivv[1] : null;
  ?>
  <div class="foot"><div class="ft">
    <div class="box" style="left:0;top:0;width:<?= tw(8051) ?>;height:<?= tw(794) ?>"></div>
    <div class="box" style="left:<?= tw(8055) ?>;top:0;width:<?= tw(1644) ?>;height:<?= tw(794) ?>"></div>
    <div class="ab u8" style="left:<?= tw(150) ?>;top:<?= tw(285) ?>;width:<?= tw(6818) ?>"><?= pe(nc_letras($total)) ?></div>
    <div class="ab ve8 c" style="left:<?= tw(7005) ?>;top:<?= tw(285) ?>;width:<?= tw(1024) ?>">SUBTOTAL</div>
    <?php if ($abi != 0): ?><div class="ab u8" style="left:<?= tw(8190) ?>;top:<?= tw(30) ?>;width:<?= tw(717) ?>">AJ. B.I.</div><div class="ab u8 r" style="left:<?= tw(8940) ?>;top:<?= tw(30) ?>;width:<?= tw(683) ?>"><?= nm($abi) ?></div><?php endif; ?>
    <?php if ($idg != 0): ?><div class="ab u8" style="left:<?= tw(8190) ?>;top:<?= tw(273) ?>;width:<?= tw(717) ?>">DTO. GRAL.</div><div class="ab u8 r" style="left:<?= tw(8942) ?>;top:<?= tw(270) ?>;width:<?= tw(684) ?>"><?= nm($idg) ?></div><?php endif; ?>
    <div class="ab u8 r" style="left:<?= tw(8190) ?>;top:<?= tw(510) ?>;width:<?= tw(1434) ?>"><?= nm($netoTot) ?></div>
    <div class="box" style="left:0;top:<?= tw(795) ?>;width:<?= tw(1957) ?>;height:<?= tw(962) ?>"></div>
    <div class="box" style="left:0;top:<?= tw(795) ?>;width:<?= tw(1957) ?>;height:<?= tw(286) ?>"></div>
    <div class="box" style="left:<?= tw(1950) ?>;top:<?= tw(795) ?>;width:<?= tw(1928) ?>;height:<?= tw(962) ?>"></div>
    <div class="box" style="left:<?= tw(1950) ?>;top:<?= tw(795) ?>;width:<?= tw(1928) ?>;height:<?= tw(286) ?>"></div>
    <div class="box" style="left:<?= tw(3885) ?>;top:<?= tw(795) ?>;width:<?= tw(1898) ?>;height:<?= tw(964) ?>"></div>
    <div class="box" style="left:<?= tw(3885) ?>;top:<?= tw(795) ?>;width:<?= tw(1898) ?>;height:<?= tw(284) ?>"></div>
    <div class="box" style="left:<?= tw(5790) ?>;top:<?= tw(795) ?>;width:<?= tw(1928) ?>;height:<?= tw(964) ?>"></div>
    <div class="box" style="left:<?= tw(5790) ?>;top:<?= tw(795) ?>;width:<?= tw(1928) ?>;height:<?= tw(284) ?>"></div>
    <div class="box" style="left:<?= tw(7725) ?>;top:<?= tw(795) ?>;width:<?= tw(1985) ?>;height:<?= tw(962) ?>"></div>
    <div class="box" style="left:<?= tw(7725) ?>;top:<?= tw(795) ?>;width:<?= tw(1985) ?>;height:<?= tw(286) ?>"></div>
    <div class="ab ve8 c" style="left:<?= tw(28) ?>;top:<?= tw(822) ?>;width:<?= tw(1894) ?>">SUBTOTAL</div>
    <div class="ab ve8 c" style="left:<?= tw(3915) ?>;top:<?= tw(825) ?>;width:<?= tw(1849) ?>">I.V.A. INSC.</div>
    <div class="ab ve8 c" style="left:<?= tw(5835) ?>;top:<?= tw(825) ?>;width:<?= tw(1849) ?>">PERC.II.BB. BS.AS</div>
    <div class="ab ve8 c" style="left:<?= tw(7766) ?>;top:<?= tw(822) ?>;width:<?= tw(1909) ?>">TOTAL</div>
    <?php if ($iv1): $a1 = round((float) nz($iv1['ALIMOV'], 0), 2); ?>
    <div class="ab u8 r" style="left:<?= tw(60) ?>;top:<?= tw(1200) ?>;width:<?= tw(570) ?>"><?= nm($a1) ?></div>
    <div class="ab u8" style="left:<?= tw(630) ?>;top:<?= tw(1200) ?>;width:<?= tw(227) ?>">%</div>
    <div class="ab u8 r" style="left:<?= tw(856) ?>;top:<?= tw(1200) ?>;width:<?= tw(1028) ?>"><?= nm($iv1['NETMOV']) ?></div>
    <div class="ab u8 r" style="left:<?= tw(3915) ?>;top:<?= tw(1204) ?>;width:<?= tw(570) ?>"><?= nm($a1) ?></div>
    <div class="ab u8" style="left:<?= tw(4492) ?>;top:<?= tw(1200) ?>;width:<?= tw(227) ?>">%</div>
    <div class="ab u8 r" style="left:<?= tw(4718) ?>;top:<?= tw(1200) ?>;width:<?= tw(1028) ?>"><?= nm($iv1['IRIMOV']) ?></div>
    <?php endif; ?>
    <div class="ab u8" style="left:<?= tw(1999) ?>;top:<?= tw(1203) ?>;width:<?= tw(989) ?>">NO GRAVADO</div>
    <div class="ab u8 r" style="left:<?= tw(2985) ?>;top:<?= tw(1200) ?>;width:<?= tw(863) ?>"><?= nm($nog) ?></div>
    <div class="ab u8" style="left:<?= tw(1995) ?>;top:<?= tw(1423) ?>;width:<?= tw(994) ?>">AJ. REDONDEO</div>
    <div class="ab u8 r" style="left:<?= tw(2985) ?>;top:<?= tw(1425) ?>;width:<?= tw(863) ?>"><?= nm($ard) ?></div>
    <div class="ab u8 r" style="left:<?= tw(5835) ?>;top:<?= tw(1204) ?>;width:<?= tw(570) ?>"><?= nm($apim) ?></div>
    <div class="ab u8" style="left:<?= tw(6412) ?>;top:<?= tw(1200) ?>;width:<?= tw(227) ?>">%</div>
    <div class="ab u8 r" style="left:<?= tw(6638) ?>;top:<?= tw(1200) ?>;width:<?= tw(1028) ?>"><?= nm($pix) ?></div>
    <div class="ab big r" style="left:<?= tw(7785) ?>;top:<?= tw(1215) ?>;width:<?= tw(1859) ?>"><?= nm($total) ?></div>
    <?php if ($iv2): $a2 = round((float) nz($iv2['ALIMOV'], 0), 2); ?>
    <div class="ab u8 r" style="left:<?= tw(60) ?>;top:<?= tw(1427) ?>;width:<?= tw(570) ?>"><?= nm($a2) ?></div>
    <div class="ab u8" style="left:<?= tw(630) ?>;top:<?= tw(1427) ?>;width:<?= tw(227) ?>">%</div>
    <div class="ab u8 r" style="left:<?= tw(856) ?>;top:<?= tw(1427) ?>;width:<?= tw(1028) ?>"><?= nm($iv2['NETMOV']) ?></div>
    <div class="ab u8 r" style="left:<?= tw(3922) ?>;top:<?= tw(1425) ?>;width:<?= tw(570) ?>"><?= nm($a2) ?></div>
    <div class="ab u8" style="left:<?= tw(4492) ?>;top:<?= tw(1425) ?>;width:<?= tw(227) ?>">%</div>
    <div class="ab u8 r" style="left:<?= tw(4718) ?>;top:<?= tw(1425) ?>;width:<?= tw(1028) ?>"><?= nm($iv2['IRIMOV']) ?></div>
    <?php endif; ?>
    <div class="box" style="left:0;top:<?= tw(1755) ?>;width:<?= tw(9711) ?>;height:<?= tw(1850) ?>"></div>
    <?php if ($qrUrl): ?><div id="qr" class="qr" style="left:<?= tw(60) ?>;top:<?= tw(1830) ?>"></div><?php endif; ?>
    <div class="ab ve7" style="left:<?= tw(1815) ?>;top:<?= tw(1845) ?>;width:<?= tw(4429) ?>">Comprobante Autorizado</div>
    <div class="ab ve7" style="left:<?= tw(6465) ?>;top:<?= tw(1845) ?>;width:<?= tw(1354) ?>">C.A.E.:</div>
    <div class="ab u8 b7" style="left:<?= tw(7815) ?>;top:<?= tw(1845) ?>;width:<?= tw(1855) ?>"><?= pe(trim(nz($h['CAEMOV'], ''))) ?></div>
    <div class="ab ve7" style="left:<?= tw(6465) ?>;top:<?= tw(2040) ?>;width:<?= tw(1354) ?>">FECHA DE VTO:</div>
    <div class="ab u8 b7" style="left:<?= tw(7815) ?>;top:<?= tw(2040) ?>;width:<?= tw(1855) ?>"><?= pe($h['FVCMOV'] ? fecha_serial($h['FVCMOV']) : '') ?></div>
  </div></div>
</div>
<?php if ($qrUrl): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  try { new QRCode(document.getElementById('qr'), { text: <?= json_encode($qrUrl) ?>, width: 256, height: 256, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {}
</script>
<?php endif; ?>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 600); });</script><?php endif; ?>
</body></html>
