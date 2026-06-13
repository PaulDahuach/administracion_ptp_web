<?php
/**
 * Exportación A.R.I.B. — Agente de Recaudación de Ingresos Brutos · PERCEPCIONES (formato SIAP/ARBA).
 * Loader + formato COMPARTIDO por index.php (preview) y export.php (descarga .zip).
 * Porta `rutExportacion_AgentePercepcionIngBrutos2` + `Qry CD Exportacion ARIB - Percepciones`:
 *   Movimientos de venta con percepción IIBB (PIXMOV>0), JOIN Tbl Localidades por CODLOC, acotados
 *   por FEXMOV. **SIEMPRE libro blanco (ESTMOV=True)**: es un export fiscal, capacitación NUNCA va a
 *   ARBA (ver [[dual-ledger-visibility]]). No escribe en la mdb (solo lee + genera el .txt) → readonly.
 *   Espejo del export de Retenciones (modules/export_arib_ret) con su mismo motor txt→zip→MD5.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

define('ARIB_P_ACTIV', '7');   // nº de actividad ARBA percepciones; el legacy arma el segmento "D" & txtActiv

function aribp_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC')); if (!$d) return null; return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days; }

/** Movimientos con percepción IIBB (PIXMOV>0) del período; calcula qryCom/qryBI/qryPIX (NC negada). */
function aribp_rows($sDesde, $sHasta) {
    $w = 'M.PIXMOV>0 AND M.ESTMOV=True';
    if ($sDesde !== null) $w .= " AND M.FEXMOV>=$sDesde";
    if ($sHasta !== null) $w .= " AND M.FEXMOV<=$sHasta";
    $rows = db_query("SELECT M.CITMOV, M.CIPMOV, M.CIIMOV, M.CINMOV, M.CICMOV, M.FEXMOV, M.NUMMOV,
        M.DENMOV, M.DCXMOV, L.DENLOC, M.NETMOV, M.TOTMOV, M.PIXMOV, M.CODCRI
        FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Localidades] AS L ON M.CODLOC = L.CODLOC
        WHERE $w ORDER BY M.CITMOV, M.CIPMOV, M.CIIMOV, M.CINMOV;");

    $out = array();
    foreach ($rows as $r) {
        $cic  = trim((string) nz($r['CICMOV'], ''));
        $com  = ($cic === 'FV') ? 'F' : (($cic === 'ND') ? 'D' : (($cic === 'NC') ? 'C' : ''));
        $isNC = ($cic === 'NC');
        $net  = (float) nz($r['NETMOV'], 0);
        $tot  = (float) nz($r['TOTMOV'], 0);
        $pix  = (float) nz($r['PIXMOV'], 0);
        // qryBI: CODCRI=1 → NETMOV ; resto → TOTMOV-PIXMOV. NC niega.
        if ((int) nz($r['CODCRI'], 0) === 1) $bi = $isNC ? -$net : $net;
        else { $base = $tot - $pix; $bi = $isNC ? -$base : $base; }
        $r['qryCom'] = $com;
        $r['qryBI']  = $bi;
        $r['qryPIX'] = $isNC ? -$pix : $pix;
        $out[] = $r;
    }
    return $out;
}

/** Importe ARBA ancho fijo: $intpos enteros (positivo) / $intpos-1 + signo (negativo) + "." + 2 dec. */
function aribp_imp($v, $intpos) {
    $neg = ((float) $v) < 0;
    $s = number_format(abs(round((float) $v, 2)), 2, '.', '');
    $p = explode('.', $s);
    return ($neg ? '-' : '') . str_pad($p[0], $neg ? ($intpos - 1) : $intpos, '0', STR_PAD_LEFT) . '.' . $p[1];
}

/** Una línea del .txt SIAP percepciones (concatenación sin separador, como el Print #; del legacy). */
function aribp_line($r) {
    $cii = substr(trim((string) nz($r['CIIMOV'], '')) . ' ', 0, 1);                       // letra (A/B/…) o espacio
    $pdv = str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
    $nro = str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
    return (string) nz($r['CITMOV'], '')              // CUIT con guiones (13)
         . fecha_serial($r['FEXMOV'])                 // dd/mm/yyyy
         . $r['qryCom']                               // F / D / C
         . $cii                                       // letra del comprobante
         . $pdv . $nro                                // 0000 + 00000000
         . aribp_imp($r['qryBI'], 9)                  // base imponible (9 enteros)
         . aribp_imp($r['qryPIX'], 8)                 // percepción (8 enteros)
         . 'A';
}

/** Nombre base (sin extensión): AR-{cuit}-{AAAA}{MM}{intervalo}-D{activ}-LOTE1. */
function aribp_filebase($desde, $hasta) {
    $cuit = defined('AFIP_CUIT') ? AFIP_CUIT : '';
    $d1 = new DateTime($desde); $d2 = new DateTime($hasta);
    $days = (int) $d1->diff($d2)->days;
    $intervalo = ($days > 15) ? '0' : (((int) $d2->format('d') > 20) ? '2' : '1');   // 0 mensual · 1 1ra · 2 2da
    return 'AR-' . $cuit . '-' . $d1->format('Y') . $d1->format('m') . $intervalo . '-D' . ARIB_P_ACTIV . '-LOTE1';
}

/** Etiqueta del intervalo derivado (Mensual / 1era Quincena / 2da Quincena). */
function aribp_intervalo_txt($desde, $hasta) {
    $d1 = new DateTime($desde); $d2 = new DateTime($hasta);
    $days = (int) $d1->diff($d2)->days;
    return ($days > 15) ? 'Mensual' : (((int) $d2->format('d') > 20) ? '2da Quincena' : '1era Quincena');
}
