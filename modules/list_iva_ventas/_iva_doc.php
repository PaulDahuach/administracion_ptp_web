<?php
/** I.V.A. Ventas (Deudores) — loader + render compartido del LISTADO fiel (Rpt CD IVA + subreportes
 *  Localidades y Categorias). Lo usan list_iva_ventas/index.php (preview) y print.php (popup).
 *
 *  Inclusión: JOIN [Tbl Operaciones Auxiliares] por CODAUX con IVAAUX=True. NC (CODOPE=460) negada.
 *  Detalle: NETO=NETMOV · IVA=IRIMOV · NO GRAV=NOGMOV · AJUSTES=ABIMOV+ARDMOV · PERCEP=PIXMOV · TOTAL=TOTMOV.
 *  CAT=INICRI (Tbl Categorias Resp. IVA). Resúmenes LOCALIDADES (x provincia/localidad) y CATEGORIAS
 *  (x categoría/comprobante/alícuota desde Tbl Movimientos IVA). Validado vs PDF Ago/Ene-2023 al centavo. */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

/** WHERE común del libro IVA (M=Movimientos, O=Operaciones Auxiliares). */
function iva_where($sd, $sh, $libro, $codcue) {
    $w = "M.CODORI='D' AND O.IVAAUX=True AND M.FEXMOV BETWEEN $sd AND $sh";
    if ($libro === 'blanco') $w .= " AND M.ESTMOV=True";
    elseif ($libro === 'capacitacion') $w .= " AND M.ESTMOV=False";
    if ($codcue) $w .= " AND M.CODCUE=" . (int) $codcue;
    return $w;
}

/** Carga detalle + totales + resúmenes Localidades/Categorías. */
function iva_load($sd, $sh, $libro, $codcue) {
    $w = iva_where($sd, $sh, $libro, $codcue);

    // ---- Detalle (una fila por movimiento) + datos de provincia/localidad para el resumen ----
    $rows = db_query("SELECT M.NUMMOV, M.FEXMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DENMOV,
        M.CITMOV, M.CODOPE, C.INICRI, M.NETMOV, M.IRIMOV, M.NOGMOV, M.ABIMOV, M.ARDMOV, M.PIXMOV, M.TOTMOV,
        P.DENPRO, L.DENLOC
        FROM ((([Tbl Movimientos] AS M
          LEFT JOIN [Tbl Categorias Responsabilidad IVA] AS C ON C.CODCRI = M.CODCRI)
          INNER JOIN [Tbl Operaciones Auxiliares] AS O ON O.CODAUX = M.CODAUX)
          LEFT JOIN ([Tbl Provincias] AS P RIGHT JOIN [Tbl Localidades] AS L ON P.CODPRO = L.CODPRO)
            ON L.CODLOC = M.CODLOC)
        WHERE $w ORDER BY M.NUMMOV");

    $det = array();
    $T = array('neto' => 0.0, 'iva' => 0.0, 'nograv' => 0.0, 'ajuste' => 0.0, 'percep' => 0.0, 'total' => 0.0, 'n' => 0);
    $loc = array(); // [prov][loc] => sums

    foreach ($rows as $m) {
        $sg = ((int) $m['CODOPE'] == 460) ? -1 : 1;
        $neto   = $sg * (float) nz($m['NETMOV'], 0);
        $iva    = $sg * (float) nz($m['IRIMOV'], 0);
        $nograv = $sg * (float) nz($m['NOGMOV'], 0);
        $ajuste = $sg * ((float) nz($m['ABIMOV'], 0) + (float) nz($m['ARDMOV'], 0));
        $percep = $sg * (float) nz($m['PIXMOV'], 0);
        $total  = $sg * (float) nz($m['TOTMOV'], 0);

        $T['neto'] += $neto; $T['iva'] += $iva; $T['nograv'] += $nograv;
        $T['ajuste'] += $ajuste; $T['percep'] += $percep; $T['total'] += $total; $T['n']++;

        $cic = trim((string) nz($m['CICMOV'], ''));
        $cii = trim((string) nz($m['CIIMOV'], ''));
        $pdv = str_pad((string) (int) nz($m['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
        $nro = str_pad((string) (int) nz($m['CINMOV'], 0), 8, '0', STR_PAD_LEFT);

        $det[] = array(
            'FECHA'  => fecha_serial($m['FEXMOV']),
            'COMP'   => $cic . ($cii !== '' ? ' ' . $cii : '') . ' ' . $pdv . '-' . $nro,
            'DENMOV' => trim((string) nz($m['DENMOV'], '')),
            'CAT'    => trim((string) nz($m['INICRI'], '')),
            'CUIT'   => trim((string) nz($m['CITMOV'], '')),
            'NETO'   => $neto, 'IVA' => $iva, 'NOGRAV' => $nograv,
            'AJUSTE' => $ajuste, 'PERCEP' => $percep, 'TOTAL' => $total,
        );

        // Resumen Localidades (provincia → localidad)
        $pn = trim((string) nz($m['DENPRO'], ''));
        $ln = trim((string) nz($m['DENLOC'], ''));
        if (!isset($loc[$pn])) $loc[$pn] = array();
        if (!isset($loc[$pn][$ln])) $loc[$pn][$ln] = array('n' => 0, 'neto' => 0.0, 'iva' => 0.0, 'nograv' => 0.0, 'ajuste' => 0.0, 'percep' => 0.0, 'total' => 0.0);
        $L = &$loc[$pn][$ln];
        $L['n']++; $L['neto'] += $neto; $L['iva'] += $iva; $L['nograv'] += $nograv;
        $L['ajuste'] += $ajuste; $L['percep'] += $percep; $L['total'] += $total;
        unset($L);
    }
    // ordenar provincias y localidades alfabéticamente (legacy ORDER BY DENPRO, DENLOC)
    uksort($loc, 'strcasecmp');
    foreach ($loc as $pn => $ls) { uksort($loc[$pn], 'strcasecmp'); }

    // ---- Resumen Categorías (categoría → comprobante → alícuota) desde [Tbl Movimientos IVA] ----
    $crows = db_query("SELECT M.CODCRI, C.INICRI, MoI.ALIMOV AS ALI, M.CICMOV, M.CODOPE AS OPE,
        COUNT(M.NUMMOV) AS N, SUM(MoI.NETMOV) AS NET, SUM(MoI.IRIMOV) AS IRI,
        SUM(M.NOGMOV) AS NOG, SUM(M.ABIMOV) AS ABI, SUM(M.ARDMOV) AS ARD, SUM(M.PIXMOV) AS PIX, SUM(M.TOTMOV) AS TOT
        FROM (([Tbl Operaciones Auxiliares] AS O
          INNER JOIN (([Tbl Categorias Responsabilidad IVA] AS C
            RIGHT JOIN [Tbl Movimientos] AS M ON C.CODCRI = M.CODCRI)
            INNER JOIN [Tbl Movimientos IVA] AS MoI ON M.NUMMOV = MoI.NUMMOV) ON O.CODAUX = M.CODAUX))
        WHERE $w GROUP BY M.CODCRI, C.INICRI, MoI.ALIMOV, M.CICMOV, M.CODOPE
        ORDER BY M.CODCRI, M.CICMOV");

    $cat = array(); // [catKey] => ['nombre'=>, 'sub'=>sums, 'comps'=>[cic => ['sub'=>sums,'alis'=>[ali=>sums]]]]
    foreach ($crows as $r) {
        $sg = ((int) $r['OPE'] == 460) ? -1 : 1;
        $ck = trim((string) nz($r['INICRI'], ''));
        if ($ck === '') $ck = '(Sin categoría)';
        $cic = trim((string) nz($r['CICMOV'], '')); if ($cic === '') $cic = 'OTROS';
        $ali = ($r['ALI'] === null) ? null : round((float) $r['ALI'], 2);
        $v = array(
            'n'      => (int) $r['N'],
            'neto'   => $sg * (float) nz($r['NET'], 0),
            'iva'    => $sg * (float) nz($r['IRI'], 0),
            'nograv' => $sg * (float) nz($r['NOG'], 0),
            'ajuste' => $sg * ((float) nz($r['ABI'], 0) + (float) nz($r['ARD'], 0)),
            'percep' => $sg * (float) nz($r['PIX'], 0),
            'total'  => $sg * (float) nz($r['TOT'], 0),
        );
        if (!isset($cat[$ck])) $cat[$ck] = array('nombre' => $ck, 'sub' => iva_zero(), 'comps' => array());
        if (!isset($cat[$ck]['comps'][$cic])) $cat[$ck]['comps'][$cic] = array('sub' => iva_zero(), 'alis' => array());
        $ak = ($ali === null) ? '0' : number_format($ali, 2, '.', '');
        if (!isset($cat[$ck]['comps'][$cic]['alis'][$ak]))
            $cat[$ck]['comps'][$cic]['alis'][$ak] = array('ali' => ($ali === null ? 0.0 : $ali)) + iva_zero();
        iva_acc($cat[$ck]['sub'], $v);
        iva_acc($cat[$ck]['comps'][$cic]['sub'], $v);
        iva_acc($cat[$ck]['comps'][$cic]['alis'][$ak], $v);
    }
    foreach ($cat as $ck => $c) { foreach ($c['comps'] as $cic => $cm) { ksort($cat[$ck]['comps'][$cic]['alis'], SORT_NUMERIC); } }

    return array('det' => $det, 'tot' => $T, 'loc' => $loc, 'cat' => $cat);
}

function iva_zero() { return array('n' => 0, 'neto' => 0.0, 'iva' => 0.0, 'nograv' => 0.0, 'ajuste' => 0.0, 'percep' => 0.0, 'total' => 0.0); }
function iva_acc(&$dst, $v) { foreach ($v as $k => $x) $dst[$k] += $x; }

/** Estilos de la hoja IVA (geometría/fuentes fieles al .report). */
function iva_styles() { ?>
<style>
  /* Rpt CD IVA — portrait Letter, contenido 19,0cm. Fuentes del .report: captions 7pt · detalle 8pt · grupos 8pt bold. */
  .iva-doc { font-family: "Univers Condensed", "Arial Narrow", sans-serif; }
  .iva-tbl { width: 18.8cm; border-collapse: collapse; table-layout: fixed; }
  .iva-tbl thead th { font-size: 7pt; font-weight: 400; text-align: center; vertical-align: middle;
    border: 1px solid #000; background: #c0c0c0; line-height: 1.05; padding: 1px 2px; }
  .iva-tbl tbody td { font-size: 8pt; height: .36cm; line-height: .36cm; padding: 0 2px;
    white-space: nowrap; overflow: visible; text-overflow: clip; vertical-align: top; }
  .iva-tbl td.dn { white-space: normal; word-break: break-word; overflow: hidden; }
  .iva-tbl .r { text-align: right; padding-right: 4px; } .iva-tbl .c { text-align: center; } .iva-tbl .l { text-align: left; }
  .iva-tbl tfoot td { font-size: 8pt; font-weight: 700; padding: 1px 2px; }
  .iva-tbl tfoot tr.tot td { border-top: 1px solid #000; }
  .iva-tbl.noline tfoot tr.tot td { border-top: 0; }
  .iva-grp { width: 16.6cm; margin: .5cm 0 0 2.2cm; border-collapse: collapse; table-layout: fixed; border: 1px solid #000; }
  .iva-grp th.gtit { font-weight: 400; }  /* título del subreporte (LOCALIDADES/CATEGORIAS), pegado a los captions */
  .iva-grp thead th { font-size: 7pt; font-weight: 400; text-align: center; vertical-align: middle;
    border: 1px solid #000; background: #c0c0c0; line-height: 1.05; padding: 1px 2px; }
  .iva-grp tbody td { font-size: 8pt; height: .32cm; line-height: .32cm; padding: 0 2px; white-space: nowrap; vertical-align: top; }
  .iva-grp td.nw { white-space: normal; word-break: break-word; }
  .iva-grp tbody tr.sub td { font-weight: 700; }
  .iva-grp .r { text-align: right; padding-right: 4px; } .iva-grp .c { text-align: center; } .iva-grp .l { text-align: left; }
  .iva-grp tfoot td { font-size: 8pt; font-weight: 700; border-top: 1px solid #000; padding: 1px 2px; }
  .iva-ind { padding-left: .5cm; }
</style>
<?php }

/** Render del cuerpo (detalle + resúmenes). $nivel: 'D' detalle | 'T' total. $agrup: bool. */
function iva_body($data, $nivel, $agrup) {
    $det = $data['det']; $T = $data['tot'];
    $detalle = ($nivel !== 'T');
    ?>
    <table class="iva-tbl<?= $detalle ? '' : ' noline' ?>">
      <colgroup>
        <col style="width:1.30cm"><col style="width:2.40cm"><col style="width:2.37cm"><col style="width:0.60cm">
        <col style="width:1.60cm"><col style="width:2.05cm"><col style="width:1.88cm"><col style="width:1.88cm">
        <col style="width:1.05cm"><col style="width:1.62cm"><col style="width:2.05cm">
      </colgroup>
      <thead>
        <tr>
          <th rowspan="2">FECHA</th>
          <th rowspan="2">COMPROBANTE</th>
          <th colspan="3">CUENTA CORRIENTE</th>
          <th rowspan="2">NETO<br>GRAVADO</th>
          <th rowspan="2">I.V.A.</th>
          <th rowspan="2">NO GRAVADO</th>
          <th rowspan="2">AJUSTES</th>
          <th rowspan="2">PERCEPCION<br>ING. BRUTOS</th>
          <th rowspan="2">TOTAL</th>
        </tr>
        <tr><th>DENOMINACION</th><th>CAT</th><th>C.U.I.T. N&ordm;</th></tr>
      </thead>
      <?php if ($detalle): ?>
      <tbody>
        <?php foreach ($det as $d): ?>
        <tr>
          <td class="l"><?= h($d['FECHA']) ?></td>
          <td class="l"><?= h($d['COMP']) ?></td>
          <td class="l dn"><?= h($d['DENMOV']) ?></td>
          <td class="l"><?= h($d['CAT']) ?></td>
          <td class="c"><?= h($d['CUIT']) ?></td>
          <td class="r"><?= money($d['NETO']) ?></td>
          <td class="r"><?= money($d['IVA']) ?></td>
          <td class="r"><?= money($d['NOGRAV']) ?></td>
          <td class="r"><?= money($d['AJUSTE']) ?></td>
          <td class="r"><?= money($d['PERCEP']) ?></td>
          <td class="r"><?= money($d['TOTAL']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endif; ?>
      <tfoot>
        <tr class="tot">
          <td class="l" colspan="3">TOTAL</td>
          <td class="r" colspan="2"><?= (int) $T['n'] ?></td>
          <td class="r"><?= money($T['neto']) ?></td>
          <td class="r"><?= money($T['iva']) ?></td>
          <td class="r"><?= money($T['nograv']) ?></td>
          <td class="r"><?= money($T['ajuste']) ?></td>
          <td class="r"><?= money($T['percep']) ?></td>
          <td class="r"><?= money($T['total']) ?></td>
        </tr>
      </tfoot>
    </table>
    <?php
    if ($agrup) { iva_loc($data['loc'], $T); iva_cat($data['cat'], $T); }
}

/** Resumen LOCALIDADES (provincia con subtotal en cabecera + localidades debajo). */
function iva_loc($loc, $T) {
    ?>
    <table class="iva-grp">
      <colgroup>
        <col style="width:2.30cm"><col style="width:2.86cm"><col style="width:0.91cm"><col style="width:2.05cm">
        <col style="width:1.88cm"><col style="width:1.88cm"><col style="width:1.05cm"><col style="width:1.62cm"><col style="width:2.05cm">
      </colgroup>
      <thead>
        <tr><th colspan="9" class="gtit">LOCALIDADES</th></tr>
        <tr><th>PROVINCIA</th><th>LOCALIDAD</th><th></th><th>NETO<br>GRAVADO</th><th>I.V.A.</th>
          <th>NO GRAVADO</th><th>AJUSTES</th><th>PERCEPCION<br>ING. BRUTOS</th><th>TOTAL</th></tr>
      </thead>
      <tbody>
        <?php foreach ($loc as $pn => $ls):
          $ps = iva_zero(); foreach ($ls as $L) iva_acc($ps, $L); ?>
        <tr class="sub">
          <td class="l nw"><?= h($pn !== '' ? $pn : '(Sin provincia)') ?></td>
          <td></td>
          <td class="r"><?= (int) $ps['n'] ?></td>
          <td class="r"><?= money($ps['neto']) ?></td>
          <td class="r"><?= money($ps['iva']) ?></td>
          <td class="r"><?= money($ps['nograv']) ?></td>
          <td class="r"><?= money($ps['ajuste']) ?></td>
          <td class="r"><?= money($ps['percep']) ?></td>
          <td class="r"><?= money($ps['total']) ?></td>
        </tr>
          <?php foreach ($ls as $ln => $L): ?>
        <tr>
          <td></td>
          <td class="l iva-ind nw"><?= h($ln !== '' ? $ln : '(Sin localidad)') ?></td>
          <td class="r"><?= (int) $L['n'] ?></td>
          <td class="r"><?= money($L['neto']) ?></td>
          <td class="r"><?= money($L['iva']) ?></td>
          <td class="r"><?= money($L['nograv']) ?></td>
          <td class="r"><?= money($L['ajuste']) ?></td>
          <td class="r"><?= money($L['percep']) ?></td>
          <td class="r"><?= money($L['total']) ?></td>
        </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td class="l">TOTAL</td><td></td><td class="r"><?= (int) $T['n'] ?></td>
          <td class="r"><?= money($T['neto']) ?></td><td class="r"><?= money($T['iva']) ?></td>
          <td class="r"><?= money($T['nograv']) ?></td><td class="r"><?= money($T['ajuste']) ?></td>
          <td class="r"><?= money($T['percep']) ?></td><td class="r"><?= money($T['total']) ?></td></tr>
      </tfoot>
    </table>
    <?php
}

/** Resumen CATEGORIAS (categoría → comprobante → alícuota). */
function iva_cat($cat, $T) {
    ?>
    <table class="iva-grp">
      <colgroup>
        <col style="width:2.40cm"><col style="width:2.17cm"><col style="width:1.50cm"><col style="width:2.05cm">
        <col style="width:1.88cm"><col style="width:1.88cm"><col style="width:1.05cm"><col style="width:1.62cm"><col style="width:2.05cm">
      </colgroup>
      <thead>
        <tr><th colspan="9" class="gtit">CATEGORIAS</th></tr>
        <tr><th>CATEGORIA</th><th>COMPROBANTE</th><th>ALICUOTA</th><th>NETO<br>GRAVADO</th><th>I.V.A.</th>
          <th>NO GRAVADO</th><th>AJUSTES</th><th>PERCEPCION<br>ING. BRUTOS</th><th>TOTAL</th></tr>
      </thead>
      <tbody>
        <?php foreach ($cat as $c): $s = $c['sub']; ?>
        <tr class="sub">
          <td class="l nw"><?= h($c['nombre']) ?></td><td></td><td></td>
          <td class="r"><?= money($s['neto']) ?></td><td class="r"><?= money($s['iva']) ?></td>
          <td class="r"><?= money($s['nograv']) ?></td><td class="r"><?= money($s['ajuste']) ?></td>
          <td class="r"><?= money($s['percep']) ?></td><td class="r"><?= money($s['total']) ?></td>
        </tr>
          <?php foreach ($c['comps'] as $cic => $cm): $cs = $cm['sub']; ?>
        <tr class="sub">
          <td></td><td class="l iva-ind nw"><?= h($cic) ?></td><td></td>
          <td class="r"><?= money($cs['neto']) ?></td><td class="r"><?= money($cs['iva']) ?></td>
          <td class="r"><?= money($cs['nograv']) ?></td><td class="r"><?= money($cs['ajuste']) ?></td>
          <td class="r"><?= money($cs['percep']) ?></td><td class="r"><?= money($cs['total']) ?></td>
        </tr>
            <?php foreach ($cm['alis'] as $a): ?>
        <tr>
          <td></td><td></td><td class="r"><?= money($a['ali']) ?></td>
          <td class="r"><?= money($a['neto']) ?></td><td class="r"><?= money($a['iva']) ?></td>
          <td class="r"><?= money($a['nograv']) ?></td><td class="r"><?= money($a['ajuste']) ?></td>
          <td class="r"><?= money($a['percep']) ?></td><td class="r"><?= money($a['total']) ?></td>
        </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td class="l">TOTAL</td><td></td><td></td>
          <td class="r"><?= money($T['neto']) ?></td><td class="r"><?= money($T['iva']) ?></td>
          <td class="r"><?= money($T['nograv']) ?></td><td class="r"><?= money($T['ajuste']) ?></td>
          <td class="r"><?= money($T['percep']) ?></td><td class="r"><?= money($T['total']) ?></td></tr>
      </tfoot>
    </table>
    <?php
}
