<?php
/** Listado de Remitos Acreedores Pendientes (Rpt CA Remitos Pendientes). Remitos de proveedor
 *  (CODOPE=300) que todavía NO fueron facturados: no existe fila en Tbl Movimientos Remitos que los
 *  referencie (REMMOV null). Agrupado por cuenta. Filtros: período (CEFMOV) + cuenta (cód, opcional). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : '2000-01-01';
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : date('Y-m-d');
$sd = sp_serial($desIso); $sh = sp_serial($hasIso);
$cue = isset($_GET['cue']) && ctype_digit((string) $_GET['cue']) ? (int) $_GET['cue'] : '';

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');
$cueW = ($cue !== '') ? ' AND M.CODCUE=' . $cue : '';

// remito (CODOPE=300) sin referencia en Movimientos Remitos (REMMOV null) → pendiente.
// ACE: LEFT JOIN simple Movimientos→Movimientos Remitos; el INNER a Cuentas se resuelve por mapa en PHP.
$rows = db_query("SELECT M.NUMMOV, M.CODCUE, M.CIPMOV, M.CINMOV, M.CEPMOV, M.CENMOV, M.CEFMOV, M.FEXMOV, M.DETMOV
    FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Movimientos Remitos] AS R ON M.NUMMOV = R.REMMOV
    WHERE M.CODORI='A' AND M.CODOPE=300 AND R.REMMOV Is Null AND M.CEFMOV >= $sd AND M.CEFMOV <= $sh$estW$cueW
    ORDER BY M.CODCUE, M.NUMMOV;");

$den = array();
foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A';") as $c)
    $den[(int) $c['CODCUE']] = trim((string) nz($c['DENCUE'], ''));
// ordenar por denominación (el legacy ordena DENCUE, NUMMOV)
usort($rows, function ($a, $b) use ($den) {
    $c = strcasecmp(isset($den[(int) $a['CODCUE']]) ? $den[(int) $a['CODCUE']] : '', isset($den[(int) $b['CODCUE']]) ? $den[(int) $b['CODCUE']] : '');
    return $c ? $c : ((int) $a['NUMMOV'] - (int) $b['NUMMOV']);
});

$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Remitos Acreedores Pendientes', 'bi-box-arrow-in-down', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Cuenta (cód.)</label><input type="number" name="cue" value="<?= h((string) $cue) ?>" class="form-control form-control-sm" placeholder="(Todas)"></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">REMITOS ACREEDORES PENDIENTES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:5.6cm"><col style="width:1.6cm"><col style="width:3.4cm"><col style="width:3.4cm"><col style="width:5.0cm"></colgroup>
    <thead><tr><th>Cuenta Corriente</th><th class="r">Nº Mov</th><th>Remito Proveedor</th><th>Comprobante Interno</th><th>Detalle</th></tr></thead>
    <tbody>
      <?php $pcue = null; $cnt = 0; foreach ($rows as $r): $cc = (int) $r['CODCUE']; $cnt++;
        $remP = str_pad((string) (int) nz($r['CEPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CENMOV'], 0), 8, '0', STR_PAD_LEFT);
        $intP = str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT); ?>
      <?php if ($cc !== $pcue): $pcue = $cc; ?>
      <tr class="parent"><td colspan="5"><?= h(isset($den[$cc]) ? $den[$cc] : '') ?></td></tr>
      <?php endif; ?>
      <tr>
        <td></td>
        <td class="r mono"><?= str_pad((string) (int) $r['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td>
        <td class="mono"><?= h($remP) ?> · <?= h(fecha_serial($r['CEFMOV'])) ?></td>
        <td class="mono"><?= h($intP) ?> · <?= h(fecha_serial($r['FEXMOV'])) ?></td>
        <td><?= h(trim((string) nz($r['DETMOV'], ''))) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!count($rows)): ?>
      <tr><td colspan="5" class="text-muted">No hay remitos pendientes en el período.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
