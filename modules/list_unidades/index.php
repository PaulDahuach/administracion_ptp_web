<?php
/** Listado de Unidades de Medida (Rpt SI Unidades de Medida). DENOMINACION + DECIMALES + CODIGO. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$rows = db_query("SELECT DENUDM, DECUDM, CODUDM FROM [Tbl Unidades de Medida] ORDER BY DENUDM;");
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Unidades de Medida', 'bi-rulers', $toolbar);
?>
<link href="../../assets/css/listado.css?v=24" rel="stylesheet">
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">UNIDADES DE MEDIDA</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-tight" style="table-layout:fixed; width:19.0cm">
    <colgroup><col style="width:15.6cm"><col style="width:2.0cm"><col style="width:1.4cm"></colgroup>
    <thead><tr><th>Denominación</th><th class="r">Decimales</th><th class="r">Código</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h(trim((string) nz($r['DENUDM'], ''))) ?></td>
        <td class="r mono"><?= (int) nz($r['DECUDM'], 0) ?></td>
        <td class="r mono"><?= str_pad((string) (int) $r['CODUDM'], 8, '0', STR_PAD_LEFT) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
