<?php
/** Exportación A.R.I.B. — Agente de Recaudación IIBB · RETENCIONES (SIAP/ARBA).
 *  Preview de las retenciones del período + botón de descarga del artefacto ARBA (.zip con MD5).
 *  Porta `rutExportacion_AgenteRetencionIngBrutos2`. Lógica/formato en _arib.php. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../config/afip.php';
require_once __DIR__ . '/_arib.php';
auth_require_login();

function ar2($v) { return number_format((float) $v, 2, '.', ','); }

// Las retenciones son QUINCENALES → el default es la última quincena completa.
$now = new DateTime('today');
if ((int) $now->format('j') <= 15) {                          // 2da quincena del mes anterior (16 → fin)
    $ref = (clone $now)->modify('last day of last month');
    $defDes = $ref->format('Y-m-16'); $defHas = $ref->format('Y-m-d');
} else {                                                       // 1era quincena del mes actual (1 → 15)
    $defDes = $now->format('Y-m-01'); $defHas = $now->format('Y-m-15');
}
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = arib_serial($desIso); $sh = arib_serial($hasIso);

$rows = arib_rows($sd, $sh);
$tot = 0.0; foreach ($rows as $r) $tot += (float) nz($r['RIXMOV'], 0);
$base = arib_filebase($desIso, $hasIso);
$intervalo = arib_intervalo_txt($desIso, $hasIso);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');

$dl = 'export.php?desde=' . urlencode($desIso) . '&hasta=' . urlencode($hasIso);
$toolbar = '<a href="' . h($dl) . '" class="btn btn-success btn-sm' . (count($rows) ? '' : ' disabled') . '"><i class="bi bi-download me-1"></i>Descargar .zip (ARBA)</a>';
module_head('Exportación A.R.I.B. — Retenciones IIBB', 'bi-file-earmark-arrow-up', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
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
    <div class="lst-tit">EXPORTACIÓN A.R.I.B. — RETENCIONES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-tight">
    <colgroup><col style="width:1.6cm"><col style="width:1.6cm"><col style="width:2.6cm"><col style="width:2.4cm"><col style="width:5.0cm"><col style="width:3.4cm"><col style="width:2.4cm"><col style="width:2.4cm"></colgroup>
    <thead><tr>
      <th class="r">Mov</th><th class="r">N° Ret.</th><th>Orden de Pago</th><th>CUIT</th><th>Proveedor</th><th>Régimen</th><th class="r">Total O.P.</th><th class="r">Retención</th>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r):
        $op = trim((string) nz($r['CICMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
        $reg = trim((string) nz($r['DENRRI'], '')) . ' (' . ar2($r['ALIRRI']) . '%)'; ?>
      <tr>
        <td class="r mono"><?= str_pad((string) (int) $r['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td>
        <td class="r mono"><?= str_pad((string) (int) nz($r['RINMOV'], 0), 8, '0', STR_PAD_LEFT) ?></td>
        <td class="mono"><?= h($op) ?></td>
        <td class="mono"><?= h(trim((string) nz($r['CITMOV'], ''))) ?></td>
        <td><?= h(trim((string) nz($r['DENMOV'], ''))) ?></td>
        <td><?= h($reg) ?></td>
        <td class="r mono"><?= ar2($r['TOTMOV']) ?></td>
        <td class="r mono"><?= ar2($r['RIXMOV']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!count($rows)): ?>
      <tr><td colspan="8" class="text-muted">Sin retenciones IIBB en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot><tr class="tot">
      <td colspan="7">TOTAL RETENCIONES: <?= count($rows) ?></td>
      <td class="r mono"><?= ar2($tot) ?></td>
    </tr></tfoot>
  </table>
</div>
<?php module_foot(); ?>
