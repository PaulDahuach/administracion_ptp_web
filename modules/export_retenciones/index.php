<?php
/** Exportación de Retenciones SUFRIDAS (recibos CODOPE=480) — IIBB / IVA / Ganancias / SUSS.
 *  Preview de las retenciones del período + descarga del .txt. ?tipo=iibb|iva|ganancias|suss.
 *  Porta `rutExportacion_Retencion*`. Lógica/formato en _ret.php. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/_ret.php';
auth_require_login();

function rt2($v) { return number_format((float) $v, 2, '.', ','); }

$tipo = isset($_GET['tipo']) ? strtolower($_GET['tipo']) : 'iibb';
$cf = ret_cfg($tipo);
if (!$cf) { $tipo = 'iibb'; $cf = ret_cfg('iibb'); }

// declaraciones mensuales → default mes anterior completo
$ref = new DateTime('first day of last month');
$defDes = $ref->format('Y-m-01');
$defHas = (new DateTime('last day of last month'))->format('Y-m-d');
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = ret_serial($desIso); $sh = ret_serial($hasIso);

$rows = ret_rows($tipo, $sd, $sh);
$tot = 0.0; foreach ($rows as $r) $tot += (float) nz($r['RT'], 0);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');

$dl = 'export.php?tipo=' . urlencode($tipo) . '&desde=' . urlencode($desIso) . '&hasta=' . urlencode($hasIso);
$toolbar = '<a href="' . h($dl) . '" class="btn btn-success btn-sm' . (count($rows) ? '' : ' disabled') . '"><i class="bi bi-download me-1"></i>Descargar ' . h($cf['file']) . '</a>';
module_head('Exportación ' . $cf['label'], 'bi-file-earmark-arrow-up', $toolbar);

$tabs = array('iibb' => 'Ingresos Brutos', 'iva' => 'I.V.A.', 'ganancias' => 'Ganancias', 'suss' => 'S.U.S.S.');
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<ul class="nav nav-pills nav-sm mb-2 no-print">
  <?php foreach ($tabs as $k => $lbl): ?>
  <li class="nav-item"><a class="nav-link py-1 px-2<?= $k === $tipo ? ' active' : '' ?>" href="?tipo=<?= $k ?>&desde=<?= h($desIso) ?>&hasta=<?= h($hasIso) ?>"><?= h($lbl) ?></a></li>
  <?php endforeach; ?>
</ul>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <input type="hidden" name="tipo" value="<?= h($tipo) ?>">
  <div class="lst-fgrid3">
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>

<div class="alert alert-light border small d-flex flex-wrap gap-3 no-print" role="alert">
  <span><i class="bi bi-file-earmark-text me-1"></i><b>Formato:</b> <?= h($cf['fmt']) ?></span>
  <span><i class="bi bi-download me-1"></i><b>Archivo:</b> <span class="mono"><?= h($cf['file']) ?></span></span>
  <span><i class="bi bi-shield-check me-1"></i><b>Libro:</b> Blanco (fiscal)</span>
  <span class="ms-auto text-muted">Retenciones que nos hicieron en los recibos del período (Fec. Mov.).</span>
</div>

<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit"><?= h($cf['titulo']) ?></div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-tight">
    <colgroup><col style="width:2.0cm"><col style="width:1.8cm"><col style="width:2.6cm"><col style="width:7.0cm"><col style="width:2.6cm"><col style="width:2.4cm"></colgroup>
    <thead><tr>
      <th>Recibo</th><th>Fec. Mov.</th><th>CUIT</th><th>Denominación</th><th>N° Retención</th><th class="r">Importe</th>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r):
        $rec = str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
        $nret = str_pad((string) (int) nz($r['RP'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['RN'], 0), 8, '0', STR_PAD_LEFT); ?>
      <tr>
        <td class="mono"><?= h($rec) ?></td>
        <td class="mono"><?= h(fecha_serial($r['FIXMOV'])) ?></td>
        <td class="mono"><?= h(trim((string) nz($r['CITMOV'], ''))) ?></td>
        <td><?= h(trim((string) nz($r['DENMOV'], ''))) ?></td>
        <td class="mono"><?= h($nret) ?></td>
        <td class="r mono"><?= rt2($r['RT']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!count($rows)): ?>
      <tr><td colspan="6" class="text-muted">Sin retenciones de este tipo en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot><tr class="tot">
      <td colspan="5">TOTAL RETENCIONES: <?= count($rows) ?></td>
      <td class="r mono"><?= rt2($tot) ?></td>
    </tr></tfoot>
  </table>
</div>
<?php module_foot(); ?>
