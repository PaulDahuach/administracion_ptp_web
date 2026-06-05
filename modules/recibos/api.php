<?php
/**
 * Recibos (cobranzas) — CODOPE=480, CICMOV=RC. Grabación contable completa (porta Frm CD Recibos
 * SetData Case "A"). Escribe: header (acredita cta cte, CREMOV=total) + referencias (facturas que
 * cancela, bajan su SDOMOV y acreditan su vencimiento) + asiento doble en Tbl Movimientos Imputaciones
 * (DEBE: cheques→CACC_2 / efectivo→CACC_1 / retenciones→CACC_H/I/J/U; HABER: cliente→cuenta del CODAUX)
 * + cheques recibidos a cartera (Tbl Cheques, VADCHQ=True) + update SOPCUE. Numeración/ESTMOV/PDV por
 * el modo doble-libro. V1: forma de pago cheques(+efectivo), operación Cancelación (CODAUX=484).
 *
 * recibo_insert() NO maneja la transacción (lo hace el caller: guardar() o el test) → testeable con rollback.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('RECIBO_LIB')) {   // si se incluye como librería (test), no corre el dispatch ni auth
    auth_require_login();
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    try {
        switch ($action) {
            case 'guardar': guardar(); break;
            default: fail('Acción inválida: ' . $action);
        }
    } catch (Exception $e) { fail($e->getMessage(), 500); }
}

function rec_iso($iso) {
    if (!$iso) return null;
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$d) return null;
    return (int) (new DateTime('1899-12-30'))->diff($d)->days;
}
function rec_txt($s) { $s = trim((string) $s); return $s === '' ? 'Null' : "'" . db_esc($s) . "'"; }

/** Inserta una imputación contable + actualiza el saldo cacheado de la cuenta. Mantiene ord/totDeb/totCre. */
function imp_row(&$ord, &$totDeb, &$totCre, $nummov, $cuenta, $deb, $cre, $codchq, $fax, $caccZ) {
    $ord++;
    if ((string) $cuenta !== (string) $caccZ) {   // el balanceo no acumula
        $totDeb += (float) $deb; $totCre += (float) $cre;
    }
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
 * Núcleo de la grabación (sin transacción). Devuelve [nummov, cinmov, total].
 * $d = {codcue, codaux, fexmov(iso), fixmov(iso), codfdp, detmov, efectivo,
 *       referencias:[{refmov,fvxmov(iso),imp}], retenciones:{rt1,rip,rin, rt2,rgp,rgn,codrrg, rt3,rvp,rvn, rt4,rsp,rsn},
 *       cheques:[{codban,syn,fex(iso),fax(iso),imp,plz,lib,cit,loc}]}
 */
function recibo_insert($d, $estTrue, $cipmov) {
    $rc = db_row("SELECT CACC_1, CACC_2, CACC_H, CACC_I, CACC_J, CACC_U, CACC_Z FROM [Rec Control];");
    $cacc1 = $rc['CACC_1']; $cacc2 = $rc['CACC_2']; $caccZ = $rc['CACC_Z'];

    $codcue = (int) $d['codcue'];
    $codaux = (int) $d['codaux'];
    $cli = db_row("SELECT DENCUE, DCXCUE, DNXCUE, DPXCUE, DDXCUE, CODLOC, CODCRI, CITCUE, SOPCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue;");
    if (!$cli) throw new Exception('Cliente inexistente');
    $haber = db_row("SELECT CODCUE FROM [Tbl Operaciones Auxiliares] WHERE CODAUX=$codaux;");
    if (!$haber) throw new Exception('Operación (CODAUX) inexistente');
    $ctaHaber = $haber['CODCUE'];

    $fex = rec_iso($d['fexmov']);
    $fix = isset($d['fixmov']) && $d['fixmov'] ? rec_iso($d['fixmov']) : $fex;
    if ($fex === null) throw new Exception('Falta la fecha de emisión');
    $codfdp = (int) nz($d['codfdp'], 4);
    $efectivo = round((float) nz($d['efectivo'], 0), 2);

    $refs = (isset($d['referencias']) && is_array($d['referencias'])) ? $d['referencias'] : array();
    $chqs = (isset($d['cheques']) && is_array($d['cheques'])) ? $d['cheques'] : array();
    $ret = isset($d['retenciones']) && is_array($d['retenciones']) ? $d['retenciones'] : array();
    $rt1 = round((float) nz(isset($ret['rt1']) ? $ret['rt1'] : 0, 0), 2);
    $rt2 = round((float) nz(isset($ret['rt2']) ? $ret['rt2'] : 0, 0), 2);
    $rt3 = round((float) nz(isset($ret['rt3']) ? $ret['rt3'] : 0, 0), 2);
    $rt4 = round((float) nz(isset($ret['rt4']) ? $ret['rt4'] : 0, 0), 2);

    $sumRef = 0; foreach ($refs as $r) $sumRef += (float) nz($r['imp'], 0);
    $sumChq = 0; foreach ($chqs as $c) $sumChq += (float) nz($c['imp'], 0);
    $total  = round($sumChq + $efectivo + $rt1 + $rt2 + $rt3 + $rt4, 2);   // curTotMov
    $sdomov = round(-($total - $sumRef), 2);                               // cancelación

    // Numeración
    $nummov = next_number('ULTMOV');
    $cinmov = next_number_pdv('ULTREC', $estTrue ? $cipmov : null);
    $cipSql = $estTrue ? (string) (int) $cipmov : 'Null';
    $estSql = $estTrue ? 'True' : 'False';
    $soc = round((float) nz($cli['SOPCUE'], 0), 2);   // saldo operativo snapshot

    // --- Header (Tbl Movimientos) ---
    $denSql = rec_txt(nz($cli['DENCUE'], ''));
    $citSql = rec_txt(nz($cli['CITCUE'], ''));
    $dcx = rec_txt(nz($cli['DCXCUE'], '')); $dnx = rec_txt(nz($cli['DNXCUE'], ''));
    $dpx = rec_txt(nz($cli['DPXCUE'], '')); $ddx = rec_txt(nz($cli['DDXCUE'], ''));
    $codloc = (int) nz($cli['CODLOC'], 0); $codcri = (int) nz($cli['CODCRI'], 0);
    $detSql = rec_txt(isset($d['detmov']) ? $d['detmov'] : '');
    // retención números (header)
    $rip = (int) nz(isset($ret['rip']) ? $ret['rip'] : 0, 0); $rin = (int) nz(isset($ret['rin']) ? $ret['rin'] : 0, 0);
    $rgp = isset($ret['rgp']) && $ret['rgp'] !== '' ? (int) $ret['rgp'] : 0; $rgn = isset($ret['rgn']) && $ret['rgn'] !== '' ? (int) $ret['rgn'] : 0;
    $codrrg = isset($ret['codrrg']) && $ret['codrrg'] !== '' ? (int) $ret['codrrg'] : 'Null';
    $rvp = (int) nz(isset($ret['rvp']) ? $ret['rvp'] : 0, 0); $rvn = (int) nz(isset($ret['rvn']) ? $ret['rvn'] : 0, 0);
    $rsp = (int) nz(isset($ret['rsp']) ? $ret['rsp'] : 0, 0); $rsn = (int) nz(isset($ret['rsn']) ? $ret['rsn'] : 0, 0);
    $rgpSql = $rgp > 0 ? (string) $rgp : 'Null'; $rgnSql = $rgn > 0 ? (string) $rgn : 'Null';

    db_exec("INSERT INTO [Tbl Movimientos]
        (NUMMOV, CODORI, FEXMOV, CODOPE, CODAUX, CICMOV, CIPMOV, ESTMOV, CINMOV, CECMOV, CEPMOV, CENMOV, CEFMOV,
         CODCUE, SOCMOV, DENMOV, DCXMOV, DNXMOV, DPXMOV, DDXMOV, CODLOC, CODCRI, CITMOV, CODCDV, CODFDP, DETMOV, FIXMOV,
         RT1MOV, RIPMOV, RINMOV, RT2MOV, RGPMOV, RGNMOV, CODRRG, RT3MOV, RVPMOV, RVNMOV, RT4MOV, RSPMOV, RSNMOV,
         CREMOV, TOTMOV, SDOMOV, ANUMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($nummov, 'D', $fex, 480, $codaux, 'RC', $cipSql, $estSql, $cinmov, 'RT', $cipSql, 0, $fex,
         $codcue, $soc, $denSql, $dcx, $dnx, $dpx, $ddx, $codloc, $codcri, $citSql, 2, $codfdp, $detSql, $fix,
         $rt1, $rip, $rin, $rt2, $rgpSql, $rgnSql, $codrrg, $rt3, $rvp, $rvn, $rt4, $rsp, $rsn,
         $total, $total, $sdomov, False, 0, 0, Now());");

    // --- Referencias (facturas canceladas) ---
    foreach ($refs as $r) {
        $ref = (int) nz($r['refmov'], 0); $imp = round((float) nz($r['imp'], 0), 2);
        $fvx = rec_iso($r['fvxmov']);
        $fvxSql = $fvx === null ? 'Null' : (string) $fvx;
        db_exec("INSERT INTO [Tbl Movimientos Referencias] (NUMMOV, REFMOV, FVXMOV, IMPMOV) VALUES ($nummov, $ref, $fvxSql, $imp);");
        // Vencimiento: CREMOV += imp (puede estar null)
        if ($fvx !== null) {
            $v = db_row("SELECT CREMOV FROM [Tbl Movimientos Vencimientos] WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
            $nuevo = round((float) nz($v ? $v['CREMOV'] : 0, 0) + $imp, 2);
            db_exec("UPDATE [Tbl Movimientos Vencimientos] SET CREMOV=$nuevo WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
        }
        // Factura: SDOMOV -= imp
        $f = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV=$ref;");
        $nsdo = round((float) nz($f ? $f['SDOMOV'] : 0, 0) - $imp, 2);
        db_exec("UPDATE [Tbl Movimientos] SET SDOMOV=$nsdo WHERE NUMMOV=$ref;");
    }

    // --- Asiento (Tbl Movimientos Imputaciones) ---
    $ord = 0; $totDeb = 0; $totCre = 0;
    // DEBE: cheques → CACC_2 (crea cada cheque en cartera)
    foreach ($chqs as $c) {
        $imp = round((float) nz($c['imp'], 0), 2);
        $codchq = next_number('ULTCHQ');
        $ban = (int) nz($c['codban'], 0);
        $syn = rec_txt(nz($c['syn'], '')); $lib = rec_txt(nz($c['lib'], '')); $cit = rec_txt(nz($c['cit'], '')); $loc = rec_txt(nz($c['loc'], ''));
        $fexc = rec_iso(isset($c['fex']) ? $c['fex'] : ''); $faxc = rec_iso(isset($c['fax']) ? $c['fax'] : '');
        $fexSql = $fexc === null ? 'Null' : (string) $fexc; $faxSql = $faxc === null ? 'Null' : (string) $faxc;
        $plz = (int) nz($c['plz'], 0);
        db_exec("INSERT INTO [Tbl Cheques] (CODCHQ, CODBAN, SYNCHQ, FEXCHQ, FAXCHQ, IMPCHQ, PLZCHQ, LIBCHQ, CITCHQ, LOCCHQ, VADCHQ)
            VALUES ($codchq, $ban, $syn, $fexSql, $faxSql, $imp, $plz, $lib, $cit, $loc, True);");
        imp_row($ord, $totDeb, $totCre, $nummov, $cacc2, $imp, 0, $codchq, $faxc, $caccZ);
    }
    if ($efectivo > 0) imp_row($ord, $totDeb, $totCre, $nummov, $cacc1, $efectivo, 0, null, null, $caccZ);
    // Retenciones (DEBE)
    if ($rt1 > 0) imp_row($ord, $totDeb, $totCre, $nummov, $rc['CACC_H'], $rt1, 0, null, null, $caccZ);
    if ($rt2 > 0) imp_row($ord, $totDeb, $totCre, $nummov, $rc['CACC_I'], $rt2, 0, null, null, $caccZ);
    if ($rt3 > 0) imp_row($ord, $totDeb, $totCre, $nummov, $rc['CACC_J'], $rt3, 0, null, null, $caccZ);
    if ($rt4 > 0) imp_row($ord, $totDeb, $totCre, $nummov, $rc['CACC_U'], $rt4, 0, null, null, $caccZ);
    // HABER: cliente (cuenta del CODAUX)
    imp_row($ord, $totDeb, $totCre, $nummov, $ctaHaber, 0, $total, null, null, $caccZ);
    // Balanceo (redondeo) contra CACC_Z
    $dif = round($totDeb - $totCre, 2);
    if (abs($dif) >= 0.005) {
        if ($dif > 0) imp_row($ord, $totDeb, $totCre, $nummov, $caccZ, 0, $dif, null, null, $caccZ);
        else          imp_row($ord, $totDeb, $totCre, $nummov, $caccZ, -$dif, 0, null, null, $caccZ);
    }

    // --- Update Cuenta Corriente (baja la deuda) ---
    db_exec("UPDATE [Tbl Cuentas Corrientes] SET SOPCUE = SOPCUE - $total, FUOCUE = $fex WHERE CODCUE=$codcue;");

    return array('nummov' => $nummov, 'cinmov' => $cinmov, 'cipmov' => ($estTrue ? (int) $cipmov : null), 'total' => $total);
}

function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $d = json_decode(isset($_POST['data']) ? $_POST['data'] : '', true);
    if (!is_array($d)) { fail('Datos inválidos'); return; }
    if (empty($d['codcue'])) { fail('Falta el cliente'); return; }
    if (empty($d['referencias'])) { fail('No hay comprobantes a cancelar'); return; }

    $modo = auth_modo();
    $estTrue = ($modo !== 'capacitacion');
    $cipmov = isset($d['cipmov']) ? (int) $d['cipmov'] : 0;
    if ($estTrue && $cipmov <= 0) { fail('Elegí un punto de venta'); return; }

    db_begin();
    try {
        $res = recibo_insert($d, $estTrue, $cipmov);
        db_commit();
        ok($res);
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar el recibo: ' . $e->getMessage(), 500);
    }
}
