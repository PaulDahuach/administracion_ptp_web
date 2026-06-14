<?php
/** Listado de Movimientos de Stock x Producto (Periódico) — Rpt SI Movimientos x Producto (Periodico).
 *  Agrupa por producto los movimientos de stock (MoS.STKMOV=True) en un período (FEXMOV). Buckets
 *  (×FCTMOV): Remitido Compras (ICCMOV/ECCMOV) · Existencia (INGMOV/EGRMOV) · Comprometido Ventas
 *  (ICVMOV/ECVMOV) · Servicios (SVCMOV) · Total=(IC−EC)+(ING−EGR)+(IV−EV)+SVC. Filtros: producto,
 *  período, sucursal, operación, Categoría/Rubro/Subrubro/Línea y libro (ESTMOV via modo). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/stocklist.php';
auth_require_login();

function f2($v) { return number_format((float) $v, 2, '.', ','); }
function n2($v) { return ((float) $v == 0.0) ? '' : f2($v); }
function sp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

$L = sl_lookups();
$p = sl_params();

// período: default mes de la fecha del sistema
$fecape = db_row("SELECT FECAPE FROM [Rec Control];");
$hoyIso = date('Y-m-d');
if ($fecape) { $f = fecha_serial($fecape['FECAPE']); if ($f && strpos($f, '/') !== false) { $q = explode('/', $f); $hoyIso = $q[2] . '-' . $q[1] . '-' . $q[0]; } }
$desIso = isset($_GET['desde']) && $_GET['desde'] !== '' ? $_GET['desde'] : substr($hoyIso, 0, 8) . '01';
$hasIso = isset($_GET['hasta']) && $_GET['hasta'] !== '' ? $_GET['hasta'] : date('Y-m-t', strtotime($hoyIso));
$sd = sp_serial($desIso); $sh = sp_serial($hasIso);

$pro = isset($_GET['pro']) ? trim((string) $_GET['pro']) : '';
$ope = isset($_GET['ope']) && ctype_digit((string) $_GET['ope']) ? (int) $_GET['ope'] : '';

// operaciones de stock disponibles
$opes = array();
foreach (db_query("SELECT DISTINCT M.CODOPE, O.DENOPE FROM ([Tbl Movimientos Stock] AS MoS INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MoS.NUMMOV) INNER JOIN [Tbl Operaciones] AS O ON O.CODOPE=M.CODOPE WHERE MoS.STKMOV=True ORDER BY M.CODOPE;") as $o)
    $opes[(int) $o['CODOPE']] = trim((string) nz($o['DENOPE'], ''));

$cols = array('cat' => 'P.CODCAT', 'rub' => 'P.CODRUB', 'sub' => 'P.CODSUB', 'lin' => 'P.CODLIN', 'suc' => 'MoS.CODSUC');
$where = "MoS.STKMOV=True AND M.FEXMOV >= $sd AND M.FEXMOV <= $sh" . sl_where($p, $cols);
if ($pro !== '') $where .= " AND MoS.CODPRO = '" . db_esc($pro) . "'";
if ($ope !== '') $where .= " AND M.CODOPE = " . $ope;
$lib = auth_libro_unico();
if ($lib === 'blanco') $where .= ' AND M.ESTMOV=True';
elseif ($lib === 'capacitacion') $where .= ' AND M.ESTMOV=False';

$rows = db_query("SELECT MoS.CODPRO, P.DENPRO, P.CODUDM, M.FEXMOV, M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DENMOV,
    MoS.ICCMOV*MoS.FCTMOV AS qryIC, MoS.ECCMOV*MoS.FCTMOV AS qryEC, MoS.INGMOV*MoS.FCTMOV AS qINg, MoS.EGRMOV*MoS.FCTMOV AS qEgr,
    MoS.ICVMOV*MoS.FCTMOV AS qryIV, MoS.ECVMOV*MoS.FCTMOV AS qryEV, MoS.SVCMOV AS qSvc
    FROM ([Tbl Movimientos Stock] AS MoS INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MoS.NUMMOV)
      INNER JOIN [Tbl Productos] AS P ON P.CODPRO=MoS.CODPRO
    WHERE $where ORDER BY MoS.CODPRO, M.FEXMOV, M.NUMMOV;");

// agrupar por producto
$grupos = array();
foreach ($rows as $r) { $k = (string) $r['CODPRO']; if (!isset($grupos[$k])) $grupos[$k] = array(); $grupos[$k][] = $r; }

$toolbar = '<button onclick="window.print()" class="btn btn-outline-light btn-sm"><i class="bi bi-printer me-1"></i>Imprimir</button>';
module_head('Movimientos de Stock x Producto', 'bi-arrow-left-right', $toolbar);
$me = isset($_SESSION['uname']) ? $_SESSION['uname'] : (isset($_SESSION['uid']) ? $_SESSION['uid'] : '');
$tot = array('ic'=>0,'ec'=>0,'ing'=>0,'egr'=>0,'iv'=>0,'ev'=>0,'svc'=>0,'t'=>0); $nmov = 0;
?>
<link href="../../assets/css/listado.css?v=26" rel="stylesheet">
<form method="get" class="lst-filter no-print" data-bs-theme="light">
  <div class="lst-fgrid3">
    <span class="lst-fpair"><label>Producto (cód.)</label><input type="text" name="pro" value="<?= h($pro) ?>" class="form-control form-control-sm" placeholder="(Todos)"></span>
    <span class="lst-fpair"><label>Desde</label><input type="date" name="desde" value="<?= h($desIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Hasta</label><input type="date" name="hasta" value="<?= h($hasIso) ?>" class="form-control form-control-sm"></span>
    <span class="lst-fpair"><label>Operación</label><select name="ope" class="form-select form-select-sm"><option value="">(Todas)</option>
      <?php foreach ($opes as $c => $d): ?><option value="<?= $c ?>"<?= ($ope === $c ? ' selected' : '') ?>><?= h($d) ?></option><?php endforeach; ?></select></span>
    <?= sl_select('cat', 'cat', $p['cat'], 'Categoría') ?>
    <?= sl_select('rub', 'rub', $p['rub'], 'Rubro') ?>
    <?= sl_select('sub', 'sub', $p['sub'], 'Subrubro') ?>
    <?= sl_select('lin', 'lin', $p['lin'], 'Línea') ?>
    <?= sl_select('suc', 'suc', $p['suc'], 'Sucursal') ?>
  </div>
  <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Ver</button>
</form>
<div class="lst-doc">
  <div class="lst-head">
    <div class="lst-emp"><?= h(sys('empresa', 'PROCESADORA TEXTIL PARQUE S.A.')) ?></div>
    <div class="lst-tit">MOVIMIENTOS DE STOCK x PRODUCTO ( PERIÓDICO )</div>
    <div class="lst-fecha"><?= date('d/m/Y H:i:s') ?></div>
  </div>
  <div class="lst-params">
    <span>USUARIO</span><span>:</span><span class="v"><?= h($me) ?></span>
    <span>PERÍODO</span><span>:</span><span class="v"><?= h(fecha_serial($sd)) ?> - <?= h(fecha_serial($sh)) ?></span>
    <span>SUCURSAL</span><span>:</span><span class="v"><?= h(sl_param_text($p, 'suc')) ?></span>
  </div>
  <table class="lst-tbl lst-jer" style="table-layout:fixed; width:19.0cm">
    <colgroup>
      <col style="width:1.3cm"><col style="width:1.2cm"><col style="width:2.5cm"><col style="width:3.4cm">
      <col style="width:1.3cm"><col style="width:1.4cm"><col style="width:1.3cm"><col style="width:1.4cm"><col style="width:1.3cm"><col style="width:1.4cm"><col style="width:1.2cm"><col style="width:1.3cm">
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2" class="r">Fecha</th><th rowspan="2" class="r">Número</th><th rowspan="2">Comprobante</th><th rowspan="2">Cuenta Corriente</th>
        <th colspan="2">Remitido Compras</th><th colspan="2">Existencia</th><th colspan="2">Comprometido Ventas</th><th rowspan="2" class="r">Serv.</th><th rowspan="2" class="r">Total</th>
      </tr>
      <tr><th class="r">Ingr.</th><th class="r">Egr.</th><th class="r">Ingr.</th><th class="r">Egr.</th><th class="r">Ingr.</th><th class="r">Egr.</th></tr>
    </thead>
    <tbody>
      <?php foreach ($grupos as $cod => $movs):
        $udm = isset($L['udm'][(string) (int) $movs[0]['CODUDM']]) ? $L['udm'][(string) (int) $movs[0]['CODUDM']]['den'] : '';
        $st = array('ic'=>0,'ec'=>0,'ing'=>0,'egr'=>0,'iv'=>0,'ev'=>0,'svc'=>0,'t'=>0); ?>
      <tr class="parent"><td colspan="12"><span class="mono"><?= h($cod) ?></span> <?= h(trim((string) nz($movs[0]['DENPRO'], ''))) ?> · <?= h($udm) ?></td></tr>
      <?php foreach ($movs as $r):
        $ic=(float)nz($r['qryIC'],0); $ec=(float)nz($r['qryEC'],0); $ing=(float)nz($r['qINg'],0); $egr=(float)nz($r['qEgr'],0);
        $iv=(float)nz($r['qryIV'],0); $ev=(float)nz($r['qryEV'],0); $svc=(float)nz($r['qSvc'],0);
        $tt=($ic-$ec)+($ing-$egr)+($iv-$ev)+$svc;
        $st['ic']+=$ic;$st['ec']+=$ec;$st['ing']+=$ing;$st['egr']+=$egr;$st['iv']+=$iv;$st['ev']+=$ev;$st['svc']+=$svc;$st['t']+=$tt; $nmov++;
        $comp = trim(trim((string) nz($r['CICMOV'],'')) . ' ' . trim((string) nz($r['CIIMOV'],'')) . ' ' . str_pad((string)(int)nz($r['CIPMOV'],0),4,'0',STR_PAD_LEFT) . '-' . str_pad((string)(int)nz($r['CINMOV'],0),8,'0',STR_PAD_LEFT)); ?>
      <tr>
        <td class="r"><?= h(fecha_serial($r['FEXMOV'])) ?></td>
        <td class="r mono"><?= str_pad((string)(int)nz($r['NUMMOV'],0),8,'0',STR_PAD_LEFT) ?></td>
        <td><?= h($comp) ?></td>
        <td><?= h(trim((string) nz($r['DENMOV'], ''))) ?></td>
        <td class="r mono"><?= n2($ic) ?></td><td class="r mono"><?= n2($ec) ?></td>
        <td class="r mono"><?= n2($ing) ?></td><td class="r mono"><?= n2($egr) ?></td>
        <td class="r mono"><?= n2($iv) ?></td><td class="r mono"><?= n2($ev) ?></td>
        <td class="r mono"><?= n2($svc) ?></td><td class="r mono"><?= n2($tt) ?></td>
      </tr>
      <?php endforeach;
        foreach ($st as $k => $v) $tot[$k] += $v; ?>
      <tr class="tot">
        <td colspan="4">SUBTOTAL [<?= h($cod) ?>] - <?= h(trim((string) nz($movs[0]['DENPRO'], ''))) ?>: <?= count($movs) ?></td>
        <td class="r mono"><?= f2($st['ic']) ?></td><td class="r mono"><?= f2($st['ec']) ?></td>
        <td class="r mono"><?= f2($st['ing']) ?></td><td class="r mono"><?= f2($st['egr']) ?></td>
        <td class="r mono"><?= f2($st['iv']) ?></td><td class="r mono"><?= f2($st['ev']) ?></td>
        <td class="r mono"><?= f2($st['svc']) ?></td><td class="r mono"><?= f2($st['t']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!count($grupos)): ?>
      <tr><td colspan="12" class="text-muted">Sin movimientos de stock en el período.</td></tr>
      <?php endif; ?>
    </tbody>
    <?php if (count($grupos)): ?>
    <tfoot><tr class="tot">
      <td colspan="4">TOTAL PRODUCTOS: <?= count($grupos) ?></td>
      <td class="r mono"><?= f2($tot['ic']) ?></td><td class="r mono"><?= f2($tot['ec']) ?></td>
      <td class="r mono"><?= f2($tot['ing']) ?></td><td class="r mono"><?= f2($tot['egr']) ?></td>
      <td class="r mono"><?= f2($tot['iv']) ?></td><td class="r mono"><?= f2($tot['ev']) ?></td>
      <td class="r mono"><?= f2($tot['svc']) ?></td><td class="r mono"><?= f2($tot['t']) ?></td>
    </tr></tfoot>
    <?php endif; ?>
  </table>
</div>
<?php module_foot(sl_combo_script()); ?>
