<?php
/** Listado de Saldos Operaciones Acreedores (Rpt CA Saldos Operaciones). Movimientos acreedores con
 *  saldo pendiente (SDOMOV<>0) en el período, agrupados por cuenta: comprobante, débitos/créditos/saldo,
 *  Anticipos (SDOMOV>0) y A Pagar (−SDOMOV si SDOMOV<0). Filtros: período (CEFMOV) + cuenta (opcional). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

function f2($v) { return number_format((float) $v, 2, '.', ','); }
function n2($v) { return ((float) $v == 0.0) ? '' : f2($v); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

// Período predeterminado = Rec Control.DESFEC/HASFEC (período guardado del sistema).
list($defDes, $defHas) = rec_periodo();
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : $defDes;
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : $defHas;
$sd = sp_serial($desIso); $sh = sp_serial($hasIso);
$cue = isset($_GET['cue']) && ctype_digit((string) $_GET['cue']) ? (int) $_GET['cue'] : '';

$forz = auth_libro_unico();
$lib = ($forz !== '') ? $forz : (isset($_GET['libro']) ? $_GET['libro'] : 'todos');
$estW = ($lib === 'blanco') ? ' AND ESTMOV=True' : (($lib === 'capacitacion') ? ' AND ESTMOV=False' : '');
$cueW = ($cue !== '') ? ' AND CODCUE=' . $cue : '';

// NIVEL: D=Detalle (movimientos + subtotal) · S=Subtotal (un renglón por cuenta) · T=Total (un solo renglón)
$niv = isset($_GET['nivel']) ? strtoupper($_GET['nivel']) : 'D';
if (!in_array($niv, array('D', 'S', 'T'), true)) $niv = 'D';
$nivTxt = array('D' => 'Detalle', 'S' => 'Subtotal', 'T' => 'Total');

$movs = db_query("SELECT NUMMOV, CODCUE, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV, FEXMOV, CICMOV, CINMOV, DETMOV, DEBMOV, CREMOV, SDOMOV
    FROM [Tbl Movimientos] WHERE CODORI='A' AND SDOMOV<>0 AND CEFMOV >= $sd AND CEFMOV <= $sh$estW$cueW
    ORDER BY CODCUE, NUMMOV;");

$den = array();
foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A';") as $c)
    $den[(int) $c['CODCUE']] = trim((string) nz($c['DENCUE'], ''));
$provList = $den; asort($provList, SORT_STRING | SORT_FLAG_CASE);   // para el selector de Proveedor

// agrupar por cuenta (orden por denominación)
$grupos = array();
foreach ($movs as $m) { $k = (int) $m['CODCUE']; if (!isset($grupos[$k])) $grupos[$k] = array(); $grupos[$k][] = $m; }
uksort($grupos, function ($a, $b) use ($den) { return strcasecmp(isset($den[$a]) ? $den[$a] : '', isset($den[$b]) ? $den[$b] : ''); });

$tD = 0.0; $tC = 0.0; $tSal = 0.0; $tAnt = 0.0; $tApa = 0.0; $tN = 0;
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Listado de Saldos Operaciones Acreedores', 'bi-arrow-left-right', $toolbar);
?>
<link href="../../assets/css/listado.css?v=25" rel="stylesheet">
<style>
  /* Proveedor ancho en su propia fila; Desde · Hasta en la fila siguiente (mismo layout que Resumen de Cuenta) */
  .lst-fgrid3 .lst-fpair.rc-prov { grid-column: 1 / -1; grid-template-columns: 6.5rem 1fr; }
  .lst-fgrid3 .lst-fpair.rc-prov > select,
  .lst-fgrid3 .lst-fpair.rc-prov > .iwk-combo,
  .lst-fgrid3 .lst-fpair.rc-prov .iwk-combo-input { width: 100%; max-width: 34rem; }
  /* 16 columnas en hoja Carta: fuente compacta + columnas fijas que no se desbordan */
  .rc-so { table-layout: fixed; font-size: 7.2pt; }
  .rc-so th { font-size: 6.6pt; line-height: 1.05; }
  .rc-so th, .rc-so td { padding: 0 2px; overflow: hidden; text-overflow: ellipsis; }
  .rc-so td:nth-child(11) { white-space: normal; word-break: break-word; }   /* Detalle hace wrap */
</style>
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair rc-prov"><label>Proveedor</label>
      <select name="cue" class="form-select form-select-sm">
        <option value="">— Todos —</option>
        <?php foreach ($provList as $pc => $pn): ?>
        <option value="<?= (int) $pc ?>"<?= ($cue !== '' && (int) $pc === (int) $cue) ? ' selected' : '' ?>><?= h($pn) ?> (<?= str_pad((string) (int) $pc, 5, '0', STR_PAD_LEFT) ?>)</option>
        <?php endforeach; ?>
      </select></span>
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Nivel</label>
      <select name="nivel" class="form-select form-select-sm">
        <option value="D"<?= $niv === 'D' ? ' selected' : '' ?>>Detalle</option>
        <option value="S"<?= $niv === 'S' ? ' selected' : '' ?>>Subtotal</option>
        <option value="T"<?= $niv === 'T' ? ' selected' : '' ?>>Total</option>
      </select></span>
    <?php if (auth_ve_ambos()): ?>
    <span class="lst-fpair"><label>Libro</label>
      <select name="libro" class="form-select form-select-sm">
        <option value="todos"<?= $lib === 'todos' ? ' selected' : '' ?>>Todos</option>
        <option value="blanco"<?= $lib === 'blanco' ? ' selected' : '' ?>>Blanco</option>
        <option value="capacitacion"<?= $lib === 'capacitacion' ? ' selected' : '' ?>>Capacitación</option>
      </select></span>
    <?php endif; ?>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc lst-doc-wide">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">SALDOS OPERACIONES ACREEDORES</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>CUENTA</span><span>:</span><span class="v"><?= ($cue !== '' && isset($den[$cue])) ? h($den[$cue]) : '' ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
    <span>NIVEL</span><span>:</span><span class="v"><?= h($nivTxt[$niv]) ?></span>
  </div>
  <table class="lst-tbl lst-jer rc-so">
    <colgroup>
      <col style="width:1.4cm"><col style="width:1.1cm">
      <col style="width:1.3cm"><col style="width:.55cm"><col style="width:.55cm"><col style="width:.6cm"><col style="width:1.2cm">
      <col style="width:1.3cm"><col style="width:.55cm"><col style="width:1.2cm">
      <col style="width:1.9cm">
      <col style="width:1.4cm"><col style="width:1.5cm"><col style="width:1.5cm"><col style="width:1.5cm"><col style="width:1.5cm">
    </colgroup>
    <thead>
      <!-- Encabezado 100% fiel a Rpt CA Saldos Operaciones (16 columnas). El nombre de cuenta + #
           comprob. + totales del grupo van en la fila de cabecera de cuenta; los movimientos debajo. -->
      <tr>
        <th rowspan="2">Cuenta</th>
        <th rowspan="2" class="r">N° Mov</th>
        <th colspan="5">Comprobante Proveedor</th>
        <th colspan="3">Comprobante Interno</th>
        <th>Detalle</th>
        <th rowspan="2" class="r">Débitos</th><th rowspan="2" class="r">Créditos</th><th rowspan="2" class="r">Saldo</th>
        <th rowspan="2" class="r">Anticipos</th><th rowspan="2" class="r">A Pagar</th>
      </tr>
      <tr>
        <th>Fecha</th><th>Cod</th><th>Ide</th><th>PDV</th><th>Número</th>
        <th>Emisión</th><th>Cod</th><th>Número</th>
        <th class="r"># Comprob.</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($grupos as $cc => $ms):
        $gd=0;$gc=0;$gs=0;$ga=0;$gp=0;
        foreach ($ms as $m) { $sdo=(float)nz($m['SDOMOV'],0); $gd+=(float)nz($m['DEBMOV'],0); $gc+=(float)nz($m['CREMOV'],0); $gs+=$sdo; $ga+=($sdo>0?$sdo:0); $gp+=($sdo<0?-$sdo:0); }
        $tD+=$gd;$tC+=$gc;$tSal+=$gs;$tAnt+=$ga;$tApa+=$gp; $tN+=count($ms);
        // Fila de cuenta = nombre + #comprob + totales del grupo (Detalle y Subtotal; en Total no).
        if ($niv !== 'T'): ?>
      <tr class="parent">
        <td colspan="10"><?= h(isset($den[$cc]) ? $den[$cc] : '') ?></td>
        <td class="r mono"><?= count($ms) ?></td>
        <td class="r mono"><?= n2($gd) ?></td><td class="r mono"><?= n2($gc) ?></td><td class="r mono"><?= f2($gs) ?></td><td class="r mono"><?= f2($ga) ?></td><td class="r mono"><?= f2($gp) ?></td>
      </tr>
      <?php endif;
      if ($niv === 'D') foreach ($ms as $m):
        $deb=(float)nz($m['DEBMOV'],0); $cre=(float)nz($m['CREMOV'],0); $sdo=(float)nz($m['SDOMOV'],0);
        $ant=$sdo>0?$sdo:0; $apa=$sdo<0?-$sdo:0;
        $cep=(int)nz($m['CEPMOV'],0); $cen=(int)nz($m['CENMOV'],0); $cin=(int)nz($m['CINMOV'],0); ?>
      <tr>
        <td></td>
        <td class="r mono"><?= str_pad((string)(int)$m['NUMMOV'],8,'0',STR_PAD_LEFT) ?></td>
        <td class="mono"><?= h(fecha_serial($m['CEFMOV'])) ?></td>
        <td class="mono"><?= h(trim((string)nz($m['CECMOV'],''))) ?></td>
        <td class="mono"><?= h(trim((string)nz($m['CEIMOV'],''))) ?></td>
        <td class="r mono"><?= $cep ? str_pad((string)$cep,4,'0',STR_PAD_LEFT) : '' ?></td>
        <td class="r mono"><?= $cen ? str_pad((string)$cen,8,'0',STR_PAD_LEFT) : '' ?></td>
        <td class="mono"><?= h(fecha_serial($m['FEXMOV'])) ?></td>
        <td class="mono"><?= h(trim((string)nz($m['CICMOV'],''))) ?></td>
        <td class="r mono"><?= $cin ? str_pad((string)$cin,8,'0',STR_PAD_LEFT) : '' ?></td>
        <td><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
        <td class="r mono"><?= n2($deb) ?></td><td class="r mono"><?= n2($cre) ?></td><td class="r mono"><?= n2($sdo) ?></td>
        <td class="r mono"><?= n2($ant) ?></td><td class="r mono"><?= n2($apa) ?></td>
      </tr>
      <?php endforeach;
      endforeach; ?>
      <?php if (!count($grupos)): ?>
      <tr><td colspan="16" class="text-muted">Sin operaciones con saldo en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <?php if (count($grupos)): ?>
    <tfoot><tr class="tot">
      <td colspan="10">TOTAL</td>
      <td class="r mono"><?= $tN ?></td>
      <td class="r mono"><?= f2($tD) ?></td><td class="r mono"><?= f2($tC) ?></td><td class="r mono"><?= f2($tSal) ?></td><td class="r mono"><?= f2($tAnt) ?></td><td class="r mono"><?= f2($tApa) ?></td>
    </tr></tfoot>
    <?php endif; ?>
  </table>
</div>
<?php module_foot('<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>'); ?>
