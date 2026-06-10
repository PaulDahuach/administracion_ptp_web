<?php
/** Listado de Bancos (Rpt IC Bancos) — listado imprimible del maestro de bancos. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$rows = db_query("SELECT DENBAN, CITBAN, CODBAN FROM [Tbl Bancos] ORDER BY DENBAN;");

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Bancos', 'bi-bank', $toolbar);
?>
<link href="../../assets/css/listado.css?v=2" rel="stylesheet">

<div class="lst-doc">
  <div class="lst-head">
    <div><div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div><div class="lst-tit">BANCOS</div></div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl">
    <thead><tr><th>Denominación</th><th class="r">C.U.I.T. Nº</th><th class="r" style="width:7rem">Código</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h(trim((string) nz($r['DENBAN'], ''))) ?></td>
        <td class="r"><?= h(trim((string) nz($r['CITBAN'], ''))) ?></td>
        <td class="r mono"><?= str_pad((string) (int) $r['CODBAN'], 8, '0', STR_PAD_LEFT) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
