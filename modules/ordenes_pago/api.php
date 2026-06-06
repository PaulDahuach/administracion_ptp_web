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
            case 'guardar': guardar(); break;
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
