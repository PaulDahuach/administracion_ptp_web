<?php
/** Listado de Cuentas Corrientes Acreedoras (Rpt CA Cuentas). Maestro: Denominación + Código,
 *  ordenado por denominación. Porta SELECT CODCUE/DENCUE FROM Tbl Cuentas Corrientes WHERE CODORI='A'. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$rows = db_query("SELECT DENCUE, CODCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A' ORDER BY DENCUE;");
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Cuentas Corrientes Acreedoras', 'bi-person-vcard', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">CUENTAS CORRIENTES ACREEDORAS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-tight" style="table-layout:fixed; width:19.0cm">
    <colgroup><col style="width:17.0cm"><col style="width:2.0cm"></colgroup>
    <thead><tr><th>Denominación</th><th class="r">Código</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr><td><?= h(trim((string) nz($r['DENCUE'], ''))) ?></td><td class="r mono"><?= str_pad((string) (int) $r['CODCUE'], 8, '0', STR_PAD_LEFT) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
