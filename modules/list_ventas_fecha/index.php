<?php
/** Listado Ventas x Fecha (Rpt CD Ventas x Fecha). Lista plana de comprobantes de venta
 *  (CODOPE 420=FV, 440=ND, 460=NC) del período, ordenados por fecha. NC negada (qryTot). NIVEL D/T.
 *  Filtros: período (FEXMOV, default rec_periodo). Modo doble-libro (auth_libro_unico). Geometría fiel. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function vf_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = vf_serial($desIso); $sh = vf_serial($hasIso);
$niv = (isset($_GET['nivel']) && strtoupper($_GET['nivel']) === 'T') ? 'T' : 'D';

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND ESTMOV=True' : (($lib === 'capacitacion') ? ' AND ESTMOV=False' : '');

$rows = db_query("SELECT FEXMOV, NUMMOV, CICMOV, CIIMOV, CIPMOV, CINMOV, DENMOV, DETMOV, CODOPE, TOTMOV
    FROM [Tbl Movimientos] WHERE FEXMOV >= $sd AND FEXMOV <= $sh AND (CODOPE=420 OR CODOPE=440 OR CODOPE=460)$estW
    ORDER BY FEXMOV, NUMMOV;");

$tot = 0.0; $det = array();
foreach ($rows as $r) {
    $sg = ((int) $r['CODOPE'] === 460) ? -1 : 1;
    $t = $sg * (float) nz($r['TOTMOV'], 0);
    $tot += $t;
    $pdv = (int) nz($r['CIPMOV'], 0);
    $det[] = array(
        'FECHA' => fecha_serial($r['FEXMOV']),
        'COD'   => trim((string) nz($r['CICMOV'], '')),
        'ID'    => trim((string) nz($r['CIIMOV'], '')),
        'PDV'   => $pdv > 0 ? str_pad((string) $pdv, 4, '0', STR_PAD_LEFT) : '',
        'NRO'   => str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        'DEN'   => trim((string) nz($r['DENMOV'], '')),
        'DET'   => trim((string) nz($r['DETMOV'], '')),
        'TOT'   => $t,
    );
}

$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Ventas x Fecha', 'bi-calendar-week', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<style>
  @media print { @page { size: Letter portrait; margin: 12mm; } .lst-doc { width: auto; box-shadow: none; } }
  .vf-doc { font-family: "Univers Condensed", "Arial Narrow", sans-serif; }
  .vf-tbl { width: 18.8cm; border-collapse: collapse; table-layout: fixed; }
  .vf-tbl thead th { font-size: 7pt; font-weight: 400; text-align: center; vertical-align: middle;
    border: 1px solid #000; background: #c0c0c0; line-height: 1.05; padding: 1px 2px; }
  .vf-tbl tbody td { font-size: 8pt; height: .34cm; line-height: .34cm; padding: 0 2px; vertical-align: top; white-space: nowrap; }
  .vf-tbl td.w { white-space: normal; word-break: break-word; }
  .vf-tbl .r { text-align: right; } .vf-tbl .c { text-align: center; } .vf-tbl .l { text-align: left; }
  .vf-tbl tfoot td { font-size: 8pt; font-weight: 700; padding: 1px 2px; border-top: 1px solid #000; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Nivel</label>
      <select name="nivel" class="form-select form-select-sm">
        <option value="D"<?= $niv === 'D' ? ' selected' : '' ?>>Detalle</option>
        <option value="T"<?= $niv === 'T' ? ' selected' : '' ?>>Total</option>
      </select></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>

<div class="lst-doc vf-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">VENTAS x FECHA</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
  </div>
  <table class="vf-tbl">
    <colgroup>
      <col style="width:1.24cm"><col style="width:0.47cm"><col style="width:0.40cm"><col style="width:0.59cm">
      <col style="width:1.13cm"><col style="width:6.38cm"><col style="width:6.61cm"><col style="width:1.98cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">FECHA</th>
        <th colspan="4">COMPROBANTE</th>
        <th rowspan="2">CUENTA CORRIENTE</th>
        <th rowspan="2">DETALLE</th>
        <th rowspan="2">TOTAL</th>
      </tr>
      <tr><th>COD</th><th>ID</th><th>PDV</th><th>NUMERO</th></tr>
    </thead>
    <?php if ($niv !== 'T'): ?>
    <tbody>
      <?php foreach ($det as $d): ?>
      <tr>
        <td class="l"><?= h($d['FECHA']) ?></td>
        <td class="l"><?= h($d['COD']) ?></td>
        <td class="l"><?= h($d['ID']) ?></td>
        <td class="l"><?= h($d['PDV']) ?></td>
        <td class="l"><?= h($d['NRO']) ?></td>
        <td class="l w"><?= h($d['DEN']) ?></td>
        <td class="l w"><?= h($d['DET']) ?></td>
        <td class="r"><?= money($d['TOT']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$det): ?>
      <tr><td colspan="8" class="l" style="padding:.4cm">Sin comprobantes de venta en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <?php endif; ?>
    <tfoot>
      <tr>
        <td class="l" colspan="7">TOTAL MOVIMIENTOS: <?= count($det) ?></td>
        <td class="r"><?= money($tot) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php module_foot(); ?>
