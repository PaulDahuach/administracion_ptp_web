<?php
/** Listado de Líneas (Rpt SI Lineas). Maestro simple: DENOMINACION + CODIGO. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$rows = db_query("SELECT DENLIN, CODLIN FROM [Tbl Lineas] ORDER BY DENLIN;");
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Líneas', 'bi-bookmark', $toolbar);
?>
<link href="../../assets/css/listado.css?v=24" rel="stylesheet">
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">LÍNEAS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-tight" style="table-layout:fixed; width:19.0cm">
    <colgroup><col style="width:17.6cm"><col style="width:1.4cm"></colgroup>
    <thead><tr><th>Denominación</th><th class="r">Código</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr><td><?= h(trim((string) nz($r['DENLIN'], ''))) ?></td><td class="r mono"><?= str_pad((string) (int) $r['CODLIN'], 8, '0', STR_PAD_LEFT) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
