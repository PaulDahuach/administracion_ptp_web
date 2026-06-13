<?php
/**
 * Exportación A.R.I.B. — Agente de Recaudación de Ingresos Brutos · RETENCIONES (formato SIAP/ARBA).
 * Loader + formato COMPARTIDO por index.php (preview) y export.php (descarga .zip).
 * Porta `rutExportacion_AgenteRetencionIngBrutos2` + `Qry CA Exportacion ARIB - Retenciones`:
 *   Órdenes de Pago (CODOPE=340) con retención IIBB (RIXMOV>0) y régimen válido (JOIN Tbl Regimenes),
 *   acotadas por FEXMOV. **SIEMPRE libro blanco (ESTMOV=True)**: es un export fiscal, capacitación
 *   NUNCA va a ARBA. No escribe en la mdb (solo lee + genera el .txt) → compatible con readonly.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

define('ARIB_ACTIV', '6');   // nº de actividad ARBA del agente (retenciones=6 · percepciones=7)

function arib_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }

/** Movimientos de retención IIBB del período (orden CITMOV, RINMOV como el legacy). */
function arib_rows($sDesde, $sHasta) {
    $w = 'M.RIXMOV>0 AND M.CODOPE=340 AND M.ESTMOV=True';
    if ($sDesde !== null) $w .= " AND M.FEXMOV>=$sDesde";
    if ($sHasta !== null) $w .= " AND M.FEXMOV<=$sHasta";
    return db_query("SELECT M.CITMOV, M.RINMOV, M.FEXMOV, M.CIPMOV, M.CINMOV, M.CICMOV, M.TOTMOV, M.RIXMOV,
        M.NUMMOV, M.DENMOV, M.CODRRI, R.DENRRI, R.ALIRRI
        FROM [Tbl Regimenes Retencion Ingresos Brutos] AS R INNER JOIN [Tbl Movimientos] AS M ON R.CODRRI=M.CODRRI
        WHERE $w ORDER BY M.CITMOV, M.RINMOV;");
}

/** Importe ARBA ancho fijo: 8 enteros (7 si negativo, el signo ocupa 1) + punto + 2 decimales. */
function arib_imp($v) {
    $neg = ((float) $v) < 0;
    $s = number_format(abs(round((float) $v, 2)), 2, '.', '');
    $p = explode('.', $s);
    return ($neg ? '-' : '') . str_pad($p[0], $neg ? 7 : 8, '0', STR_PAD_LEFT) . '.' . $p[1];
}

/** Una línea del .txt SIAP (concatenación sin separador, como el Print #; del legacy). */
function arib_line($r) {
    return (string) nz($r['CITMOV'], '')
         . fecha_serial($r['FEXMOV'])                                              // dd/mm/yyyy
         . '0001' . str_pad((string) (int) nz($r['RINMOV'], 0), 8, '0', STR_PAD_LEFT)
         . arib_imp($r['RIXMOV'])
         . 'A';
}

/** Nombre base (sin extensión): AR-{cuit}-{AAAA}{MM}{intervalo}-{activ}-LOTE1. */
function arib_filebase($desde, $hasta) {
    $cuit = defined('AFIP_CUIT') ? AFIP_CUIT : '';
    $d1 = new DateTime($desde); $d2 = new DateTime($hasta);
    $days = (int) $d1->diff($d2)->days;
    $intervalo = ($days > 15) ? '0' : (((int) $d2->format('d') > 20) ? '2' : '1');   // 0 mensual · 1 1ra · 2 2da
    return 'AR-' . $cuit . '-' . $d1->format('Y') . $d1->format('m') . $intervalo . '-' . ARIB_ACTIV . '-LOTE1';
}

/** Etiqueta del intervalo derivado (Mensual / 1era Quincena / 2da Quincena). */
function arib_intervalo_txt($desde, $hasta) {
    $d1 = new DateTime($desde); $d2 = new DateTime($hasta);
    $days = (int) $d1->diff($d2)->days;
    return ($days > 15) ? 'Mensual' : (((int) $d2->format('d') > 20) ? '2da Quincena' : '1era Quincena');
}
