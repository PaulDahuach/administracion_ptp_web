<?php
/**
 * Exportación I.V.A. Ventas a Holistor — porta `Menu.rutExportacion_HolistorIVAVentas` + `Qry CD Exportacion
 * IVA Holistor`. Espejo del de Compras ([[imputaciones-holistor]]) pero del lado DEUDORES (ventas):
 *  - Libro BLANCO (ESTMOV=True), CODORI='D', operación/auxiliar IVA-relevante (IVAOPE/IVAAUX).
 *  - Una fila por (comprobante, ALÍCUOTA) leyendo Tbl Movimientos IVA (NO por imputación). NO usa mapeo
 *    cuenta→CODHOL: los códigos son FIJOS (net='VTA', no gravado='NG', percep.IIBB='PIB').
 *  - qryFct = IIf(CODOPE=460,-1,1) (NC invierte). NOGMOV/percepción van SÓLO en la 1ª fila del comprobante;
 *    la percep. IIBB (PIXMOV) va en la misma fila si hay 1 alícuota, o como fila aparte si hay 2 (qryCnt>1).
 * Reusa los helpers de formato del módulo de Compras (HOL_LIB). Sin Outlook ni reporte Access → descarga web.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
define('HOL_LIB', 1);
require_once __DIR__ . '/../holistor_iva_compras/api.php';   // hol_num/hol_fecha/hol_pad/hol_rpad/hol_cri/hol_cuit/hol_dom/hol_serial

if (!defined('HOLV_LIB')) {
    auth_require_login();
    header('Content-Type: application/json');
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    try {
        switch ($action) {
            case 'preview':  holv_preview();   break;
            case 'exportar': holv_exportar();  break;
            default: fail('Acción inválida: ' . $action);
        }
    } catch (Exception $e) { fail($e->getMessage(), 500); }
}

/** CIC del legacy (FV→F, NC→C, ND→D, RC→RE). */
function holv_cic($r) {
    $c = trim((string) nz($r['CICMOV'], ''));
    if ($c === 'FV') return 'F ';
    if ($c === 'NC') return 'C ';
    if ($c === 'ND') return 'D ';
    if ($c === 'RC') return 'RE';
    return '??';
}
function holv_cii($r) { return substr(trim((string) nz($r['CIIMOV'], '')) . ' ', 0, 1); }
function holv_clean($s) { return str_replace(array("\r", "\n"), '', trim((string) nz($s, ''))); }

/** Las filas del período (porta Qry CD Exportacion IVA Holistor): una por (comprobante, alícuota). Libro blanco. */
function holv_query($desde, $hasta) {
    $d1 = hol_serial($desde); $d2 = hol_serial($hasta);
    if ($d1 === null || $d2 === null) throw new Exception('Rango de fechas inválido');
    $sql = "SELECT M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.FIXMOV, M.CODOPE, M.CODCRI, M.NOGMOV, M.PIXMOV, M.TOTMOV,
        M.CITMOV, M.DENMOV, M.DCXMOV, M.DNXMOV, M.DPXMOV, M.DDXMOV, M.CODLOC, Cri.INICRI, L.CPXLOC, P.HMLPRO,
        MI.ALIMOV, MI.NETMOV, MI.IRIMOV, MI.DECMOV
      FROM ((((([Tbl Movimientos] AS M
        LEFT JOIN [Tbl Operaciones] AS O ON O.CODOPE=M.CODOPE)
        LEFT JOIN [Tbl Operaciones Auxiliares] AS A ON A.CODAUX=M.CODAUX)
        INNER JOIN [Tbl Categorias Responsabilidad IVA] AS Cri ON Cri.CODCRI=M.CODCRI)
        LEFT JOIN [Tbl Localidades] AS L ON L.CODLOC=M.CODLOC)
        LEFT JOIN [Tbl Provincias] AS P ON P.CODPRO=L.CODPRO)
        LEFT JOIN [Tbl Movimientos IVA] AS MI ON MI.NUMMOV=M.NUMMOV
      WHERE M.CODORI='D' AND M.ESTMOV=True AND M.FIXMOV>=$d1 AND M.FIXMOV<=$d2 AND (O.IVAOPE=True OR A.IVAAUX=True)
      ORDER BY M.FIXMOV, M.NUMMOV, MI.DECMOV;";
    return db_query($sql);
}

function holv_header() {
    return implode(' ', array(
        'CM', 'I', 'PDV.', 'NUMERO..', 'FECHA.....', 'VTA',
        'NETO................', 'NG.', 'NO GRAVADO..........', 'EXC EXEN', 'PERC', 'PERCEPCIONES........',
        'ALIC.IVA.', 'I.V.A. LIQUIDADO....', 'I.V.A. DEBITO FISCAL', 'TOTAL...............',
        'COND', 'C.U.I.T..CLI.', hol_pad('NOMBRE CLIENTE', 50), hol_pad('DOMICILIO CLIENTE', 50), 'C.P.', 'PR', 'DC'
    ));
}

/** Campos compartidos del final de la línea (cond, CUIT, nombre, domicilio, CP, prov, doc). */
function holv_tail($r) {
    return array(
        hol_cri($r), hol_pad(hol_cuit($r['CITMOV']), 13),
        hol_pad(holv_clean($r['DENMOV']), 50), hol_pad(holv_clean(hol_dom($r)), 50),
        hol_rpad(trim((string) nz($r['CPXLOC'], '')), 4),
        ($r['CODLOC'] === null) ? '  ' : hol_rpad(str_pad((string) (int) nz($r['HMLPRO'], 0), 2, '0', STR_PAD_LEFT), 2),
        hol_rpad('80', 2)
    );
}
/** Cabecera del comprobante (CIC/letra/PDV/número/fecha/VTA), común a la fila normal y a la de percepción. */
function holv_head($r) {
    return array(
        holv_cic($r), holv_cii($r),
        str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT),
        str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        hol_fecha($r['FIXMOV']), 'VTA'
    );
}

/** Una fila normal (alícuota). $first = primera fila del comprobante (lleva NOGMOV); $qryCnt = filas del comprobante. */
function holv_line($r, $first, $qryCnt) {
    $fct = ((int) $r['CODOPE'] === 460) ? -1 : 1;
    $netmov = $r['NETMOV']; $irimov = (float) nz($r['IRIMOV'], 0);
    $nogmov = $r['NOGMOV']; $pix = (float) nz($r['PIXMOV'], 0);
    if ($netmov === null) {                                  // no discriminado
        $net = str_repeat(' ', 20); $ali = str_repeat(' ', 10); $iva = str_repeat(' ', 20);
        $sTot = (float) nz($r['TOTMOV'], 0);
    } else {
        $net = hol_rpad(hol_num((float) $netmov * $fct), 20);
        $ali = hol_rpad(hol_num((float) nz($r['ALIMOV'], 0)), 10);
        $iva = hol_rpad(hol_num($irimov * $fct), 20);
        $sTot = (float) $netmov + $irimov;
        if ($first) $sTot += (float) nz($nogmov, 0) + $pix;
    }
    $sTot = hol_rpad(hol_num(round($sTot * $fct, 2)), 20);
    if ($first && $nogmov !== null) { $nog = hol_rpad(hol_num((float) $nogmov * $fct), 20); $lblNog = 'NG '; }
    else { $nog = str_repeat(' ', 20); $lblNog = '   '; }
    $capPerc = '   '; $impPerc = str_repeat(' ', 20);
    if ($qryCnt == 1 && $pix > 0) { $capPerc = 'PIB'; $impPerc = hol_rpad(hol_num($pix * $fct), 20); }
    return implode(' ', array_merge(holv_head($r), array($net, $lblNog, $nog, 'EXC 0.00', $capPerc, $impPerc, $ali, $iva, $iva, $sTot), holv_tail($r)));
}
/** Fila aparte de percepción IIBB (cuando el comprobante tiene >1 alícuota). */
function holv_line_pix($r) {
    $fct = ((int) $r['CODOPE'] === 460) ? -1 : 1;
    $impPerc = hol_rpad(hol_num((float) nz($r['PIXMOV'], 0) * $fct), 20);
    return implode(' ', array_merge(holv_head($r),
        array(str_repeat(' ', 20), '   ', str_repeat(' ', 20), 'EXC 0.00', 'PIB', $impPerc, str_repeat(' ', 10), str_repeat(' ', 20), str_repeat(' ', 20), $impPerc),
        holv_tail($r)));
}

/** Genera el .txt completo (con el estado por-comprobante: 1ª fila, qryCnt, fila PIX). */
function holv_build($desde, $hasta, $conHeader) {
    $rows = array(); foreach (holv_query($desde, $hasta) as $r) $rows[] = $r;
    $civ = array();                                          // alícuotas (ALIMOV no nulo) por comprobante
    foreach ($rows as $r) { $n = (int) $r['NUMMOV']; if (!isset($civ[$n])) $civ[$n] = 0; if ($r['ALIMOV'] !== null) $civ[$n]++; }
    $out = array(); if ($conHeader) $out[] = holv_header();
    $lng = -1; $intRow = 0;
    foreach ($rows as $r) {
        $num = (int) $r['NUMMOV']; $qryCIV = $civ[$num]; $pix = (float) nz($r['PIXMOV'], 0);
        $qryPIX = ($qryCIV > 1 && $pix > 0) ? 1 : 0; $qryCnt = $qryCIV + $qryPIX;
        $first = ($lng !== $num); if ($first) $intRow = 1;
        $out[] = holv_line($r, $first, $qryCnt);
        $insPix = false;
        if ($qryPIX > 0) { if ($qryCIV == 2) { if ($intRow == 2) $insPix = true; } else $insPix = true; }
        if ($insPix) { $out[] = holv_line_pix($r); $intRow++; }
        $lng = $num; $intRow++;
    }
    return implode("\r\n", $out) . "\r\n";
}

function holv_preview() {
    $desde = isset($_GET['desde']) ? $_GET['desde'] : ''; $hasta = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $rows = array(); $n = 0; $comps = array();
    foreach (holv_query($desde, $hasta) as $r) {
        $n++; $comps[(int) $r['NUMMOV']] = true;
        $fct = ((int) $r['CODOPE'] === 460) ? -1 : 1;
        if ($n <= 500) $rows[] = array(
            'NUMMOV' => (int) $r['NUMMOV'], 'FECHA' => fecha_serial($r['FIXMOV']),
            'COMP' => trim(trim((string) nz($r['CICMOV'], '')) . ' ' . trim((string) nz($r['CIIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT)),
            'CLIENTE' => trim((string) nz($r['DENMOV'], '')),
            'ALI' => ($r['ALIMOV'] === null ? null : round((float) $r['ALIMOV'], 2)),
            'NETO' => round((float) nz($r['NETMOV'], 0) * $fct, 2), 'IVA' => round((float) nz($r['IRIMOV'], 0) * $fct, 2),
            'PIX' => round((float) nz($r['PIXMOV'], 0) * $fct, 2),
        );
    }
    ok(array('total' => $n, 'comprobantes' => count($comps), 'rows' => $rows));
}

function holv_exportar() {
    $desde = isset($_GET['desde']) ? $_GET['desde'] : ''; $hasta = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $conHeader = isset($_GET['header']) && $_GET['header'] === '1';
    $body = holv_build($desde, $hasta, $conHeader);
    header('Content-Type: text/plain; charset=windows-1252');
    header('Content-Disposition: attachment; filename="I.V.A. Ventas.txt"');
    echo iconv('UTF-8', 'Windows-1252//TRANSLIT', $body);
}
