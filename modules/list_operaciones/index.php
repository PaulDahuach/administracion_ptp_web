<?php
/** Listado de Operaciones (Rpt IC Operaciones) — config de comprobante + auxiliares + modelos de imputación. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function lo_es_true($v) { return $v === true || $v === -1 || $v === '-1' || $v === 1 || $v === '1'; }
function lo_niv($s) { $s = trim((string) $s); if ($s === '' || !ctype_digit($s)) return $s; $L = array(1, 1, 1, 2, 2); $out = array(); $i = 0; foreach ($L as $n) { if ($i >= strlen($s)) break; $out[] = substr($s, $i, $n); $i += $n; } if ($i < strlen($s)) $out[] = substr($s, $i); return implode('.', $out); }

// maps
$cta = array(); foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Contables];") as $r) $cta[trim((string) $r['CODCUE'])] = trim((string) nz($r['DENCUE'], ''));
$cdc = array(); foreach (db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo];") as $r) $cdc[(int) $r['CODCDC']] = trim((string) nz($r['DENCDC'], ''));

// auxiliares por operación
$auxOp = array();
foreach (db_query("SELECT CODOPE, CODAUX, DENAUX, CODCUE FROM [Tbl Operaciones Auxiliares] ORDER BY CODOPE, CODAUX;") as $a) {
    $op = (int) $a['CODOPE']; if (!isset($auxOp[$op])) $auxOp[$op] = array();
    $cc = trim((string) nz($a['CODCUE'], ''));
    $auxOp[$op][] = array('cod' => trim((string) nz($a['CODAUX'], '')), 'den' => trim((string) nz($a['DENAUX'], '')),
        'cuenta' => $cc !== '' ? lo_niv($cc) : '', 'cuentaDen' => ($cc !== '' && isset($cta[$cc])) ? $cta[$cc] : '');
}
// modelos (imputaciones) por operación
$modOp = array();
foreach (db_query("SELECT M.CODOPE, MI.CODCUE, MI.CODCDC, MI.PDBMOD, MI.PHBMOD FROM [Tbl Modelos] AS M
    INNER JOIN [Tbl Modelos Imputaciones] AS MI ON M.CODMOD=MI.CODMOD ORDER BY M.CODOPE, MI.CODMOD, MI.ORDMOD;") as $i) {
    $op = (int) $i['CODOPE']; if (!isset($modOp[$op])) $modOp[$op] = array();
    $cc = trim((string) nz($i['CODCUE'], ''));
    $modOp[$op][] = array('cuenta' => lo_niv($cc), 'cuentaDen' => isset($cta[$cc]) ? $cta[$cc] : '',
        'centro' => isset($cdc[(int) nz($i['CODCDC'], 0)]) ? $cdc[(int) $i['CODCDC']] : '',
        'debe' => ($i['PDBMOD'] !== null && $i['PDBMOD'] !== '') ? number_format((float) $i['PDBMOD'], 2, '.', ',') : '',
        'haber' => ($i['PHBMOD'] !== null && $i['PHBMOD'] !== '') ? number_format((float) $i['PHBMOD'], 2, '.', ',') : '');
}

$ops = db_query("SELECT CODOPE, DENOPE, ICCOPE, CUEOPE, ICIOPE, ICPOPE, IVAOPE, CITOPE, RSXOPE, ICEOPE, ICROPE FROM [Tbl Operaciones] ORDER BY CODOPE;");

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Operaciones', 'bi-gear-wide-connected', $toolbar);

function flags_interno($r) {
    $f = array(); if (lo_es_true($r['CUEOPE'])) $f[] = 'Cta.Cte'; if (lo_es_true($r['ICIOPE'])) $f[] = 'Identif.'; if (lo_es_true($r['ICPOPE'])) $f[] = 'P.Venta';
    return $f;
}
function flags_externo($r) {
    $f = array(); if (lo_es_true($r['IVAOPE'])) $f[] = 'Gravado'; if (lo_es_true($r['CITOPE'])) $f[] = 'C.U.I.T.'; if (lo_es_true($r['RSXOPE'])) $f[] = 'Razón Social'; if (lo_es_true($r['ICEOPE'])) $f[] = 'Nº'; if (lo_es_true($r['ICROPE'])) $f[] = 'Constancia';
    return $f;
}
?>
<link href="../../assets/css/listado.css?v=2" rel="stylesheet">
<div class="lst-doc">
  <div class="lst-head">
    <div><div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div><div class="lst-tit">OPERACIONES</div></div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <table class="lst-tbl">
    <thead><tr><th style="width:3rem">Cód</th><th>Denominación</th><th>Comprobante</th></tr></thead>
    <tbody>
      <?php foreach ($ops as $r): $op = (int) $r['CODOPE']; $fi = flags_interno($r); $fe = flags_externo($r); ?>
      <tr>
        <td class="mono"><?= $op ?></td>
        <td>
          <b><?= h(trim((string) nz($r['DENOPE'], ''))) ?></b>
          <?php if (!empty($auxOp[$op])): ?>
            <div class="lst-sub"><div class="lst-sub-h">Auxiliares</div>
              <?php foreach ($auxOp[$op] as $a): ?>
                <div><span class="mono"><?= h($a['cod']) ?></span> <?= h($a['den']) ?><?= $a['cuenta'] !== '' ? ' <span class="text-muted">· ' . h($a['cuenta'] . ($a['cuentaDen'] ? ' ' . $a['cuentaDen'] : '')) . '</span>' : '' ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($modOp[$op])): ?>
            <div class="lst-sub"><div class="lst-sub-h">Modelo de imputación</div>
              <?php foreach ($modOp[$op] as $m): ?>
                <div><span class="mono"><?= h($m['cuenta']) ?></span> <?= h($m['cuentaDen']) ?><?= $m['centro'] ? ' · ' . h($m['centro']) : '' ?><?= $m['debe'] !== '' ? ' · Debe ' . h($m['debe']) : '' ?><?= $m['haber'] !== '' ? ' · Haber ' . h($m['haber']) : '' ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </td>
        <td>
          <span class="mono"><?= h(trim((string) nz($r['ICCOPE'], ''))) ?></span>
          <?php if ($fi): ?><div class="lst-sub-h">Interno: <?= h(implode(' · ', $fi)) ?></div><?php endif; ?>
          <?php if ($fe): ?><div class="lst-sub-h">Externo: <?= h(implode(' · ', $fe)) ?></div><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="lst-tot">TOTAL ENTIDADES: <?= count($ops) ?></div>
</div>
<?php module_foot(); ?>
