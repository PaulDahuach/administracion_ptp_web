<?php
/**
 * Resumen de Cuenta (Acreedores) — loader + render COMPARTIDO (Rpt CA Resumen de Cuenta).
 * Lo usan: print.php (Consulta → botón Imprimir, popup que auto-imprime) y
 *          modules/list_resumen_cuenta_acr/ (Listado con barra de parámetros estilo listado).
 * Encabezado 100% fiel al .report (16 columnas en grupos: Comprobante Proveedor/Interno ·
 * Saldo Anterior sobre Detalle/Referencias · Período Débitos/Créditos/Saldo · Saldo Actual ·
 * Saldo Comprobante). Período/orden por CEFMOV/NUMMOV (fecha del comprobante del proveedor).
 * NIVEL: D (Detalle) | P (Producto: expande cada factura con sus renglones de stock).
 */
require_once __DIR__ . '/../../includes/db.php';

if (!defined('RCA_OPS')) define('RCA_OPS', '310,320,330,340,350');   // CP,NC,ND,OP,CancAntic
if (!function_exists('rca_f2')) { function rca_f2($v) { return number_format((float) $v, 2, '.', ','); } }
if (!function_exists('rca_f4')) { function rca_f4($v) { return number_format((float) $v, 4, '.', ','); } }

/**
 * Carga el resumen de una cuenta acreedora.
 * @param int $codcue  cuenta
 * @param int|null $sDesde  serial Access (o null = sin tope inferior)
 * @param int|null $sHasta  serial Access (o null = sin tope superior)
 * @param string $libro  ''|'todos' (integral) | 'blanco' | 'capacitacion'
 * @param string $nivel  'D' | 'P'
 * @return array  cab, rows, refs, prods, saldoAnterior, nivel
 */
function rca_load($codcue, $sDesde, $sHasta, $libro, $nivel) {
    $codcue = (int) $codcue;
    $cab = $codcue ? db_row("SELECT CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='A';") : null;
    $d = array('cab' => $cab, 'rows' => array(), 'refs' => array(), 'prods' => array(),
        'saldoAnterior' => 0.0, 'nivel' => ($nivel === 'P' ? 'P' : 'D'));
    if (!$cab) return $d;

    $base = "CODORI='A' AND CODCUE=$codcue AND CODOPE IN (" . RCA_OPS . ")";
    if ($libro === 'blanco') $base .= ' AND ESTMOV=True';
    elseif ($libro === 'capacitacion') $base .= ' AND ESTMOV=False';

    // El resumen legacy acota y ordena por CEFMOV (fecha del comprobante del proveedor) / NUMMOV.
    if ($sDesde !== null) {
        $r = db_row("SELECT SUM(DEBMOV) AS D, SUM(CREMOV) AS C FROM [Tbl Movimientos] WHERE $base AND CEFMOV < $sDesde;");
        $d['saldoAnterior'] = (float) nz($r['D'], 0) - (float) nz($r['C'], 0);
    }
    $w = $base;
    if ($sDesde !== null) $w .= " AND CEFMOV >= $sDesde";
    if ($sHasta !== null) $w .= " AND CEFMOV <= $sHasta";
    $d['rows'] = db_query("SELECT NUMMOV, CODOPE, CICMOV, CIIMOV, CIPMOV, CINMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV, FEXMOV, DEBMOV, CREMOV, SDOMOV, DETMOV
        FROM [Tbl Movimientos] WHERE $w ORDER BY NUMMOV;");

    // referencias → comprobante EXTERNO del comprobante referenciado (FC A xxxx)
    foreach (db_query("SELECT R.NUMMOV, M.CECMOV, M.CEIMOV, M.CENMOV
        FROM [Tbl Movimientos Referencias] AS R LEFT JOIN [Tbl Movimientos] AS M ON M.NUMMOV=R.REFMOV
        WHERE R.NUMMOV IN (SELECT NUMMOV FROM [Tbl Movimientos] WHERE $w);") as $r) {
        $k = (int) $r['NUMMOV'];
        if (!isset($d['refs'][$k])) $d['refs'][$k] = array();
        $d['refs'][$k][] = trim(trim((string) nz($r['CECMOV'], '')) . ' ' . trim((string) nz($r['CEIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CENMOV'], 0), 8, '0', STR_PAD_LEFT));
    }

    // nivel PRODUCTO: renglones de stock por movimiento (cantidad neta + total con IVA)
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
                'udm' => trim((string) nz($r['DENUDM'], '')), 'pun' => $pun, 'cant' => $cant, 'tot' => -round($cant * $pun * $iva, 2));
        }
    }
    return $d;
}

/** CSS de la tabla del resumen (compartido por print.php y el listado). */
function rca_styles() { ?>
<style>
  /* tabla ancha que se auto-ajusta: nada se trunca, el detalle hace wrap */
  .rc-tbl { table-layout: auto; width: 100%; font-size: 7.4pt; border-collapse: collapse; }
  .rc-tbl thead th { border: 1px solid #000; padding: 1px 3px; text-align: center; font-weight: 700; font-size: 6.6pt; line-height: 1.05; }
  .rc-tbl td { padding: 0 3px; vertical-align: top; white-space: nowrap; }
  .rc-tbl td.det { white-space: normal; word-break: break-word; }   /* el detalle hace wrap */
  .rc-tbl .r { text-align: right; } .rc-tbl .mono { font-variant-numeric: tabular-nums; }
  /* la línea separadora entre registros sólo se ve en nivel Producto (delimita cada bloque comp.+productos) */
  .rc-tbl.pmode tr.mov td { border-top: 1px solid #999; }
  .rc-tbl tr.prod td { font-size: 6.8pt; color: #333; }
  .rc-tbl tr.prod td.det { padding-left: 14px; }
  .rc-tbl tr.tot td { font-weight: 700; border-top: 1px solid #000; }
  .rc-tbl tr.ant td { font-weight: 700; background: #f4f4f4; }
</style>
<?php }

/** Tabla del resumen — encabezado 100% fiel + filas de movimiento (+ productos en nivel P). */
function rca_table($d) {
    $nivel = $d['nivel']; $saldoAnterior = (float) $d['saldoAnterior'];
    $refs = $d['refs']; $prods = $d['prods'];
    $saldoPer = 0.0; $tDeb = 0.0; $tCre = 0.0;
    ?>
  <table class="rc-tbl<?= $nivel === 'P' ? ' pmode' : '' ?>">
    <thead>
      <!-- Encabezado 100% fiel a Rpt CA Resumen de Cuenta: SALDO ANTERIOR va sobre Detalle/Referencias
           (intencional en el legacy); Débitos/Créditos/Saldo van bajo PERÍODO (no traen saldo inicial). -->
      <tr class="g">
        <th rowspan="2">Mov. Nº</th>
        <th colspan="5">Comprobante Proveedor</th>
        <th colspan="3">Comprobante Interno</th>
        <th colspan="2">Saldo Anterior</th>
        <th colspan="3">Período</th>
        <th rowspan="2">Saldo Actual</th>
        <th rowspan="2">Saldo Comprobante</th>
      </tr>
      <tr>
        <th>Fecha</th><th>C</th><th>ID</th><th>PDV</th><th>Número</th>
        <th>Fecha</th><th>C</th><th>Número</th>
        <th>Detalle</th><th>Referencias</th>
        <th>Débitos</th><th>Créditos</th><th>Saldo</th>
      </tr>
    </thead>
    <tbody>
      <tr class="ant">
        <td colspan="9"></td>
        <td colspan="2" class="r mono"><?= rca_f2($saldoAnterior) ?></td>
        <td colspan="2"></td>
        <td class="r mono"><?= rca_f2(0) ?></td>
        <td class="r mono"><?= rca_f2($saldoAnterior) ?></td>
        <td></td>
      </tr>
      <?php foreach ($d['rows'] as $m):
        $deb = (float) nz($m['DEBMOV'], 0); $cre = (float) nz($m['CREMOV'], 0); $sdo = (float) nz($m['SDOMOV'], 0);
        $saldoPer += $deb - $cre; $tDeb += $deb; $tCre += $cre; $nm = (int) $m['NUMMOV'];
        $saldoAct = $saldoAnterior + $saldoPer;
        $cep = (int) nz($m['CEPMOV'], 0); $cen = (int) nz($m['CENMOV'], 0); $cin = (int) nz($m['CINMOV'], 0); ?>
      <tr class="mov">
        <td class="r mono"><?= str_pad((string) $nm, 8, '0', STR_PAD_LEFT) ?></td>
        <td class="r"><?= h(fecha_serial($m['CEFMOV'])) ?></td>
        <td><?= h(trim((string) nz($m['CECMOV'], ''))) ?></td>
        <td><?= h(trim((string) nz($m['CEIMOV'], ''))) ?></td>
        <td class="r mono"><?= $cep ? str_pad((string) $cep, 4, '0', STR_PAD_LEFT) : '' ?></td>
        <td class="r mono"><?= $cen ? str_pad((string) $cen, 8, '0', STR_PAD_LEFT) : '' ?></td>
        <td class="r"><?= h(fecha_serial($m['FEXMOV'])) ?></td>
        <td><?= h(trim((string) nz($m['CICMOV'], ''))) ?></td>
        <td class="r mono"><?= $cin ? str_pad((string) $cin, 8, '0', STR_PAD_LEFT) : '' ?></td>
        <td class="det"><?= h(trim((string) nz($m['DETMOV'], ''))) ?></td>
        <td class="det"><?= isset($refs[$nm]) ? h(implode(' · ', $refs[$nm])) : '' ?></td>
        <td class="r mono"><?= $deb > 0 ? rca_f2($deb) : '' ?></td>
        <td class="r mono"><?= $cre > 0 ? rca_f2($cre) : '' ?></td>
        <td class="r mono"><?= rca_f2($saldoPer) ?></td>
        <td class="r mono"><?= rca_f2($saldoAct) ?></td>
        <td class="r mono"><?= rca_f2($sdo) ?></td>
      </tr>
      <?php if ($nivel === 'P' && isset($prods[$nm])) foreach ($prods[$nm] as $p): ?>
      <tr class="prod">
        <td></td>
        <td class="r mono"><?= h($p['cod']) ?></td>
        <td class="det" colspan="7"><?= h($p['den']) ?> · <?= h($p['udm']) ?></td>
        <td class="r mono" colspan="4">Neto <?= rca_f4($p['pun']) ?> × <?= rca_f4($p['cant']) ?></td>
        <td></td><td></td>
        <td class="r mono"><?= rca_f2($p['tot']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="tot">
      <td colspan="11">TOTAL CUENTA</td>
      <td class="r mono"><?= rca_f2($tDeb) ?></td><td class="r mono"><?= rca_f2($tCre) ?></td>
      <td class="r mono"><?= rca_f2($saldoPer) ?></td><td class="r mono"><?= rca_f2($saldoAnterior + $saldoPer) ?></td><td></td>
    </tr></tfoot>
  </table>
<?php }
