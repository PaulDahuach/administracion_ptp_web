<?php
/**
 * Exportación de RETENCIONES SUFRIDAS (lado Deudores, en recibos CODOPE=480) — 4 tipos.
 * El cliente nos retiene al cobrarnos; exportamos el .txt para declararlas. Porta:
 *   - iibb      → `rutExportacion_RetencionIngBrutos`  (CM03 / SD99 Convenio Multilateral, CSV con comas)
 *   - iva       → `rutExportacion_RetencionIVA`        (SIAP IVA "493" ancho fijo)
 *   - ganancias → `rutExportacion_RetencionGanancias`  (SIAP Ganancias / SICORE)
 *   - suss      → `rutExportacion_RetencionSUSS`       (SIJP)
 * Período por **FIXMOV**. **Libro blanco forzado (ESTMOV=True)**: export fiscal (capacitación no declara).
 * Sólo lee + genera el .txt (sin zip/md5) → compatible con readonly. Loader/formato compartido.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

function ret_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

/** Config por tipo: campos de retención (importe RT / pdv RP / nº RN) + metadatos de UI/archivo. */
function ret_cfg($tipo) {
    $c = array(
        'iibb'      => array('label' => 'Retenciones Ingresos Brutos', 'titulo' => 'RETENCIONES INGRESOS BRUTOS - CONVENIO MULTILATERAL', 'rt' => 'RT1MOV', 'rp' => 'RIPMOV', 'rn' => 'RINMOV', 'file' => 'CM03_R.txt', 'fmt' => 'CM03 (SD99 Convenio Multilateral)'),
        'iva'       => array('label' => 'Retenciones I.V.A.',          'titulo' => 'RETENCIONES I.V.A.',                                   'rt' => 'RT3MOV', 'rp' => 'RVPMOV', 'rn' => 'RVNMOV', 'file' => 'IVA_R.txt',  'fmt' => 'SIAP IVA Ingresos Directos ("493")'),
        'ganancias' => array('label' => 'Retenciones Ganancias',       'titulo' => 'RETENCIONES GANANCIAS',                                'rt' => 'RT2MOV', 'rp' => 'RGPMOV', 'rn' => 'RGNMOV', 'file' => 'Gan_R.txt',  'fmt' => 'SIAP Ganancias (SICORE)'),
        'suss'      => array('label' => 'Retenciones S.U.S.S.',         'titulo' => 'RETENCIONES S.U.S.S.',                                 'rt' => 'RT4MOV', 'rp' => 'RSPMOV', 'rn' => 'RSNMOV', 'file' => 'SUSS_R.txt', 'fmt' => 'SIJP (Versión 25.0)'),
    );
    return isset($c[$tipo]) ? $c[$tipo] : null;
}

/** Retenciones del tipo en el período (recibos CODOPE=480 con RTxMOV>0). Libro blanco. */
function ret_rows($tipo, $sDesde, $sHasta) {
    $cf = ret_cfg($tipo); if (!$cf) return array();
    $rt = $cf['rt']; $rp = $cf['rp']; $rn = $cf['rn'];
    $sel  = "M.CITMOV, M.CIPMOV, M.CINMOV, M.FIXMOV, M.NUMMOV, M.DENMOV, M.NETMOV, M.$rt AS RT, M.$rp AS RP, M.$rn AS RN";
    $from = "[Tbl Movimientos] AS M";
    if ($tipo === 'ganancias') {   // JOIN al régimen para HMLRRG
        $sel .= ", G.HMLRRG AS HML";
        $from = "[Tbl Regimenes Retencion Ganancias] AS G INNER JOIN [Tbl Movimientos] AS M ON G.CODRRG = M.CODRRG";
    }
    $w = "M.CODOPE=480 AND M.FIXMOV IS NOT NULL AND M.$rt>0 AND M.ESTMOV=True";
    if ($sDesde !== null) $w .= " AND M.FIXMOV>=$sDesde";
    if ($sHasta !== null) $w .= " AND M.FIXMOV<=$sHasta";
    return db_query("SELECT $sel FROM $from WHERE $w ORDER BY M.CITMOV, M.CIPMOV, M.CINMOV;");
}

/** Importe ancho fijo: $intw enteros + "." + 2 dec (Format "0…0.00"). */
function ret_fixed($v, $intw) {
    $s = number_format((float) $v, 2, '.', '');
    $p = explode('.', $s);
    return str_pad($p[0], $intw, '0', STR_PAD_LEFT) . '.' . $p[1];
}
/** Importe justificado a derecha en $w (Right(String($w,"0") & "x.xx", $w)). */
function ret_right($v, $w) {
    $s = number_format((float) $v, 2, '.', '');
    return substr(str_pad($s, $w, '0', STR_PAD_LEFT), -$w);
}

/** Una línea del .txt del tipo dado (porta el Print #3 del legacy). */
function ret_line($tipo, $r) {
    $cit   = (string) nz($r['CITMOV'], '');
    $citND = str_replace('-', '', $cit);
    $fix   = fecha_serial($r['FIXMOV']);                 // dd/mm/yyyy
    $pp    = explode('/', $fix); $dd = $pp[0]; $mm = $pp[1]; $yy = isset($pp[2]) ? $pp[2] : '';
    $rp    = (int) nz($r['RP'], 0);
    $rn    = (int) nz($r['RN'], 0);
    $rt    = (float) nz($r['RT'], 0);
    switch ($tipo) {
        case 'iibb':   // {cuit},{fix},,{rip4}{rin8},{rt1 11.2},,
            return $citND . ',' . $fix . ',,' . str_pad((string) $rp, 4, '0', STR_PAD_LEFT) . str_pad((string) $rn, 8, '0', STR_PAD_LEFT) . ',' . ret_fixed($rt, 11) . ',,';
        case 'iva':    // 493{cuit c/guiones}{fix}{rvp8}{rvn8}{rt3 right16}
            return '493' . $cit . $fix . str_pad((string) $rp, 8, '0', STR_PAD_LEFT) . str_pad((string) $rn, 8, '0', STR_PAD_LEFT) . ret_right($rt, 16);
        case 'ganancias':  // {cuitND}{rgp últimos2}{rgn8}{dd}{mm}{yyyy}{hml3}{rt2 right12}
            return $citND . sprintf('%02d', $rp % 100) . str_pad((string) $rn, 8, '0', STR_PAD_LEFT) . $dd . $mm . $yy . str_pad((string) (int) nz($r['HML'], 0), 3, '0', STR_PAD_LEFT) . ret_right($rt, 12);
        case 'suss':   // {cuitND 11}{yyyy}/{mm}/{dd} {rsp2}{rsn8}{rt4 right15}
            return str_pad($citND, 11, '0', STR_PAD_LEFT) . $yy . '/' . $mm . '/' . $dd . ' ' . sprintf('%02d', $rp) . str_pad((string) $rn, 8, '0', STR_PAD_LEFT) . ret_right($rt, 15);
    }
    return '';
}
