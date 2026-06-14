<?php
/** Listado de Precios de Venta Exclusivos (Rpt SI Exclusiones Precios de Venta). Precios pactados por
 *  cuenta para un producto (Tbl Cuentas Corrientes Exclusiones). Agrupado por Cuenta. Filtro: Cuenta.
 *  NOTA: en este backend la tabla está vacía (nunca se cargaron exclusiones). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function f4($v) { return number_format((float) $v, 4, '.', ','); }

$cue = isset($_GET['cue']) && ctype_digit((string) $_GET['cue']) ? (int) $_GET['cue'] : '';
$where = "C.CODORI='D'";
if ($cue !== '') $where .= " AND E.CODCUE=" . $cue;

$rows = db_query("SELECT E.CODCUE, C.DENCUE, E.CODPRO, P.DENPRO, U.DENUDM, M.SIMMON, E.PUNPRO
    FROM [Tbl Unidades de Medida] AS U RIGHT JOIN (([Tbl Monedas] AS M INNER JOIN [Tbl Productos] AS P ON M.CODMON=P.CODMON)
      INNER JOIN ([Tbl Cuentas Corrientes] AS C INNER JOIN [Tbl Cuentas Corrientes Exclusiones] AS E ON C.CODCUE=E.CODCUE)
      ON P.CODPRO=E.CODPRO) ON U.CODUDM=P.CODUDM
    WHERE $where ORDER BY E.CODCUE, P.DENPRO;");

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Precios de Venta Exclusivos', 'bi-cash-coin', $toolbar);
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair"><label>Cuenta (código)</label>
    <input type="number" name="cue" value="<?= h((string) $cue) ?>" class="form-control form-control-sm" placeholder="(Todas)"></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">PRECIOS DE VENTA EXCLUSIVOS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup><col style="width:10.5cm"><col style="width:4.0cm"><col style="width:4.0cm"></colgroup>
    <thead><tr><th>Producto</th><th>Unidad</th><th class="r">Precio Unitario Neto</th></tr></thead>
    <tbody>
      <?php $pcue = null; foreach ($rows as $r): $cc = (int) $r['CODCUE'];
        if ($cc !== $pcue): $pcue = $cc; ?>
      <tr class="parent"><td colspan="3"><span class="mono"><?= str_pad((string) $cc, 8, '0', STR_PAD_LEFT) ?></span> <?= h(trim((string) nz($r['DENCUE'], ''))) ?></td></tr>
      <?php endif; ?>
      <tr>
        <td><?= h(trim((string) nz($r['DENPRO'], ''))) ?></td>
        <td><?= h(trim((string) nz($r['DENUDM'], ''))) ?></td>
        <td class="r mono"><?= h(trim((string) nz($r['SIMMON'], ''))) ?> <?= f4($r['PUNPRO']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!count($rows)): ?>
      <tr><td colspan="3" class="text-muted">Sin exclusiones de precio cargadas.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
