<?php
/**
 * Impresión de RECIBO — reproduce FIEL el legacy "Rpt CD Recibos" (+ subreportes Referencias y
 * Cheques). Hoja Carta como preview (.hoja), fuente Univers Condensed self-hosteada.
 * Layout: membrete + Señor(es) + banda CONCEPTO/saldos + tabla LIQUIDACION + tabla DETALLE DEL PAGO
 * (con retenciones inline) + importe en letras + cláusula legal + FIRMA/ACLARACION.
 * Uso: ?nummov=N  (&print=1 para auto-imprimir).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
auth_require_login();

$num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
$h = db_row("SELECT * FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=480;");
if (!$h) { http_response_code(404); echo 'Recibo no encontrado'; exit; }
$estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
$lib = auth_libro_unico();
if (($lib === 'blanco' && !$estTrue) || ($lib === 'negro' && $estTrue)) { http_response_code(403); echo 'Recibo no disponible en este libro'; exit; }

// ── Lookups ──
$loc = db_row("SELECT L.CPXLOC, L.DENLOC, P.DENPRO FROM [Tbl Localidades] AS L LEFT JOIN [Tbl Provincias] AS P ON L.CODPRO=P.CODPRO WHERE L.CODLOC=" . (int) nz($h['CODLOC'], 0) . ";");
$cdv = db_row("SELECT DENCDV FROM [Tbl Condiciones de Venta] WHERE CODCDV=" . (int) nz($h['CODCDV'], 0) . ";");
$fdp = db_row("SELECT DENFDP FROM [Tbl Formas de Pago] WHERE CODFDP=" . (int) nz($h['CODFDP'], 0) . ";");
$op  = db_row("SELECT DENAUX FROM [Tbl Operaciones Auxiliares] WHERE CODAUX=" . (int) nz($h['CODAUX'], 0) . ";");

// ── LIQUIDACION (referencias) ──
$refs = db_query("SELECT R.REFMOV, R.FVXMOV, R.IMPMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, V.DEBMOV, V.CREMOV
    FROM ([Tbl Movimientos Referencias] AS R
      LEFT JOIN [Tbl Movimientos] AS M ON M.NUMMOV=R.REFMOV)
      LEFT JOIN [Tbl Movimientos Vencimientos] AS V ON V.NUMMOV=R.REFMOV AND V.FVXMOV=R.FVXMOV
    WHERE R.NUMMOV=$num;");

// ── DETALLE DEL PAGO (cheques) ──
$ban = array();
foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos]") as $b) $ban[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));
$chqs = db_query("SELECT C.CODBAN, C.SYNCHQ, C.FEXCHQ, C.FAXCHQ, C.IMPCHQ, C.PLZCHQ, C.LIBCHQ, C.CITCHQ, C.LOCCHQ
    FROM [Tbl Cheques] AS C INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON MI.CODCHQ=C.CODCHQ WHERE MI.NUMMOV=$num ORDER BY MI.ORDMOV;");

// retenciones que van como filas del DETALLE DEL PAGO
$retRows = array();
$retDefs = array('RT1MOV' => 'I.I.B.B.', 'RT2MOV' => 'GANANCIAS', 'RT3MOV' => 'I.V.A.', 'RT4MOV' => 'S.U.S.S.');
foreach ($retDefs as $k => $lblr) { $v = round((float) nz($h[$k], 0), 2); if ($v > 0) $retRows[] = array('TXT' => 'RETENCIONES ' . $lblr, 'IMP' => $v); }

$comp  = '0' . str_pad((string) (int) nz($h['CIPMOV'], 0), 3, '0', STR_PAD_LEFT) . ' - ' . str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
$total = round((float) nz($h['TOTMOV'], 0), 2);
$soc   = round((float) nz($h['SOCMOV'], 0), 2);
$mov8  = str_pad((string) $num, 8, '0', STR_PAD_LEFT);

// Empresa (de mdl*Empresa)
$EMP = array('nom' => 'PROCESADORA TEXTIL PARQUE S.A.', 'dir' => 'RUTA 32 KM. 1.5 - PARQUE INDUSTRIAL',
    'loc' => '(2700) - Pergamino Bs. As.', 'tel' => '(02477) 42-0710', 'cuit' => '30-70838113-2', 'ini' => '06/2003');

function pe($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function nm($v) { return number_format((float) $v, 2, '.', ','); }   // punto decimal, coma miles

/** Importe en letras (pesos). "SON PESOS: ... CON XX/100.-" */
function pesos_letras($n) {
    $n = round((float) $n, 2);
    $ent = (int) floor($n);
    $cen = (int) round(($n - $ent) * 100);
    $u = array('', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE');
    $dec = array('', '', 'VEINTI', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA');
    $cent = array('', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS');
    $tres = function ($x) use ($u, $dec, $cent) {
        $x = (int) $x; if ($x == 0) return '';
        if ($x == 100) return 'CIEN';
        $c = (int) ($x / 100); $r = $x % 100; $s = $cent[$c];
        if ($r > 0) {
            if ($s !== '') $s .= ' ';
            if ($r <= 20) $s .= $u[$r];
            elseif ($r < 30) $s .= 'VEINTI' . $u[$r - 20];
            else { $d = (int) ($r / 10); $un = $r % 10; $s .= $dec[$d] . ($un ? ' Y ' . $u[$un] : ''); }
        }
        return trim($s);
    };
    if ($ent == 0) $palabras = 'CERO';
    else {
        $millon = (int) ($ent / 1000000); $miles = (int) (($ent % 1000000) / 1000); $resto = $ent % 1000;
        $palabras = '';
        if ($millon > 0) $palabras .= ($millon == 1 ? 'UN MILLON' : $tres($millon) . ' MILLONES') . ' ';
        if ($miles > 0) $palabras .= ($miles == 1 ? 'MIL' : $tres($miles) . ' MIL') . ' ';
        $palabras .= $tres($resto);
        $palabras = trim($palabras);
    }
    return 'SON PESOS: ' . $palabras . ' CON ' . str_pad((string) $cen, 2, '0', STR_PAD_LEFT) . '/100.-';
}
$legal = 'EN CASO DE INCUMPLIMIENTO DE PAGO POR PARTE DEL DEUDOR, ESTE ACEPTA SOMETERSE A LA COMPETENCIA Y JURISDICCION DE LOS TRIBUNALES ORDINARIOS DE LA CIUDAD DE PERGAMINO, RENUNCIANDO A OTRO FUERO O JURISDICCION QUE PUDIERA CORRESPONDERLE.-';
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Recibo Nº <?= pe($comp) ?></title>
<style>
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed.woff') ?>') format('woff'); font-weight:normal; font-style:normal; font-display:swap; }
  @font-face { font-family:'Univers Condensed'; src:url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff2') ?>') format('woff2'), url('<?= bu('/assets/fonts/UniversCondensed-Bold.woff') ?>') format('woff'); font-weight:bold; font-style:normal; font-display:swap; }
  * { box-sizing:border-box; }
  body { background:#e9edf2; color:#000; margin:0; font-family:'Univers Condensed',"Arial Narrow","Liberation Sans Narrow",Arial,sans-serif; font-size:11px; }
  .hoja { background:#fff; width:216mm; min-height:279mm; margin:10px auto; padding:12mm 14mm; box-shadow:0 2px 12px rgba(0,0,0,.2); display:flex; flex-direction:column; }
  .foot { margin-top:auto; }   /* pie anclado al fondo de la hoja (se corre a otra hoja si desborda) */
  .num { text-align:right; font-variant-numeric:tabular-nums; }
  /* Membrete */
  .top { display:flex; justify-content:space-between; align-items:flex-start; }
  .emp .nom { font-size:26px; font-weight:bold; letter-spacing:.01em; line-height:1; }
  .doc { text-align:left; font-size:13px; font-weight:bold; }
  .doc .rc { font-size:26px; font-weight:bold; line-height:1; margin-bottom:2px; }
  .doc .ln { display:flex; gap:8px; }
  .doc .ln .lbl { min-width:120px; }
  .empdet { font-size:11px; font-weight:bold; line-height:1.25; margin-top:8px; }
  .fiscal { font-size:11px; font-weight:bold; line-height:1.25; margin-top:8px; }
  .fiscal td { padding:0 0 0 0; } .fiscal td.v { padding-left:18px; text-align:right; }
  .iva { font-size:12px; font-weight:bold; margin-top:8px; }
  /* Señor(es) */
  .sres { border:1px solid #000; margin-top:10px; padding:5px 8px 9px; min-height:74px; position:relative; }
  .sres .cap { font-size:10px; }
  .sres .body { font-weight:bold; margin-left:26px; margin-top:2px; line-height:1.35; }
  .sres .body .ln { display:flex; }
  .sres .body .calle { min-width:230px; }
  /* Banda CONCEPTO + saldos */
  .cbar { display:flex; gap:0; margin-top:9px; }
  .cbox { border:1px solid #000; border-right:0; flex:1 1 0; }
  .cbar .cbox:last-child { border-right:1px solid #000; }
  .chd { background:#C0C0C0; border-bottom:1px solid #000; text-align:center; font-weight:bold; font-size:10px; padding:2px 4px; line-height:1.05;
         min-height:28px; display:flex; align-items:center; justify-content:center; }
  .cvl { text-align:center; font-weight:bold; font-size:11px; padding:2px 5px; min-height:18px; }
  .cvl.num { text-align:right; }
  /* Tablas LIQUIDACION / DETALLE */
  .tcap { background:#C0C0C0; border:1px solid #000; border-bottom:0; text-align:center; font-weight:bold; font-size:11px; padding:2px; margin-top:9px; }
  table.g { width:100%; border-collapse:collapse; }
  table.g th, table.g td { border:1px solid #000; padding:1px 5px; font-size:11px; line-height:1.25; }
  table.g th { background:#C0C0C0; font-weight:bold; font-size:9.5px; text-align:center; }
  table.g td.num, table.g th.num { text-align:right; }
  table.g td.c { text-align:center; }
  .totrow td { font-weight:bold; }
  /* Pie */
  .legal { font-size:8px; line-height:1.2; margin-top:14px; }
  .sonpesos { font-size:11px; font-weight:bold; margin-top:4px; }
  .firma { border:1px solid #000; margin-top:8px; height:46mm; display:flex; align-items:flex-end; justify-content:space-around; padding:0 0 7px; }
  .firma .fb { width:40%; text-align:center; }
  .firma .fl { border-top:1px solid #000; margin:0 10%; } .firma .ft { font-weight:bold; font-size:10px; padding-top:3px; }
  .bar { position:sticky; top:0; background:#1e293b; color:#fff; padding:.5rem 1rem; display:flex; gap:1rem; align-items:center; font-family:system-ui; }
  .bar button, .bar a { padding:.35rem .9rem; border:0; border-radius:.35rem; background:#2563eb; color:#fff; cursor:pointer; text-decoration:none; }
  @media print { .bar { display:none; } body { background:#fff; } .hoja { box-shadow:none; margin:0; width:auto; min-height:auto; padding:0; } @page { size:letter portrait; margin:12mm 14mm; } }
</style></head><body>
<div class="bar"><button onclick="window.print()">🖨 Imprimir</button><span>Recibo <b><?= pe($comp) ?></b> · <?= pe(trim(nz($h['DENMOV'], ''))) ?> · <?= nm($total) ?></span></div>

<div class="hoja">
  <!-- ── Membrete ── -->
  <div class="top">
    <div class="emp">
      <div class="nom"><?= pe($EMP['nom']) ?></div>
      <div class="empdet"><?= pe($EMP['dir']) ?><br><?= pe($EMP['loc']) ?><br><?= pe($EMP['tel']) ?></div>
      <div class="iva">I.V.A. RESPONSABLE INSCRIPTO</div>
    </div>
    <div class="doc">
      <div class="rc">RECIBO</div>
      <div class="ln"><span>Nº <?= pe($comp) ?></span></div>
      <div class="ln"><span class="lbl">FECHA DE EMISION:</span><span><?= pe(fecha_serial($h['FEXMOV'])) ?></span></div>
      <table class="fiscal">
        <tr><td>C.U.I.T. Nº</td><td class="v"><?= pe($EMP['cuit']) ?></td></tr>
        <tr><td>ING. BRUTOS</td><td class="v"><?= pe($EMP['cuit']) ?></td></tr>
        <tr><td>INICIO DE ACTIVIDADES</td><td class="v"><?= pe($EMP['ini']) ?></td></tr>
        <tr><td>MOVIMIENTO Nº</td><td class="v"><?= pe($mov8) ?></td></tr>
      </table>
    </div>
  </div>

  <!-- ── Señor(es) ── -->
  <div class="sres">
    <span class="cap">Señor(es)</span>
    <div class="body">
      <div class="ln"><?= pe(trim(nz($h['DENMOV'], ''))) ?></div>
      <div class="ln"><span class="calle"><?= pe(trim(nz($h['DCXMOV'], ''))) ?></span><span><?= pe(trim(nz($h['DNXMOV'], ''))) ?></span></div>
      <div class="ln"><?= pe(trim('(' . nz($loc['CPXLOC'], '') . ') ' . nz($loc['DENLOC'], ''))) ?></div>
      <div class="ln"><?= pe($loc ? trim(nz($loc['DENPRO'], '')) : '') ?></div>
    </div>
  </div>

  <!-- ── CONCEPTO / CONDICION / FORMA / SALDOS ── -->
  <div class="cbar">
    <div class="cbox"><div class="chd">CONCEPTO</div><div class="cvl"><?= pe($op ? trim(nz($op['DENAUX'], '')) : '') ?></div></div>
    <div class="cbox"><div class="chd">CONDICION DE VENTA</div><div class="cvl"><?= pe($cdv ? trim(nz($cdv['DENCDV'], '')) : '') ?></div></div>
    <div class="cbox"><div class="chd">FORMA DE PAGO</div><div class="cvl"><?= pe($fdp ? trim(nz($fdp['DENFDP'], '')) : '') ?></div></div>
    <div class="cbox"><div class="chd">SALDO ANTERIOR<br>CTA. CTE.</div><div class="cvl num"><?= nm($soc) ?></div></div>
    <div class="cbox"><div class="chd">IMPORTE<br>RECIBO</div><div class="cvl num"><?= nm($total) ?></div></div>
    <div class="cbox"><div class="chd">SALDO ACTUAL<br>CTA. CTE.</div><div class="cvl num"><?= nm($soc - $total) ?></div></div>
  </div>

  <!-- ── LIQUIDACION ── -->
  <div class="tcap">LIQUIDACION</div>
  <table class="g">
    <thead><tr>
      <th colspan="4">COMPROBANTE</th><th>VENCIMIENTO</th><th>IMPORTE ORIGEN</th><th>PAGOS A CUENTA</th><th>PAGO ACTUAL</th><th>SALDO COMPROBANTE</th>
    </tr></thead>
    <tbody>
    <?php $tliq = 0; foreach ($refs as $r):
      $origen = round((float) nz($r['DEBMOV'], 0), 2); $cre = round((float) nz($r['CREMOV'], 0), 2);
      $pagoAct = round((float) nz($r['IMPMOV'], 0), 2); $pagosCta = round($cre - $pagoAct, 2); $saldoC = round($origen - $cre, 2); $tliq += $pagoAct; ?>
      <tr>
        <td style="width:26px"><?= pe(trim(nz($r['CICMOV'], ''))) ?></td>
        <td class="c" style="width:18px"><?= pe(trim(nz($r['CIIMOV'], ''))) ?></td>
        <td class="c" style="width:36px"><?= pe(str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT)) ?></td>
        <td style="width:62px"><?= pe(str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT)) ?></td>
        <td class="c"><?= pe(fecha_serial($r['FVXMOV'])) ?></td>
        <td class="num"><?= nm($origen) ?></td><td class="num"><?= nm($pagosCta) ?></td><td class="num"><?= nm($pagoAct) ?></td><td class="num"><?= nm($saldoC) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr class="totrow"><td colspan="7" class="num">TOTAL</td><td class="num"><?= nm($tliq) ?></td><td></td></tr>
    </tbody>
  </table>

  <!-- ── DETALLE DEL PAGO ── -->
  <div class="tcap">DETALLE DEL PAGO</div>
  <table class="g">
    <thead><tr>
      <th>CUENTA / BANCO</th><th>SERIE - Nº</th><th>EMISION</th><th>PLAZA</th><th>ACREDITACION</th><th>LIBRADOR</th><th>C.U.I.T.</th><th>LOCALIDAD</th><th>IMPORTE</th>
    </tr></thead>
    <tbody>
    <?php $tdet = 0; foreach ($chqs as $c): $imp = round((float) nz($c['IMPCHQ'], 0), 2); $tdet += $imp; ?>
      <tr>
        <td><?= pe(isset($ban[(int) $c['CODBAN']]) ? $ban[(int) $c['CODBAN']] : '') ?></td>
        <td><?= pe(trim(nz($c['SYNCHQ'], ''))) ?></td>
        <td class="c"><?= pe(fecha_serial($c['FEXCHQ'])) ?></td>
        <td class="c"><?= pe((string) (int) nz($c['PLZCHQ'], 0)) ?></td>
        <td class="c"><?= pe(fecha_serial($c['FAXCHQ'])) ?></td>
        <td><?= pe(trim(nz($c['LIBCHQ'], ''))) ?></td>
        <td><?= pe(trim(nz($c['CITCHQ'], ''))) ?></td>
        <td><?= pe(trim(nz($c['LOCCHQ'], ''))) ?></td>
        <td class="num"><?= nm($imp) ?></td>
      </tr>
    <?php endforeach; foreach ($retRows as $rr): $tdet += $rr['IMP']; ?>
      <tr><td colspan="8"><?= pe($rr['TXT']) ?></td><td class="num"><?= nm($rr['IMP']) ?></td></tr>
    <?php endforeach; ?>
      <tr class="totrow"><td colspan="8" class="num">TOTAL</td><td class="num"><?= nm($tdet) ?></td></tr>
    </tbody>
  </table>

  <!-- ── Pie (anclado al fondo de la hoja) ── -->
  <div class="foot">
    <div class="legal"><?= pe($legal) ?></div>
    <div class="sonpesos"><?= pe(pesos_letras($total)) ?></div>
    <div class="firma">
      <div class="fb"><div class="fl"></div><div class="ft">FIRMA</div></div>
      <div class="fb"><div class="fl"></div><div class="ft">ACLARACION</div></div>
    </div>
  </div>
</div>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 250); });</script><?php endif; ?>
</body></html>
