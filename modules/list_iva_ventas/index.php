<?php
/** Listado I.V.A. Ventas (Deudores) — Rpt CD IVA. Variante LISTADO (imprimir/PDF) fiel al legacy:
 *  barra de parámetros (Cuenta Corriente · Desde · Hasta · Nivel · Agrupamientos · Fecha y Hora ·
 *  Desde Hoja Nº · Estado) + hoja con detalle + resúmenes LOCALIDADES y CATEGORIAS. La CONSULTA
 *  interactiva vive en modules/iva_ventas/. Loader + render en _iva_doc.php. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/_iva_doc.php';
auth_require_login();

function liv_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

// período predeterminado = Rec Control.DESFEC/HASFEC
list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$cue = isset($_GET['cue']) && ctype_digit((string) $_GET['cue']) ? (int) $_GET['cue'] : 0;
$niv = (isset($_GET['nivel']) && strtoupper($_GET['nivel']) === 'T') ? 'T' : 'D';
$enviado = isset($_GET['ok']);
// los checkbox sólo viajan cuando están tildados: en la 1ª carga van por default en true; una vez
// enviado el form (ok=1), reflejan el estado real del tilde (presente=tildado, ausente=destildado).
$agrup = $enviado ? isset($_GET['agr']) : true;
$now   = $enviado ? isset($_GET['fyh']) : true;

// libro: forzado por categoría (operador→blanco / capacitación→capacitacion); S/A eligen (default blanco)
$forz = auth_libro_unico();
$libro = ($forz !== '') ? $forz : (isset($_GET['libro']) ? $_GET['libro'] : 'blanco');

$prov = db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='D' ORDER BY DENCUE;");
$cueDen = '';
if ($cue) { foreach ($prov as $c) { if ((int) $c['CODCUE'] === $cue) { $cueDen = trim((string) nz($c['DENCUE'], '')); break; } } }

$data = $enviado ? iva_load(liv_serial($desIso), liv_serial($hasIso), $libro, $cue) : null;
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"' . ($data ? '' : ' disabled') . '><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de I.V.A. Ventas', 'bi-percent', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<?php iva_styles(); ?>
<style>
  @media print { @page { size: Letter portrait; margin: 12mm; } .lst-doc { width: auto; box-shadow: none; } }
  /* la hoja usa el ancho estándar .lst-doc (216mm, interior 19,0cm = ancho exacto de la tabla) */
  .lst-fgrid3 .lst-fpair.rc-prov { grid-column: 1 / -1; grid-template-columns: 6.5rem 1fr; }
  .lst-fgrid3 .lst-fpair.rc-prov > select,
  .lst-fgrid3 .lst-fpair.rc-prov > .iwk-combo,
  .lst-fgrid3 .lst-fpair.rc-prov .iwk-combo-input { width: 100%; max-width: 34rem; }
  .lst-fchecks { display: flex; gap: 1.2rem; align-items: center; margin-top: .4rem; flex-wrap: wrap; }
  .lst-fchecks label { font-size: .82rem; display: flex; align-items: center; gap: .3rem; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <input type="hidden" name="ok" value="1">
  <div class="lst-fgrid3">
    <span class="lst-fpair rc-prov"><label>Cuenta Corriente</label>
      <select name="cue" class="form-select form-select-sm">
        <option value="">— Todas —</option>
        <?php foreach ($prov as $c): $cc = (int) $c['CODCUE']; ?>
        <option value="<?= $cc ?>"<?= $cc === $cue ? ' selected' : '' ?>><?= h(trim((string) nz($c['DENCUE'], ''))) ?> (<?= str_pad((string) $cc, 5, '0', STR_PAD_LEFT) ?>)</option>
        <?php endforeach; ?>
      </select></span>
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Nivel</label>
      <select name="nivel" class="form-select form-select-sm">
        <option value="D"<?= $niv === 'D' ? ' selected' : '' ?>>Detalle</option>
        <option value="T"<?= $niv === 'T' ? ' selected' : '' ?>>Total</option>
      </select></span>
    <?php if (auth_ve_ambos()): ?>
    <span class="lst-fpair"><label>Estado</label>
      <select name="libro" class="form-select form-select-sm">
        <option value="blanco"<?= $libro === 'blanco' ? ' selected' : '' ?>>Blanco</option>
        <option value="capacitacion"<?= $libro === 'capacitacion' ? ' selected' : '' ?>>Capacitación</option>
        <option value="todos"<?= $libro === 'todos' ? ' selected' : '' ?>>Todos</option>
      </select></span>
    <?php endif; ?>
  </div>
  <div class="lst-fchecks">
    <label><input type="checkbox" name="agr" value="1"<?= $agrup ? ' checked' : '' ?>> Agrupamientos</label>
    <label><input type="checkbox" name="fyh" value="1"<?= $now ? ' checked' : '' ?>> Fecha y Hora</label>
    <button class="btn btn-primary btn-sm ms-auto"><i class="bi bi-search me-1"></i>Ver</button>
  </div>
</form>

<?php if (!$enviado): ?>
<div class="text-muted px-2 py-4">Elegí el período y los parámetros, luego <strong>Ver</strong> para generar el libro de I.V.A. Ventas.</div>
<?php else: ?>
<div class="lst-doc iva-page iva-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">I.V.A. VENTAS</div>
    <div class="lst-fecha"><?= $now ? date('d/m/Y H:i:s') : '' ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTA</span><span>:</span><span class="v"><?= $cue ? str_pad((string) $cue, 5, '0', STR_PAD_LEFT) . ' ' . h($cueDen) : 'TODAS' ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial(liv_serial($desIso))) ?> - <?= h(fecha_serial(liv_serial($hasIso))) ?></span>
    <span>NIVEL</span><span>:</span><span class="v"><?= $niv === 'T' ? 'Total' : 'Detalle' ?></span>
  </div>
  <?php iva_body($data, $niv, $agrup); ?>
</div>
<?php endif; ?>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
