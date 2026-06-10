<?php
/** Listado de Plan de Cuentas (Rpt IC Plan de Cuentas) — árbol jerárquico + flags (checkbox) + cuenta bancaria. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function cbx($b) { return ($b === true || $b == -1) ? '<span class="cbx on"></span>' : '<span class="cbx"></span>'; }
function niv_count($r) { $n = 0; foreach (array('CN1CUE', 'CN2CUE', 'CN3CUE', 'CN4CUE', 'CN5CUE') as $c) if (trim((string) nz($r[$c], '')) !== '') $n++; return $n; }

$cbx_map = array();
foreach (db_query("SELECT CODCBX, DENCBX FROM [Tbl Cuentas Bancarias];") as $b) $cbx_map[(int) $b['CODCBX']] = trim((string) nz($b['DENCBX'], ''));

$rows = db_query("SELECT CODCUE, CN1CUE, CN2CUE, CN3CUE, CN4CUE, CN5CUE, DENCUE, IMPCUE, CONCUE, DECCUE, CCCCUE, GASCUE, DISCUE, CODCBX FROM [Tbl Cuentas Contables] ORDER BY CODCUE;");

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Plan de Cuentas', 'bi-list-nested', $toolbar);
?>
<link href="../../assets/css/listado.css?v=23" rel="stylesheet">
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">PLAN DE CUENTAS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup>
      <col style="width:9.0cm"><col style="width:3.4cm">
      <col style="width:1.3cm"><col style="width:1.2cm"><col style="width:1.4cm"><col style="width:1.1cm"><col style="width:1.2cm">
    </colgroup>
    <thead><tr>
      <th>Cuenta</th><th>Cuenta Bancaria</th>
      <th>Consolidada</th><th>Decimales</th><th>Conciliable</th><th>Gastos</th><th>Discont.</th>
    </tr></thead>
    <tbody>
      <?php foreach ($rows as $r):
        $niv = niv_count($r); $imp = ($r['IMPCUE'] === true || $r['IMPCUE'] == -1);
        $cod = lo_niv(trim((string) nz($r['CODCUE'], ''))); $cb = (int) nz($r['CODCBX'], 0); ?>
      <tr class="<?= $imp ? '' : 'parent' ?>">
        <td><span class="cod" style="padding-left:<?= ($niv - 1) * 11 ?>px"><?= h($cod) ?></span> <?= h(trim((string) nz($r['DENCUE'], ''))) ?></td>
        <td><?= ($cb && isset($cbx_map[$cb])) ? h($cbx_map[$cb]) : '' ?></td>
        <td class="c"><?= $imp ? cbx($r['CONCUE']) : '' ?></td>
        <td class="c"><?= $imp ? cbx($r['DECCUE']) : '' ?></td>
        <td class="c"><?= $imp ? cbx($r['CCCCUE']) : '' ?></td>
        <td class="c"><?= $imp ? cbx($r['GASCUE']) : '' ?></td>
        <td class="c"><?= $imp ? cbx($r['DISCUE']) : '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($rows) ?></div>
</div>
<?php module_foot(); ?>
