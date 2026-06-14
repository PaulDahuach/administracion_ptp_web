<?php
/** Resumen de Cuenta (Deudores) — hoja imprimible legacy (Rpt CD Resumen de Cuenta).
 *  Se abre desde el botón Imprimir de la CONSULTA (?codcue=&desde=&hasta=&libro=&nivel=) → popup que
 *  auto-imprime. Loader + tabla en _resumen_doc.php (compartido con el Listado list_resumen_cuenta). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/_resumen_doc.php';
auth_require_login();

function rcd_sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

$codcue = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
$nivel  = (isset($_GET['nivel']) && strtoupper($_GET['nivel']) === 'P') ? 'P' : 'D';
$libro  = isset($_GET['libro']) ? $_GET['libro'] : 'todos';
$forz = auth_libro_unico();
if ($forz !== '') $libro = $forz;
$sDesde = rcd_sp_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
$sHasta = rcd_sp_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');

$d = rcd_load($codcue, $sDesde, $sHasta, $libro, $nivel);
$cab = $d['cab'];
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Resumen de Cuenta</title>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<?php rcd_styles(); ?>
<style>
  @media print { @page { size: Letter landscape; margin: 10mm; } .lst-doc.lst-land { width: auto; } }
  .lst-doc.lst-land { width: 279mm; min-height: 216mm; }
</style>
</head><body>
<div class="lst-doc lst-doc-wide lst-land">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">RESUMEN DE CUENTA</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>CUENTA</span><span>:</span><span class="v"><?= $cab ? str_pad((string) $codcue, 5, '0', STR_PAD_LEFT) . ' ' . h(trim((string) nz($cab['DENCUE'], ''))) : '—' ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sDesde)) ?> - <?= h(fecha_serial($sHasta)) ?></span>
    <span>NIVEL</span><span>:</span><span class="v"><?= $nivel === 'P' ? 'Producto' : 'Detalle' ?></span>
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
  </div>
  <?php if (!$cab): ?>
  <div class="lst-tot">Cuenta inválida.</div>
  <?php else: rcd_table($d); endif; ?>
</div>
<script>if (!/[?&]noprint=1/.test(location.search)) window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body></html>
