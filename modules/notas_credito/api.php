<?php
/**
 * Notas de Crédito (deudores) — emisión electrónica (NC clase A, AFIP tipo 3).
 * Porta `Frm CD Creditos NF`. La NC acredita al cliente por CONCEPTO (Tbl Operaciones Auxiliares,
 * CODOPE=460): el concepto da la cuenta del DEBE (CODCUE) y si lleva IVA (IVAAUX). Asiento inverso de
 * la FV: DEBE cuenta-concepto (neto) + IVA débito + percep / HABER deudores (total). Referencia a la(s)
 * FV(s) que acredita (Tbl Movimientos Referencias) reduciendo su SDOMOV. CREMOV=total; SOPCUE -= total.
 *
 * nc_insert($d, $estTrue, $afip): SIN control de transacción (el caller envuelve). $afip = {cinmov, cae,
 * cae_vto, coddoc} (nº + CAE de AFIP) o null (negro/borrador, sin CAE).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

if (!defined('NC_LIB')) {
    require_once __DIR__ . '/../../includes/auth.php';
    auth_require_login();
    header('Content-Type: application/json');
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    try {
        switch ($action) {
            case 'conceptos':       listar_conceptos(); break;
            case 'buscar_clientes': buscar_clientes(); break;
            case 'get_cliente':     get_cliente(); break;
            case 'pendientes':      pendientes(); break;
            case 'guardar':         guardar(); break;
            default: fail('Acción inválida: ' . $action);
        }
    } catch (Exception $e) { fail($e->getMessage(), 500); }
    exit;
}

function nc_iso($s) { if ($s === null || $s === '') return null; if (is_numeric($s)) return (int) $s; return (int) (new DateTime('1899-12-30'))->diff(new DateTime($s))->days; }
function nc_ymd_serial($v) { $v = (string) $v; if (preg_match('/^\d{8}$/', $v)) { $dt = DateTime::createFromFormat('Ymd', $v); return $dt ? (int) (new DateTime('1899-12-30'))->diff($dt)->days : null; } return nc_iso($v); }
function nc_txt($v) { $v = trim((string) $v); return ($v === '') ? 'Null' : "'" . db_esc($v) . "'"; }
function nc_num($v) { return (string) round((float) $v, 2); }

/** Conceptos de NC (CODOPE=460): cada uno con su cuenta (CODCUE) y si lleva IVA (IVAAUX). */
function listar_conceptos() {
    $rows = db_query("SELECT CODAUX, DENAUX, IVAAUX, CODCUE FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=460 AND CODCUE Is Not Null AND CODCUE <> '' ORDER BY DENAUX;");
    $out = array();
    foreach ($rows as $r) $out[] = array('CODAUX' => (int) $r['CODAUX'], 'DENAUX' => trim((string) nz($r['DENAUX'], '')),
        'IVA' => ($r['IVAAUX'] === true || $r['IVAAUX'] == -1), 'CUENTA' => trim((string) nz($r['CODCUE'], '')));
    ok($out);
}

function buscar_clientes() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q); $num = is_numeric($q) ? ' OR CODCUE = ' . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='D' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

function get_cliente() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $c = db_row("SELECT C.CODCUE, C.DENCUE, C.CITCUE, C.SOPCUE, C.DCXCUE, C.DNXCUE, C.CODLOC, L.DENLOC, P.DENPRO, C.CODCRI, C.CODCDV, C.SPICUE
        FROM ([Tbl Provincias] AS P RIGHT JOIN ([Tbl Localidades] AS L INNER JOIN [Tbl Cuentas Corrientes] AS C ON L.CODLOC=C.CODLOC) ON P.CODPRO=L.CODPRO)
        WHERE C.CODORI='D' AND C.CODCUE=$cc;");
    if (!$c) { fail('Cliente no encontrado'); return; }
    $cri = db_row("SELECT DENCRI, ICXCRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($c['CODCRI'], 0) . ";");
    $c['LETRA'] = $cri ? strtoupper(trim((string) nz($cri['ICXCRI'], 'A'))) : 'A';
    $c['DENCRI'] = $cri ? trim((string) nz($cri['DENCRI'], '')) : '';
    $c['COND_IVA'] = ((int) nz($c['CODCRI'], 0) == 1) ? 1 : 5;
    $c['SALDO'] = round((float) nz($c['SOPCUE'], 0), 2);
    $c['DOMICILIO'] = trim(nz($c['DCXCUE'], '') . ' ' . nz($c['DNXCUE'], ''));
    $c['LOCALIDAD'] = trim(nz($c['DENLOC'], '') . (nz($c['DENPRO'], '') ? ' - ' . nz($c['DENPRO'], '') : ''));
    ok($c);
}

/** Vencimientos de FV/ND pendientes del cliente (saldo > 0) para la grilla Referencias — patrón de RC/OP. */
function pendientes() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $rows = db_query("SELECT M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.FEXMOV, V.FVXMOV, V.DEBMOV, V.CREMOV, V.DETMOV
        FROM [Tbl Movimientos] AS M INNER JOIN [Tbl Movimientos Vencimientos] AS V ON V.NUMMOV = M.NUMMOV
        WHERE M.CODORI='D' AND (M.CODOPE=420 OR M.CODOPE=440) AND M.CODCUE=$cc AND M.SDOMOV>0" . nc_estmov_w() . " ORDER BY V.FVXMOV;");
    $out = array();
    foreach ($rows as $r) {
        $saldo = round((float) nz($r['DEBMOV'], 0) - (float) nz($r['CREMOV'], 0), 2);
        if ($saldo <= 0.005) continue;
        $out[] = array(
            'REFMOV' => (int) $r['NUMMOV'],
            'COMP'   => trim((string) nz($r['CICMOV'], '')) . ' ' . trim((string) nz($r['CIIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
            'FEXMOV' => fecha_serial($r['FEXMOV']),
            'FVXMOV' => fecha_serial($r['FVXMOV']),
            'FVXISO' => (new DateTime('1899-12-30'))->modify('+' . (int) $r['FVXMOV'] . ' days')->format('Y-m-d'),
            'DETMOV' => trim((string) nz($r['DETMOV'], '')),
            'SALDO'  => $saldo,
        );
    }
    ok($out);
}

/** Filtro de visibilidad por libro (doble libro). */
function nc_estmov_w() { $l = auth_libro_unico(); if ($l === 'blanco') return ' AND M.ESTMOV=True'; if ($l === 'negro') return ' AND M.ESTMOV=False'; return ''; }

/** Imputación contable + mayorización (DEBCUE/CRECUE). $keepZero: guarda el 0 explícito (fila percep). */
function nc_imp(&$ord, &$totDeb, &$totCre, $nummov, $cuenta, $deb, $cre, $keepZero = false) {
    $deb = round((float) $deb, 2); $cre = round((float) $cre, 2);
    $ord++;
    $cc = db_esc((string) $cuenta);
    $bal = db_row("SELECT DEBCUE, CRECUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
    $soc = $bal ? round((float) nz($bal['DEBCUE'], 0) - (float) nz($bal['CRECUE'], 0), 2) : 0;
    $debSql = ($deb != 0 || $keepZero) ? (string) $deb : 'Null';
    $creSql = $cre != 0 ? (string) $cre : 'Null';
    db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, DEBMOV, CREMOV, CODCDC, SOCMOV)
        VALUES ($nummov, $ord, '$cc', $debSql, $creSql, 1, $soc);");
    if ($deb != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE + $deb WHERE CODCUE='$cc';");
    if ($cre != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE + $cre WHERE CODCUE='$cc';");
    $totDeb += $deb; $totCre += $cre;
}

function nc_insert($d, $estTrue, $afip) {
    $rc = db_row("SELECT CACC_A, CACC_C, CACC_N, CACC_Z FROM [Rec Control];");
    $caccA = trim((string) $rc['CACC_A']); $caccC = trim((string) $rc['CACC_C']);
    $caccN = trim((string) $rc['CACC_N']); $caccZ = trim((string) $rc['CACC_Z']);

    $codcue = (int) $d['codcue'];
    $cli = db_row("SELECT DENCUE, SOPCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='D';");
    if (!$cli) throw new Exception('Cliente inexistente');

    $fex = nc_iso($d['fexmov']);
    if ($fex === null) throw new Exception('Falta la fecha de emisión');
    $ciimov = strtoupper(trim((string) nz($d['ciimov'], 'A')));
    $cipmov = (isset($d['cipmov']) && (int) $d['cipmov'] > 0) ? (int) $d['cipmov'] : null;
    $cipSql = ($cipmov === null) ? 'Null' : (string) $cipmov;
    $codcdv = (int) nz($d['codcdv'], 2);
    $codaux = (int) $d['codaux'];
    $estSql = $estTrue ? 'True' : 'False';

    // Concepto: cuenta del DEBE (CODCUE) + si lleva IVA (IVAAUX).
    $aux = db_row("SELECT DENAUX, IVAAUX, CODCUE FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=460 AND CODAUX=$codaux;");
    if (!$aux) throw new Exception('Concepto (CODAUX) inválido: ' . $codaux);
    $ctaConcepto = trim((string) nz($aux['CODCUE'], ''));
    $tieneIva = ($aux['IVAAUX'] === true || $aux['IVAAUX'] == -1);

    $netmov = round((float) nz($d['netmov'], 0), 2);                  // neto gravado (si IVAAUX) o no gravado
    $irimov = $tieneIva ? round((float) nz($d['irimov'], 0), 2) : 0;  // IVA débito
    $pixmov = round((float) nz($d['pixmov'], 0), 2);                  // percep IIBB
    $total  = round((float) nz($d['totmov'], 0), 2);
    $soc    = round((float) nz($d['soc'], 0), 2);
    $spimov = (isset($d['spimov']) && ($d['spimov'] === true || $d['spimov'] == 1)) ? 'True' : 'False';
    $mpimov = round((float) nz(isset($d['mpimov']) ? $d['mpimov'] : 0, 0), 2);
    $refs   = isset($d['refs']) && is_array($d['refs']) ? $d['refs'] : array();

    // Numeración: NUMMOV interno; CINMOV = nº de AFIP (electrónico) o contador local (negro).
    $nummov = next_number('ULTMOV');
    $cinmov = ($afip && isset($afip['cinmov']) && $afip['cinmov']) ? (int) $afip['cinmov'] : next_number_pdv('ULTNC' . $ciimov, $cipmov);
    $coddoc = (int) nz(($afip && isset($afip['coddoc'])) ? $afip['coddoc'] : (isset($d['coddoc']) ? $d['coddoc'] : 80), 80);
    $caeSql = ($afip && !empty($afip['cae'])) ? "'" . db_esc($afip['cae']) . "'" : 'Null';
    $fvcSerial = ($afip && !empty($afip['cae_vto'])) ? nc_ymd_serial($afip['cae_vto']) : null;
    $fvcSql = ($fvcSerial === null) ? 'Null' : (string) $fvcSerial;

    // SDOMOV: crédito no aplicado a referencias (negativo, como un recibo). Si se aplica todo → 0.
    $sumRef = 0; foreach ($refs as $r) $sumRef += round((float) nz($r['imp'], 0), 2);
    $sumRef = round($sumRef, 2);
    $sdomov = round(-($total - $sumRef), 2);

    // ── Header ──
    $denSql = nc_txt(nz($cli['DENCUE'], '')); $citSql = nc_txt(nz($d['citmov'], ''));
    $dcx = nc_txt(nz($d['dcxmov'], '')); $dnx = nc_txt(nz(isset($d['dnxmov']) ? $d['dnxmov'] : '', ''));
    $codloc = (int) nz($d['codloc'], 0); $codcri = (int) nz($d['codcri'], 0);
    $detSql = nc_txt(isset($d['detmov']) ? $d['detmov'] : '');
    $cotmov = round((float) nz(isset($d['cotmov']) ? $d['cotmov'] : 1, 1), 4);

    db_exec("INSERT INTO [Tbl Movimientos]
        (NUMMOV, CODORI, FEXMOV, FIXMOV, CODOPE, CODAUX, CICMOV, CIIMOV, CIPMOV, CINMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV,
         CODCUE, SOCMOV, DENMOV, DCXMOV, DNXMOV, CODLOC, CODCRI, CITMOV, CODCDV, DETMOV, COTMOV, NETMOV, IRIMOV, SPIMOV, MPIMOV, PIXMOV,
         CREMOV, TOTMOV, SDOMOV, CODDOC, CAEMOV, FVCMOV, ESTMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($nummov, 'D', $fex, $fex, 460, $codaux, 'NC', '$ciimov', $cipSql, $cinmov, 'NC', '$ciimov', $cipSql, $cinmov, $fex,
         $codcue, " . nc_num($soc) . ", $denSql, $dcx, $dnx, $codloc, $codcri, $citSql, $codcdv, $detSql, $cotmov, " . nc_num($netmov) . ", " . nc_num($irimov) . ", $spimov, " . nc_num($mpimov) . ", " . nc_num($pixmov) . ",
         " . nc_num($total) . ", " . nc_num($total) . ", " . nc_num($sdomov) . ", $coddoc, $caeSql, $fvcSql, $estSql, 0, 0, Now());");

    // ── IVA (si el concepto lleva IVA) ──
    if ($tieneIva && $irimov != 0) {
        $ali = round((float) nz(isset($d['ali']) ? $d['ali'] : 21, 21), 2);
        db_exec("INSERT INTO [Tbl Movimientos IVA] (NUMMOV, ALIMOV, NETMOV, IRIMOV, DECMOV)
            VALUES ($nummov, $ali, " . nc_num($netmov) . ", " . nc_num($irimov) . ", False);");
    }

    // ── Asiento (inverso de la FV): DEBE concepto + IVA débito + percep / HABER deudores ──
    $ord = 0; $totDeb = 0; $totCre = 0;
    nc_imp($ord, $totDeb, $totCre, $nummov, $ctaConcepto, $netmov, 0);
    if ($tieneIva && $irimov != 0) nc_imp($ord, $totDeb, $totCre, $nummov, $caccC, $irimov, 0);
    if ($spimov === 'True') nc_imp($ord, $totDeb, $totCre, $nummov, $caccN, $pixmov, 0, true);   // percep IIBB (guarda el 0 explícito, como el legacy)
    nc_imp($ord, $totDeb, $totCre, $nummov, $caccA, 0, $total);
    $dif = round($totDeb - $totCre, 2);
    if (abs($dif) >= 0.005) { if ($dif > 0) nc_imp($ord, $totDeb, $totCre, $nummov, $caccZ, 0, $dif); else nc_imp($ord, $totDeb, $totCre, $nummov, $caccZ, -$dif, 0); }

    // ── Referencias: aplica el crédito a la(s) FV(s) — mismo patrón que RC/OP (3 pasos) ──
    foreach ($refs as $r) {
        $refMov = (int) nz(isset($r['nummov']) ? $r['nummov'] : (isset($r['refmov']) ? $r['refmov'] : 0), 0);
        $imp = round((float) nz($r['imp'], 0), 2);
        if ($refMov <= 0 || $imp == 0) continue;
        $fvx = (isset($r['fvxmov']) && $r['fvxmov'] !== '') ? nc_iso($r['fvxmov']) : null;
        $fvxSql = ($fvx === null) ? 'Null' : (string) $fvx;
        // 1) Referencia (FK a Tbl Movimientos Vencimientos: FVXMOV debe ser un vencimiento real de la FV)
        db_exec("INSERT INTO [Tbl Movimientos Referencias] (NUMMOV, REFMOV, FVXMOV, IMPMOV) VALUES ($nummov, $refMov, $fvxSql, " . nc_num($imp) . ");");
        // 2) Vencimiento de la FV: CREMOV += imp (lo acreditado contra ese vencimiento)
        if ($fvx !== null) {
            $v = db_row("SELECT CREMOV FROM [Tbl Movimientos Vencimientos] WHERE NUMMOV=$refMov AND FVXMOV=$fvx;");
            $nuevo = round((float) nz($v ? $v['CREMOV'] : 0, 0) + $imp, 2);
            db_exec("UPDATE [Tbl Movimientos Vencimientos] SET CREMOV=" . nc_num($nuevo) . " WHERE NUMMOV=$refMov AND FVXMOV=$fvx;");
        }
        // 3) Factura: SDOMOV -= imp (saldo pendiente)
        $f = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV=$refMov;");
        $nsdo = round((float) nz($f ? $f['SDOMOV'] : 0, 0) - $imp, 2);
        db_exec("UPDATE [Tbl Movimientos] SET SDOMOV=" . nc_num($nsdo) . " WHERE NUMMOV=$refMov;");
    }

    // ── Cuenta corriente: la NC reduce la deuda → SOPCUE -= total ──
    db_exec("UPDATE [Tbl Cuentas Corrientes] SET FUOCUE=$fex, SOPCUE = " . round((float) nz($cli['SOPCUE'], 0) - $total, 2) . " WHERE CODCUE=$codcue;");

    return array('nummov' => $nummov, 'cinmov' => $cinmov, 'total' => $total, 'sdomov' => $sdomov, 'balanceo' => round($totDeb - $totCre, 2),
        'cuenta_concepto' => $ctaConcepto, 'tiene_iva' => $tieneIva);
}

// ───────────────────────── Orquestación AFIP (WSFE + CbtesAsoc) ─────────────────────────

/** Mapea la NC ($d) al request de solicitarCAE (NC tipo 3), con los CbtesAsoc de las FV referenciadas. */
function nc_afip_request($d) {
    require_once __DIR__ . '/../../config/afip.php';
    $letra = strtoupper(trim((string) nz($d['ciimov'], 'A')));
    $cbteTipo = afip_cbte_tipo('NC', $letra);
    if (!$cbteTipo) throw new Exception('Clase de NC inválida: ' . $letra);
    $codaux = (int) $d['codaux'];
    $aux = db_row("SELECT IVAAUX FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=460 AND CODAUX=$codaux;");
    $tieneIva = $aux && ($aux['IVAAUX'] === true || $aux['IVAAUX'] == -1);

    $neto = round((float) nz($d['netmov'], 0), 2);
    $iva  = $tieneIva ? round((float) nz($d['irimov'], 0), 2) : 0;
    $pix  = round((float) nz($d['pixmov'], 0), 2);
    $total = round((float) nz($d['totmov'], 0), 2);
    $coddoc = (int) nz(isset($d['coddoc']) ? $d['coddoc'] : 80, 80);
    $docnro = preg_replace('/[^0-9]/', '', (string) nz($d['citmov'], ''));
    if ($docnro === '') { $docnro = '0'; $coddoc = 99; }
    $cbteFch = (new DateTime('1899-12-30'))->modify('+' . (int) nc_iso($d['fexmov']) . ' days')->format('Ymd');

    // CbtesAsoc: cada FV referenciada (Tipo/PtoVta/Nro/CbteFch).
    $cbtesAsoc = array();
    foreach ((isset($d['refs']) && is_array($d['refs']) ? $d['refs'] : array()) as $r) {
        $fv = db_row("SELECT CICMOV, CIIMOV, CIPMOV, CINMOV, FEXMOV FROM [Tbl Movimientos] WHERE NUMMOV=" . (int) $r['nummov'] . ";");
        if (!$fv) continue;
        $t = afip_cbte_tipo(trim((string) $fv['CICMOV']), trim((string) nz($fv['CIIMOV'], 'A')));
        if (!$t) continue;
        $cbtesAsoc[] = array('Tipo' => $t, 'PtoVta' => (int) nz($fv['CIPMOV'], 0), 'Nro' => (int) nz($fv['CINMOV'], 0),
            'CbteFch' => (new DateTime('1899-12-30'))->modify('+' . (int) nz($fv['FEXMOV'], 0) . ' days')->format('Ymd'));
    }

    $req = array(
        'pto_vta' => AFIP_PTO_VTA, 'cbte_tipo' => $cbteTipo, 'concepto' => AFIP_CONCEPTO,
        'doc_tipo' => $coddoc, 'doc_nro' => $docnro, 'cbte_fch' => $cbteFch,
        'imp_total' => $total, 'imp_trib' => $pix, 'imp_op_ex' => 0,
        'cond_iva_receptor' => (int) nz(isset($d['cond_iva']) ? $d['cond_iva'] : ((int) nz($d['codcri'], 0) == 1 ? 1 : 5), 1),
        '_coddoc' => $coddoc,
    );
    if ($tieneIva) {
        $req['imp_neto'] = $neto; $req['imp_iva'] = $iva; $req['imp_tot_conc'] = 0;
        $req['iva_array'] = array(array('Id' => afip_iva_id(isset($d['ali']) ? $d['ali'] : 21), 'BaseImp' => $neto, 'Importe' => $iva));
    } else {
        $req['imp_neto'] = 0; $req['imp_iva'] = 0; $req['imp_tot_conc'] = $neto;   // NO GRAVADO → conceptos no gravados
    }
    if ($pix > 0) $req['trib_array'] = array(array('Id' => 7, 'Desc' => 'Percepcion IIBB', 'BaseImp' => $neto, 'Alic' => 0, 'Importe' => $pix));
    if (count($cbtesAsoc)) $req['cbtes_asoc'] = $cbtesAsoc;
    return $req;
}

/** Pide el CAE de la NC a AFIP (NO toca la DB). Devuelve {cinmov, cae, cae_vto, coddoc, pto_vta}. */
function nc_solicitar_cae($d) {
    require_once __DIR__ . '/../../includes/afip_wsfe.php';
    $req = nc_afip_request($d);
    $coddoc = $req['_coddoc']; unset($req['_coddoc']);
    $wsfe = new AfipWsfe();
    $prox = $wsfe->ultimoAutorizado($req['pto_vta'], $req['cbte_tipo']) + 1;
    $req['cbte_desde'] = $prox; $req['cbte_hasta'] = $prox;
    $r = $wsfe->solicitarCAE($req);
    return array('cinmov' => (int) $r['cbte_desde'], 'cae' => $r['cae'], 'cae_vto' => $r['cae_vencimiento'], 'coddoc' => $coddoc, 'pto_vta' => $req['pto_vta']);
}

/** Emite la NC: pide el CAE (fuera de tx) y graba (en tx). Sincroniza el contador local con AFIP. */
function nc_emitir($d, $estTrue) {
    require_once __DIR__ . '/../../config/afip.php';
    $afip = nc_solicitar_cae($d);
    $d['cipmov'] = $afip['pto_vta'];
    db_begin();
    try {
        $res = nc_insert($d, $estTrue, $afip);
        $letra = strtoupper(trim((string) nz($d['ciimov'], 'A')));
        @db_exec("UPDATE [Tbl Puntos de Venta] SET ULTNC$letra=" . (int) $afip['cinmov'] . " WHERE CODPDV=" . (int) $afip['pto_vta'] . ";");
        db_commit();
        $res['cae'] = $afip['cae']; $res['cae_vto'] = $afip['cae_vto'];
        return $res;
    } catch (Exception $e) { db_rollback(); throw $e; }
}

function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? json_decode($_POST['data'], true) : null;
    if (!is_array($raw)) { fail('Datos inválidos'); return; }
    // BLANCO (operador/integral) = NC electrónica con CAE. NEGRO (capacitación) = sin CAE, pdv 9999.
    $estTrue = (auth_modo() !== 'capacitacion');
    try {
        if ($estTrue) {
            $res = nc_emitir($raw, true);
        } else {
            unset($raw['cipmov']);
            db_begin();
            try { $res = nc_insert($raw, false, null); db_commit(); }
            catch (Exception $e) { db_rollback(); throw $e; }
        }
        ok($res);
    } catch (Exception $e) {
        fail('No se pudo ' . ($estTrue ? 'emitir' : 'grabar') . ' la nota de crédito: ' . $e->getMessage(), 500);
    }
}
