<?php
/**
 * Constancia / Comprobante de Retención de Ingresos Brutos — reproduce FIEL el legacy
 * "Rpt CA Constancia Retencion Ingresos Brutos". Nº = RINMOV. Hoja Carta, Univers Condensed.
 * Secciones: A) Agente de Retención (empresa) · B) Sujeto Retenido (proveedor) · C) Retención
 * Practicada (impuesto/régimen LEYRRI/comprobante origen OP/montos TOTMOV y RIXMOV) + firma.
 * Uso: ?nummov=N  (&print=1 para auto-imprimir).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
auth_require_login();

$num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
$h = db_row("SELECT * FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=340;");
if (!$h) { http_response_code(404); echo 'Orden de pago no encontrada'; exit; }
$estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
$lib = auth_libro_unico();
if (($lib === 'blanco' && !$estTrue) || ($lib === 'negro' && $estTrue)) { http_response_code(403); echo 'No disponible en este libro'; exit; }
$rix = round((float) nz($h['RIXMOV'], 0), 2);
if ($rix <= 0 || (int) nz($h['RINMOV'], 0) <= 0) { http_response_code(404); echo 'La orden de pago no practicó retención de Ingresos Brutos'; exit; }

// Sujeto retenido (snapshot del movimiento) + localidad/provincia
$loc = db_row("SELECT L.CPXLOC, L.DENLOC, P.DENPRO FROM [Tbl Localidades] AS L LEFT JOIN [Tbl Provincias] AS P ON L.CODPRO=P.CODPRO WHERE L.CODLOC=" . (int) nz($h['CODLOC'], 0) . ";");
$reg = db_row("SELECT LEYRRI, DENRRI FROM [Tbl Regimenes Retencion Ingresos Brutos] WHERE CODRRI=" . (int) nz($h['CODRRI'], 0) . ";");

$nroCons = str_pad((string) (int) nz($h['RINMOV'], 0), 8, '0', STR_PAD_LEFT);
$compOri = 'Orden de Pago Nº ' . str_pad((string) (int) nz($h['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . ' - ' . str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
$total   = round((float) nz($h['TOTMOV'], 0), 2);
$sujDom  = trim(trim((string) nz($h['DCXMOV'], '')) . ' ' . trim((string) nz($h['DNXMOV'], '')));
$sujLoc  = $loc ? trim(($loc['CPXLOC'] !== null && $loc['CPXLOC'] !== '' ? '(' . nz($loc['CPXLOC'], '') . ') ' : '') . nz($loc['DENLOC'], '')) : '';
$sujProv = $loc ? trim((string) nz($loc['DENPRO'], '')) : '';

// Empresa (agente de retención)
$EMP = array('nom' => 'PROCESADORA TEXTIL PARQUE S.A.', 'dir' => 'RUTA 32 KM. 1.5 - PARQUE INDUSTRIAL',
    'loc' => '(2700) - PERGAMINO', 'prov' => 'BUENOS AIRES', 'cuit' => '30-70838113-2');

function pe($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function nm($v) { return number_format((float) $v, 2, '.', ','); }
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Constancia Retención IIBB Nº <?= pe($nroCons) ?></title>
<style>
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed.woff') ?>') format('woff'); font-weight:normal; font-style:normal; font-display:swap; }
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff') ?>') format('woff'); font-weight:bold; font-style:normal; font-display:swap; }
  * { box-sizing:border-box; }
  body { background:#e9edf2; color:#000; margin:0; font-family:'Univers Condensed',"Arial Narrow","Liberation Sans Narrow",Arial,sans-serif; font-size:12px; }
  .hoja { background:#fff; width:216mm; min-height:279mm; margin:10px auto; padding:14mm 16mm; box-shadow:0 2px 12px rgba(0,0,0,.2); display:flex; flex-direction:column; }
  .foot { margin-top:auto; }
  /* Membrete */
  .top { display:flex; justify-content:space-between; align-items:flex-start; }
  .emp .nom { font-size:24px; font-weight:bold; line-height:1; }
  .doc { text-align:left; font-size:13px; font-weight:bold; line-height:1.3; }
  .doc .ln { display:flex; gap:10px; } .doc .ln .lbl { min-width:118px; }
  /* Secciones */
  .sec { font-size:12px; margin-top:26px; margin-bottom:3px; }
  table.dl { border-collapse:collapse; width:100%; }
  table.dl td { font-size:12px; font-weight:bold; padding:1px 0; vertical-align:top; line-height:1.45; }
  table.dl td.lbl { width:46%; white-space:nowrap; }
  table.dl td.sep { width:14px; }
  table.dl td.val { width:54%; }
  .money { display:inline-block; min-width:14px; }
  /* Pie */
  .firma { border:1px solid #000; margin-top:10px; height:42mm; display:flex; align-items:flex-end; justify-content:space-around; padding:0 0 7px; }
  .firma .fb { width:42%; text-align:center; }
  .firma .fl { border-top:1px solid #000; margin:0 8%; } .firma .ft { font-weight:bold; font-size:10px; padding-top:3px; }
  .bar { position:sticky; top:0; background:#1e293b; color:#fff; padding:.5rem 1rem; display:flex; gap:1rem; align-items:center; font-family:system-ui; }
  .bar button { padding:.35rem .9rem; border:0; border-radius:.35rem; background:#2563eb; color:#fff; cursor:pointer; }
  @media print { .bar { display:none; } body { background:#fff; } .hoja { box-shadow:none; margin:0; width:auto; min-height:auto; padding:0; } @page { size:letter portrait; margin:14mm 16mm; } }
</style></head><body>
<div class="bar"><button onclick="window.print()">🖨 Imprimir</button><span>Constancia Retención IIBB <b>Nº <?= pe($nroCons) ?></b> · <?= pe(trim(nz($h['DENMOV'], ''))) ?> · <?= nm($rix) ?></span></div>

<div class="hoja">
  <!-- ── Membrete ── -->
  <div class="top">
    <div class="emp"><div class="nom"><?= pe($EMP['nom']) ?></div></div>
    <div class="doc">
      <div>IMPUESTO A LOS ING. BRUTOS</div>
      <div>COMPROBANTE DE RETENCION</div>
      <div>Nº <?= pe($nroCons) ?></div>
      <div class="ln"><span class="lbl">FECHA DE EMISION:</span><span><?= pe(fecha_serial($h['FEXMOV'])) ?></span></div>
    </div>
  </div>

  <!-- ── A) Agente de Retención ── -->
  <div class="sec">A.- Datos del Agente de Retención</div>
  <table class="dl">
    <tr><td class="lbl">NOMBRE Y APELLIDO O DENOMINACION</td><td class="sep">:</td><td class="val"><?= pe($EMP['nom']) ?></td></tr>
    <tr><td class="lbl">AGENTE DE RETENCION Nº</td><td class="sep">:</td><td class="val"><?= pe($EMP['cuit']) ?></td></tr>
    <tr><td class="lbl">C.U.I.T. Nº</td><td class="sep">:</td><td class="val"><?= pe($EMP['cuit']) ?></td></tr>
    <tr><td class="lbl">DOMICILIO</td><td class="sep">:</td><td class="val"><?= pe($EMP['dir']) ?></td></tr>
    <tr><td class="lbl">LOCALIDAD</td><td class="sep">:</td><td class="val"><?= pe($EMP['loc']) ?></td></tr>
    <tr><td class="lbl">PROVINCIA</td><td class="sep">:</td><td class="val"><?= pe($EMP['prov']) ?></td></tr>
  </table>

  <!-- ── B) Sujeto Retenido ── -->
  <div class="sec">B.- Datos del Sujeto Retenido</div>
  <table class="dl">
    <tr><td class="lbl">NOMBRE Y APELLIDO O DENOMINACION</td><td class="sep">:</td><td class="val"><?= pe(trim(nz($h['DENMOV'], ''))) ?></td></tr>
    <tr><td class="lbl">C.U.I.T. Nº</td><td class="sep">:</td><td class="val"><?= pe(trim(nz($h['CITMOV'], ''))) ?></td></tr>
    <tr><td class="lbl">DOMICILIO</td><td class="sep">:</td><td class="val"><?= pe($sujDom) ?></td></tr>
    <tr><td class="lbl">LOCALIDAD</td><td class="sep">:</td><td class="val"><?= pe($sujLoc) ?></td></tr>
    <tr><td class="lbl">PROVINCIA</td><td class="sep">:</td><td class="val"><?= pe($sujProv) ?></td></tr>
  </table>

  <!-- ── C) Retención Practicada ── -->
  <div class="sec">C.- Datos de la Retención Practicada</div>
  <table class="dl">
    <tr><td class="lbl">IMPUESTO</td><td class="sep">:</td><td class="val">IMPUESTO A LOS INGRESOS BRUTOS BS. AS.</td></tr>
    <tr><td class="lbl">REGIMEN</td><td class="sep">:</td><td class="val"><?= pe($reg ? trim(nz($reg['LEYRRI'], '')) : '') ?></td></tr>
    <tr><td class="lbl">COMPROBANTE QUE ORIGINA LA RETENCION</td><td class="sep">:</td><td class="val"><?= pe($compOri) ?></td></tr>
    <tr><td class="lbl">MONTO DEL COMPROBANTE QUE ORIGINA LA RETENCION</td><td class="sep">:</td><td class="val">$ <span class="money"><?= nm($total) ?></span></td></tr>
    <tr><td class="lbl">MONTO DE LA RETENCION</td><td class="sep">:</td><td class="val">$ <span class="money"><?= nm($rix) ?></span></td></tr>
  </table>

  <!-- ── Pie ── -->
  <div class="foot">
    <div class="firma">
      <div class="fb"><div class="fl"></div><div class="ft">FIRMA DEL AGENTE RETENCION</div></div>
      <div class="fb"><div class="fl"></div><div class="ft">ACLARACION</div></div>
    </div>
  </div>
</div>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 250); });</script><?php endif; ?>
</body></html>
