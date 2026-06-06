<?php
/**
 * Órdenes de Pago (acreedores) — CODOPE=340, CODORI='A', CICMOV='OP'. Grabación contable (porta
 * Frm CA Ordenes de Pago SetData Case "A"). Espejo de Recibos pero del lado proveedores:
 *  - Header DEBMOV=TOTMOV (debita la cta cte del proveedor → baja lo que le debemos).
 *  - Asiento INVERTIDO: DEBE = cuenta del proveedor (CODAUX); HABER = retención IIBB→CACC_O +
 *    efectivo→CACC_1 + cheques (cada uno a su cuenta: 11103 cartera endosado / 217xx posdatado),
 *    creando/endosando el cheque con VADCHQ=False (salen).
 *  - Retención IIBB: RIXMOV (monto) + RINMOV (nº constancia, vía ULTRIX) + datos (PIDMOV base,
 *    ARBMOV alícuota, CODRRI régimen).
 *  - SOPCUE += TOTMOV (+ SANCUE para anticipos 341/343).
 * op_insert() NO maneja la transacción (testeable con rollback; guard OP_LIB evita el dispatch).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('OP_LIB')) {
    auth_require_login();
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    try {
        switch ($action) {
            case 'buscar_proveedores': buscar_proveedores(); break;
            case 'get_proveedor':      get_proveedor();      break;
            case 'pendientes':         pendientes();         break;
            case 'operaciones':        listar_operaciones(); break;
            case 'regimenes':          listar_regimenes();   break;
            case 'cuentas_bancarias':  listar_cuentas_bancarias(); break;
            case 'cartera':            cheques_cartera();    break;
            case 'pdvs':               listar_pdvs();        break;
            case 'guardar':            guardar();            break;
            case 'anular':             fail('Anulación de órdenes de pago: pendiente de portar.'); break;
            case 'listar':             listar();             break;
            case 'detalle':            detalle();            break;
            default: fail('Acción inválida: ' . $action);
        }
    } catch (Exception $e) { fail($e->getMessage(), 500); }
}

function op_iso($iso) {
    if (!$iso) return null;
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    return $d ? (int) (new DateTime('1899-12-30'))->diff($d)->days : null;
}
function op_txt($s) { $s = trim((string) $s); return $s === '' ? 'Null' : "'" . db_esc($s) . "'"; }

/** Inserta una imputación contable + actualiza el saldo cacheado. Mantiene ord/totDeb/totCre. */
function op_imp(&$ord, &$totDeb, &$totCre, $nummov, $cuenta, $deb, $cre, $codchq, $fax, $caccZ) {
    $ord++;
    if ((string) $cuenta !== (string) $caccZ) { $totDeb += (float) $deb; $totCre += (float) $cre; }
    $cc  = db_esc((string) $cuenta);
    $sld = db_row("SELECT DEBCUE, CRECUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
    $soc = round((float) nz($sld['DEBCUE'], 0) - (float) nz($sld['CRECUE'], 0), 2);
    $debSql = ($deb > 0) ? round($deb, 2) : 'Null';
    $creSql = ($cre > 0) ? round($cre, 2) : 'Null';
    $chqSql = ($codchq === null) ? 'Null' : (int) $codchq;
    $faxSql = ($fax === null) ? 'Null' : (int) $fax;
    db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, DEBMOV, CREMOV, CODCDC, SOCMOV, CODCHQ, FAXMOV)
        VALUES ($nummov, $ord, '$cc', $debSql, $creSql, 1, $soc, $chqSql, $faxSql);");
    if ($deb > 0) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE + " . round($deb, 2) . " WHERE CODCUE='$cc';");
    if ($cre > 0) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE + " . round($cre, 2) . " WHERE CODCUE='$cc';");
}

/**
 * Núcleo de la grabación de una OP (sin transacción). Devuelve [nummov, cinmov, rinmov].
 * $d = {codcue, codaux, fexmov(iso), fixmov(iso), codfdp, detmov, efectivo, totmov?,
 *       cec, cep, cen, cef,  (comprobante proveedor RC)
 *       referencias:[{refmov,fvxmov(iso),imp}],
 *       ret:{rix, pid, rid, vei, sri, codrri, arb, sia, aia},   (retención IIBB)
 *       cheques:[{codcue(account), codchq?, codban, syn, fex(iso), fax(iso), plz, lib, cit, loc, imp, dif}] }
 */
function op_insert($d, $estTrue, $cipmov) {
    $rc = db_row("SELECT CACC_O, CACC_1, CACC_V, CACC_Z FROM [Rec Control];");
    $caccO = $rc['CACC_O']; $cacc1 = $rc['CACC_1']; $caccV = (string) $rc['CACC_V']; $caccZ = $rc['CACC_Z'];

    $codcue = (int) $d['codcue'];
    $codaux = (int) $d['codaux'];
    $cli = db_row("SELECT DENCUE, CODCRI, CITCUE, SOPCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue;");
    if (!$cli) throw new Exception('Proveedor inexistente');

    $fex = op_iso($d['fexmov']);
    if ($fex === null) throw new Exception('Falta la fecha de emisión');
    $codfdp = (int) nz($d['codfdp'], 4);
    $efectivo = round((float) nz($d['efectivo'], 0), 2);
    $refs = (isset($d['referencias']) && is_array($d['referencias'])) ? $d['referencias'] : array();
    $chqs = (isset($d['cheques']) && is_array($d['cheques'])) ? $d['cheques'] : array();
    $ret = isset($d['ret']) && is_array($d['ret']) ? $d['ret'] : array();
    $rix = round((float) nz(isset($ret['rix']) ? $ret['rix'] : 0, 0), 2);

    $sumRef = 0; foreach ($refs as $r) $sumRef += (float) nz($r['imp'], 0);
    $sumChq = 0; foreach ($chqs as $c) $sumChq += (float) nz($c['imp'], 0);
    $total  = isset($d['totmov']) ? round((float) $d['totmov'], 2) : round($sumRef, 2);   // OP = lo que se cancela
    $maxRef = round($sumRef, 2);

    // SDOMOV por CODAUX
    if ($codaux == 341) $sdomov = $total;                       // anticipo
    elseif ($codaux == 343) $sdomov = round($total - $maxRef, 2); // canc + anticipo
    else $sdomov = 0;                                            // cancelación (342)

    // OP: el header guarda CIPMOV=0 (acreedores no tienen PDV de venta); el nº ODP sale del contador
    // por-PDV (ULTODP) del PDV del operador ($cipmov), o 9999 en capacitación.
    $nummov = next_number('ULTMOV');
    $cinmov = next_number_pdv('ULTODP', $estTrue ? (int) $cipmov : 9999);
    $cipSql = '0';
    $estSql = $estTrue ? 'True' : 'False';
    $soc = round((float) nz($cli['SOPCUE'], 0), 2);

    // Constancia de retención IIBB
    $rinSql = 'Null'; $rin = null;
    if ($rix > 0) { $rin = next_number('ULTRIX'); $rinSql = (string) $rin; }

    // --- Header ---
    $denSql = op_txt(nz($cli['DENCUE'], '')); $citSql = op_txt(nz($cli['CITCUE'], ''));
    $codcri = (int) nz($cli['CODCRI'], 0);
    $detSql = op_txt(isset($d['detmov']) ? $d['detmov'] : '');
    $cec = op_txt(isset($d['cec']) ? $d['cec'] : 'RC');
    $cep = (int) nz(isset($d['cep']) ? $d['cep'] : 0, 0);
    $cen = (int) nz(isset($d['cen']) ? $d['cen'] : 0, 0);
    $cef = isset($d['cef']) && $d['cef'] ? op_iso($d['cef']) : $fex; $cefSql = $cef === null ? 'Null' : (string) $cef;
    // retención datos
    $pid = round((float) nz(isset($ret['pid']) ? $ret['pid'] : 0, 0), 2);
    $rid = round((float) nz(isset($ret['rid']) ? $ret['rid'] : 0, 0), 2);
    $vei = (int) nz(isset($ret['vei']) ? $ret['vei'] : 0, 0); $veiSql = $vei > 0 ? (string) $vei : 'Null';
    $sri = (isset($ret['sri']) && $ret['sri']) ? 'True' : 'False';
    $codrri = isset($ret['codrri']) && $ret['codrri'] !== '' ? (int) $ret['codrri'] : 'Null';
    $arb = round((float) nz(isset($ret['arb']) ? $ret['arb'] : 0, 0), 4);
    $sia = (isset($ret['sia']) && $ret['sia']) ? 'True' : 'False';
    $aia = round((float) nz(isset($ret['aia']) ? $ret['aia'] : 0, 0), 2);

    db_exec("INSERT INTO [Tbl Movimientos]
        (NUMMOV, CODORI, FEXMOV, CODOPE, CODAUX, CICMOV, CIPMOV, ESTMOV, CINMOV, CECMOV, CEPMOV, CENMOV, CEFMOV,
         CODCUE, SOCMOV, DENMOV, CODCRI, CITMOV, CODFDP, DETMOV, TOTMOV, DEBMOV, SDOMOV,
         RIXMOV, RIDMOV, PIDMOV, VEIMOV, SRIMOV, CODRRI, ARBMOV, SIAMOV, AIAMOV, RINMOV,
         ANUMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($nummov, 'A', $fex, 340, $codaux, 'OP', $cipSql, $estSql, $cinmov, $cec, $cep, $cen, $cefSql,
         $codcue, $soc, $denSql, $codcri, $citSql, $codfdp, $detSql, $total, $total, $sdomov,
         $rix, $rid, $pid, $veiSql, $sri, $codrri, $arb, $sia, $aia, $rinSql,
         False, 0, 0, Now());");

    // --- Referencias ---
    foreach ($refs as $r) {
        $ref = (int) nz($r['refmov'], 0); $imp = round((float) nz($r['imp'], 0), 2); $fvx = op_iso($r['fvxmov']);
        $fvxSql = $fvx === null ? 'Null' : (string) $fvx;
        db_exec("INSERT INTO [Tbl Movimientos Referencias] (NUMMOV, REFMOV, FVXMOV, IMPMOV) VALUES ($nummov, $ref, $fvxSql, $imp);");
        if ($fvx !== null) {
            $v = db_row("SELECT DEBMOV FROM [Tbl Movimientos Vencimientos] WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
            $nuevo = round((float) nz($v ? $v['DEBMOV'] : 0, 0) + $imp, 2);
            db_exec("UPDATE [Tbl Movimientos Vencimientos] SET DEBMOV=$nuevo WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
        }
        $f = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV=$ref;");
        $nsdo = round((float) nz($f ? $f['SDOMOV'] : 0, 0) + $imp, 2);
        db_exec("UPDATE [Tbl Movimientos] SET SDOMOV=$nsdo WHERE NUMMOV=$ref;");
    }

    // --- Asiento ---
    $ord = 0; $totDeb = 0; $totCre = 0;
    // DEBE: proveedor (cuenta del CODAUX; 343 = anticipo + cancelación)
    if ($codaux == 343) {
        $cAnt = db_row("SELECT CODCUE FROM [Tbl Operaciones Auxiliares] WHERE CODAUX=341;");
        $cCan = db_row("SELECT CODCUE FROM [Tbl Operaciones Auxiliares] WHERE CODAUX=342;");
        op_imp($ord, $totDeb, $totCre, $nummov, $cAnt['CODCUE'], round($total - $maxRef, 2), 0, null, null, $caccZ);
        op_imp($ord, $totDeb, $totCre, $nummov, $cCan['CODCUE'], $maxRef, 0, null, null, $caccZ);
    } else {
        $cPro = db_row("SELECT CODCUE FROM [Tbl Operaciones Auxiliares] WHERE CODAUX=$codaux;");
        if (!$cPro) throw new Exception('Operación (CODAUX) inexistente');
        op_imp($ord, $totDeb, $totCre, $nummov, $cPro['CODCUE'], $total, 0, null, null, $caccZ);
    }
    // HABER: retención IIBB → CACC_O
    if ($rix > 0) op_imp($ord, $totDeb, $totCre, $nummov, $caccO, 0, $rix, null, null, $caccZ);
    // HABER: efectivo → CACC_1 (no en interdepósito)
    if ($efectivo > 0 && $codfdp != 5) op_imp($ord, $totDeb, $totCre, $nummov, $cacc1, 0, $efectivo, null, null, $caccZ);
    // HABER: cheques (cada uno a su cuenta; crea/endosa, VADCHQ=False salen)
    foreach ($chqs as $c) {
        $imp = round((float) nz($c['imp'], 0), 2);
        $cuenta = (string) $c['codcue'];
        $codchq = isset($c['codchq']) && $c['codchq'] ? (int) $c['codchq'] : null;
        if ($codchq) {
            $cq = db_row("SELECT FAXCHQ FROM [Tbl Cheques] WHERE CODCHQ=$codchq;");
            db_exec("UPDATE [Tbl Cheques] SET VADCHQ=False WHERE CODCHQ=$codchq;");
            $fax = $cq ? (int) nz($cq['FAXCHQ'], 0) : null;
        } else {
            $codchq = next_number('ULTCHQ');
            $ban = (int) nz($c['codban'], 0);
            $syn = op_txt(nz($c['syn'], '')); $lib = op_txt(nz($c['lib'], '')); $cit = op_txt(nz($c['cit'], '')); $loc = op_txt(nz($c['loc'], ''));
            $fexc = op_iso(isset($c['fex']) ? $c['fex'] : ''); $faxc = op_iso(isset($c['fax']) ? $c['fax'] : '');
            $fexSql = $fexc === null ? 'Null' : (string) $fexc; $faxSql = $faxc === null ? 'Null' : (string) $faxc;
            $plz = (int) nz($c['plz'], 0);
            $dif = (substr($cuenta, 0, strlen($caccV)) === $caccV) ? 'True' : 'False';   // posdatado → a devengar
            db_exec("INSERT INTO [Tbl Cheques] (CODCHQ, CODBAN, SYNCHQ, FEXCHQ, FAXCHQ, IMPCHQ, PLZCHQ, LIBCHQ, CITCHQ, LOCCHQ, VADCHQ, DIFCHQ)
                VALUES ($codchq, $ban, $syn, $fexSql, $faxSql, $imp, $plz, $lib, $cit, $loc, False, $dif);");
            $fax = $faxc;
        }
        op_imp($ord, $totDeb, $totCre, $nummov, $cuenta, 0, $imp, $codchq, $fax, $caccZ);
    }
    // Balanceo
    $dif = round($totDeb - $totCre, 2);
    if (abs($dif) >= 0.005) {
        if ($dif > 0) op_imp($ord, $totDeb, $totCre, $nummov, $caccZ, 0, $dif, null, null, $caccZ);
        else          op_imp($ord, $totDeb, $totCre, $nummov, $caccZ, -$dif, 0, null, null, $caccZ);
    }

    // --- Cuenta corriente del proveedor ---
    db_exec("UPDATE [Tbl Cuentas Corrientes] SET SOPCUE = SOPCUE + $total, FUOCUE = $fex WHERE CODCUE=$codcue;");
    if ($codaux == 341 || $codaux == 343) {
        $san = db_row("SELECT SANCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue;");
        $delta = ($codaux == 341) ? $total : round($total - $maxRef, 2);
        $nuevoSan = round((float) nz($san ? $san['SANCUE'] : 0, 0) + $delta, 2);
        db_exec("UPDATE [Tbl Cuentas Corrientes] SET SANCUE = $nuevoSan WHERE CODCUE=$codcue;");
    }

    return array('nummov' => $nummov, 'cinmov' => $cinmov, 'cipmov' => ($estTrue ? (int) $cipmov : 0), 'rinmov' => $rin, 'total' => $total);
}

function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $d = json_decode(isset($_POST['data']) ? $_POST['data'] : '', true);
    if (!is_array($d)) { fail('Datos inválidos'); return; }
    if (empty($d['codcue'])) { fail('Falta el proveedor'); return; }
    $modo = auth_modo();
    $estTrue = ($modo !== 'capacitacion');
    $cipmov = isset($d['cipmov']) ? (int) $d['cipmov'] : 0;
    db_begin();
    try {
        $res = op_insert($d, $estTrue, $cipmov);
        db_commit();
        ok($res);
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar la orden de pago: ' . $e->getMessage(), 500);
    }
}

// ───────────────────────── Lookups / búsqueda ─────────────────────────
function op_serial_iso($s) { if ($s === null || $s === '') return ''; return (new DateTime('1899-12-30'))->modify('+' . (int) $s . ' days')->format('Y-m-d'); }
function estmov_w() { $l = auth_libro_unico(); if ($l === 'blanco') return ' AND ESTMOV=True'; if ($l === 'negro') return ' AND ESTMOV=False'; return ''; }
function comp_str($cic, $cii, $cip, $cin) {
    $pdv = str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT);
    $nro = str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT);
    return trim((string) nz($cic, '') . ' ' . nz($cii, '')) . ' ' . $pdv . '-' . $nro;
}

function buscar_proveedores() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q); $num = is_numeric($q) ? " OR CODCUE = " . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes]
        WHERE CODORI='A' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

function get_proveedor() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $c = db_row("SELECT C.CODCUE, C.DENCUE, C.CITCUE, C.SOPCUE, C.DCXCUE, C.DNXCUE, C.CODLOC, L.DENLOC, P.DENPRO, C.CODCRI, C.CODRRI, C.SRICUE
        FROM ([Tbl Provincias] AS P RIGHT JOIN ([Tbl Localidades] AS L INNER JOIN [Tbl Cuentas Corrientes] AS C ON L.CODLOC=C.CODLOC) ON P.CODPRO=L.CODPRO)
        WHERE C.CODORI='A' AND C.CODCUE=$cc;");
    if (!$c) { fail('Proveedor no encontrado'); return; }
    $c['SALDO'] = round((float) nz($c['SOPCUE'], 0), 2);
    $c['DOMICILIO'] = trim(nz($c['DCXCUE'], '') . ' ' . nz($c['DNXCUE'], ''));
    $c['LOCALIDAD'] = trim(nz($c['DENLOC'], '') . ' - ' . nz($c['DENPRO'], ''));
    // Retención IIBB: sujeto + régimen del proveedor → alícuota por defecto (el padrón ARBA la pisaría).
    $c['SUJETO'] = ($c['SRICUE'] === true || $c['SRICUE'] == -1) ? 1 : 0;
    $c['CODRRI'] = (int) nz($c['CODRRI'], 0);
    $c['ALIRRI'] = 0; $c['DENRRI'] = '';
    if ($c['SUJETO'] && $c['CODRRI'] > 0) {
        $rg = db_row("SELECT DENRRI, ALIRRI FROM [Tbl Regimenes Retencion Ingresos Brutos] WHERE CODRRI=" . $c['CODRRI'] . ";");
        if ($rg) { $c['ALIRRI'] = round((float) nz($rg['ALIRRI'], 0), 4); $c['DENRRI'] = trim((string) nz($rg['DENRRI'], '')); }
    }
    ok($c);
}

/** Comprobantes pendientes del proveedor (CP/ND con saldo) — muestra el comprobante EXTERNO (su FC). */
function pendientes() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $rows = db_query("SELECT M.NUMMOV, M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV, M.FEXMOV, V.FVXMOV, V.DEBMOV, V.CREMOV, V.DETMOV
        FROM [Tbl Movimientos] AS M INNER JOIN [Tbl Movimientos Vencimientos] AS V ON V.NUMMOV=M.NUMMOV
        WHERE M.CODORI='A' AND (M.CODOPE=310 OR M.CODOPE=330) AND M.CODCUE=$cc AND M.SDOMOV<>0
        ORDER BY V.FVXMOV;");
    $out = array();
    foreach ($rows as $r) {
        $pend = round((float) nz($r['CREMOV'], 0) - (float) nz($r['DEBMOV'], 0), 2);
        if ($pend <= 0.005) continue;
        $out[] = array('REFMOV' => (int) $r['NUMMOV'], 'COMP' => comp_str($r['CECMOV'], $r['CEIMOV'], $r['CEPMOV'], $r['CENMOV']),
            'FEXMOV' => fecha_serial($r['FEXMOV']), 'FVXMOV' => fecha_serial($r['FVXMOV']), 'FVXISO' => op_serial_iso($r['FVXMOV']),
            'DETMOV' => trim((string) nz($r['DETMOV'], '')), 'SALDO' => $pend);
    }
    ok($out);
}

function listar_operaciones() { ok(db_query("SELECT CODAUX, DENAUX FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=340 ORDER BY CODAUX;")); }
function listar_regimenes() { ok(db_query("SELECT CODRRI, DENRRI, ALIRRI FROM [Tbl Regimenes Retencion Ingresos Brutos] ORDER BY DENRRI;")); }
function listar_cuentas_bancarias() { ok(db_query("SELECT CODCBX, DENCUE FROM [Tbl Cuentas Contables] WHERE CODCBX Is Not Null AND CODCUE Like '11104%' ORDER BY DENCUE;")); }
function listar_pdvs() { ok(db_query("SELECT CODPDV, NOMPDV FROM [Tbl Puntos de Venta] WHERE CODPDV <> 9999 ORDER BY CODPDV;")); }

/** Cheques en cartera disponibles para endosar (VADCHQ=True), con banco. */
function cheques_cartera() {
    $ban = array(); foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos]") as $b) $ban[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));
    $out = array();
    foreach (db_query("SELECT CODCHQ, CODBAN, SYNCHQ, FEXCHQ, FAXCHQ, IMPCHQ, LIBCHQ, LOCCHQ FROM [Tbl Cheques] WHERE VADCHQ=True ORDER BY FAXCHQ;") as $c)
        $out[] = array('CODCHQ' => (int) $c['CODCHQ'], 'BANCO' => isset($ban[(int) $c['CODBAN']]) ? $ban[(int) $c['CODBAN']] : '',
            'SYN' => trim((string) nz($c['SYNCHQ'], '')), 'FEX' => fecha_serial($c['FEXCHQ']), 'FAX' => fecha_serial($c['FAXCHQ']),
            'LIB' => trim((string) nz($c['LIBCHQ'], '')), 'LOC' => trim((string) nz($c['LOCCHQ'], '')), 'IMP' => round((float) nz($c['IMPCHQ'], 0), 2));
    ok($out);
}

/** Listado de OPs (CODOPE=340). */
function listar() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sd = isset($_GET['desde']) && $_GET['desde'] ? (int) (new DateTime('1899-12-30'))->diff(new DateTime($_GET['desde']))->days : null;
    $sh = isset($_GET['hasta']) && $_GET['hasta'] ? (int) (new DateTime('1899-12-30'))->diff(new DateTime($_GET['hasta']))->days : null;
    $w = "CODOPE=340" . estmov_w();
    if ($q !== '') { $qs = db_esc($q); $cond = "(DENMOV Like '%$qs%' OR CITMOV Like '%$qs%'"; if (is_numeric($q)) $cond .= " OR CINMOV=" . (int) $q; $w .= " AND $cond)"; }
    if ($sd !== null) $w .= " AND FEXMOV >= $sd";
    if ($sh !== null) $w .= " AND FEXMOV <= $sh";
    $rows = db_query("SELECT TOP 200 NUMMOV, FEXMOV, CINMOV, DENMOV, TOTMOV, ANUMOV FROM [Tbl Movimientos] WHERE $w ORDER BY FEXMOV DESC, NUMMOV DESC;");
    $out = array();
    foreach ($rows as $r) $out[] = array('NUMMOV' => (int) $r['NUMMOV'], 'FEXMOV' => fecha_serial($r['FEXMOV']), 'FEXMOVO' => (int) nz($r['FEXMOV'], 0),
        'COMP' => 'OP 0000-' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT), 'DENMOV' => trim((string) nz($r['DENMOV'], '')),
        'TOTMOV' => round((float) nz($r['TOTMOV'], 0), 2), 'ANU' => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1) ? 1 : 0);
    ok(array('ordenes' => $out, 'tope' => count($out) >= 200));
}

/** Detalle completo de una OP para verla en pantalla, bloqueada. */
function detalle() {
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT * FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=340;");
    if (!$h) { fail('Orden de pago no encontrada'); return; }
    $estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1); $lib = auth_libro_unico();
    if (($lib === 'blanco' && !$estTrue) || ($lib === 'negro' && $estTrue)) { fail('OP no disponible en este libro'); return; }

    $refs = array();
    foreach (db_query("SELECT R.REFMOV, R.FVXMOV, R.IMPMOV, M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV
        FROM [Tbl Movimientos Referencias] AS R LEFT JOIN [Tbl Movimientos] AS M ON M.NUMMOV=R.REFMOV WHERE R.NUMMOV=$num;") as $r)
        $refs[] = array('COMP' => comp_str($r['CECMOV'], $r['CEIMOV'], $r['CEPMOV'], $r['CENMOV']), 'FVXMOV' => fecha_serial($r['FVXMOV']), 'IMP' => round((float) nz($r['IMPMOV'], 0), 2));

    $ban = array(); foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos]") as $b) $ban[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));
    $chqs = array();
    foreach (db_query("SELECT C.CODBAN, C.SYNCHQ, C.FEXCHQ, C.FAXCHQ, C.IMPCHQ, C.LIBCHQ, C.LOCCHQ
        FROM [Tbl Cheques] AS C INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON MI.CODCHQ=C.CODCHQ WHERE MI.NUMMOV=$num ORDER BY MI.ORDMOV;") as $c)
        $chqs[] = array('BANCO' => isset($ban[(int) $c['CODBAN']]) ? $ban[(int) $c['CODBAN']] : '', 'SYN' => trim((string) nz($c['SYNCHQ'], '')),
            'FAX' => fecha_serial($c['FAXCHQ']), 'LIB' => trim((string) nz($c['LIBCHQ'], '')), 'IMP' => round((float) nz($c['IMPCHQ'], 0), 2));

    ok(array('NUMMOV' => $num, 'CINMOV' => (int) nz($h['CINMOV'], 0), 'FEXISO' => op_serial_iso($h['FEXMOV']),
        'CODAUX' => (int) nz($h['CODAUX'], 0), 'CODCUE' => (int) nz($h['CODCUE'], 0), 'DENMOV' => trim((string) nz($h['DENMOV'], '')),
        'CITMOV' => trim((string) nz($h['CITMOV'], '')), 'SOCMOV' => round((float) nz($h['SOCMOV'], 0), 2), 'DETMOV' => trim((string) nz($h['DETMOV'], '')),
        'CODFDP' => (int) nz($h['CODFDP'], 4), 'TOTMOV' => round((float) nz($h['TOTMOV'], 0), 2),
        'RIXMOV' => round((float) nz($h['RIXMOV'], 0), 2), 'RINMOV' => (int) nz($h['RINMOV'], 0), 'ARBMOV' => round((float) nz($h['ARBMOV'], 0), 4), 'CODRRI' => (int) nz($h['CODRRI'], 0),
        'ANU' => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) ? 1 : 0, 'referencias' => $refs, 'cheques' => $chqs));
}
