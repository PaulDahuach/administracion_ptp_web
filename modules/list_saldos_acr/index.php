<?php
/** Listado de Saldos Acreedores (Rpt CA Saldos). Por cuenta acreedora con movimientos: Fecha Última
 *  Operación (FUOCUE) + Saldo Anticipos (Σ SDOMOV>0) + Saldo Operativo (ΣDEBMOV−ΣCREMOV). Respeta el
 *  modo doble-libro (auth_libro_unico → ESTMOV). Ordenado por denominación; totales al pie. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function f2($v) { return number_format((float) $v, 2, '.', ','); }

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND ESTMOV=True' : (($lib === 'capacitacion') ? ' AND ESTMOV=False' : '');

// saldos calculados por cuenta (sólo cuentas con movimientos = INNER JOIN del legacy)
$agg = array();
foreach (db_query("SELECT CODCUE, SUM(DEBMOV) AS D, SUM(CREMOV) AS C, SUM(IIF(SDOMOV>0,SDOMOV,0)) AS SAN
    FROM [Tbl Movimientos] WHERE CODORI='A'$estW GROUP BY CODCUE;") as $r)
    $agg[(int) $r['CODCUE']] = array('san' => (float) nz($r['SAN'], 0), 'sop' => (float) nz($r['D'], 0) - (float) nz($r['C'], 0));

// maestro (denominación + fecha última operación cacheada)
$rows = array();
foreach (db_query("SELECT CODCUE, DENCUE, FUOCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A' ORDER BY DENCUE;") as $c) {
    $cc = (int) $c['CODCUE'];
    if (!isset($agg[$cc])) continue;
    $rows[] = array('cod' => $cc, 'den' => trim((string) nz($c['DENCUE'], '')), 'fuo' => $c['FUOCUE'],
                    'san' => $agg[$cc]['san'], 'sop' => $agg[$cc]['sop']);
}

$tSan = 0.0; $tSop = 0.0; foreach ($rows as $r) { $tSan += $r['san']; $tSop += $r['sop']; }
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Saldos Acreedores', 'bi-cash-stack', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<style>.sac-tbl thead th { text-align: center; vertical-align: middle; }</style>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">SALDOS ACREEDORES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params"><span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span></div>
  <table class="lst-tbl lst-tight sac-tbl" style="table-layout:fixed; width:19.0cm">
    <colgroup><col style="width:12.0cm"><col style="width:1.4cm"><col style="width:1.6cm"><col style="width:2.0cm"><col style="width:2.0cm"></colgroup>
    <thead>
      <tr>
        <th rowspan="2">Denominación</th>
        <th rowspan="2">Código</th>
        <th rowspan="2">Fecha Última Operación</th>
        <th colspan="2">Saldo</th>
      </tr>
      <tr><th>Anticipos</th><th>Operativo</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['den']) ?></td>
        <td class="r mono"><?= str_pad((string) $r['cod'], 8, '0', STR_PAD_LEFT) ?></td>
        <td class="r"><?= h(fecha_serial($r['fuo'])) ?></td>
        <td class="r mono"><?= f2($r['san']) ?></td>
        <td class="r mono"><?= f2($r['sop']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="tot">
      <td colspan="3">TOTAL: <?= count($rows) ?></td>
      <td class="r mono"><?= f2($tSan) ?></td><td class="r mono"><?= f2($tSop) ?></td>
    </tr></tfoot>
  </table>
</div>
<?php module_foot(); ?>
