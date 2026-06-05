<?php
/** Impresión de recibo (página completa, porta Rpt CD Recibos). Membrete + RECIBO + cliente +
 *  comprobantes cancelados + retenciones + cheques + importe + saldos cta cte. 2 copias (Original/Duplicado). */
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

$loc = db_row("SELECT L.DENLOC, P.DENPRO FROM [Tbl Localidades] AS L LEFT JOIN [Tbl Provincias] AS P ON L.CODPRO=P.CODPRO WHERE L.CODLOC=" . (int) nz($h['CODLOC'], 0) . ";");
$cri = db_row("SELECT INICRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($h['CODCRI'], 0) . ";");
$op  = db_row("SELECT DENAUX FROM [Tbl Operaciones Auxiliares] WHERE CODAUX=" . (int) nz($h['CODAUX'], 0) . ";");
$refs = db_query("SELECT R.IMPMOV, R.FVXMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.FEXMOV
    FROM [Tbl Movimientos Referencias] AS R LEFT JOIN [Tbl Movimientos] AS M ON M.NUMMOV=R.REFMOV WHERE R.NUMMOV=$num;");
$ban = array(); foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos]") as $b) $ban[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));
$chqs = db_query("SELECT C.CODBAN, C.SYNCHQ, C.FAXCHQ, C.IMPCHQ, C.LOCCHQ
    FROM [Tbl Cheques] AS C INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON MI.CODCHQ=C.CODCHQ WHERE MI.NUMMOV=$num ORDER BY MI.ORDMOV;");

$comp = '0' . str_pad((string) (int) nz($h['CIPMOV'], 0), 3, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
$total = round((float) nz($h['TOTMOV'], 0), 2);
$soc = round((float) nz($h['SOCMOV'], 0), 2);
$retLabels = array('RT1MOV' => 'Ret. I.I.B.B.', 'RT2MOV' => 'Ret. Ganancias', 'RT3MOV' => 'Ret. I.V.A.', 'RT4MOV' => 'Ret. S.U.S.S.');
function pe($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function rmoney($v) { return number_format((float) $v, 2, ',', '.'); }
// Empresa (de mdl*Empresa del legacy)
$EMP = array('nom' => 'PROCESADORA TEXTIL PARQUE S.A.', 'dir' => 'RUTA 32 KM. 1.5 - PARQUE INDUSTRIAL',
    'loc' => '(2700) - Pergamino Bs. As.', 'tel' => '(02477) 42-0710', 'cuit' => '30-70838113-2', 'ini' => '06/2003');
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Recibo RC <?= pe($comp) ?></title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font: 11px/1.3 Arial, sans-serif; color: #000; }
  .hoja { width: 190mm; margin: 0 auto; padding: 8mm 6mm; page-break-after: always; }
  .hoja:last-child { page-break-after: auto; }
  .top { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 6px; }
  .emp .nom { font-size: 16px; font-weight: 700; }
  .emp .det { font-size: 10px; }
  .doc { text-align: right; }
  .doc .rc { font-size: 22px; font-weight: 700; letter-spacing: 2px; }
  .doc .nro { font-size: 14px; font-weight: 700; }
  .copia { font-size: 10px; color: #555; }
  .cli { margin-top: 8px; border: 1px solid #000; padding: 6px; }
  .cli b { display: inline-block; min-width: 90px; }
  table.g { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 10.5px; }
  table.g th, table.g td { border: 1px solid #999; padding: 2px 5px; }
  table.g th { background: #eee; text-align: left; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .tot { margin-top: 8px; display: flex; justify-content: flex-end; gap: 18px; align-items: flex-end; }
  .tot .imp { font-size: 16px; font-weight: 700; border: 2px solid #000; padding: 4px 12px; }
  .saldos { font-size: 10px; margin-top: 6px; }
  .firma { margin-top: 26px; display: flex; justify-content: space-between; }
  .firma .l { border-top: 1px solid #000; width: 60mm; text-align: center; font-size: 10px; padding-top: 2px; }
  .bar { position: sticky; top: 0; background: #1e293b; color: #fff; padding: .5rem 1rem; font-family: system-ui; display: flex; gap: 1rem; align-items: center; }
  .bar button { padding: .35rem .9rem; border: 0; border-radius: .35rem; background: #2563eb; color: #fff; cursor: pointer; }
  @media print { .bar { display: none; } @page { size: A4 portrait; margin: 6mm; } .hoja { width: auto; padding: 0; } }
</style></head><body>
<div class="bar">
  <button onclick="window.print()">🖨 Imprimir</button>
  <span>Recibo <b>RC <?= pe($comp) ?></b> · <?= pe(trim(nz($h['DENMOV'], ''))) ?> · <?= rmoney($total) ?></span>
</div>
<?php foreach (array('ORIGINAL', 'DUPLICADO') as $copia): ?>
<div class="hoja">
  <div class="top">
    <div class="emp">
      <div class="nom"><?= pe($EMP['nom']) ?></div>
      <div class="det"><?= pe($EMP['dir']) ?> · <?= pe($EMP['loc']) ?> · Tel <?= pe($EMP['tel']) ?></div>
      <div class="det">I.V.A. Responsable Inscripto · C.U.I.T. <?= pe($EMP['cuit']) ?> · Ing. Brutos <?= pe($EMP['cuit']) ?> · Inicio Act. <?= pe($EMP['ini']) ?></div>
    </div>
    <div class="doc">
      <div class="rc">RECIBO</div>
      <div class="nro">N° <?= pe($comp) ?></div>
      <div class="copia"><?= $copia ?></div>
      <div class="det">Fecha de emisión: <?= pe(fecha_serial($h['FEXMOV'])) ?></div>
    </div>
  </div>
  <div class="cli">
    <div><b>Señor(es):</b> <?= pe(trim(nz($h['DENMOV'], ''))) ?></div>
    <div><b>Domicilio:</b> <?= pe(trim(nz($h['DCXMOV'], '') . ' ' . nz($h['DNXMOV'], ''))) ?> · <?= pe($loc ? trim(nz($loc['DENLOC'], '') . ' - ' . nz($loc['DENPRO'], '')) : '') ?></div>
    <div><b>Cond. IVA:</b> <?= pe($cri ? trim(nz($cri['INICRI'], '')) : '') ?> · <b>C.U.I.T.:</b> <?= pe(trim(nz($h['CITMOV'], ''))) ?> · <b>Concepto:</b> <?= pe($op ? trim(nz($op['DENAUX'], '')) : '') ?></div>
    <?php if (trim(nz($h['DETMOV'], '')) !== ''): ?><div><b>Detalle:</b> <?= pe(trim($h['DETMOV'])) ?></div><?php endif; ?>
  </div>

  <?php if ($refs): ?>
  <table class="g"><thead><tr><th>Comprobante</th><th>Emisión</th><th>Vencimiento</th><th class="num">Importe</th></tr></thead><tbody>
    <?php foreach ($refs as $r): $c = trim((string) nz($r['CICMOV'], '') . ' ' . nz($r['CIIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT); ?>
    <tr><td><?= pe($c) ?></td><td><?= pe(fecha_serial($r['FEXMOV'])) ?></td><td><?= pe(fecha_serial($r['FVXMOV'])) ?></td><td class="num"><?= rmoney($r['IMPMOV']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>

  <?php $hayRet = false; foreach ($retLabels as $k => $l) if ((float) nz($h[$k], 0) > 0) $hayRet = true; if ($hayRet): ?>
  <table class="g" style="width:60%"><thead><tr><th>Retención</th><th class="num">Importe</th></tr></thead><tbody>
    <?php foreach ($retLabels as $k => $l): $v = (float) nz($h[$k], 0); if ($v <= 0) continue; ?>
    <tr><td><?= pe($l) ?></td><td class="num"><?= rmoney($v) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>

  <?php if ($chqs): ?>
  <table class="g"><thead><tr><th>Forma de pago: cheques</th><th>Serie-Nº</th><th>Acreditación</th><th class="num">Importe</th></tr></thead><tbody>
    <?php foreach ($chqs as $c): ?>
    <tr><td><?= pe(isset($ban[(int) $c['CODBAN']]) ? $ban[(int) $c['CODBAN']] : '') ?><?= trim(nz($c['LOCCHQ'], '')) !== '' ? ' (' . pe(trim($c['LOCCHQ'])) . ')' : '' ?></td><td><?= pe(trim(nz($c['SYNCHQ'], ''))) ?></td><td><?= pe(fecha_serial($c['FAXCHQ'])) ?></td><td class="num"><?= rmoney($c['IMPCHQ']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>

  <div class="tot"><div><div style="font-size:10px">IMPORTE RECIBO</div><div class="imp">$ <?= rmoney($total) ?></div></div></div>
  <div class="saldos">Saldo anterior cta. cte.: $ <?= rmoney($soc) ?> · Saldo actual cta. cte.: $ <?= rmoney($soc - $total) ?></div>
  <div class="firma"><div class="l">Firma</div><div class="l">Aclaración</div></div>
</div>
<?php endforeach; ?>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 250); });</script><?php endif; ?>
</body></html>
