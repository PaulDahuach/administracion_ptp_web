<?php
/**
 * Notas de Débito Acreedoras — registración de la ND del PROVEEDOR. Porta `Frm CA Debitos`.
 * CODORI='A', CODOPE=330, CICMOV='ND', CODAUX=332, contador ULTNDI. Sin AFIP/CAE.
 *
 * DEBITA la cuenta corriente del proveedor (reduce lo que le debemos): DEBMOV=TOTMOV, SOPCUE += TOTMOV.
 * Anatomía = cabecera/IVA/comprobante de la NC ([[transaccional-nc-acreedoras]]) + REFERENCIAS estilo OP
 * ([[transaccional-ordenes-pago]]). Asiento INVERTIDO respecto al CP/NC:
 *  - HABER = la imputación que carga el usuario (revierte el gasto + IVA Crédito Fiscal). Σ = TOTMOV.
 *  - DEBE  = Proveedores (CACC_K) por lo referenciado (Σ IMPMOV) + Anticipo a Proveedores (CACC_L) por el
 *    excedente (TOTMOV − Σ referencias). SDOMOV = ese excedente (queda como saldo a favor).
 *  - Referencias: por cada comprobante referenciado, INSERT Tbl Movimientos Referencias + debita su
 *    vencimiento (Tbl Movimientos Vencimientos DEBMOV += IMPMOV) + SDOMOV del referenciado += IMPMOV.
 *
 * Reusa el CP como librería (CP_LIB): buscar_proveedores/get_proveedor/cuentas_imputables/centros_costo
 * (mismos campos que espera el form, igual que la NC). pendientes()/nd_imp (imputación con centro de costo)/
 * nd_iso son propios. nd_insert NO maneja la transacción (el caller envuelve).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

define('CP_LIB', 1);
require_once __DIR__ . '/../comprobantes_pagar/api.php';   // buscar_proveedores/get_proveedor/cuentas_imputables/centros_costo

if (!defined('NDA_LIB')) {
    auth_require_login();
    header('Content-Type: application/json');
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    try {
        switch ($action) {
            case 'buscar_proveedores': buscar_proveedores();    break;   // reusados del CP
            case 'get_proveedor':      get_proveedor();         break;
            case 'cuentas':            cuentas_imputables();    break;
            case 'centros_costo':      centros_costo();         break;
            case 'auto_imputar':       auto_imputar_ep();       break;
            case 'pendientes':         nda_pendientes();        break;   // CP/ND con saldo a referenciar
            case 'guardar':            nda_guardar();           break;
            case 'anular':             nda_anular();            break;
            case 'listar':             nda_listar();            break;
            case 'detalle':            nda_detalle();           break;
            default: fail('Acción inválida: ' . $action);
        }
    } catch (Exception $e) { fail($e->getMessage(), 500); }
}

function nda_num($v) { return (string) round((float) $v, 2); }
function nda_txt($s) { $s = trim((string) $s); return $s === '' ? 'Null' : "'" . db_esc($s) . "'"; }

/** ISO 'yyyy-mm-dd' → serial Access (entero). */
function nd_iso($iso) {
    if (!$iso) return null;
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    return $d ? (int) (new DateTime('1899-12-30'))->diff($d)->days : null;
}

/** Inserta una imputación contable (con centro de costo + ALIMOV/IVAMOV/TOTMOV para el export Holistor) +
 *  mayoriza el saldo cacheado. Mantiene ord/totDeb/totCre. La línea de gasto lleva ali/iva/tot; las demás Null. */
function nd_imp(&$ord, &$totDeb, &$totCre, $nummov, $cuenta, $deb, $cre, $codcdc, $caccZ, $ali = null, $iva = null, $tot = null) {
    $ord++;
    if ((string) $cuenta !== (string) $caccZ) { $totDeb += (float) $deb; $totCre += (float) $cre; }
    $cc  = db_esc((string) $cuenta);
    $sld = db_row("SELECT DEBCUE, CRECUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
    $soc = round((float) nz($sld['DEBCUE'], 0) - (float) nz($sld['CRECUE'], 0), 2);
    $debSql = ($deb > 0) ? round($deb, 2) : 'Null';
    $creSql = ($cre > 0) ? round($cre, 2) : 'Null';
    $cdc = (int) nz($codcdc, 1); if ($cdc <= 0) $cdc = 1;
    $aliSql = ($ali === null) ? 'Null' : (string) round((float) $ali, 2);
    $ivaSql = ($iva === null) ? 'Null' : (string) round((float) $iva, 2);
    $totSql = ($tot === null) ? 'Null' : (string) round((float) $tot, 2);
    db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, DEBMOV, CREMOV, CODCDC, SOCMOV, ALIMOV, IVAMOV, TOTMOV)
        VALUES ($nummov, $ord, '$cc', $debSql, $creSql, $cdc, $soc, $aliSql, $ivaSql, $totSql);");
    if ($deb > 0) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE + " . round($deb, 2) . " WHERE CODCUE='$cc';");
    if ($cre > 0) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE + " . round($cre, 2) . " WHERE CODCUE='$cc';");
}

/** Comprobantes del proveedor (CP/ND) con saldo pendiente → para referenciar y debitar (patrón OP). */
function nda_pendientes() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $out = array();
    foreach (db_query("SELECT M.NUMMOV, M.CICMOV, M.CINMOV, M.FEXMOV, M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV, M.CEFMOV, V.FVXMOV, V.DEBMOV, V.CREMOV, V.DETMOV
        FROM [Tbl Movimientos] AS M INNER JOIN [Tbl Movimientos Vencimientos] AS V ON V.NUMMOV=M.NUMMOV
        WHERE M.CODORI='A' AND (M.CODOPE=310 OR M.CODOPE=330) AND M.CODCUE=$cc AND M.SDOMOV<>0
        ORDER BY V.FVXMOV;") as $r) {
        $pend = round((float) nz($r['CREMOV'], 0) - (float) nz($r['DEBMOV'], 0), 2);
        if ($pend <= 0.005) continue;
        $ext = trim(trim((string) nz($r['CECMOV'], '')) . ' ' . trim((string) nz($r['CEIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CEPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CENMOV'], 0), 8, '0', STR_PAD_LEFT));
        $out[] = array(
            'REFMOV' => (int) $r['NUMMOV'],
            'INT'    => trim((string) nz($r['CICMOV'], '') . ' ' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT)),
            'EXT'    => $ext, 'COMP' => $ext,
            'FVXMOV' => fecha_serial($r['FVXMOV']), 'FVXISO' => nda_fiso($r['FVXMOV']),
            'DETMOV' => trim((string) nz($r['DETMOV'], '')), 'SALDO' => $pend,
        );
    }
    ok($out);
}

/**
 * Graba la ND Acreedora. $d: codcue, fexmov, fixmov, cec/cei/cep/cen/cef, codcri, citmov, detmov,
 *   ivas[{net,ali,iva}], nogmov/ip1mov/ip2mov/ap1mov/ap2mov, total,
 *   imputaciones[{codcue,codcdc,importe}]  (HABER: gasto + IVA crédito fiscal a revertir),
 *   referencias[{refmov,fvxmov,imp}]       (comprobantes que se debitan).
 */
function nd_insert($d, $estTrue) {
    $rc = db_row("SELECT CACC_K, CACC_L, CACC_Z FROM [Rec Control];");
    $caccK = trim((string) $rc['CACC_K']);   // Proveedores (DEBE por lo referenciado)
    $caccL = trim((string) $rc['CACC_L']);   // Anticipo a Proveedores (DEBE por el excedente)
    $caccZ = trim((string) $rc['CACC_Z']);   // balanceo / redondeo

    $codcue = (int) $d['codcue'];
    $prov = db_row("SELECT DENCUE, DCXCUE, DNXCUE, DDXCUE, DPXCUE, CODLOC, SOPCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='A';");
    if (!$prov) throw new Exception('Proveedor inexistente');

    $fex = nd_iso($d['fexmov']); if ($fex === null) throw new Exception('Falta la fecha de emisión');
    $fix = isset($d['fixmov']) && $d['fixmov'] !== '' ? nd_iso($d['fixmov']) : $fex;
    $estSql = $estTrue ? 'True' : 'False';

    // Comprobante del proveedor
    $cec = strtoupper(trim((string) nz(isset($d['cec']) ? $d['cec'] : 'FC', 'FC')));
    $cei = strtoupper(trim((string) nz(isset($d['cei']) ? $d['cei'] : 'A', 'A')));
    $cep = (int) nz(isset($d['cep']) ? $d['cep'] : 0, 0);
    $cen = (int) nz(isset($d['cen']) ? $d['cen'] : 0, 0);
    $cef = isset($d['cef']) && $d['cef'] !== '' ? nd_iso($d['cef']) : $fex;

    // Importes / IVA
    $ivas = isset($d['ivas']) && is_array($d['ivas']) ? array_values($d['ivas']) : array();
    $netmov = 0; $irimov = 0;
    foreach ($ivas as $iv) { $netmov += (float) nz($iv['net'], 0); $irimov += (float) nz($iv['iva'], 0); }
    $netmov = round($netmov, 2); $irimov = round($irimov, 2);
    $nogmov = round((float) nz(isset($d['nogmov']) ? $d['nogmov'] : 0, 0), 2);
    $ip1 = round((float) nz(isset($d['ip1mov']) ? $d['ip1mov'] : 0, 0), 2);
    $ip2 = round((float) nz(isset($d['ip2mov']) ? $d['ip2mov'] : 0, 0), 2);
    $ap1 = round((float) nz(isset($d['ap1mov']) ? $d['ap1mov'] : 0, 0), 2);
    $ap2 = round((float) nz(isset($d['ap2mov']) ? $d['ap2mov'] : 0, 0), 2);
    $total = round((float) nz($d['total'], $netmov + $irimov + $nogmov + $ip1 + $ip2), 2);

    // Imputación (HABER) y referencias
    $imps = isset($d['imputaciones']) && is_array($d['imputaciones']) ? $d['imputaciones'] : array();
    if (!count($imps)) throw new Exception('Cargá la imputación contable (lo que se revierte va al Haber).');
    $sumImp = 0; foreach ($imps as $i) $sumImp += round((float) nz($i['importe'], 0), 2);
    if (abs(round($sumImp, 2) - $total) >= 0.01) throw new Exception('La imputación (' . number_format($sumImp, 2) . ') no coincide con el total (' . number_format($total, 2) . ').');

    $refs = isset($d['referencias']) && is_array($d['referencias']) ? $d['referencias'] : array();
    $sumRef = 0; foreach ($refs as $r) $sumRef += round((float) nz($r['imp'], 0), 2);
    $sumRef = round($sumRef, 2);
    if ($sumRef - $total >= 0.01) throw new Exception('Las referencias (' . number_format($sumRef, 2) . ') no pueden superar el total (' . number_format($total, 2) . ').');
    $curAnt = round($total - $sumRef, 2);   // excedente → anticipo a proveedores

    // Numeración: NUMMOV interno; CINMOV = contador ULTNDI (blanco=PDV1 / capacitación=9999). CIPMOV=0.
    $nummov = next_number('ULTMOV');
    $cinmov = next_number_pdv('ULTNDI', $estTrue ? 1 : 9999);

    // ── Header ──
    $den = nda_txt(nz($prov['DENCUE'], '')); $cit = nda_txt(isset($d['citmov']) ? nz($d['citmov'], '') : '');
    $dcx = nda_txt(nz($prov['DCXCUE'], '')); $dnx = nda_txt(nz($prov['DNXCUE'], ''));
    $codloc = (int) nz($prov['CODLOC'], 0); $codcri = (int) nz($d['codcri'], 0);
    $det = nda_txt(isset($d['detmov']) ? $d['detmov'] : '');
    $soc = round((float) nz($prov['SOPCUE'], 0), 2);

    db_exec("INSERT INTO [Tbl Movimientos]
        (NUMMOV, CODORI, FEXMOV, FIXMOV, CODOPE, CODAUX, CICMOV, CIPMOV, CINMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV,
         CODCUE, SOCMOV, DENMOV, DCXMOV, DNXMOV, CODLOC, CODCRI, CITMOV, DETMOV, NETMOV, IRIMOV, NOGMOV, IP1MOV, IP2MOV, AP1MOV, AP2MOV,
         DEBMOV, TOTMOV, SDOMOV, ESTMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($nummov, 'A', $fex, $fix, 330, 332, 'ND', 0, $cinmov, '$cec', '$cei', $cep, $cen, $cef,
         $codcue, " . nda_num($soc) . ", $den, $dcx, $dnx, $codloc, $codcri, $cit, $det, " . ($netmov != 0 ? nda_num($netmov) : 'Null') . ", " . ($irimov != 0 ? nda_num($irimov) : 'Null') . ", " . ($nogmov != 0 ? nda_num($nogmov) : 'Null') . ", " . ($ip1 != 0 ? nda_num($ip1) : 'Null') . ", " . ($ip2 != 0 ? nda_num($ip2) : 'Null') . ", " . ($ap1 != 0 ? nda_num($ap1) : 'Null') . ", " . ($ap2 != 0 ? nda_num($ap2) : 'Null') . ",
         " . nda_num($total) . ", " . nda_num($total) . ", " . nda_num($curAnt) . ", $estSql, 0, 0, Now());");

    // ── IVA (hasta 2 alícuotas) ──
    $dec = false;
    foreach ($ivas as $iv) {
        $net = round((float) nz($iv['net'], 0), 2); if ($net == 0 && (float) nz($iv['iva'], 0) == 0) continue;
        db_exec("INSERT INTO [Tbl Movimientos IVA] (NUMMOV, NETMOV, ALIMOV, IRIMOV, DECMOV)
            VALUES ($nummov, " . nda_num($net) . ", " . round((float) nz($iv['ali'], 0), 2) . ", " . nda_num((float) nz($iv['iva'], 0)) . ", " . ($dec ? 'True' : 'False') . ");");
        $dec = true;
    }

    // ── Referencias: debitar el vencimiento del comprobante referenciado (patrón OP) ──
    foreach ($refs as $r) {
        $ref = (int) nz($r['refmov'], 0); $imp = round((float) nz($r['imp'], 0), 2); if ($ref <= 0 || $imp == 0) continue;
        $fvx = nd_iso($r['fvxmov']); $fvxSql = $fvx === null ? 'Null' : (string) $fvx;
        db_exec("INSERT INTO [Tbl Movimientos Referencias] (NUMMOV, REFMOV, FVXMOV, IMPMOV) VALUES ($nummov, $ref, $fvxSql, " . nda_num($imp) . ");");
        if ($fvx !== null) {
            $v = db_row("SELECT DEBMOV FROM [Tbl Movimientos Vencimientos] WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
            db_exec("UPDATE [Tbl Movimientos Vencimientos] SET DEBMOV=" . nda_num(round((float) nz($v ? $v['DEBMOV'] : 0, 0) + $imp, 2)) . " WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
        }
        $f = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV=$ref;");
        db_exec("UPDATE [Tbl Movimientos] SET SDOMOV=" . nda_num(round((float) nz($f ? $f['SDOMOV'] : 0, 0) + $imp, 2)) . " WHERE NUMMOV=$ref;");
    }

    // ── Asiento: HABER imputación del usuario (gasto + IVA revertido) / DEBE Proveedores (Σref) + Anticipo (excedente) ──
    $ord = 0; $totDeb = 0; $totCre = 0;
    if ($sumRef > 0) nd_imp($ord, $totDeb, $totCre, $nummov, $caccK, $sumRef, 0, 1, $caccZ);   // DEBE Proveedores
    if ($curAnt > 0) nd_imp($ord, $totDeb, $totCre, $nummov, $caccL, $curAnt, 0, 1, $caccZ);   // DEBE Anticipo a Proveedores
    foreach ($imps as $i) {
        $cre = round((float) nz($i['importe'], 0), 2); if ($cre == 0) continue;
        $cdc = (int) nz(isset($i['codcdc']) ? $i['codcdc'] : 1, 1);
        nd_imp($ord, $totDeb, $totCre, $nummov, trim((string) $i['codcue']), 0, $cre, $cdc, $caccZ,
            isset($i['alimov']) ? $i['alimov'] : null, isset($i['ivamov']) ? $i['ivamov'] : null, isset($i['totmov']) ? $i['totmov'] : null);   // HABER gasto/IVA (+ ALIMOV/IVAMOV/TOTMOV Holistor)
    }
    $dif = round($totDeb - $totCre, 2);
    if (abs($dif) >= 0.005) { if ($dif > 0) nd_imp($ord, $totDeb, $totCre, $nummov, $caccZ, 0, $dif, 1, $caccZ); else nd_imp($ord, $totDeb, $totCre, $nummov, $caccZ, -$dif, 0, 1, $caccZ); }

    // ── Cuenta corriente: la ND debita → reduce lo que le debemos → SOPCUE += total ──
    db_exec("UPDATE [Tbl Cuentas Corrientes] SET FUOCUE=$fex, SOPCUE=" . nda_num(round($soc + $total, 2)) . " WHERE CODCUE=$codcue;");

    return array('nummov' => $nummov, 'cinmov' => $cinmov, 'total' => $total, 'sdomov' => $curAnt, 'balanceo' => round($totDeb - $totCre, 2));
}

/** Endpoint guardar (envuelve nd_insert en transacción). */
function nda_guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? json_decode($_POST['data'], true) : null;
    if (!is_array($raw)) { fail('Datos inválidos'); return; }
    $estTrue = (auth_modo() !== 'capacitacion');
    db_begin();
    try { $res = nd_insert($raw, $estTrue); db_commit(); $res['anulable'] = true; ok($res); }
    catch (Exception $e) { db_rollback(); fail('No se pudo grabar la nota de débito acreedora: ' . $e->getMessage(), 500); }
}

/** Serial Access → 'yyyy-mm-dd' (para los <input type=date>). */
function nda_fiso($s) { $f = fecha_serial($s); if (!$f || strpos($f, '/') === false) return ''; $p = explode('/', $f); return $p[2] . '-' . $p[1] . '-' . $p[0]; }

/** Buscar ND Acreedoras emitidas (CODOPE=330) por proveedor / nº / texto / fecha — filtrado por el libro del modo. */
function nda_listar() {
    $w = array("M.CODORI='A'", "M.CODOPE=330");
    $unico = auth_libro_unico();
    if ($unico === 'blanco') $w[] = "M.ESTMOV=True"; elseif ($unico === 'capacitacion') $w[] = "M.ESTMOV=False";
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if ($q !== '') { $s = db_esc($q); $num = is_numeric($q) ? (" OR M.CINMOV=" . (int) $q . " OR M.CENMOV=" . (int) $q . " OR M.NUMMOV=" . (int) $q) : ''; $w[] = "((M.DENMOV Like '%$s%') OR (M.CITMOV Like '%$s%')$num)"; }
    if (isset($_GET['codcue']) && (int) $_GET['codcue'] > 0) $w[] = "M.CODCUE=" . (int) $_GET['codcue'];
    $de = nd_iso(isset($_GET['desde']) ? $_GET['desde'] : null); if ($de !== null) $w[] = "M.FEXMOV>=$de";
    $ha = nd_iso(isset($_GET['hasta']) ? $_GET['hasta'] : null); if ($ha !== null) $w[] = "M.FEXMOV<=$ha";
    $where = implode(' AND ', $w);
    $out = array();
    foreach (db_query("SELECT TOP 200 NUMMOV, CINMOV, FEXMOV, CODCUE, DENMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, TOTMOV, ANUMOV FROM [Tbl Movimientos] AS M WHERE $where ORDER BY FEXMOV DESC, NUMMOV DESC;") as $r) {
        $out[] = array('NUMMOV' => (int) $r['NUMMOV'], 'NUMERO' => str_pad((string) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT), 'FECHA' => fecha_serial($r['FEXMOV']),
            'PROVEEDOR' => trim((string) nz($r['DENMOV'], '')),
            'COMP' => trim(trim((string) nz($r['CECMOV'], '')) . ' ' . trim((string) nz($r['CEIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CEPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CENMOV'], 0), 8, '0', STR_PAD_LEFT)),
            'TOTAL' => round((float) nz($r['TOTMOV'], 0), 2), 'ANULADO' => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1));
    }
    ok($out);
}

/** Detalle de una ND para cargarla en el form en sólo-lectura: cabecera, IVA, imputación (Haber) y referencias. */
function nda_detalle() {
    if (!function_exists('anular_es_anulable')) require_once __DIR__ . '/../../includes/comprobante_anular.php';
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT CINMOV, CIPMOV, FEXMOV, FIXMOV, CODCUE, DENMOV, CITMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV, DETMOV, NETMOV, IRIMOV, NOGMOV, IP1MOV, IP2MOV, AP1MOV, AP2MOV, TOTMOV, ESTMOV, ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=330;");
    if (!$h) { fail('Nota de débito no encontrada'); return; }
    $cc = (int) $h['CODCUE'];
    $pv = db_row("SELECT SANCUE, SOPCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$cc AND CODORI='A';");
    $estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
    $r = array('NUMMOV' => $num, 'NUMERO' => str_pad((string) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT), 'CIPMOV' => str_pad((string) (int) nz($h['CIPMOV'], 0), 4, '0', STR_PAD_LEFT),
        'FEXISO' => nda_fiso($h['FEXMOV']), 'CODCUE' => $cc, 'PROVEEDOR' => trim((string) nz($h['DENMOV'], '')), 'INFO' => trim((string) nz($h['CITMOV'], '')),
        'SANCUE' => $pv ? round((float) nz($pv['SANCUE'], 0), 2) : 0, 'SOPCUE' => $pv ? round((float) nz($pv['SOPCUE'], 0), 2) : 0,
        'CEC' => trim((string) nz($h['CECMOV'], 'FC')), 'CEI' => trim((string) nz($h['CEIMOV'], 'A')), 'CEP' => (int) nz($h['CEPMOV'], 0), 'CEN' => (int) nz($h['CENMOV'], 0),
        'CEFISO' => nda_fiso($h['CEFMOV']), 'FIXISO' => nda_fiso($h['FIXMOV']), 'DETMOV' => trim((string) nz($h['DETMOV'], '')), 'NOGRAV' => round((float) nz($h['NOGMOV'], 0), 2),
        'AP1' => round((float) nz($h['AP1MOV'], 0), 2), 'IP1' => round((float) nz($h['IP1MOV'], 0), 2), 'AP2' => round((float) nz($h['AP2MOV'], 0), 2), 'IP2' => round((float) nz($h['IP2MOV'], 0), 2),
        'TOTAL' => round((float) nz($h['TOTMOV'], 0), 2), 'ANULADO' => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1), 'ANULABLE' => anular_es_anulable($estTrue, ''),
        'NET1' => 0, 'ALI1' => 0, 'IRI1' => 0, 'NET2' => 0, 'ALI2' => 0, 'IRI2' => 0, 'imputacion' => array(), 'referencias' => array());
    $ivl = array();
    foreach (db_query("SELECT NETMOV, ALIMOV, IRIMOV FROM [Tbl Movimientos IVA] WHERE NUMMOV=$num ORDER BY DECMOV;") as $iv)
        $ivl[] = array('net' => round((float) nz($iv['NETMOV'], 0), 2), 'ali' => round((float) nz($iv['ALIMOV'], 0), 2), 'iva' => round((float) nz($iv['IRIMOV'], 0), 2));
    if (count($ivl) >= 1) { $r['NET1'] = $ivl[0]['net']; $r['ALI1'] = $ivl[0]['ali']; $r['IRI1'] = $ivl[0]['iva']; }
    else { $r['NET1'] = round((float) nz($h['NETMOV'], 0), 2); $r['IRI1'] = round((float) nz($h['IRIMOV'], 0), 2); }
    if (count($ivl) >= 2) { $r['NET2'] = $ivl[1]['net']; $r['ALI2'] = $ivl[1]['ali']; $r['IRI2'] = $ivl[1]['iva']; }
    foreach (db_query("SELECT CODCUE, CREMOV, CODCDC FROM [Tbl Movimientos Imputaciones] WHERE NUMMOV=$num AND CREMOV>0 ORDER BY ORDMOV;") as $i) {
        $cu = trim((string) nz($i['CODCUE'], '')); $den = db_row("SELECT DENCUE FROM [Tbl Cuentas Contables] WHERE CODCUE='" . db_esc($cu) . "';");
        $r['imputacion'][] = array('codcue' => $cu, 'label' => $cu . ' · ' . trim((string) nz($den ? $den['DENCUE'] : '', '')), 'codcdc' => trim((string) nz($i['CODCDC'], '')), 'importe' => round((float) nz($i['CREMOV'], 0), 2));
    }
    foreach (db_query("SELECT R.REFMOV, R.FVXMOV, R.IMPMOV, M.CICMOV, M.CINMOV, M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV FROM [Tbl Movimientos Referencias] AS R LEFT JOIN [Tbl Movimientos] AS M ON M.NUMMOV=R.REFMOV WHERE R.NUMMOV=$num;") as $rr) {
        $r['referencias'][] = array('refmov' => (int) $rr['REFMOV'], 'imp' => round((float) nz($rr['IMPMOV'], 0), 2), 'fvxiso' => nda_fiso($rr['FVXMOV']), 'fvx' => fecha_serial($rr['FVXMOV']),
            'comp' => trim((string) nz($rr['CICMOV'], '')) . ' ' . str_pad((string) nz($rr['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
            'compext' => trim(trim((string) nz($rr['CECMOV'], '')) . ' ' . trim((string) nz($rr['CEIMOV'], '')) . ' ' . str_pad((string) (int) nz($rr['CEPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($rr['CENMOV'], 0), 8, '0', STR_PAD_LEFT)));
    }
    ok($r);
}

/** Anula una ND (admin · sin CAE → anulable · transacción): revierte cta cte + referencias + asiento. Patrón OP. */
function nda_anular() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $num = isset($_POST['nummov']) ? (int) $_POST['nummov'] : 0;
    $h = db_row("SELECT TOTMOV, CODCUE, ESTMOV, ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=330;");
    if (!$h) { fail('Nota de débito no encontrada'); return; }
    if ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) { fail('La nota de débito ya está anulada'); return; }
    if (!auth_is_admin()) { fail('Sólo un administrador puede anular', 403); return; }
    $estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
    $lib = auth_libro_unico();
    if (($lib === 'blanco' && !$estTrue) || ($lib === 'capacitacion' && $estTrue)) { fail('Nota de débito no disponible en este libro'); return; }
    db_begin();
    try {
        $total = round((float) nz($h['TOTMOV'], 0), 2); $codcue = (int) $h['CODCUE'];
        // 1) Cuenta corriente: revertir (la ND debitó → SOPCUE += total; al anular -= total)
        db_exec("UPDATE [Tbl Cuentas Corrientes] SET SOPCUE = SOPCUE - $total WHERE CODCUE=$codcue;");
        // 2) Referencias: descomprometer el vencimiento del comprobante referenciado + su SDOMOV + borrar
        foreach (db_query("SELECT REFMOV, FVXMOV, IMPMOV FROM [Tbl Movimientos Referencias] WHERE NUMMOV=$num;") as $r) {
            $ref = (int) $r['REFMOV']; $imp = round((float) nz($r['IMPMOV'], 0), 2); $fvx = (int) $r['FVXMOV'];
            $v = db_row("SELECT DEBMOV FROM [Tbl Movimientos Vencimientos] WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
            $nuevo = round((float) nz($v ? $v['DEBMOV'] : 0, 0) - $imp, 2);
            db_exec("UPDATE [Tbl Movimientos Vencimientos] SET DEBMOV=" . ($nuevo == 0 ? 'Null' : $nuevo) . " WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
            $f = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV=$ref;");
            db_exec("UPDATE [Tbl Movimientos] SET SDOMOV=" . round((float) nz($f ? $f['SDOMOV'] : 0, 0) - $imp, 2) . " WHERE NUMMOV=$ref;");
        }
        db_exec("DELETE FROM [Tbl Movimientos Referencias] WHERE NUMMOV=$num;");
        // 3) Asiento: revertir saldos contables cacheados + zerar
        foreach (db_query("SELECT CODCUE, DEBMOV, CREMOV FROM [Tbl Movimientos Imputaciones] WHERE NUMMOV=$num;") as $i) {
            $cc = db_esc((string) $i['CODCUE']);
            if ($i['DEBMOV'] !== null) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE - " . round((float) $i['DEBMOV'], 2) . " WHERE CODCUE='$cc';");
            if ($i['CREMOV'] !== null) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE - " . round((float) $i['CREMOV'], 2) . " WHERE CODCUE='$cc';");
        }
        db_exec("UPDATE [Tbl Movimientos Imputaciones] SET DEBMOV=0, CREMOV=0 WHERE NUMMOV=$num;");
        // 4) Header anulado
        $det = db_row("SELECT DETMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num;");
        db_exec("UPDATE [Tbl Movimientos] SET DETMOV='" . db_esc(trim('[ANULADO] ' . trim((string) nz($det['DETMOV'], '')))) . "', DEBMOV=0, TOTMOV=0, SDOMOV=0, ANUMOV=True WHERE NUMMOV=$num;");
        db_commit();
        ok(array('anulado' => $num));
    } catch (Exception $e) { db_rollback(); fail('No se pudo anular la nota de débito: ' . $e->getMessage(), 400); }
}
