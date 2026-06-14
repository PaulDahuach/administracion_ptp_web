<?php
/** Exportación A.R.I.B. — Agente de Recaudación IIBB · PERCEPCIONES (SIAP/ARBA).
 *  Preview de las percepciones del período + botón de descarga del artefacto ARBA (.zip con MD5).
 *  Porta `rutExportacion_AgentePercepcionIngBrutos2`. Lógica/formato en _arib_p.php. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../config/afip.php';
require_once __DIR__ . '/_arib_p.php';
auth_require_login();

function arp2($v) { return number_format((float) $v, 2, '.', ','); }

// Las percepciones IIBB son MENSUALES → el default es el mes anterior completo.
$ref = new DateTime('first day of last month');
$defDes = $ref->format('Y-m-01');
$defHas = (new DateTime('last day of last month'))->format('Y-m-d');
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = aribp_serial($desIso); $sh = aribp_serial($hasIso);

$rows = aribp_rows($sd, $sh);
$tBI = 0.0; $tPIX = 0.0;
foreach ($rows as $r) { $tBI += (float) $r['qryBI']; $tPIX += (float) $r['qryPIX']; }
$base = aribp_filebase($desIso, $hasIso);
$intervalo = aribp_intervalo_txt($desIso, $hasIso);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');

$dl = 'export.php?desde=' . urlencode($desIso) . '&hasta=' . urlencode($hasIso);
$toolbar = '<a href="' . h($dl) . '" class="btn btn-success btn-sm' . (count($rows) ? '' : ' disabled') . '"><i class="bi bi-download me-1"></i>Descargar .zip (ARBA)</a>';
module_head('Exportación A.R.I.B. — Percepciones IIBB', 'bi-file-earmark-arrow-up', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>

<div class="alert alert-light border small d-flex flex-wrap gap-3 no-print" role="alert">
  <span><i class="bi bi-calendar-range me-1"></i><b>Intervalo:</b> <?= h($intervalo) ?></span>
  <span><i class="bi bi-file-earmark-zip me-1"></i><b>Archivo:</b> <span class="mono"><?= h($base) ?>_&lt;md5&gt;.zip</span></span>
  <span><i class="bi bi-shield-check me-1"></i><b>Libro:</b> Blanco (fiscal)</span>
  <span class="ms-auto text-muted">El .zip contiene <span class="mono"><?= h($base) ?>.txt</span> firmado con MD5 (intercambio ARBA).</span>
</div>

<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">AGENTE DE RECAUDACION DE INGRESOS BRUTOS - PERCEPCIONES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-tight">
    <colgroup><col style="width:1.2cm"><col style="width:0.9cm"><col style="width:2.0cm"><col style="width:0.9cm"><col style="width:1.8cm"><col style="width:2.0cm"><col style="width:6.6cm"><col style="width:2.6cm"><col style="width:2.4cm"></colgroup>
    <thead><tr>
      <th class="r">PDV</th><th class="c">IDE</th><th class="r">N° Comp.</th><th class="c">COD</th><th>Emisión</th><th class="r">Movim. N°</th><th>Cuenta Corriente</th><th class="r">Base Imponible</th><th class="r">Importe Percep.</th>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="r mono"><?= str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) ?></td>
        <td class="c mono"><?= h(substr(trim((string) nz($r['CIIMOV'], '')) . ' ', 0, 1)) ?></td>
        <td class="r mono"><?= str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT) ?></td>
        <td class="c mono"><?= h($r['qryCom']) ?></td>
        <td class="mono"><?= h(fecha_serial($r['FEXMOV'])) ?></td>
        <td class="r mono"><?= str_pad((string) (int) nz($r['NUMMOV'], 0), 8, '0', STR_PAD_LEFT) ?></td>
        <td><?= h(trim((string) nz($r['DENMOV'], ''))) ?></td>
        <td class="r mono"><?= arp2($r['qryBI']) ?></td>
        <td class="r mono"><?= arp2($r['qryPIX']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!count($rows)): ?>
      <tr><td colspan="9" class="text-muted">Sin percepciones IIBB en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot><tr class="tot">
      <td colspan="7">TOTAL PERCEPCIONES: <?= count($rows) ?></td>
      <td class="r mono"><?= arp2($tBI) ?></td>
      <td class="r mono"><?= arp2($tPIX) ?></td>
    </tr></tfoot>
  </table>
</div>
<?php module_foot(); ?>
