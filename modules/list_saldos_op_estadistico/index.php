<?php
/** Listado Saldos Operaciones Deudores Estadístico (Rpt CD Saldos Operaciones Estadistico). Variante
 *  estadística de Saldos Operaciones: movimientos con SDOMOV<>0 por cuenta, con columnas de antigüedad.
 *  qryAco=SDOMOV>0 · qryApa=−SDOMOV<0 · qryDias=DateDiff(FEXMOV,hasta) · qryAcoDias=qryAco·qryDias.
 *  Por cuenta: PROM.DIAS=Avg(qryDias), Σ(qryAcoDias), ponderada=Σ(qryAcoDias)/Avg(qryDias). Modo doble-libro.
 *  Período FEXMOV. Datos validados al centavo vs PDF (libro blanco). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function se_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }
function se_f2($v) { return number_format((float) $v, 2, '.', ','); }
function se_n($v) { return ((float) $v == 0.0) ? '' : se_f2($v); }

list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = se_serial($desIso); $sh = se_serial($hasIso);
$niv = (isset($_GET['nivel']) && strtoupper($_GET['nivel']) === 'T') ? 'T' : 'D';

$lib = auth_libro_unico();
$estW = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');

$den = array();
foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='D';") as $c)
    $den[(int) $c['CODCUE']] = trim((string) nz($c['DENCUE'], ''));

$movs = db_query("SELECT M.CODCUE, M.NUMMOV, M.FEXMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.SDOMOV
    FROM [Tbl Movimientos] AS M WHERE M.CODORI='D' AND M.SDOMOV<>0 AND M.FEXMOV >= $sd AND M.FEXMOV <= $sh$estW
    ORDER BY M.CODCUE, M.NUMMOV;");

// agrupar por cuenta (ordenadas por denominación)
$grp = array();
foreach ($movs as $m) { $k = (int) $m['CODCUE']; if (!isset($grp[$k])) $grp[$k] = array(); $grp[$k][] = $m; }
uksort($grp, function ($a, $b) use ($den) { return strcasecmp(isset($den[$a]) ? $den[$a] : '', isset($den[$b]) ? $den[$b] : ''); });

// totales generales
$Tn = 0; $Taco = 0.0; $Tapa = 0.0; $TacoDias = 0.0; $Tdias = 0.0;
foreach ($grp as $k => $lst) foreach ($lst as $m) {
    $sdo = (float) nz($m['SDOMOV'], 0); $aco = $sdo > 0 ? $sdo : 0.0;
    $dias = (int) ($sh - (int) $m['FEXMOV']);
    $Tn++; $Taco += $aco; $Tapa += $sdo < 0 ? -$sdo : 0.0; $TacoDias += $aco * $dias; $Tdias += $dias;
}
$Tavg = $Tn ? $Tdias / $Tn : 0;
$Tpond = $Tavg ? $TacoDias / $Tavg : 0;

$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Saldos Operaciones Deudores Estadístico', 'bi-graph-up', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<style>
  @media print { @page { size: Letter landscape; margin: 10mm; } .lst-doc { width: auto; box-shadow: none; } }
  .lst-doc.se-page { width: 277mm; min-height: 200mm; }
  .se-doc { font-family: "Univers Condensed", "Arial Narrow", sans-serif; }
  .se-tbl { width: 25.5cm; border-collapse: collapse; table-layout: fixed; }
  .se-tbl thead th { font-size: 7pt; font-weight: 400; text-align: center; vertical-align: middle;
    border: 1px solid #000; background: #c0c0c0; line-height: 1.05; padding: 1px 2px; }
  .se-tbl tbody td { font-size: 8pt; height: .33cm; line-height: .33cm; padding: 0 2px; vertical-align: top; white-space: nowrap; }
  .se-tbl .r { text-align: right; } .se-tbl .c { text-align: center; } .se-tbl .l { text-align: left; }
  .se-tbl tr.acc td { font-weight: 700; border-top: 1px solid #000; }
  .se-tbl td.w { white-space: normal; word-break: break-word; }
  .se-tbl tfoot td { font-size: 8pt; font-weight: 700; padding: 1px 2px; border-top: 1px solid #000; }
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Nivel</label>
      <select name="nivel" class="form-select form-select-sm">
        <option value="D"<?= $niv === 'D' ? ' selected' : '' ?>>Detalle</option>
        <option value="T"<?= $niv === 'T' ? ' selected' : '' ?>>Total</option>
      </select></span>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>

<div class="lst-doc lst-doc-wide se-page se-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">SALDOS OPERACIONES DEUDORES ESTADISTICO</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
    <span>NIVEL</span><span>:</span><span class="v"><?= $niv === 'T' ? 'Total' : 'Detalle' ?></span>
  </div>
  <table class="se-tbl">
    <colgroup>
      <col style="width:1.6cm"><col style="width:1.4cm"><col style="width:0.5cm"><col style="width:0.4cm">
      <col style="width:0.6cm"><col style="width:1.2cm"><col style="width:2.2cm"><col style="width:2.2cm">
      <col style="width:2.2cm"><col style="width:2.3cm"><col style="width:1.0cm"><col style="width:3.3cm"><col style="width:2.4cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">N&ordm; MOV /<br>CUENTA CORRIENTE</th>
        <th rowspan="2">EMISION</th>
        <th colspan="4">COMPROBANTE</th>
        <th rowspan="2">SALDO<br>COMPROBANTE</th>
        <th rowspan="2">A COBRAR</th>
        <th rowspan="2">A PAGAR</th>
        <th rowspan="2">SALDO<br>ACUMULADO</th>
        <th rowspan="2">DIAS</th>
        <th rowspan="2">A COBRAR<br>* DIAS</th>
        <th rowspan="2">A COBRAR * DIAS<br>/ PROM. DIAS</th>
      </tr>
      <tr><th>COD</th><th>IDE</th><th>PDV</th><th>NUMERO</th></tr>
    </thead>
    <tbody>
      <?php foreach ($grp as $k => $lst):
        $n = count($lst); $sAco = 0.0; $sApa = 0.0; $sNet = 0.0; $sAcoDias = 0.0; $sDias = 0.0;
        foreach ($lst as $m) {
            $sdo = (float) nz($m['SDOMOV'], 0); $aco = $sdo > 0 ? $sdo : 0.0; $apa = $sdo < 0 ? -$sdo : 0.0;
            $dias = (int) ($sh - (int) $m['FEXMOV']);
            $sAco += $aco; $sApa += $apa; $sNet += $sdo; $sAcoDias += $aco * $dias; $sDias += $dias;
        }
        $avg = $n ? $sDias / $n : 0; $pond = $avg ? $sAcoDias / $avg : 0; ?>
      <tr class="acc">
        <td class="l w" colspan="6"><?= (int) $k ?> <?= h(isset($den[$k]) ? $den[$k] : '') ?> &nbsp;(<?= $n ?>)</td>
        <td class="r"><?= se_f2($sNet) ?></td>
        <td class="r"><?= se_f2($sAco) ?></td>
        <td class="r"><?= se_n($sApa) ?></td>
        <td class="c">PROM. DIAS: <?= round($avg) ?></td>
        <td></td>
        <td class="r"><?= se_f2($sAcoDias) ?></td>
        <td class="r"><?= se_f2($pond) ?></td>
      </tr>
      <?php if ($niv !== 'T'): $run = 0.0; foreach ($lst as $m):
        $sdo = (float) nz($m['SDOMOV'], 0); $aco = $sdo > 0 ? $sdo : 0.0; $apa = $sdo < 0 ? -$sdo : 0.0;
        $dias = (int) ($sh - (int) $m['FEXMOV']); $run += $sdo;
        $pdv = (int) nz($m['CIPMOV'], 0); ?>
      <tr>
        <td class="r"><?= str_pad((string) (int) $m['NUMMOV'], 8, '0', STR_PAD_LEFT) ?></td>
        <td class="c"><?= h(fecha_serial($m['FEXMOV'])) ?></td>
        <td class="c"><?= h(trim((string) nz($m['CICMOV'], ''))) ?></td>
        <td class="c"><?= h(trim((string) nz($m['CIIMOV'], ''))) ?></td>
        <td class="c"><?= $pdv > 0 ? str_pad((string) $pdv, 4, '0', STR_PAD_LEFT) : '' ?></td>
        <td class="c"><?= str_pad((string) (int) nz($m['CINMOV'], 0), 8, '0', STR_PAD_LEFT) ?></td>
        <td class="r"><?= se_f2($sdo) ?></td>
        <td class="r"><?= se_n($aco) ?></td>
        <td class="r"><?= se_n($apa) ?></td>
        <td class="r"><?= se_f2($run) ?></td>
        <td class="c"><?= $dias ?></td>
        <td class="r"><?= $aco > 0 ? se_f2($aco * $dias) : '' ?></td>
        <td></td>
      </tr>
      <?php endforeach; endif; ?>
      <?php endforeach; ?>
      <?php if (!$grp): ?>
      <tr><td colspan="13" class="l" style="padding:.4cm">Sin movimientos con saldo pendiente en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="l" colspan="6">TOTAL &nbsp;(<?= $Tn ?>)</td>
        <td class="r"><?= se_f2($Taco - $Tapa) ?></td>
        <td class="r"><?= se_f2($Taco) ?></td>
        <td class="r"><?= se_f2($Tapa) ?></td>
        <td class="c">PROM. DIAS: <?= round($Tavg) ?></td>
        <td></td>
        <td class="r"><?= se_f2($TacoDias) ?></td>
        <td class="r"><?= se_f2($Tpond) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php module_foot(); ?>
