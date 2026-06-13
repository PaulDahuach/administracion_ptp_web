<?php
/**
 * Resumen de Cuenta (Deudores) — loader + render COMPARTIDO (Rpt CD Resumen de Cuenta).
 * Lo usan print.php (Consulta → Imprimir) y modules/list_resumen_cuenta/ (Listado).
 * Estructura idéntica al de acreedores pero con UN solo bloque Comprobante (interno CIC/CII/CIP/CIN);
 * SIN filtro de codope (todos los movimientos de la cuenta), período por FEXMOV, refs = comprobante
 * interno del comprobante referenciado. Saldo cta cte = Σ(DEBMOV−CREMOV) (positivo = nos debe).
 * NIVEL: D (Detalle) | P (Producto: expande cada comprobante con sus renglones de stock).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

if (!function_exists('rcd_f2')) { function rcd_f2($v) { return number_format((float) $v, 2, '.', ','); } }
if (!function_exists('rcd_f4')) { function rcd_f4($v) { return number_format((float) $v, 4, '.', ','); } }

function rcd_load($codcue, $sDesde, $sHasta, $libro, $nivel) {
    $codcue = (int) $codcue;
    $cab = $codcue ? db_row("SELECT CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='D';") : null;
    $d = array('cab' => $cab, 'rows' => array(), 'refs' => array(), 'prods' => array(),
        'saldoAnterior' => 0.0, 'nivel' => ($nivel === 'P' ? 'P' : 'D'));
    if (!$cab) return $d;

    $base = "CODORI='D' AND CODCUE=$codcue";
    if ($libro === 'blanco') $base .= ' AND ESTMOV=True';
    elseif ($libro === 'capacitacion') $base .= ' AND ESTMOV=False';

    if ($sDesde !== null) {
        $r = db_row("SELECT SUM(DEBMOV) AS D, SUM(CREMOV) AS C FROM [Tbl Movimientos] WHERE $base AND FEXMOV < $sDesde;");
        $d['saldoAnterior'] = (float) nz($r['D'], 0) - (float) nz($r['C'], 0);
    }
    $w = $base;
    if ($sDesde !== null) $w .= " AND FEXMOV >= $sDesde";
    if ($sHasta !== null) $w .= " AND FEXMOV <= $sHasta";
    $d['rows'] = db_query("SELECT NUMMOV, FEXMOV, CICMOV, CIIMOV, CIPMOV, CINMOV, DETMOV, DEBMOV, CREMOV, SDOMOV
        FROM [Tbl Movimientos] WHERE $w ORDER BY NUMMOV;");

    // referencias → comprobante INTERNO del comprobante referenciado (FV A 0003 00005762)
    foreach (db_query("SELECT R.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV
        FROM [Tbl Movimientos Referencias] AS R LEFT JOIN [Tbl Movimientos] AS M ON M.NUMMOV=R.REFMOV
        WHERE R.NUMMOV IN (SELECT NUMMOV FROM [Tbl Movimientos] WHERE $w);") as $r) {
        $k = (int) $r['NUMMOV'];
        if (!isset($d['refs'][$k])) $d['refs'][$k] = array();
        $d['refs'][$k][] = trim(trim((string) nz($r['CICMOV'], '')) . ' ' . trim((string) nz($r['CIIMOV'], '')) . ' '
            . str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . ' ' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT));
    }

    // nivel PRODUCTO: renglones de stock por movimiento
    if ($d['nivel'] === 'P') {
        $aliiva = (float) nz(db_row("SELECT ALIIVA FROM [Rec Control];")['ALIIVA'], 21);
        foreach (db_query("SELECT MoS.NUMMOV, MoS.CODPRO, P.DENPRO, U.DENUDM, MoS.PUNMOV, MoS.DECMOV,
            MoS.ICCMOV, MoS.ECCMOV, MoS.INGMOV, MoS.EGRMOV, MoS.SVCMOV
            FROM ([Tbl Movimientos Stock] AS MoS INNER JOIN [Tbl Productos] AS P ON P.CODPRO=MoS.CODPRO)
              INNER JOIN [Tbl Unidades de Medida] AS U ON U.CODUDM=MoS.CODUDM
            WHERE MoS.STKMOV=True AND MoS.NUMMOV IN (SELECT NUMMOV FROM [Tbl Movimientos] WHERE $w)
            ORDER BY MoS.NUMMOV, MoS.ORDMOV;") as $r) {
            $k = (int) $r['NUMMOV'];
            $cant = (float) nz($r['ICCMOV'], 0) - (float) nz($r['ECCMOV'], 0) + (float) nz($r['INGMOV'], 0) - (float) nz($r['EGRMOV'], 0) + (float) nz($r['SVCMOV'], 0);
            $pun = (float) nz($r['PUNMOV'], 0);
            $iva = 1 + ($aliiva / 100) / ((nz($r['DECMOV'], 0) ? 2 : 1));
            if (!isset($d['prods'][$k])) $d['prods'][$k] = array();
            $d['prods'][$k][] = array('cod' => (string) $r['CODPRO'], 'den' => trim((string) nz($r['DENPRO'], '')),
                'udm' => trim((string) nz($r['DENUDM'], '')), 'pun' => $pun, 'cant' => $cant, 'tot' => round($cant * $pun * $iva, 2));
        }
    }
    return $d;
}

/** CSS de la tabla del resumen deudor (fuentes del .report: caption 7pt · detalle 8pt · total 8pt bold). */
function rcd_styles() { ?>
<style>
  .rc-tbl { table-layout: auto; width: 100%; font-size: 8pt; border-collapse: collapse; }
  .rc-tbl thead th { border: 1px solid #000; padding: 1px 3px; text-align: center; font-weight: 700; font-size: 7pt; line-height: 1.05; text-transform: uppercase; }
  .rc-tbl td { padding: 0 3px; vertical-align: top; white-space: nowrap; }
  .rc-tbl td.det { white-space: normal; word-break: break-word; }
  .rc-tbl th.dd, .rc-tbl td.dd { min-width: 7cm; }          /* Detalle más ancho */
  .rc-tbl th.rf, .rc-tbl td.rf { max-width: 4.2cm; white-space: normal; word-break: break-word; }  /* Referencias más angosta */
  .rc-tbl .r { text-align: right; } .rc-tbl .mono { font-variant-numeric: tabular-nums; }
  .rc-tbl.pmode tr.mov td { border-top: 1px solid #999; }
  .rc-tbl tr.prod td { font-size: 7.4pt; color: #333; }
  .rc-tbl tr.prod td.det { padding-left: 14px; }
  .rc-tbl tr.tot td { font-weight: 700; border-top: 1px solid #000; }
  .rc-tbl tr.ant td { font-weight: 700; background: #f4f4f4; }
</style>
<?php }

/** Tabla del resumen deudor — encabezado 100% fiel + filas de movimiento (+ productos en nivel P). */
function rcd_table($d) {
    $nivel = $d['nivel']; $saldoAnterior = (float) $d['saldoAnterior'];
    $refs = $d['refs']; $prods = $d['prods'];
    $saldoPer = 0.0; $tDeb = 0.0; $tCre = 0.0;
    ?>
  <table class="rc-tbl<?= $nivel === 'P' ? ' pmode' : '' ?>">
    <thead>
      <tr class="g">
        <th rowspan="2">Mov. Nº</th>
        <th colspan="5">Comprobante</th>
        <th colspan="2" class="r">Saldo Anterior</th>
        <th colspan="3">Período</th>
        <th rowspan="2">Saldo Actual</th>
        <th rowspan="2">Saldo Comprobante</th>
      </tr>
      <tr>
        <th>Fecha</th><th>C</th><th>ID</th><th>PDV</th><th>Número</th>
        <th class="dd">Detalle</th><th class="rf">Referencias</th>
        <th>Débitos</th><th>Créditos</th><th>Saldo</th>
      </tr>
    </thead>
    <tbody>
      <tr class="ant">
        <td colspan="6"></td>
        <td colspan="2" class="r mono"><?= rcd_f2($saldoAnterior) ?></td>
        <td colspan="2"></td>
        <td class="r mono"><?= rcd_f2(0) ?></td>
        <td class="r mono"><?= rcd_f2($saldoAnterior) ?></td>
        <td></td>
      </tr>
      <?php foreach ($d['rows'] as $m):
        $deb = (float) nz($m['DEBMOV'], 0); $cre = (float) nz($m['CREMOV'], 0); $sdo = (float) nz($m['SDOMOV'], 0);
        $saldoPer += $deb - $cre; $tDeb += $deb; $tCre += $cre; $nm = (int) $m['NUMMOV'];
        $saldoAct = $saldoAnterior + $saldoPer;
        $cip = (int) nz($m['CIPMOV'], 0); $cin = (int) nz($m['CINMOV'], 0); ?>
      <tr class="mov">
        <td class="r mono"><?= str_pad((string) $nm, 8, '0', STR_PAD_LEFT) ?></td>
        <td class="r"><?= h(fecha_serial($m['FEXMOV'])) ?></td>
        <td><?= h(trim((string) nz($m['CICMOV'], ''))) ?></td>
        <td><?= h(trim((string) nz($m['CIIMOV'], ''))) ?></td>
        <td class="r mono"><?= $cip ? str_pad((string) $cip, 4, '0', STR_PAD_LEFT) : '' ?></td>
        <td class="r mono"><?= $cin ? str_pad((string) $cin, 8, '0', STR_PAD_LEFT) : '' ?></td>
        <td class="det dd"><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
        <td class="det rf"><?= isset($refs[$nm]) ? h(implode(' · ', $refs[$nm])) : '' ?></td>
        <td class="r mono"><?= $deb > 0 ? rcd_f2($deb) : '' ?></td>
        <td class="r mono"><?= $cre > 0 ? rcd_f2($cre) : '' ?></td>
        <td class="r mono"><?= rcd_f2($saldoPer) ?></td>
        <td class="r mono"><?= rcd_f2($saldoAct) ?></td>
        <td class="r mono"><?= rcd_f2($sdo) ?></td>
      </tr>
      <?php if ($nivel === 'P' && isset($prods[$nm])) foreach ($prods[$nm] as $p): ?>
      <tr class="prod">
        <td></td>
        <td class="r mono"><?= h($p['cod']) ?></td>
        <td class="det" colspan="5"><?= h($p['den']) ?> · <?= h($p['udm']) ?></td>
        <td class="r mono" colspan="3">Neto <?= rcd_f4($p['pun']) ?> × <?= rcd_f4($p['cant']) ?></td>
        <td></td><td></td>
        <td class="r mono"><?= rcd_f2($p['tot']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="tot">
      <td colspan="8">TOTAL CUENTA</td>
      <td class="r mono"><?= rcd_f2($tDeb) ?></td><td class="r mono"><?= rcd_f2($tCre) ?></td>
      <td class="r mono"><?= rcd_f2($saldoPer) ?></td><td class="r mono"><?= rcd_f2($saldoAnterior + $saldoPer) ?></td><td></td>
    </tr></tfoot>
  </table>
<?php }
