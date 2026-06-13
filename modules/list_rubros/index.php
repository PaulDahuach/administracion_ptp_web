<?php
/** Listado de Rubros (Rpt SI Rubros). Dos paneles: Rubro (izq) + sus Subrubros (der).
 *  Cada uno: Denominación · Cuenta Contable Compras/Ventas (NivCue) · % Utilidad · Dis · Código.
 *  TOTAL ENTIDADES = cantidad de rubros (como el legacy). Orden por DENRUB, subrubros por DENSUB. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function cbx($b) { return ($b === true || $b == -1) ? '<span class="cbx on"></span>' : '<span class="cbx"></span>'; }
function pu($v) { $v = nz($v, ''); return ($v === '' || $v === null) ? '' : number_format((float) $v, 2, '.', ''); }
function cod4($v) { return str_pad((string) (int) $v, 4, '0', STR_PAD_LEFT); }

$rubros = db_query("SELECT CODRUB, DENRUB, CPARUB, VTARUB, PUNRUB, DISRUB FROM [Tbl Rubros] ORDER BY DENRUB;");
$subs = array();
foreach (db_query("SELECT CODSUB, CODRUB, DENSUB, CPASUB, VTASUB, PUNSUB, DISSUB FROM [Tbl SubRubros] ORDER BY DENSUB;") as $s) {
    $rk = (int) $s['CODRUB'];
    if (!isset($subs[$rk])) $subs[$rk] = array();
    $subs[$rk][] = $s;
}

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Rubros', 'bi-tag', $toolbar);
?>
<link href="../../assets/css/listado.css?v=24" rel="stylesheet">
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">RUBROS</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-jer">
    <colgroup>
      <col style="width:3.2cm"><col style="width:1.9cm"><col style="width:1.9cm"><col style="width:1.0cm"><col style="width:.7cm"><col style="width:.9cm">
      <col style="width:3.2cm"><col style="width:1.9cm"><col style="width:1.9cm"><col style="width:1.0cm"><col style="width:.7cm"><col style="width:.9cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">Denominación</th><th colspan="2">Cuenta Contable</th><th rowspan="2">% Util.</th><th rowspan="2">Dis</th><th rowspan="2">Código</th>
        <th rowspan="2">Subrubros</th><th colspan="2">Cuenta Contable</th><th rowspan="2">% Util.</th><th rowspan="2">Dis</th><th rowspan="2">Código</th>
      </tr>
      <tr><th>Compras</th><th>Ventas</th><th>Compras</th><th>Ventas</th></tr>
    </thead>
    <tbody>
      <?php foreach ($rubros as $r): $rk = (int) $r['CODRUB']; ?>
      <tr class="parent">
        <td><?= h(trim((string) nz($r['DENRUB'], ''))) ?></td>
        <td><?= h(lo_niv(nz($r['CPARUB'], ''))) ?></td>
        <td><?= h(lo_niv(nz($r['VTARUB'], ''))) ?></td>
        <td class="r"><?= pu($r['PUNRUB']) ?></td>
        <td class="c"><?= cbx($r['DISRUB']) ?></td>
        <td class="r mono"><?= cod4($r['CODRUB']) ?></td>
        <td></td><td></td><td></td><td></td><td></td><td></td>
      </tr>
      <?php if (isset($subs[$rk])) foreach ($subs[$rk] as $s): ?>
      <tr>
        <td></td><td></td><td></td><td></td><td></td><td></td>
        <td><?= h(trim((string) nz($s['DENSUB'], ''))) ?></td>
        <td><?= h(lo_niv(nz($s['CPASUB'], ''))) ?></td>
        <td><?= h(lo_niv(nz($s['VTASUB'], ''))) ?></td>
        <td class="r"><?= pu($s['PUNSUB']) ?></td>
        <td class="c"><?= cbx($s['DISSUB']) ?></td>
        <td class="r mono"><?= cod4($s['CODSUB']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($rubros) ?></div>
</div>
<?php module_foot(); ?>
