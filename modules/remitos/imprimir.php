<?php
/**
 * Impresión de remito sobre FORMULARIO PREIMPRESO. Porta Rpt CD Remitos NF: posiciona los campos
 * (cliente/domicilio/localidad/CUIT/cond.IVA/transporte/valor declarado/comprobante) y las líneas
 * (cantidad/unidad/código/denominación/PTP/O.Corte/O.Proceso) en coordenadas absolutas (mm, tomadas
 * del reporte legacy en twips ÷56.69). El NÚMERO del remito va PREIMPRESO en el papel (no se imprime).
 * Imprime 3 copias: Original / Duplicado / Triplicado. Calibrá con los offsets --off-x/--off-y.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
auth_require_login();

$num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
$h = db_row("SELECT * FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=410;");
if (!$h) { http_response_code(404); echo 'Remito no encontrado'; exit; }
// Visibilidad por modo doble-libro: no imprimir un remito de otro libro.
$estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
$lib = auth_libro_unico();
if (($lib === 'blanco' && !$estTrue) || ($lib === 'capacitacion' && $estTrue)) { http_response_code(403); echo 'Remito no disponible en este libro'; exit; }

// Lookups
$loc = db_row("SELECT L.DENLOC, P.DENPRO FROM [Tbl Localidades] AS L LEFT JOIN [Tbl Provincias] AS P ON L.CODPRO=P.CODPRO WHERE L.CODLOC=" . (int) nz($h['CODLOC'], 0) . ";");
$cri = db_row("SELECT INICRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($h['CODCRI'], 0) . ";");
$tra = null;
if (nz($h['CODTRA'], null) !== null && (int) $h['CODTRA'] > 0)
    $tra = db_row("SELECT DENTRA, DOMTRA, CITTRA FROM [Tbl Transportes] WHERE CODTRA=" . (int) $h['CODTRA'] . ";");
$lineas = db_query("SELECT CODPRO, DENMOV, CODUDM, EGRMOV, SVCMOV, ODCMOV, ODPMOV, PDLMOV
    FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num ORDER BY ORDMOV;");
$udm = array();
foreach (db_query("SELECT CODUDM, DENUDM FROM [Tbl Unidades de Medida]") as $u) $udm[(int) $u['CODUDM']] = trim((string) nz($u['DENUDM'], ''));

$comp = 'RV ' . trim((string) nz($h['CIIMOV'], '')) . ' ' . str_pad((string) (int) nz($h['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
$dom  = trim(nz($h['DCXMOV'], '') . ' ' . nz($h['DNXMOV'], '') . (nz($h['DPXMOV'], '') !== '' ? ' P' . $h['DPXMOV'] : '') . (nz($h['DDXMOV'], '') !== '' ? ' D' . $h['DDXMOV'] : ''));
$locTxt = $loc ? trim(nz($loc['DENLOC'], '') . ' - ' . nz($loc['DENPRO'], '')) : '';
$vdx = nz($h['VDXMOV'], null);
function f($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Remito <?= f($comp) ?> · imprimir</title>
<style>
  /* Calibración global: nudge X/Y en mm para alinear con el preimpreso de tu impresora. */
  :root { --off-x: 0mm; --off-y: 0mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 10pt/1.1 Arial, sans-serif; color: #000; }
  .hoja { position: relative; width: 210mm; height: 148mm; page-break-after: always; overflow: hidden; }
  .hoja:last-child { page-break-after: auto; }
  .f { position: absolute; transform: translate(var(--off-x), var(--off-y)); white-space: nowrap; }
  .copia { position: absolute; right: 4mm; top: 2mm; font-size: 7pt; color: #555; letter-spacing: .5px; }
  /* líneas */
  .ln { position: absolute; left: 0; right: 0; }
  .ln .c { position: absolute; transform: translate(var(--off-x), var(--off-y)); white-space: nowrap; overflow: hidden; }
  .num { text-align: right; }
  /* Barra en pantalla (no se imprime) */
  .bar { position: sticky; top: 0; background: #1e293b; color: #fff; padding: .5rem 1rem; display: flex; gap: 1rem; align-items: center; font-family: system-ui; }
  .bar button { padding: .35rem .9rem; border: 0; border-radius: .35rem; background: #2563eb; color: #fff; cursor: pointer; }
  .bar .muted { color: #94a3b8; font-size: .85rem; }
  @media print { .bar { display: none; } @page { size: A4 portrait; margin: 0; } body { width: 210mm; } }
</style></head>
<body>
<div class="bar">
  <button onclick="window.print()">🖨 Imprimir (Original / Duplicado / Triplicado)</button>
  <span>Remito <b><?= f($comp) ?></b> · <?= f(trim(nz($h['DENMOV'], ''))) ?></span>
  <span class="muted">Insertá el formulario preimpreso. Calibrá con --off-x/--off-y si hace falta.</span>
</div>
<?php
$copias = array('ORIGINAL', 'DUPLICADO', 'TRIPLICADO');
// Coordenadas en mm (del Rpt legacy, twips÷56.69). Editá acá para calibrar finos.
$P = array(
    'vdx'   => array(31, 5),    'fecha' => array(128, 25),  'comp'  => array(128, 30),
    'mov'   => array(159, 30),  'tra'   => array(31, 16),   'tradom'=> array(31, 21),  'tracit' => array(31, 26),
    'cli'   => array(20, 50),   'dom'   => array(20, 58),   'loc'   => array(115, 58), 'cri'    => array(20, 66), 'cit' => array(135, 66),
);
// Columnas de las líneas (left mm, ancho mm) + Top inicial y paso por fila.
$LC = array('cant' => array(1, 18), 'udm' => array(23, 7), 'cod' => array(31, 13), 'den' => array(45, 78), 'ptp' => array(125, 14), 'odc' => array(141, 14), 'odp' => array(156, 14));
$ln_top = 80; $ln_h = 4.6;
foreach ($copias as $copia):
?>
<div class="hoja">
  <div class="copia"><?= $copia ?></div>
  <?php if ($vdx !== null): ?><div class="f" style="left:<?= $P['vdx'][0] ?>mm;top:<?= $P['vdx'][1] ?>mm"><?= number_format((float) $vdx, 2, ',', '.') ?></div><?php endif; ?>
  <div class="f" style="left:<?= $P['fecha'][0] ?>mm;top:<?= $P['fecha'][1] ?>mm"><?= f(fecha_serial($h['FEXMOV'])) ?></div>
  <div class="f" style="left:<?= $P['comp'][0] ?>mm;top:<?= $P['comp'][1] ?>mm"><?= f($comp) ?></div>
  <div class="f" style="left:<?= $P['mov'][0] ?>mm;top:<?= $P['mov'][1] ?>mm">MOV: <?= (int) $h['NUMMOV'] ?></div>
  <?php if ($tra): ?>
    <div class="f" style="left:<?= $P['tra'][0] ?>mm;top:<?= $P['tra'][1] ?>mm"><?= f(trim(nz($tra['DENTRA'], ''))) ?></div>
    <div class="f" style="left:<?= $P['tradom'][0] ?>mm;top:<?= $P['tradom'][1] ?>mm"><?= f(trim(nz($tra['DOMTRA'], ''))) ?></div>
    <div class="f" style="left:<?= $P['tracit'][0] ?>mm;top:<?= $P['tracit'][1] ?>mm"><?= f(trim(nz($tra['CITTRA'], ''))) ?></div>
  <?php endif; ?>
  <div class="f" style="left:<?= $P['cli'][0] ?>mm;top:<?= $P['cli'][1] ?>mm"><?= f(trim(nz($h['DENMOV'], ''))) ?></div>
  <div class="f" style="left:<?= $P['dom'][0] ?>mm;top:<?= $P['dom'][1] ?>mm"><?= f($dom) ?></div>
  <div class="f" style="left:<?= $P['loc'][0] ?>mm;top:<?= $P['loc'][1] ?>mm"><?= f($locTxt) ?></div>
  <div class="f" style="left:<?= $P['cri'][0] ?>mm;top:<?= $P['cri'][1] ?>mm"><?= f($cri ? trim(nz($cri['INICRI'], '')) : '') ?></div>
  <div class="f" style="left:<?= $P['cit'][0] ?>mm;top:<?= $P['cit'][1] ?>mm"><?= f(trim(nz($h['CITMOV'], ''))) ?></div>
  <?php $i = 0; foreach ($lineas as $l): $top = $ln_top + $i * $ln_h; $i++;
      $cant = (float) nz($l['EGRMOV'], 0); if ($cant == 0) $cant = -(float) nz($l['SVCMOV'], 0); ?>
  <div class="ln" style="top:<?= $top ?>mm">
    <span class="c num" style="left:<?= $LC['cant'][0] ?>mm;width:<?= $LC['cant'][1] ?>mm"><?= number_format($cant, 2, ',', '.') ?></span>
    <span class="c" style="left:<?= $LC['udm'][0] ?>mm;width:<?= $LC['udm'][1] ?>mm"><?= f(isset($udm[(int) $l['CODUDM']]) ? $udm[(int) $l['CODUDM']] : '') ?></span>
    <span class="c" style="left:<?= $LC['cod'][0] ?>mm;width:<?= $LC['cod'][1] ?>mm"><?= f(trim(nz($l['CODPRO'], ''))) ?></span>
    <span class="c" style="left:<?= $LC['den'][0] ?>mm;width:<?= $LC['den'][1] ?>mm"><?= f(trim(nz($l['DENMOV'], ''))) ?></span>
    <span class="c num" style="left:<?= $LC['ptp'][0] ?>mm;width:<?= $LC['ptp'][1] ?>mm"><?= (int) nz($l['PDLMOV'], 0) ?: '' ?></span>
    <span class="c num" style="left:<?= $LC['odc'][0] ?>mm;width:<?= $LC['odc'][1] ?>mm"><?= (int) nz($l['ODCMOV'], 0) ?: '' ?></span>
    <span class="c num" style="left:<?= $LC['odp'][0] ?>mm;width:<?= $LC['odp'][1] ?>mm"><?= (int) nz($l['ODPMOV'], 0) ?: '' ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 250); });</script><?php endif; ?>
</body></html>
