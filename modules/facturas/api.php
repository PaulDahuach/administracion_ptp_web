<?php
/**
 * Facturas de Venta (deudores) — API. Porta el SetData Case "A" de `Frm CD Facturas NF`.
 * FV electrónica (CODOPE=420, CICMOV='FV', clase A/B con CAE de AFIP). Espejo contable de las compras.
 *
 * fv_insert($d, $estTrue, $afip): SIN control de transacción (el caller la envuelve en db_begin/commit, o el
 * test en db_begin/rollback). El guard FV_LIB evita el dispatch+auth cuando se incluye como librería.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
if (!defined('FV_LIB')) {
    require_once __DIR__ . '/../../includes/auth.php';
    auth_require_login();
    header('Content-Type: application/json; charset=utf-8');
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    if (function_exists('track_hit')) {}
    switch ($action) {
        case 'buscar_clientes':    buscar_clientes(); break;
        case 'get_cliente':        get_cliente(); break;
        case 'remitos_pendientes': remitos_pendientes(); break;
        case 'pdvs':               listar_pdvs(); break;
        case 'condiciones':        listar_condiciones(); break;
        case 'formas_pago':        listar_formas_pago(); break;
        case 'bancos':             listar_bancos(); break;
        case 'listar':             listar(); break;
        case 'detalle':            detalle(); break;
        case 'guardar':            guardar(); break;
        default: fail('Acción inválida: ' . $action);
    }
}

// ───────────────────────── Lookups ─────────────────────────
function fac_serial_iso($s) { if ($s === null || $s === '') return ''; return (new DateTime('1899-12-30'))->modify('+' . (int) $s . ' days')->format('Y-m-d'); }
/** Filtro de visibilidad por libro (doble libro): blanco→ESTMOV=True, negro→False, integral→sin filtro. */
function fv_estmov_w() { $l = auth_libro_unico(); if ($l === 'blanco') return ' AND ESTMOV=True'; if ($l === 'negro') return ' AND ESTMOV=False'; return ''; }

function buscar_clientes() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q); $num = is_numeric($q) ? ' OR CODCUE = ' . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='D' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

function get_cliente() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $c = db_row("SELECT C.CODCUE, C.DENCUE, C.CITCUE, C.SOPCUE, C.DCXCUE, C.DNXCUE, C.DPXCUE, C.DDXCUE, C.CODLOC, L.DENLOC, P.DENPRO,
        C.CODCRI, C.CODCAT, C.CODVEN, C.CODCDV, C.SPICUE, C.APICUE, C.APBCUE
        FROM ([Tbl Provincias] AS P RIGHT JOIN ([Tbl Localidades] AS L INNER JOIN [Tbl Cuentas Corrientes] AS C ON L.CODLOC=C.CODLOC) ON P.CODPRO=L.CODPRO)
        WHERE C.CODORI='D' AND C.CODCUE=$cc;");
    if (!$c) { fail('Cliente no encontrado'); return; }
    $cri = db_row("SELECT DENCRI, ICXCRI, IVACRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($c['CODCRI'], 0) . ";");
    $c['LETRA'] = $cri ? strtoupper(trim((string) nz($cri['ICXCRI'], 'A'))) : 'A';      // A (RI) / B (CF, Monotributo)
    $c['DENCRI'] = $cri ? trim((string) nz($cri['DENCRI'], '')) : '';
    $c['COND_IVA'] = ((int) nz($c['CODCRI'], 0) == 1) ? 1 : 5;                            // RG 5616: 1=RI, 5=CF
    $c['SALDO'] = round((float) nz($c['SOPCUE'], 0), 2);
    $c['DOMICILIO'] = trim(nz($c['DCXCUE'], '') . ' ' . nz($c['DNXCUE'], ''));
    $c['LOCALIDAD'] = trim('(' . nz($c['DPXCUE'] ? '' : '', '') . ') ' . nz($c['DENLOC'], '') . ' - ' . nz($c['DENPRO'], ''));
    $c['LOCALIDAD'] = trim(nz($c['DENLOC'], '') . (nz($c['DENPRO'], '') ? ' - ' . nz($c['DENPRO'], '') : ''));
    ok($c);
}

/** Remitos (RV) pendientes de facturar del cliente + sus líneas de producto con la cuenta de ventas y alícuota. */
function remitos_pendientes() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    // cuenta de ventas (VTARUB) + alícuota por producto, cacheadas
    $out = array();
    foreach (db_query("SELECT NUMMOV, CINMOV, CIPMOV, CIIMOV, FEXMOV FROM [Tbl Movimientos] WHERE CODORI='D' AND CICMOV='RV' AND CODCUE=$cc AND SRPMOV=True" . fv_estmov_w() . " ORDER BY NUMMOV;") as $rv) {
        $lineas = array();
        foreach (db_query("SELECT S.ORDMOV, S.CODPRO, S.DENMOV, S.EGRMOV, S.SVCMOV, S.CFVMOV, S.PUNMOV, S.PULMOV, S.PUCMOV, S.COSMOV, S.CODUDM, S.FCTMOV, S.DUMMOV, S.CODMON, S.DECMOV, S.ODCMOV, S.PDLMOV, S.ODPMOV, S.STKMOV, S.IBXMOV
            FROM [Tbl Movimientos Stock] AS S WHERE S.NUMMOV=" . (int) $rv['NUMMOV'] . ";") as $l) {
            // Cantidad del remito: EGRMOV (no-stock) o |SVCMOV| (stock-controlado, guardado negativo).
            $entreg = ($l['EGRMOV'] !== null && $l['EGRMOV'] !== '') ? round((float) $l['EGRMOV'], 2) : abs(round((float) nz($l['SVCMOV'], 0), 2));
            $cfv = round((float) nz($l['CFVMOV'], 0), 2);
            $pend = round($entreg - $cfv, 2);
            if ($pend <= 0.0001) continue;
            // Cuenta de ventas = Tbl Subrubros.VTASUB por CODRUB+CODSUB del producto.
            $prod = db_row("SELECT CODRUB, CODSUB FROM [Tbl Productos] WHERE CODPRO='" . db_esc(trim((string) $l['CODPRO'])) . "';");
            $cic = '';
            if ($prod) {
                $sr = db_row("SELECT VTASUB FROM [Tbl Subrubros] WHERE CODRUB=" . (int) nz($prod['CODRUB'], 0) . " AND CODSUB=" . (int) nz($prod['CODSUB'], 0) . ";");
                if ($sr) $cic = trim((string) nz($sr['VTASUB'], ''));
            }
            $pun = round((float) nz($l['PUNMOV'], 0), 4);
            $lineas[] = array(
                'mrvmov' => (int) $rv['NUMMOV'], 'orvmov' => (int) $l['ORDMOV'], 'codpro' => trim((string) $l['CODPRO']), 'denmov' => trim((string) nz($l['DENMOV'], '')),
                'cant' => $pend, 'pun' => $pun, 'pul' => round((float) nz($l['PULMOV'], 0), 4), 'puc' => round((float) nz($l['PUCMOV'], 0), 4), 'cos' => round((float) nz($l['COSMOV'], 0), 4),
                'codudm' => (int) nz($l['CODUDM'], 1), 'fctmov' => round((float) nz($l['FCTMOV'], 1), 4), 'dummov' => (int) nz($l['DUMMOV'], 0), 'codmon' => trim((string) nz($l['CODMON'], 'P')), 'decmov' => ($l['DECMOV'] === true || $l['DECMOV'] == -1) ? 1 : 0,
                'odcmov' => (int) nz($l['ODCMOV'], 0), 'pdlmov' => (int) nz($l['PDLMOV'], 0), 'odpmov' => (int) nz($l['ODPMOV'], 0), 'stk' => ($l['STKMOV'] === true || $l['STKMOV'] == -1) ? 1 : 0,
                'cic' => $cic, 'ali' => 21, 'total' => round($pend * $pun, 2),
            );
        }
        if (count($lineas)) $out[] = array('NUMMOV' => (int) $rv['NUMMOV'], 'COMP' => 'RV ' . trim((string) nz($rv['CIIMOV'], '')) . ' ' . str_pad((string) (int) nz($rv['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($rv['CINMOV'], 0), 8, '0', STR_PAD_LEFT), 'FEXMOV' => fecha_serial($rv['FEXMOV']), 'lineas' => $lineas);
    }
    ok($out);
}

/** Listado de Facturas de Venta emitidas (CODOPE=420). */
function listar() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sd = isset($_GET['desde']) && $_GET['desde'] ? (int) (new DateTime('1899-12-30'))->diff(new DateTime($_GET['desde']))->days : null;
    $sh = isset($_GET['hasta']) && $_GET['hasta'] ? (int) (new DateTime('1899-12-30'))->diff(new DateTime($_GET['hasta']))->days : null;
    $w = 'CODOPE=420' . fv_estmov_w();   // visibilidad por libro (blanco/negro/integral)
    if ($q !== '') { $qs = db_esc($q); $cond = "(DENMOV Like '%$qs%' OR CITMOV Like '%$qs%' OR CAEMOV Like '%$qs%'"; if (is_numeric($q)) $cond .= ' OR CINMOV=' . (int) $q; $w .= " AND $cond)"; }
    if ($sd !== null) $w .= " AND FEXMOV >= $sd";
    if ($sh !== null) $w .= " AND FEXMOV <= $sh";
    $rows = db_query("SELECT TOP 200 NUMMOV, FEXMOV, CIIMOV, CIPMOV, CINMOV, DENMOV, TOTMOV, CAEMOV, ANUMOV FROM [Tbl Movimientos] WHERE $w ORDER BY FEXMOV DESC, NUMMOV DESC;");
    $out = array();
    foreach ($rows as $r) $out[] = array('NUMMOV' => (int) $r['NUMMOV'], 'FEXMOV' => fecha_serial($r['FEXMOV']), 'FEXMOVO' => (int) nz($r['FEXMOV'], 0),
        'COMP' => 'FV ' . trim((string) nz($r['CIIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        'DENMOV' => trim((string) nz($r['DENMOV'], '')), 'TOTMOV' => round((float) nz($r['TOTMOV'], 0), 2), 'CAE' => trim((string) nz($r['CAEMOV'], '')),
        'ANU' => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1) ? 1 : 0);
    ok(array('facturas' => $out, 'tope' => count($out) >= 200));
}

/** Detalle de una FV para verla en pantalla, bloqueada. */
function detalle() {
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT * FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=420;");
    if (!$h) { fail('Factura no encontrada'); return; }
    $estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1); $lib = auth_libro_unico();
    if (($lib === 'blanco' && !$estTrue) || ($lib === 'negro' && $estTrue)) { fail('Factura no disponible en este libro'); return; }
    $loc = db_row("SELECT L.DENLOC, P.DENPRO FROM [Tbl Localidades] AS L LEFT JOIN [Tbl Provincias] AS P ON L.CODPRO=P.CODPRO WHERE L.CODLOC=" . (int) nz($h['CODLOC'], 0) . ";");
    $cri = db_row("SELECT DENCRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($h['CODCRI'], 0) . ";");
    $prods = array();
    foreach (db_query("SELECT ORDMOV, MRVMOV, CODPRO, DENMOV, EGRMOV, PUNMOV, PDLMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num ORDER BY ORDMOV;") as $s) {
        $rem = '';
        if ($s['MRVMOV'] !== null && $s['MRVMOV'] !== '') { $rm = db_row("SELECT CINMOV FROM [Tbl Movimientos] WHERE NUMMOV=" . (int) $s['MRVMOV'] . ";"); if ($rm) $rem = (int) nz($rm['CINMOV'], 0); }
        $cant = round((float) nz($s['EGRMOV'], 0), 2); $pun = round((float) nz($s['PUNMOV'], 0), 2);
        $prods[] = array('cant' => $cant, 'rem' => $rem, 'ptp' => (int) nz($s['PDLMOV'], 0), 'codpro' => trim((string) nz($s['CODPRO'], '')), 'denmov' => trim((string) nz($s['DENMOV'], '')), 'pun' => $pun, 'total' => round($cant * $pun, 2));
    }
    ok(array('NUMMOV' => $num, 'CINMOV' => (int) nz($h['CINMOV'], 0), 'CIPMOV' => (int) nz($h['CIPMOV'], 0), 'LETRA' => strtoupper(trim((string) nz($h['CIIMOV'], 'A'))),
        'FEXISO' => fac_serial_iso($h['FEXMOV']), 'CODCUE' => (int) nz($h['CODCUE'], 0), 'DENMOV' => trim((string) nz($h['DENMOV'], '')), 'CITMOV' => trim((string) nz($h['CITMOV'], '')),
        'DOMICILIO' => trim(nz($h['DCXMOV'], '') . ' ' . nz($h['DNXMOV'], '')), 'LOCALIDAD' => $loc ? trim(nz($loc['DENLOC'], '') . (nz($loc['DENPRO'], '') ? ' - ' . nz($loc['DENPRO'], '') : '')) : '', 'DENCRI' => $cri ? trim((string) nz($cri['DENCRI'], '')) : '',
        'CODCDV' => (int) nz($h['CODCDV'], 0), 'CODFDP' => (int) nz($h['CODFDP'], 0), 'PDCMOV' => round((float) nz($h['PDCMOV'], 0), 2), 'DETMOV' => trim((string) nz($h['DETMOV'], '')),
        'NETMOV' => round((float) nz($h['NETMOV'], 0), 2), 'IRIMOV' => round((float) nz($h['IRIMOV'], 0), 2), 'TOTMOV' => round((float) nz($h['TOTMOV'], 0), 2),
        'CAE' => trim((string) nz($h['CAEMOV'], '')), 'CAE_VTO' => $h['FVCMOV'] ? fecha_serial($h['FVCMOV']) : '', 'ANU' => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) ? 1 : 0, 'productos' => $prods));
}

function listar_pdvs() { ok(db_query("SELECT CODPDV, NOMPDV FROM [Tbl Puntos de Venta] WHERE CODPDV <> 9999 ORDER BY CODPDV;")); }
function listar_condiciones() { ok(db_query("SELECT CODCDV, DENCDV FROM [Tbl Condiciones de Venta] ORDER BY DENCDV;")); }
function listar_formas_pago() { ok(db_query("SELECT CODFDP, DENFDP, CHQFDP FROM [Tbl Formas de Pago] ORDER BY CODFDP;")); }
function listar_bancos() { ok(db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos] ORDER BY DENBAN;")); }

function fv_iso($s) { if ($s === null || $s === '') return null; if (is_numeric($s)) return (int) $s; return (int) (new DateTime('1899-12-30'))->diff(new DateTime($s))->days; }
/** Serial de Access desde un cae_vto de AFIP (formato Ymd '20260616') o un serial/iso ya dado. */
function fv_ymd_serial($v) {
    $v = (string) $v;
    if (preg_match('/^\d{8}$/', $v)) { $dt = DateTime::createFromFormat('Ymd', $v); return $dt ? (int) (new DateTime('1899-12-30'))->diff($dt)->days : null; }
    return fv_iso($v);
}
function fv_txt($s) { $s = trim((string) $s); return $s === '' ? 'Null' : "'" . db_esc($s) . "'"; }
function fv_num($v) { return $v === null || $v === '' ? 'Null' : (string) round((float) $v, 2); }

/** Imputación contable + mayorización (DEBCUE/CRECUE), espejo de op_imp. $codchq/$fax para cheques. */
function fv_imp(&$ord, &$totDeb, &$totCre, $nummov, $cuenta, $deb, $cre, $codchq = null, $fax = null) {
    $deb = round((float) $deb, 2); $cre = round((float) $cre, 2);
    $ord++;
    $cc = db_esc((string) $cuenta);
    $bal = db_row("SELECT DEBCUE, CRECUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
    $soc = $bal ? round((float) nz($bal['DEBCUE'], 0) - (float) nz($bal['CRECUE'], 0), 2) : 0;
    $debSql = $deb > 0 ? (string) $deb : 'Null';
    $creSql = $cre > 0 ? (string) $cre : 'Null';
    $chqSql = ($codchq !== null && $codchq !== '') ? (int) $codchq : 'Null';
    $faxSql = ($fax !== null && $fax !== '') ? (int) $fax : 'Null';
    db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, DEBMOV, CREMOV, CODCDC, SOCMOV, CODCHQ, FAXMOV)
        VALUES ($nummov, $ord, '$cc', $debSql, $creSql, 1, $soc, $chqSql, $faxSql);");
    if ($deb > 0) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE + $deb WHERE CODCUE='$cc';");
    if ($cre > 0) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE + $cre WHERE CODCUE='$cc';");
    $totDeb += $deb; $totCre += $cre;
}

/**
 * Recibo de contado vinculado a una FV (CODCDV=1 + cheques). Porta el bloque RECIBO del SetData:
 * RC (CODOPE=480/CODAUX=481) que cobra los cheques (DEBE valores a depositar CACC_2 + caja CACC_1) contra
 * deudores (HABER CACC_A = total de la FV). Los cheques quedan en cartera (VADCHQ=True).
 */
function fv_recibo_insert($recNum, $d, $estTrue, $fvNum, $ciimov, $cipmov, $debmov, $impcaj, $cheques, $cli) {
    $rc = db_row("SELECT CACC_A, CACC_1, CACC_2, CACC_Z FROM [Rec Control];");
    $caccA = trim((string) $rc['CACC_A']); $cacc1 = trim((string) $rc['CACC_1']); $cacc2 = trim((string) $rc['CACC_2']); $caccZ = trim((string) $rc['CACC_Z']);
    $fex = fv_iso($d['fexmov']); $estSql = $estTrue ? 'True' : 'False';
    $cinmov = next_number_pdv('ULTREC', $cipmov);
    $det = 'FC-' . $ciimov . '-' . str_pad((string) $cipmov, 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) $fvNum, 8, '0', STR_PAD_LEFT);
    $sdomov = ($impcaj > $debmov) ? round(($impcaj - $debmov) * -1, 2) : 0;
    $soc = round((float) nz($d['soc'], 0), 2);

    db_exec("INSERT INTO [Tbl Movimientos]
        (NUMMOV, CODORI, FEXMOV, CODOPE, CODAUX, CICMOV, CIIMOV, CIPMOV, CINMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV,
         CODCUE, SOCMOV, DENMOV, DCXMOV, DNXMOV, CODLOC, CODCRI, CITMOV, CODCDV, CODFDP, DETMOV, COTMOV, CREMOV, TOTMOV, SDOMOV, ESTMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($recNum, 'D', $fex, 480, 481, 'RC', '$ciimov', $cipmov, $cinmov, 'RC', '$ciimov', $cipmov, $cinmov, $fex,
         " . (int) $d['codcue'] . ", " . fv_num($soc) . ", " . fv_txt(nz($cli['DENCUE'], '')) . ", " . fv_txt(nz($d['dcxmov'], '')) . ", " . fv_txt(nz($d['dnxmov'], '')) . ", " . (int) nz($d['codloc'], 0) . ", " . (int) nz($d['codcri'], 0) . ", " . fv_txt(nz($d['citmov'], '')) . ", 1, " . (int) nz($d['codfdp'], 4) . ", " . fv_txt($det) . ", " . round((float) nz($d['cotmov'], 1), 4) . ", $impcaj, $impcaj, $sdomov, $estSql, 0, 0, Now());");

    // Asiento: DEBE cheques (CACC_2, cada uno creado en cartera) + caja (CACC_1); HABER deudores (CACC_A).
    $ord = 0; $totDeb = 0; $totCre = 0; $totChq = 0;
    foreach ($cheques as $c) {
        $imp = round((float) nz($c['imp'], 0), 2); if ($imp <= 0) continue; $totChq += $imp;
        $chq = next_number('ULTCHQ');
        $fexc = fv_iso(isset($c['fex']) ? $c['fex'] : ''); $faxc = fv_iso(isset($c['fax']) ? $c['fax'] : '');
        db_exec("INSERT INTO [Tbl Cheques] (CODCHQ, CODBAN, SYNCHQ, FEXCHQ, FAXCHQ, IMPCHQ, PLZCHQ, LIBCHQ, CITCHQ, LOCCHQ, VADCHQ)
            VALUES ($chq, " . (int) nz($c['codban'], 0) . ", " . fv_txt(nz($c['syn'], '')) . ", " . ($fexc === null ? 'Null' : $fexc) . ", " . ($faxc === null ? 'Null' : $faxc) . ", $imp, " . (int) nz(isset($c['plz']) ? $c['plz'] : 0, 0) . ", " . fv_txt(nz($c['lib'], '')) . ", " . fv_txt(nz(isset($c['cit']) ? $c['cit'] : '', '')) . ", " . fv_txt(nz(isset($c['loc']) ? $c['loc'] : '', '')) . ", True);");
        fv_imp($ord, $totDeb, $totCre, $recNum, $cacc2, $imp, 0, $chq, $faxc);
    }
    $efe = round($impcaj - $totChq, 2);
    if ($efe > 0) fv_imp($ord, $totDeb, $totCre, $recNum, $cacc1, $efe, 0);
    fv_imp($ord, $totDeb, $totCre, $recNum, $caccA, 0, $debmov);
    // Balanceo
    $dif = round($totDeb - $totCre, 2);
    if (abs($dif) >= 0.005) { if ($dif > 0) fv_imp($ord, $totDeb, $totCre, $recNum, $caccZ, 0, $dif); else fv_imp($ord, $totDeb, $totCre, $recNum, $caccZ, -$dif, 0); }
    return $cinmov;
}

/**
 * Graba una Factura de Venta. $d (del form): codcue, fexmov(iso), ciimov(A/B), cipmov, codcdv, codfdp,
 * codtra, coddst, codven, detmov, cotmov, pdcmov, pdgmov, idgmov, soc(SOCMOV), spimov, apimov, mpimov, pixmov,
 * netmov, irimov, abimov, ardmov, totmov, impcaj(efectivo contado),
 * iva:[{ali, net, iri, dec(bool)}], productos:[{mrvmov,orvmov,odcmov,pdlmov,odpmov,codpro,denmov,codudm,fctmov,
 *   dummov,codmon,decmov,eximov,pulmov,punmov,pucmov,bonmov,ibxmov,picmov,cosmov,egr,stk,cic(cta rubro),ndb(neto),opc}],
 * vencimientos:[{fvxmov(iso),detmov,debmov}].
 * $afip = {cinmov, cae, cae_vto(iso o serial), coddoc} (el nº y CAE los da AFIP); null = borrador.
 */
function fv_insert($d, $estTrue, $afip) {
    $rc = db_row("SELECT CACC_A, CACC_C, CACC_N, CACC_1, CACC_Z FROM [Rec Control];");
    $caccA = trim((string) $rc['CACC_A']); $caccC = trim((string) $rc['CACC_C']);
    $caccN = trim((string) $rc['CACC_N']); $cacc1 = trim((string) $rc['CACC_1']);

    $codcue = (int) $d['codcue'];
    $cli = db_row("SELECT DENCUE, SOPCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='D';");
    if (!$cli) throw new Exception('Cliente inexistente');

    $fex = fv_iso($d['fexmov']);
    if ($fex === null) throw new Exception('Falta la fecha de emisión');
    $ciimov = strtoupper(trim((string) nz($d['ciimov'], 'A')));
    $cipmov = (int) nz($d['cipmov'], 0);
    $codcdv = (int) nz($d['codcdv'], 2);
    $codfdp = (int) nz($d['codfdp'], 9);
    $estSql = $estTrue ? 'True' : 'False';

    $netmov = round((float) nz($d['netmov'], 0), 2);
    $irimov = round((float) nz($d['irimov'], 0), 2);
    $pixmov = round((float) nz($d['pixmov'], 0), 2);
    $total  = round((float) nz($d['totmov'], 0), 2);
    $impcaj = round((float) nz(isset($d['impcaj']) ? $d['impcaj'] : 0, 0), 2);
    $soc    = round((float) nz($d['soc'], 0), 2);
    $prods  = isset($d['productos']) && is_array($d['productos']) ? $d['productos'] : array();
    $ivas   = isset($d['iva']) && is_array($d['iva']) ? $d['iva'] : array();
    $vtos   = isset($d['vencimientos']) && is_array($d['vencimientos']) ? $d['vencimientos'] : array();

    // Contado con cheque (CODCDV=1 + cheques): se genera un recibo RC vinculado (NRCMOV).
    $chequesContado = isset($d['cheques']) && is_array($d['cheques']) ? $d['cheques'] : array();
    $esContadoChq = ($codcdv == 1 && count($chequesContado) > 0);

    // Numeración: NUMMOV interno; CINMOV = nº de AFIP (electrónico) o contador local.
    $nummov = next_number('ULTMOV');
    $recNum = $esContadoChq ? next_number('ULTMOV') : null;
    $cinmov = ($afip && isset($afip['cinmov']) && $afip['cinmov']) ? (int) $afip['cinmov'] : next_number_pdv('ULTCM' . $ciimov, $cipmov);
    $coddoc = (int) nz(($afip && isset($afip['coddoc'])) ? $afip['coddoc'] : (isset($d['coddoc']) ? $d['coddoc'] : 80), 80);
    $caeSql  = ($afip && !empty($afip['cae'])) ? "'" . db_esc($afip['cae']) . "'" : 'Null';
    $fvcSerial = ($afip && !empty($afip['cae_vto'])) ? fv_ymd_serial($afip['cae_vto']) : null;
    $fvcSql  = ($fvcSerial === null) ? 'Null' : (string) $fvcSerial;

    // SDOMOV: 0 si contado (CODCDV=1); si hay saldo a favor (SOC<0) lo neteado; si no = total.
    $acuSAF = 0;   // saldo a favor a aplicar
    if ($soc >= 0) {
        $sdomov = ($codcdv == 1) ? 0 : $total;
    } elseif ($total > abs($soc)) {
        $sdomov = ($codcdv == 1) ? 0 : round($total + $soc, 2);
        $acuSAF = abs($soc);
    } else {
        $sdomov = 0;
        $acuSAF = $total;
    }

    // ── Header ──
    $denSql = fv_txt(nz($cli['DENCUE'], '')); $citSql = fv_txt(nz($d['citmov'], ''));
    $dcx = fv_txt(nz($d['dcxmov'], '')); $dnx = fv_txt(nz($d['dnxmov'], ''));
    $dpx = fv_txt(nz(isset($d['dpxmov']) ? $d['dpxmov'] : '', '')); $ddx = fv_txt(nz(isset($d['ddxmov']) ? $d['ddxmov'] : '', ''));
    $codloc = (int) nz($d['codloc'], 0); $codcri = (int) nz($d['codcri'], 0);
    $codven = isset($d['codven']) && $d['codven'] !== '' ? (int) $d['codven'] : 'Null';
    $codtra = isset($d['codtra']) && $d['codtra'] !== '' ? (int) $d['codtra'] : 'Null';
    $coddst = (int) nz($d['coddst'], 1);
    $detSql = fv_txt(isset($d['detmov']) ? $d['detmov'] : '');
    $cotmov = round((float) nz($d['cotmov'], 1), 4);
    $pdcmov = round((float) nz($d['pdcmov'], 0), 2);
    $pdgmov = round((float) nz(isset($d['pdgmov']) ? $d['pdgmov'] : 0, 0), 2);
    $idgmov = round((float) nz(isset($d['idgmov']) ? $d['idgmov'] : 0, 0), 2);
    $abimov = round((float) nz(isset($d['abimov']) ? $d['abimov'] : 0, 0), 2);
    $ardmov = round((float) nz(isset($d['ardmov']) ? $d['ardmov'] : 0, 0), 2);
    $spimov = (isset($d['spimov']) && $d['spimov']) ? 'True' : 'False';
    $apimov = round((float) nz(isset($d['apimov']) ? $d['apimov'] : 0, 0), 2);
    $mpimov = round((float) nz(isset($d['mpimov']) ? $d['mpimov'] : 0, 0), 2);
    $fdpRow = db_row("SELECT CHQFDP FROM [Tbl Formas de Pago] WHERE CODFDP=$codfdp;");
    $chqFdp = $fdpRow ? ($fdpRow['CHQFDP'] === true || $fdpRow['CHQFDP'] == -1) : false;
    $cremov = ($codfdp != 2 && !$chqFdp) ? (string) $impcaj : 'Null';   // cta cte/efectivo guardan IMPCAJ (0 en cta cte)

    db_exec("INSERT INTO [Tbl Movimientos]
        (NUMMOV, CODORI, FEXMOV, CODOPE, CODAUX, CICMOV, CIIMOV, CIPMOV, CINMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV, FIXMOV,
         CODCUE, SOCMOV, DENMOV, DCXMOV, DNXMOV, DPXMOV, DDXMOV, CODLOC, CODCRI, CITMOV, CODCDV, CODFDP, CODTRA, CODDST, CODVEN,
         PDCMOV, PDGMOV, IDGMOV, DETMOV, COTMOV, NETMOV, IRIMOV, ABIMOV, ARDMOV, SPIMOV, APIMOV, MPIMOV, PIXMOV,
         DEBMOV, CREMOV, TOTMOV, SDOMOV, NRCMOV, CODDOC, CAEMOV, FVCMOV, ESTMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($nummov, 'D', $fex, 420, 420, 'FV', '$ciimov', $cipmov, $cinmov, 'FV', '$ciimov', $cipmov, $cinmov, $fex, $fex,
         $codcue, " . fv_num($soc) . ", $denSql, $dcx, $dnx, $dpx, $ddx, $codloc, $codcri, $citSql, $codcdv, $codfdp, $codtra, $coddst, $codven,
         $pdcmov, $pdgmov, $idgmov, $detSql, $cotmov, " . fv_num($netmov) . ", " . fv_num($irimov) . ", $abimov, $ardmov, $spimov, $apimov, $mpimov, $pixmov,
         $total, $cremov, $total, $sdomov, " . ($recNum !== null ? (int) $recNum : 'Null') . ", $coddoc, $caeSql, $fvcSql, $estSql, 0, 0, Now());");

    // ── IVA (una fila por alícuota) ──
    foreach ($ivas as $iv) {
        $ali = round((float) nz($iv['ali'], 0), 2); $net = round((float) nz($iv['net'], 0), 2); $iri = round((float) nz($iv['iri'], 0), 2);
        if ($net == 0 && $iri == 0) continue;
        $dec = (isset($iv['dec']) && $iv['dec']) ? 'True' : 'False';
        db_exec("INSERT INTO [Tbl Movimientos IVA] (NUMMOV, ALIMOV, NETMOV, IRIMOV, DECMOV) VALUES ($nummov, $ali, $net, $iri, $dec);");
    }

    // ── Productos → Stock + acumular en el remito (CFVMOV) y apagar SRPMOV si quedó todo facturado ──
    $ord = 0;
    foreach ($prods as $p) {
        $ord++;
        $egr = round((float) nz($p['egr'], 0), 2);
        $mrv = isset($p['mrvmov']) && $p['mrvmov'] !== '' ? (int) $p['mrvmov'] : null;
        $orv = isset($p['orvmov']) && $p['orvmov'] !== '' ? (int) $p['orvmov'] : null;
        db_exec("INSERT INTO [Tbl Movimientos Stock]
            (NUMMOV, ORDMOV, MRVMOV, ORVMOV, ODCMOV, PDLMOV, ODPMOV, CODPRO, DENMOV, CODUDM, FCTMOV, DUMMOV, CODSUC, CODMON, DECMOV,
             EXIMOV, PULMOV, PUNMOV, PUCMOV, BONMOV, IBXMOV, PICMOV, COSMOV, CMDMOV, INGMOV, EGRMOV, STKMOV)
            VALUES ($nummov, $ord, " . ($mrv === null ? 'Null' : $mrv) . ", " . ($orv === null ? 'Null' : $orv) . ",
             " . fv_num(isset($p['odcmov']) ? $p['odcmov'] : '') . ", " . fv_num(isset($p['pdlmov']) ? $p['pdlmov'] : '') . ", " . fv_num(isset($p['odpmov']) ? $p['odpmov'] : '') . ",
             " . fv_txt(nz($p['codpro'], '')) . ", " . fv_txt(nz($p['denmov'], '')) . ", " . (int) nz($p['codudm'], 1) . ", " . round((float) nz($p['fctmov'], 1), 4) . ", " . (int) nz($p['dummov'], 0) . ", $coddst, " . fv_txt(nz($p['codmon'], 'P')) . ", " . ((isset($p['decmov']) && $p['decmov']) ? 'True' : 'False') . ",
             " . ((isset($p['eximov']) && $p['eximov']) ? 'True' : 'False') . ", " . round((float) nz($p['pulmov'], 0), 4) . ", " . round((float) nz($p['punmov'], 0), 4) . ", " . round((float) nz($p['pucmov'], 0), 4) . ", " . round((float) nz(isset($p['bonmov']) ? $p['bonmov'] : 0, 0), 2) . ", " . ((isset($p['ibxmov']) && $p['ibxmov']) ? 'True' : 'False') . ", " . round((float) nz(isset($p['picmov']) ? $p['picmov'] : 0, 0), 4) . ", " . round((float) nz($p['cosmov'], 0), 4) . ", 0, $egr, $egr, " . ((isset($p['stk']) && $p['stk']) ? 'True' : 'False') . ");");
        // Acumular cantidad facturada en la línea del remito; si quedó todo facturado, SRPMOV=False.
        if ($mrv !== null && $orv !== null) {
            $rs = db_row("SELECT CFVMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$mrv AND ORDMOV=$orv;");
            if ($rs) {
                db_exec("UPDATE [Tbl Movimientos Stock] SET CFVMOV = " . round((float) nz($rs['CFVMOV'], 0) + $egr, 2) . " WHERE NUMMOV=$mrv AND ORDMOV=$orv;");
                $pend = db_row("SELECT Count(*) AS N FROM [Tbl Movimientos Stock] WHERE NUMMOV=$mrv AND ((EGRMOV<>CFVMOV AND EGRMOV Is Not Null) OR (SVCMOV<>-CFVMOV));");
                if ((int) nz($pend['N'], 0) == 0) db_exec("UPDATE [Tbl Movimientos] SET SRPMOV=False WHERE NUMMOV=$mrv;");
            }
        }
    }

    // ── Vencimientos ──
    foreach ($vtos as $v) {
        $fvx = fv_iso($v['fvxmov']); if ($fvx === null) continue;
        db_exec("INSERT INTO [Tbl Movimientos Vencimientos] (NUMMOV, FVXMOV, DETMOV, DEBMOV)
            VALUES ($nummov, $fvx, " . fv_txt(isset($v['detmov']) ? $v['detmov'] : '') . ", " . round((float) nz($v['debmov'], 0), 2) . ");");
    }

    // ── Anticipos: aplicar saldo a favor del cliente (movimientos previos con SDOMOV<0) ──
    if ($acuSAF > 0) {
        $rest = $acuSAF;
        foreach (db_query("SELECT NUMMOV, SDOMOV FROM [Tbl Movimientos] WHERE CODCUE=$codcue AND SDOMOV<0 ORDER BY NUMMOV;") as $saf) {
            if ($rest <= 0.005) break;
            $disp = abs(round((float) nz($saf['SDOMOV'], 0), 2));
            $imp = ($disp < $rest) ? $disp : $rest;
            db_exec("INSERT INTO [Tbl Movimientos Anticipos] (NUMMOV, ANTMOV, IMPMOV) VALUES ($nummov, " . (int) $saf['NUMMOV'] . ", " . round($imp, 2) . ");");
            db_exec("UPDATE [Tbl Movimientos] SET SDOMOV = SDOMOV + " . round($imp, 2) . " WHERE NUMMOV=" . (int) $saf['NUMMOV'] . ";");
            $rest = round($rest - $imp, 2);
        }
    }

    // ── Asiento ──
    $ordi = 0; $totDeb = 0; $totCre = 0;
    // DEBE: contado efectivo parte Caja + Deudores; si no, Deudores por el total.
    if ($codcdv == 1 && $codfdp == 1) {
        if ($impcaj > 0) fv_imp($ordi, $totDeb, $totCre, $nummov, $cacc1, $impcaj, 0);
        if ($total > $impcaj) fv_imp($ordi, $totDeb, $totCre, $nummov, $caccA, round($total - $impcaj, 2), 0);
    } else {
        fv_imp($ordi, $totDeb, $totCre, $nummov, $caccA, $total, 0);
    }
    // HABER: ventas por rubro (neto de cada producto → su cuenta contable), acumulado.
    $rub = array();
    foreach ($prods as $p) {
        if (isset($p['opc']) && $p['opc'] !== '' && $p['opc'] !== null) continue;   // líneas marcadas opc no van a ventas
        $cic = trim((string) nz($p['cic'], ''));
        if ($cic === '') continue;
        $ndb = round((float) nz($p['ndb'], 0), 2);
        if (!isset($rub[$cic])) $rub[$cic] = 0;
        $rub[$cic] += $ndb;
    }
    foreach ($rub as $cic => $monto) {
        if (round($monto, 2) != 0) fv_imp($ordi, $totDeb, $totCre, $nummov, $cic, 0, round($monto, 2));
    }
    // HABER: IVA débito + percepción IIBB.
    if ($irimov > 0) fv_imp($ordi, $totDeb, $totCre, $nummov, $caccC, 0, $irimov);
    if ($pixmov > 0) fv_imp($ordi, $totDeb, $totCre, $nummov, $caccN, 0, $pixmov);

    // ── Cuenta corriente: SOPCUE += DEBMOV − efectivo de caja ──
    db_exec("UPDATE [Tbl Cuentas Corrientes] SET FUOCUE=$fex, SOPCUE = " . round((float) nz($cli['SOPCUE'], 0) + $total - $impcaj, 2) . " WHERE CODCUE=$codcue;");

    // ── Recibo de contado vinculado (CODCDV=1 + cheques) ──
    if ($esContadoChq) {
        $impCajRec = ($impcaj > 0) ? $impcaj : $total;   // en contado el efectivo cobrado = el total
        fv_recibo_insert($recNum, $d, $estTrue, $nummov, $ciimov, $cipmov, $total, $impCajRec, $chequesContado, $cli);
    }

    return array('nummov' => $nummov, 'cinmov' => $cinmov, 'total' => $total, 'sdomov' => $sdomov, 'recibo' => $recNum,
        'cae' => ($afip && isset($afip['cae'])) ? $afip['cae'] : null, 'balanceo' => round($totDeb - $totCre, 2));
}

/** Mapea la FV ($d) al request de WSFE solicitarCAE (sin cbte_desde/hasta, que dependen de la numeración). */
function fv_afip_request($d) {
    require_once __DIR__ . '/../../config/afip.php';
    $letra = strtoupper(trim((string) nz($d['ciimov'], 'A')));
    $cbteTipo = afip_cbte_tipo('FV', $letra);
    if (!$cbteTipo) throw new Exception('Clase de comprobante inválida: ' . $letra);
    $neto = round((float) nz($d['netmov'], 0), 2);
    $iva  = round((float) nz($d['irimov'], 0), 2);
    $pix  = round((float) nz($d['pixmov'], 0), 2);
    $total = round((float) nz($d['totmov'], 0), 2);
    $coddoc = (int) nz(isset($d['coddoc']) ? $d['coddoc'] : 80, 80);
    $docnro = preg_replace('/[^0-9]/', '', (string) nz($d['citmov'], ''));
    if ($docnro === '') { $docnro = '0'; $coddoc = 99; }   // sin CUIT → consumidor final
    $fexSerial = fv_iso($d['fexmov']);
    $cbteFch = (new DateTime('1899-12-30'))->modify('+' . (int) $fexSerial . ' days')->format('Ymd');

    $ivaArr = array();
    foreach ((isset($d['iva']) && is_array($d['iva']) ? $d['iva'] : array()) as $b) {
        $n = round((float) nz($b['net'], 0), 2); $i = round((float) nz($b['iri'], 0), 2);
        if ($n == 0 && $i == 0) continue;
        $ivaArr[] = array('Id' => afip_iva_id($b['ali']), 'BaseImp' => $n, 'Importe' => $i);
    }
    $req = array(
        'pto_vta' => AFIP_PTO_VTA, 'cbte_tipo' => $cbteTipo, 'concepto' => AFIP_CONCEPTO,
        'doc_tipo' => $coddoc, 'doc_nro' => $docnro, 'cbte_fch' => $cbteFch,
        'imp_neto' => $neto, 'imp_iva' => $iva, 'imp_trib' => $pix, 'imp_op_ex' => 0, 'imp_tot_conc' => 0, 'imp_total' => $total,
        'iva_array' => $ivaArr,
        'cond_iva_receptor' => (int) nz(isset($d['cond_iva']) ? $d['cond_iva'] : ((int) nz($d['codcri'], 0) == 1 ? 1 : 5), 1),
        '_coddoc' => $coddoc,
    );
    if ($pix > 0) $req['trib_array'] = array(array('Id' => 7, 'Desc' => 'Percepcion IIBB', 'BaseImp' => $neto, 'Alic' => 0, 'Importe' => $pix));
    return $req;
}

/** Pide el CAE a AFIP para la FV $d (NO toca la DB). Devuelve {cinmov, cae, cae_vto, coddoc}. */
function fv_solicitar_cae($d) {
    require_once __DIR__ . '/../../includes/afip_wsfe.php';
    $req = fv_afip_request($d);
    $coddoc = $req['_coddoc']; unset($req['_coddoc']);
    $wsfe = new AfipWsfe();
    $prox = $wsfe->ultimoAutorizado($req['pto_vta'], $req['cbte_tipo']) + 1;   // AFIP controla la numeración
    $req['cbte_desde'] = $prox; $req['cbte_hasta'] = $prox;
    $r = $wsfe->solicitarCAE($req);
    return array('cinmov' => (int) $r['cbte_desde'], 'cae' => $r['cae'], 'cae_vto' => $r['cae_vencimiento'], 'coddoc' => $coddoc, 'pto_vta' => $req['pto_vta']);
}

/** Emite la FV: pide el CAE (fuera de tx) y graba (en tx); sincroniza el contador local con AFIP. */
function fv_emitir($d, $estTrue) {
    require_once __DIR__ . '/../../config/afip.php';
    $afip = fv_solicitar_cae($d);
    $d['cipmov'] = $afip['pto_vta'];                 // el pdv electrónico (CIPMOV = pto venta AFIP)
    db_begin();
    try {
        $res = fv_insert($d, $estTrue, $afip);
        // Sincronizar el contador local (ULTCM<letra> por PDV) con el nº de AFIP, para no desfasar el legacy.
        $letra = strtoupper(trim((string) nz($d['ciimov'], 'A')));
        @db_exec("UPDATE [Tbl Puntos de Venta] SET ULTCM$letra=" . (int) $afip['cinmov'] . " WHERE CODPDV=" . (int) $afip['pto_vta'] . ";");
        db_commit();
        $res['cae'] = $afip['cae']; $res['cae_vto'] = $afip['cae_vto'];
        return $res;
    } catch (Exception $e) { db_rollback(); throw $e; }
}

function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? json_decode($_POST['data'], true) : null;
    if (!is_array($raw)) { fail('Datos inválidos'); return; }
    // BLANCO (operador/integral) = factura electrónica con CAE de AFIP.
    // NEGRO (capacitación, ESTMOV=False) = factura NO electrónica: sin CAE, pdv 9999, numeración local.
    $estTrue = (auth_modo() !== 'capacitacion');
    try {
        if ($estTrue) {
            $res = fv_emitir($raw, true);
        } else {
            $raw['cipmov'] = 9999;
            db_begin();
            try { $res = fv_insert($raw, false, null); db_commit(); }
            catch (Exception $e) { db_rollback(); throw $e; }
        }
        ok($res);
    } catch (Exception $e) {
        fail('No se pudo ' . ($estTrue ? 'emitir' : 'grabar') . ' la factura: ' . $e->getMessage(), 500);
    }
}
