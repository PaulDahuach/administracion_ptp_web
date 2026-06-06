<?php
/**
 * Percepción de IIBB (Ingresos Brutos) — lógica compartida entre Facturas y Notas de Crédito/Débito.
 * Replica fiel del legacy (Frm CD Facturas / Frm CD Creditos NF). NO define reglas de negocio nuevas.
 *
 * SPIMOV = SPICUE del cliente, anulado si CODCRI=5 (Consumidor Final), negro (ESTMOV=False) o el switch
 * Rec Control PIXCDC=True (percep DESACTIVADA). ALIPIX del padrón ARBA (PADRONRS.APBPIB por CUIT) o el
 * default de Rec Control. pixmov = neto × ALIPIX/100 si neto > MNPPIX.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** Alícuota de PERCEPCIÓN IIBB del padrón ARBA (PADRONRS.APBPIB por CUIT). null = no figura / no accesible. */
function padron_percep_alicuota($cuit) {
    $cuit = preg_replace('/[^0-9]/', '', (string) $cuit);
    if ($cuit === '') return null;
    $rc = db_row("SELECT DBPIBR FROM [Rec Control];");
    $path = trim((string) nz($rc ? $rc['DBPIBR'] : '', ''));
    if ($path === '' || !@file_exists($path)) return null;
    try {
        $cn = new COM('ADODB.Connection');
        $cn->Open('Provider=Microsoft.ACE.OLEDB.12.0;Data Source=' . $path . ';');
        $rs = $cn->Execute("SELECT APBPIB FROM [PADRONRS] WHERE CITPIB='" . str_replace("'", "''", $cuit) . "';");
        $ali = null;
        if (!$rs->EOF) { $f = $rs->Fields['APBPIB']; $ali = ($f->Value === null) ? null : (float) str_replace(',', '.', (string) $f->Value); }
        $rs->Close(); $cn->Close();
        return $ali;
    } catch (Exception $e) { return null; }
}

/**
 * Computa la percepción IIBB para un comprobante de venta. Devuelve {spimov, pixmov, alipix, mnppix}.
 * @param float  $net    neto gravado
 * @param string $cuit   CUIT del cliente
 * @param int    $codcri categoría IVA (5 = Consumidor Final → sin percep)
 * @param mixed  $spicue flag del cliente "sujeto a percepción"
 * @param bool   $estTrue blanco (true) / negro (false → sin percep)
 */
function percep_calc($net, $cuit, $codcri, $spicue, $estTrue) {
    $net = round((float) $net, 2);
    $rc = db_row("SELECT PIXCDC, MNPPIX, ALIPIX FROM [Rec Control];");
    $pixcdc = $rc && ($rc['PIXCDC'] === true || $rc['PIXCDC'] == -1);   // True = percepción DESACTIVADA
    $mnppix = round((float) nz($rc ? $rc['MNPPIX'] : 0, 0), 2);
    $sujeto = ($spicue === true || $spicue == -1) && ((int) $codcri != 5) && $estTrue && !$pixcdc;
    if (!$sujeto) return array('spimov' => false, 'pixmov' => 0.0, 'alipix' => 0.0, 'mnppix' => $mnppix);
    $alipix = padron_percep_alicuota($cuit);
    if ($alipix === null) $alipix = (float) nz($rc ? $rc['ALIPIX'] : 0, 0);   // default Rec Control si el CUIT no está en el padrón
    $pixmov = ($net > $mnppix) ? round($net * $alipix / 100, 2) : 0.0;
    return array('spimov' => true, 'pixmov' => $pixmov, 'alipix' => round($alipix, 4), 'mnppix' => $mnppix);
}
