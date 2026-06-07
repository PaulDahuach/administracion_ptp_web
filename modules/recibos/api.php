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
            case 'buscar_clientes': buscar_clientes(); break;
            case 'get_cliente':     get_cliente();     break;
            case 'pendientes':      pendientes();      break;
            case 'bancos':          listar_bancos();   break;
            case 'cuentas_bancarias': listar_cuentas_bancarias(); break;
            case 'regimenes':       listar_regimenes(); break;
            case 'operaciones':     listar_operaciones(); break;
            case 'pdvs':            listar_pdvs();      break;
            case 'guardar':         guardar();         break;
            case 'anular':          anular();          break;
            case 'listar':          listar();          break;
            case 'detalle':         detalle();         break;
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
    $codcbx = (int) nz(isset($d['codcbx']) ? $d['codcbx'] : 0, 0);   // cuenta bancaria (interdepósito)

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
         CODCUE, SOCMOV, DENMOV, DCXMOV, DNXMOV, DPXMOV, DDXMOV, CODLOC, CODCRI, CITMOV, CODCDV, CODFDP, CODCBX, DETMOV, FIXMOV,
         RT1MOV, RIPMOV, RINMOV, RT2MOV, RGPMOV, RGNMOV, CODRRG, RT3MOV, RVPMOV, RVNMOV, RT4MOV, RSPMOV, RSNMOV,
         CREMOV, TOTMOV, SDOMOV, ANUMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($nummov, 'D', $fex, 480, $codaux, 'RC', $cipSql, $estSql, $cinmov, 'RT', $cipSql, 0, $fex,
         $codcue, $soc, $denSql, $dcx, $dnx, $dpx, $ddx, $codloc, $codcri, $citSql, 2, $codfdp, " . ($codfdp == 5 ? $codcbx : 'Null') . ", $detSql, $fix,
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
    if ($codfdp == 5) {
        // INTERDEPÓSITO: el cobrado va directo a la cuenta contable del banco (no hay cheques).
        $bk = db_row("SELECT CODCUE FROM [Tbl Cuentas Contables] WHERE CODCBX=$codcbx AND CODCUE Like '11104%';");
        if (!$bk) throw new Exception('Elegí una cuenta bancaria válida');
        $deposito = round($total - ($rt1 + $rt2 + $rt3 + $rt4), 2);
        if ($deposito > 0) imp_row($ord, $totDeb, $totCre, $nummov, $bk['CODCUE'], $deposito, 0, null, $fex, $caccZ);
    } else {
        // DEBE: cheques → CACC_2 (crea cada cheque en cartera) + efectivo → CACC_1.
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
    }
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
    // Anticipo (CODAUX=483) no cancela comprobantes; el resto sí requiere referencias.
    if (empty($d['referencias']) && (int) nz($d['codaux'], 484) != 483) { fail('No hay comprobantes a cancelar'); return; }

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

// ───────────────────────── Lookups / búsqueda ─────────────────────────
function estmov_w() {
    $l = auth_libro_unico();
    if ($l === 'blanco') return ' AND ESTMOV=True';
    if ($l === 'capacitacion')  return ' AND ESTMOV=False';
    return '';
}
function comp_str($cic, $cii, $cip, $cin) {
    $pdv = str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT);
    $nro = str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT);
    return trim((string) nz($cic, '') . ' ' . nz($cii, '')) . ' ' . $pdv . '-' . $nro;
}

function buscar_clientes() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    $num = is_numeric($q) ? " OR CODCUE = " . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes]
        WHERE CODORI='D' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

function get_cliente() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $c = db_row("SELECT C.CODCUE, C.DENCUE, C.CITCUE, C.SOPCUE, C.DCXCUE, C.DNXCUE, C.CODLOC, L.DENLOC, P.DENPRO, C.CODCRI
        FROM ([Tbl Provincias] AS P RIGHT JOIN ([Tbl Localidades] AS L INNER JOIN [Tbl Cuentas Corrientes] AS C ON L.CODLOC=C.CODLOC) ON P.CODPRO=L.CODPRO)
        WHERE C.CODCUE=$cc;");
    if (!$c) { fail('Cliente no encontrado'); return; }
    $c['SALDO'] = round((float) nz($c['SOPCUE'], 0), 2);
    $c['DOMICILIO'] = trim(nz($c['DCXCUE'], '') . ' ' . nz($c['DNXCUE'], ''));
    $c['LOCALIDAD'] = trim(nz($c['DENLOC'], '') . ' - ' . nz($c['DENPRO'], ''));
    ok($c);
}

/** Comprobantes pendientes del cliente (vencimientos de FV/ND con saldo) para la grilla Referencias. */
function pendientes() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $rows = db_query("SELECT M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.FEXMOV, V.FVXMOV, V.DEBMOV, V.CREMOV, V.DETMOV
        FROM [Tbl Movimientos] AS M INNER JOIN [Tbl Movimientos Vencimientos] AS V ON V.NUMMOV = M.NUMMOV
        WHERE M.CODORI='D' AND (M.CODOPE=420 OR M.CODOPE=440) AND M.CODCUE=$cc AND M.SDOMOV>0
        ORDER BY V.FVXMOV;");
    $out = array();
    foreach ($rows as $r) {
        $saldo = round((float) nz($r['DEBMOV'], 0) - (float) nz($r['CREMOV'], 0), 2);
        if ($saldo <= 0.005) continue;
        $out[] = array(
            'REFMOV' => (int) $r['NUMMOV'],
            'COMP'   => comp_str($r['CICMOV'], $r['CIIMOV'], $r['CIPMOV'], $r['CINMOV']),
            'FEXMOV' => fecha_serial($r['FEXMOV']),
            'FVXMOV' => fecha_serial($r['FVXMOV']),
            'FVXISO' => (new DateTime('1899-12-30'))->modify('+' . (int) $r['FVXMOV'] . ' days')->format('Y-m-d'),
            'DETMOV' => trim((string) nz($r['DETMOV'], '')),
            'SALDO'  => $saldo,
        );
    }
    ok($out);
}

function listar_bancos() { ok(db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos] ORDER BY DENBAN;")); }
/** Cuentas bancarias propias (disponibilidades 11104xx con CODCBX) para interdepósito. */
function listar_cuentas_bancarias() { ok(db_query("SELECT CODCBX, DENCUE FROM [Tbl Cuentas Contables] WHERE CODCBX Is Not Null AND CODCUE Like '11104%' ORDER BY DENCUE;")); }
function listar_operaciones() { ok(db_query("SELECT CODAUX, DENAUX FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=480 ORDER BY CODAUX;")); }
/** Regímenes de retención de Ganancias (para el combo CODRRG). */
function listar_regimenes() { ok(db_query("SELECT CODRRG, DENRRG FROM [Tbl Regimenes Retencion Ganancias] ORDER BY DENRRG;")); }
function listar_pdvs() { ok(db_query("SELECT CODPDV, NOMPDV FROM [Tbl Puntos de Venta] WHERE CODPDV <> 9999 ORDER BY CODPDV;")); }

/** Listado de recibos (CODOPE=480) filtrado por modo + texto/fecha. */
function listar() {
    $q  = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sd = isset($_GET['desde']) && $_GET['desde'] ? (int) (new DateTime('1899-12-30'))->diff(new DateTime($_GET['desde']))->days : null;
    $sh = isset($_GET['hasta']) && $_GET['hasta'] ? (int) (new DateTime('1899-12-30'))->diff(new DateTime($_GET['hasta']))->days : null;
    $w = "CODOPE=480" . estmov_w();
    if ($q !== '') {
        $qs = db_esc($q);
        $cond = "(DENMOV Like '%$qs%' OR CITMOV Like '%$qs%'";
        if (is_numeric($q)) $cond .= " OR CINMOV=" . (int) $q;
        $w .= " AND $cond)";
    }
    if ($sd !== null) $w .= " AND FEXMOV >= $sd";
    if ($sh !== null) $w .= " AND FEXMOV <= $sh";
    $rows = db_query("SELECT TOP 200 NUMMOV, FEXMOV, CIPMOV, CINMOV, DENMOV, TOTMOV, ANUMOV, ESTMOV
        FROM [Tbl Movimientos] WHERE $w ORDER BY FEXMOV DESC, NUMMOV DESC;");
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            'NUMMOV' => (int) $r['NUMMOV'], 'FEXMOV' => fecha_serial($r['FEXMOV']), 'FEXMOVO' => (int) nz($r['FEXMOV'], 0),
            'COMP' => comp_str('RC', '', $r['CIPMOV'], $r['CINMOV']), 'DENMOV' => trim((string) nz($r['DENMOV'], '')),
            'TOTMOV' => round((float) nz($r['TOTMOV'], 0), 2), 'ANU' => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1) ? 1 : 0,
        );
    }
    ok(array('recibos' => $out, 'tope' => count($out) >= 200));
}

/** Detalle COMPLETO de un recibo (todos los campos del form) para verlo en la pantalla, bloqueado. */
function detalle() {
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT * FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=480;");
    if (!$h) { fail('Recibo no encontrado'); return; }
    $lib = auth_libro_unico();
    $estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
    if (($lib === 'blanco' && !$estTrue) || ($lib === 'capacitacion' && $estTrue)) { fail('Recibo no disponible en este libro'); return; }
    $iso = function ($s) { if ($s === null || $s === '') return ''; return (new DateTime('1899-12-30'))->modify('+' . (int) $s . ' days')->format('Y-m-d'); };

    $refs = array();
    foreach (db_query("SELECT R.REFMOV, R.FVXMOV, R.IMPMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV
        FROM [Tbl Movimientos Referencias] AS R LEFT JOIN [Tbl Movimientos] AS M ON M.NUMMOV=R.REFMOV WHERE R.NUMMOV=$num;") as $r)
        $refs[] = array('COMP' => comp_str($r['CICMOV'], $r['CIIMOV'], $r['CIPMOV'], $r['CINMOV']), 'FVXMOV' => fecha_serial($r['FVXMOV']), 'IMP' => round((float) nz($r['IMPMOV'], 0), 2));

    $ban = array();
    foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos]") as $b) $ban[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));
    $chqs = array();
    foreach (db_query("SELECT C.CODBAN, C.SYNCHQ, C.FEXCHQ, C.FAXCHQ, C.IMPCHQ, C.LIBCHQ, C.LOCCHQ
        FROM [Tbl Cheques] AS C INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON MI.CODCHQ=C.CODCHQ WHERE MI.NUMMOV=$num ORDER BY MI.ORDMOV;") as $c)
        $chqs[] = array('CODBAN' => (int) nz($c['CODBAN'], 0), 'BANCO' => isset($ban[(int) $c['CODBAN']]) ? $ban[(int) $c['CODBAN']] : '',
            'SYN' => trim((string) nz($c['SYNCHQ'], '')), 'FEXISO' => $iso($c['FEXCHQ']), 'FAXISO' => $iso($c['FAXCHQ']), 'FAX' => fecha_serial($c['FAXCHQ']),
            'LIB' => trim((string) nz($c['LIBCHQ'], '')), 'LOC' => trim((string) nz($c['LOCCHQ'], '')), 'IMP' => round((float) nz($c['IMPCHQ'], 0), 2));

    $loc = db_row("SELECT L.DENLOC, P.DENPRO FROM [Tbl Localidades] AS L LEFT JOIN [Tbl Provincias] AS P ON L.CODPRO=P.CODPRO WHERE L.CODLOC=" . (int) nz($h['CODLOC'], 0) . ";");

    ok(array(
        'NUMMOV' => $num, 'CIPMOV' => (int) nz($h['CIPMOV'], 0), 'CINMOV' => (int) nz($h['CINMOV'], 0),
        'FEXISO' => $iso($h['FEXMOV']), 'FIXISO' => $iso($h['FIXMOV']),
        'CODAUX' => (int) nz($h['CODAUX'], 0), 'CODCUE' => (int) nz($h['CODCUE'], 0),
        'DENMOV' => trim((string) nz($h['DENMOV'], '')), 'CITMOV' => trim((string) nz($h['CITMOV'], '')),
        'DOMICILIO' => trim(nz($h['DCXMOV'], '') . ' ' . nz($h['DNXMOV'], '')),
        'LOCALIDAD' => $loc ? trim(nz($loc['DENLOC'], '') . ' - ' . nz($loc['DENPRO'], '')) : '',
        'SOCMOV' => round((float) nz($h['SOCMOV'], 0), 2), 'DETMOV' => trim((string) nz($h['DETMOV'], '')),
        'CODFDP' => (int) nz($h['CODFDP'], 4), 'CODCBX' => (int) nz($h['CODCBX'], 0),
        'ANU' => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) ? 1 : 0, 'TOTMOV' => round((float) nz($h['TOTMOV'], 0), 2),
        'ret' => array(
            'rt1' => round((float) nz($h['RT1MOV'], 0), 2), 'rin' => (int) nz($h['RINMOV'], 0),
            'rt2' => round((float) nz($h['RT2MOV'], 0), 2), 'rgp' => (int) nz($h['RGPMOV'], 0), 'rgn' => (int) nz($h['RGNMOV'], 0), 'codrrg' => (int) nz($h['CODRRG'], 0),
            'rt3' => round((float) nz($h['RT3MOV'], 0), 2), 'rvn' => (int) nz($h['RVNMOV'], 0),
            'rt4' => round((float) nz($h['RT4MOV'], 0), 2), 'rsn' => (int) nz($h['RSNMOV'], 0),
        ),
        'referencias' => $refs, 'cheques' => $chqs,
    ));
}

/** Núcleo de la anulación (sin transacción → testeable). Porta SetData Case "B" estándar. */
function recibo_anular($num) {
    $h = db_row("SELECT NUMMOV, CODCUE, CODAUX, TOTMOV, CREMOV, ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=480;");
    if (!$h) throw new Exception('Recibo no encontrado');
    if ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) throw new Exception('El recibo ya está anulado');
    $codaux = (int) nz($h['CODAUX'], 0);
    {
        $total = round((float) nz($h['TOTMOV'], 0), 2);
        $codcue = (int) $h['CODCUE'];
        $contado = ($codaux == 481 || $codaux == 482);
        $impCajRC = round((float) nz($h['CREMOV'], 0), 2);
        $lngMovFC = 0; $impCajFC = 0;
        if ($contado) {
            // Contado: la FC/ND referenciada se "despaga" → pasa a cta cte propia (CODFDP=1, CRE=DEB, NRCMOV=Null).
            // OJO: porta Case "B" 481/482 pero SIN validar contra dato real (no hay recibos de contado en el backend).
            $fc = db_row("SELECT NUMMOV, DEBMOV FROM [Tbl Movimientos] WHERE NRCMOV=$num;");
            if (!$fc) throw new Exception('No se encontró el comprobante de contado referenciado por el recibo');
            $lngMovFC = (int) $fc['NUMMOV'];
            $impCajFC = round((float) nz($fc['DEBMOV'], 0), 2);
            db_exec("UPDATE [Tbl Movimientos] SET CODFDP=1, CREMOV=$impCajFC, NRCMOV=Null WHERE NUMMOV=$lngMovFC;");
        }
        // Referencias: revertir vencimientos y saldo de las facturas, luego borrar.
        foreach (db_query("SELECT REFMOV, FVXMOV, IMPMOV FROM [Tbl Movimientos Referencias] WHERE NUMMOV=$num;") as $r) {
            $ref = (int) $r['REFMOV']; $imp = round((float) nz($r['IMPMOV'], 0), 2); $fvx = (int) $r['FVXMOV'];
            $v = db_row("SELECT CREMOV FROM [Tbl Movimientos Vencimientos] WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
            $nuevo = round((float) nz($v ? $v['CREMOV'] : 0, 0) - $imp, 2);
            if ($nuevo == 0) db_exec("UPDATE [Tbl Movimientos Vencimientos] SET CREMOV=Null WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
            else            db_exec("UPDATE [Tbl Movimientos Vencimientos] SET CREMOV=$nuevo WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
            $f = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV=$ref;");
            db_exec("UPDATE [Tbl Movimientos] SET SDOMOV=" . round((float) nz($f ? $f['SDOMOV'] : 0, 0) + $imp, 2) . " WHERE NUMMOV=$ref;");
        }
        db_exec("DELETE FROM [Tbl Movimientos Referencias] WHERE NUMMOV=$num;");

        // Cuenta corriente: devolver la deuda (contado usa la lógica condicional del legacy).
        if (!$contado) {
            db_exec("UPDATE [Tbl Cuentas Corrientes] SET SOPCUE = SOPCUE + $total WHERE CODCUE=$codcue;");
        } else {
            $delta = ($total > $impCajRC) ? $total : (($impCajRC > $impCajFC) ? round($impCajRC - $impCajFC, 2) : 0);
            if ($delta != 0) db_exec("UPDATE [Tbl Cuentas Corrientes] SET SOPCUE = SOPCUE + $delta WHERE CODCUE=$codcue;");
        }

        // Imputaciones: revertir saldos contables cacheados + cheques.
        $imps = db_query("SELECT ORDMOV, CODCUE, DEBMOV, CREMOV, CODCHQ FROM [Tbl Movimientos Imputaciones] WHERE NUMMOV=$num;");
        $cheques = array();
        foreach ($imps as $i) {
            $cc = db_esc((string) $i['CODCUE']);
            if ($i['DEBMOV'] !== null) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE - " . round((float) $i['DEBMOV'], 2) . " WHERE CODCUE='$cc';");
            if ($i['CREMOV'] !== null) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE - " . round((float) $i['CREMOV'], 2) . " WHERE CODCUE='$cc';");
            if ($i['CODCHQ'] !== null && $i['CODCHQ'] !== '') $cheques[] = (int) $i['CODCHQ'];
        }
        // Zerar el asiento (mantiene las filas como rastro) y soltar el vínculo al cheque.
        db_exec("UPDATE [Tbl Movimientos Imputaciones] SET DEBMOV=0, CREMOV=0, FAXMOV=Null, CODCHQ=Null WHERE NUMMOV=$num;");
        // Cheques recibidos: sacar de cartera; borrar los que no quedan referenciados por otro asiento.
        foreach (array_unique($cheques) as $chq) {
            db_exec("UPDATE [Tbl Cheques] SET VADCHQ=False WHERE CODCHQ=$chq;");
            $oth = db_row("SELECT Count(*) AS N FROM [Tbl Movimientos Imputaciones] WHERE CODCHQ=$chq;");
            if ((int) nz($oth['N'], 0) == 0) db_exec("DELETE FROM [Tbl Cheques] WHERE CODCHQ=$chq;");
        }

        // Marcar el recibo anulado (montos en 0, [ANULADO], ANUMOV=True).
        db_exec("UPDATE [Tbl Movimientos] SET DETMOV='[ANULADO]', SDOMOV=0, CREMOV=0, TOTMOV=0,
            RT1MOV=0, RT2MOV=0, RT3MOV=0, RT4MOV=0, RIPMOV=Null, RINMOV=Null, RGPMOV=Null, RGNMOV=Null,
            CODRRG=Null, RVPMOV=Null, RVNMOV=Null, RSPMOV=Null, RSNMOV=Null, FIXMOV=Null, ANUMOV=True
            WHERE NUMMOV=$num;");

        // Contado: mover la imputación de la FC de DEUDORES (CACC_A) a CAJA (CACC_1).
        if ($contado) {
            $rcc = db_row("SELECT CACC_1, CACC_A FROM [Rec Control];");
            $cA = db_esc((string) $rcc['CACC_A']); $c1 = db_esc((string) $rcc['CACC_1']);
            $fcimp = db_row("SELECT ORDMOV, DEBMOV FROM [Tbl Movimientos Imputaciones] WHERE NUMMOV=$lngMovFC AND CODCUE='$cA';");
            if ($fcimp) {
                $deb = round((float) nz($fcimp['DEBMOV'], 0), 2);
                db_exec("UPDATE [Tbl Movimientos Imputaciones] SET CODCUE='$c1' WHERE NUMMOV=$lngMovFC AND ORDMOV=" . (int) $fcimp['ORDMOV'] . ";");
                db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE - $deb WHERE CODCUE='$cA';");
                db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE + $deb WHERE CODCUE='$c1';");
            }
        }
    }
}

/** Anula un recibo (auth + visibilidad por modo + transacción). */
function anular() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $num = isset($_POST['nummov']) ? (int) $_POST['nummov'] : 0;
    $h = db_row("SELECT ESTMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=480;");
    if (!$h) { fail('Recibo no encontrado'); return; }
    $estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
    $lib = auth_libro_unico();
    if (($lib === 'blanco' && !$estTrue) || ($lib === 'capacitacion' && $estTrue)) { fail('Recibo no disponible en este libro'); return; }
    db_begin();
    try {
        recibo_anular($num);
        db_commit();
        ok(array('anulado' => $num));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo anular el recibo: ' . $e->getMessage(), 500);
    }
}
