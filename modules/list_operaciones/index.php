<?php
/** Listado de Operaciones (Rpt IC Operaciones) — reporte tabular ancho: comprobante (checkboxes
 *  interno/externo) + auxiliares + modelos de imputación, en columnas alineadas. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_es_true($v) { return $v === true || $v === -1 || $v === '-1' || $v === 1 || $v === '1'; }
function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }
function cbx($b) { return lo_es_true($b) ? '<span class="cbx on"></span>' : '<span class="cbx"></span>'; }
function pct($v) { return ($v !== null && $v !== '') ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : ''; }

// maps de cuentas / centros
$cta = array(); foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Contables];") as $r) $cta[trim((string) $r['CODCUE'])] = trim((string) nz($r['DENCUE'], ''));
$cdc = array(); foreach (db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo];") as $r) $cdc[(int) $r['CODCDC']] = trim((string) nz($r['DENCDC'], ''));

// auxiliares por operación
$auxOp = array();
foreach (db_query("SELECT CODOPE, CODAUX, DENAUX, IVAAUX, CODCUE FROM [Tbl Operaciones Auxiliares] ORDER BY CODOPE, CODAUX;") as $a) {
    $op = (int) $a['CODOPE']; if (!isset($auxOp[$op])) $auxOp[$op] = array();
    $cc = trim((string) nz($a['CODCUE'], ''));
    $auxOp[$op][] = array('cod' => trim((string) nz($a['CODAUX'], '')), 'den' => trim((string) nz($a['DENAUX'], '')),
        'cuenta' => $cc !== '' ? lo_niv($cc) : '');
}
// modelos (con sus imputaciones) por operación
$modName = array();
foreach (db_query("SELECT CODMOD, CODOPE, DENMOD FROM [Tbl Modelos] ORDER BY CODOPE, CODMOD;") as $m) $modName[(int) $m['CODMOD']] = array('op' => (int) $m['CODOPE'], 'den' => trim((string) nz($m['DENMOD'], '')));
$modOp = array();   // [op] => [ ['den'=>.., 'imps'=>[ ['cuenta','centro','debe','haber'] ] ] ]
foreach ($modName as $cm => $mn) {
    if (!isset($modOp[$mn['op']])) $modOp[$mn['op']] = array();
    $imps = array();
    foreach (db_query("SELECT CODCUE, CODCDC, PDBMOD, PHBMOD FROM [Tbl Modelos Imputaciones] WHERE CODMOD=$cm ORDER BY ORDMOD;") as $i) {
        $cc = trim((string) nz($i['CODCUE'], ''));
        $imps[] = array('cuenta' => lo_niv($cc), 'centro' => isset($cdc[(int) nz($i['CODCDC'], 0)]) ? $cdc[(int) $i['CODCDC']] : '',
            'debe' => pct($i['PDBMOD']), 'haber' => pct($i['PHBMOD']));
    }
    $modOp[$mn['op']][] = array('den' => $mn['den'], 'imps' => $imps);
}

$ops = db_query("SELECT CODOPE, DENOPE, ICCOPE, ICIOPE, ICPOPE, ICNOPE, IVAOPE, CITOPE, RSXOPE, ICEOPE, ICROPE, ICXOPE FROM [Tbl Operaciones] ORDER BY CODOPE;");

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Operaciones', 'bi-gear-wide-connected', $toolbar);
?>
<link href="../../assets/css/listado.css?v=23" rel="stylesheet">
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">OPERACIONES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl lst-ops">
    <colgroup>
      <col style="width:0.7cm"><col style="width:3.1cm">
      <col style="width:.46cm"><col style="width:.42cm"><col style="width:.46cm"><col style="width:.52cm">
      <col style="width:.46cm"><col style="width:.46cm"><col style="width:.46cm"><col style="width:.56cm">
      <col style="width:.42cm"><col style="width:.42cm">
      <col style="width:0.6cm"><col style="width:3.2cm"><col style="width:1.3cm">
      <col style="width:1.25cm"><col style="width:2.05cm"><col style="width:.8cm"><col style="width:.8cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="3">Cód</th><th rowspan="3" class="l">Denominación</th>
        <th colspan="10">Comprobante</th>
        <th colspan="3">Auxiliares</th>
        <th colspan="4">Modelos de Imputación</th>
      </tr>
      <tr>
        <th colspan="4">Interno</th><th colspan="4">Externo</th><th rowspan="2">CO</th><th rowspan="2">INT</th>
        <th rowspan="2">Cód</th><th rowspan="2" class="l">Denominación</th><th rowspan="2" class="l">Cuenta</th>
        <th rowspan="2" class="l">Cuenta</th><th rowspan="2" class="l">Centro de Costo</th><th rowspan="2" class="r">Debe</th><th rowspan="2" class="r">Haber</th>
      </tr>
      <tr>
        <th>Cód</th><th>Ide</th><th>PdV</th><th>Núm</th>
        <th>Gra</th><th>CUIT</th><th>Raz</th><th>Núm</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($ops as $r): $op = (int) $r['CODOPE']; $auxs = isset($auxOp[$op]) ? $auxOp[$op] : array(); $mods = isset($modOp[$op]) ? $modOp[$op] : array(); ?>
      <tr>
        <td class="mono c">#<?= $op ?></td>
        <td class="op-den"><?= h(trim((string) nz($r['DENOPE'], ''))) ?></td>
        <td class="c mono"><?= h(trim((string) nz($r['ICCOPE'], ''))) ?></td>
        <td class="c"><?= cbx($r['ICIOPE']) ?></td>
        <td class="c"><?= cbx($r['ICPOPE']) ?></td>
        <td class="c"><?= cbx(trim((string) nz($r['ICNOPE'], 'N')) !== 'N' ? 1 : 0) ?></td>
        <td class="c"><?= cbx($r['IVAOPE']) ?></td>
        <td class="c"><?= cbx($r['CITOPE']) ?></td>
        <td class="c"><?= cbx($r['RSXOPE']) ?></td>
        <td class="c"><?= cbx($r['ICEOPE']) ?></td>
        <td class="c"><?= cbx($r['ICROPE']) ?></td>
        <td class="c"><?= cbx($r['ICXOPE']) ?></td>
        <td colspan="3" class="nest">
          <?php if ($auxs): ?><table class="sub"><colgroup><col style="width:0.6cm"><col style="width:3.2cm"><col style="width:1.3cm"></colgroup>
            <?php foreach ($auxs as $a): ?><tr><td class="mono"><?= h($a['cod']) ?></td><td><?= h($a['den']) ?></td><td class="mono"><?= h($a['cuenta']) ?></td></tr><?php endforeach; ?>
          </table><?php endif; ?>
        </td>
        <td colspan="4" class="nest">
          <?php if ($mods): ?><table class="sub"><colgroup><col style="width:1.25cm"><col style="width:2.05cm"><col style="width:.8cm"><col style="width:.8cm"></colgroup>
            <?php foreach ($mods as $md): ?>
              <?php if ($md['den'] !== ''): ?><tr><td colspan="4" class="mod-h"><?= h($md['den']) ?></td></tr><?php endif; ?>
              <?php foreach ($md['imps'] as $im): ?><tr><td class="mono"><?= h($im['cuenta']) ?></td><td><?= h($im['centro']) ?></td><td class="r"><?= h($im['debe']) ?></td><td class="r"><?= h($im['haber']) ?></td></tr><?php endforeach; ?>
            <?php endforeach; ?>
          </table><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($ops) ?></div>
</div>
<?php module_foot(); ?>
