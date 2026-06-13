<?php
/** Listado de Resumen de Cuenta (Acreedores) — Rpt CA Resumen de Cuenta.
 *  Variante LISTADO (pensada para imprimir/PDF) del resumen de cuenta de proveedores: barra de
 *  parámetros estilo listado (Proveedor · Desde · Hasta · Nivel) + hoja imprimible. La variante
 *  CONSULTA (interactiva, para pantalla) vive en modules/resumen_cuenta_acr/.
 *  Loader + tabla 100% fieles vienen del parcial compartido modules/resumen_cuenta_acr/_resumen_doc.php.
 *  NIVEL: D (Detalle) | P (Producto). Modo doble-libro (auth_libro_unico). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../resumen_cuenta_acr/_resumen_doc.php';
auth_require_login();

function lrc_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

// período (default = año de la fecha del sistema, como el resto de los listados periódicos)
$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $p = explode('/', $f); $hoyIso = $p[2] . '-' . $p[1] . '-' . $p[0]; } }
$anio = substr($hoyIso, 0, 4);
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : ($anio . '-01-01');
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : ($anio . '-12-31');

$codcue = isset($_GET['cue']) && ctype_digit((string) $_GET['cue']) ? (int) $_GET['cue'] : 0;
$nivel  = (isset($_GET['nivel']) && strtoupper($_GET['nivel']) === 'P') ? 'P' : 'D';

$forz = auth_libro_unico();
$libro = ($forz !== '') ? $forz : (isset($_GET['libro']) ? $_GET['libro'] : 'todos');

// proveedores para el selector (todos los acreedores)
$prov = db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A' ORDER BY DENCUE;");

$d = $codcue ? rca_load($codcue, lrc_serial($desIso), lrc_serial($hasIso), $libro, $nivel) : null;
$cab = $d ? $d['cab'] : null;
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"' . ($cab ? '' : ' disabled') . '><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Resumen de Cuenta (Listado) — Proveedores', 'bi-journal-text', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<?php rca_styles(); ?>
<style>
  @media print { @page { size: Letter landscape; margin: 10mm; } .lst-doc.lst-land { width: auto; } }
  .lst-doc.lst-land { width: 279mm; min-height: 216mm; }
  /* Proveedor ancho en su propia fila; Desde · Hasta · Nivel en la fila siguiente (Nivel = 3ª col) */
  .lst-fgrid3 .lst-fpair.rc-prov { grid-column: 1 / -1; grid-template-columns: 6.5rem 1fr; }
  .lst-fgrid3 .lst-fpair.rc-prov > select,
  .lst-fgrid3 .lst-fpair.rc-prov > .iwk-combo,
  .lst-fgrid3 .lst-fpair.rc-prov .iwk-combo-input { width: 100%; max-width: 34rem; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair rc-prov"><label>Proveedor</label>
      <select name="cue" class="form-select form-select-sm">
        <option value="">— Elegir proveedor —</option>
        <?php foreach ($prov as $c): $cc = (int) $c['CODCUE']; ?>
        <option value="<?= $cc ?>"<?= $cc === $codcue ? ' selected' : '' ?>><?= h(trim((string) nz($c['DENCUE'], ''))) ?> (<?= str_pad((string) $cc, 5, '0', STR_PAD_LEFT) ?>)</option>
        <?php endforeach; ?>
      </select></span>
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Nivel</label>
      <select name="nivel" class="form-select form-select-sm">
        <option value="D"<?= $nivel === 'D' ? ' selected' : '' ?>>Detalle</option>
        <option value="P"<?= $nivel === 'P' ? ' selected' : '' ?>>Producto</option>
      </select></span>
    <?php if (auth_ve_ambos()): ?>
    <span class="lst-fpair"><label>Libro</label>
      <select name="libro" class="form-select form-select-sm">
        <option value="todos"<?= $libro === 'todos' ? ' selected' : '' ?>>Todos</option>
        <option value="blanco"<?= $libro === 'blanco' ? ' selected' : '' ?>>Blanco</option>
        <option value="capacitacion"<?= $libro === 'capacitacion' ? ' selected' : '' ?>>Capacitación</option>
      </select></span>
    <?php endif; ?>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>

<?php if (!$codcue): ?>
<div class="text-muted px-2 py-4">Elegí un proveedor y el período para ver el resumen de cuenta.</div>
<?php else: ?>
<div class="lst-doc lst-doc-wide lst-land">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">RESUMEN DE CUENTA</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>CUENTA</span><span>:</span><span class="v"><?= $cab ? str_pad((string) $codcue, 5, '0', STR_PAD_LEFT) . ' ' . h(trim((string) nz($cab['DENCUE'], ''))) : '—' ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial(lrc_serial($desIso))) ?> - <?= h(fecha_serial(lrc_serial($hasIso))) ?></span>
    <span>NIVEL</span><span>:</span><span class="v"><?= $nivel === 'P' ? 'Producto' : 'Detalle' ?></span>
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
  </div>
  <?php if (!$cab): ?>
  <div class="lst-tot">Cuenta inválida.</div>
  <?php else: rca_table($d); endif; ?>
</div>
<?php endif; ?>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
