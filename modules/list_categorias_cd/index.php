<?php
/** Listado de Categorías de Cuentas Corrientes (Deudores) — Rpt CD Categorias Cuentas.
 *  DENOMINACIÓN + % DESCUENTO (LDPCAT) + CÓDIGO. Tbl Categorias Cuentas Corrientes WHERE CODORI='D'. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$rows = db_query("SELECT DENCAT, LDPCAT, CODCAT FROM [Tbl Categorias Cuentas Corrientes] WHERE CODORI='D' ORDER BY DENCAT;");
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Categorías (Deudores)', 'bi-tags', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<style>.cat-tbl thead th { text-align: center; }</style>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">CATEGORIAS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-tight cat-tbl" style="table-layout:fixed; width:19.0cm">
    <colgroup><col style="width:15.0cm"><col style="width:2.4cm"><col style="width:1.6cm"></colgroup>
    <thead><tr><th>Denominación</th><th>% Descuento</th><th>Código</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h(trim((string) nz($r['DENCAT'], ''))) ?></td>
        <td class="r mono"><?= number_format((float) nz($r['LDPCAT'], 0), 2, '.', ',') ?></td>
        <td class="r mono"><?= str_pad((string) (int) $r['CODCAT'], 8, '0', STR_PAD_LEFT) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
