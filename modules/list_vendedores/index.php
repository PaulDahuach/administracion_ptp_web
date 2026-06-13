<?php
/** Listado de Vendedores (Rpt CD Vendedores). DENOMINACIÓN + CÓDIGO. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$rows = db_query("SELECT DENVEN, CODVEN FROM [Tbl Vendedores] ORDER BY DENVEN;");
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Vendedores', 'bi-person-badge', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<style>.cd-tbl thead th { text-align: center; }</style>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">VENDEDORES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-tight cd-tbl" style="table-layout:fixed; width:19.0cm">
    <colgroup><col style="width:17.4cm"><col style="width:1.6cm"></colgroup>
    <thead><tr><th>Denominación</th><th>Código</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h(trim((string) nz($r['DENVEN'], ''))) ?></td>
        <td class="r mono"><?= str_pad((string) (int) $r['CODVEN'], 8, '0', STR_PAD_LEFT) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
