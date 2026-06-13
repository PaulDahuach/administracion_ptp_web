<?php
/** Listado de Cuentas Bancarias (Rpt IC Cuentas Bancarias). DENCBX / CCDCBX (copias) / DISCBX (discont) / CODCBX. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$rows = db_query("SELECT DENCBX, CCDCBX, DISCBX, CODCBX FROM [Tbl Cuentas Bancarias] ORDER BY DENCBX;");
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Cuentas Bancarias', 'bi-bank2', $toolbar);
function cbx($b) { return ($b === true || $b == -1) ? '<span class="cbx on"></span>' : '<span class="cbx"></span>'; }
?>
<link href="../../assets/css/listado.css?v=24" rel="stylesheet">
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">CUENTAS BANCARIAS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-tight" style="table-layout:fixed; width:19.0cm">
    <colgroup><col style="width:12.3cm"><col style="width:3.0cm"><col style="width:2.3cm"><col style="width:1.4cm"></colgroup>
    <thead><tr><th>Denominación</th><th class="text-center">Copias Constancia Depósito</th><th class="text-center">Discontinuada</th><th class="r">Código</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h(trim((string) nz($r['DENCBX'], ''))) ?></td>
        <td class="text-center mono"><?= (int) nz($r['CCDCBX'], 0) ?></td>
        <td class="text-center"><?= cbx($r['DISCBX']) ?></td>
        <td class="r mono"><?= str_pad((string) (int) $r['CODCBX'], 8, '0', STR_PAD_LEFT) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
