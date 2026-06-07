<?php
/**
 * Comprobantes a Pagar (acreedores) — registración de la factura del PROVEEDOR (NO se emite: no hay AFIP/CAE).
 * Porta `Frm CA Comprobantes a Pagar`. CODORI='A', CODOPE=310, CICMOV='CP'. El número del proveedor (FC/NC/ND
 * A 0004-00044136) va en los campos externos CEC/CEI/CEP/CEN/CEF; el nuestro es un contador interno (ULTCAP).
 *
 * Asiento (sentido compra): DEBE = la imputación que carga el usuario (cuenta del gasto/bien + IVA Crédito
 * Fiscal, suma = total) ; HABER = Proveedores (Rec Control CACC_K) por el total [+ Anticipo a Proveedores
 * CACC_L si se aplica saldo a favor]. CREMOV=TOTMOV ; SDOMOV = -(Σ vencimientos) (negativo = le debemos) ;
 * cuenta corriente SOPCUE -= TOTMOV (SANCUE -= anticipos).
 *
 * Alcance v1 (caso servicio/gasto): SIN productos/stock (CODAUX=312) ni remitos de proveedor — esos actualizan
 * existencias y costos (Tbl Productos / Productos Proveedores) y quedan como TODO. cp_insert($d,$estTrue): SIN
 * transacción (el caller envuelve).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

if (!defined('CP_LIB')) {
    require_once __DIR__ . '/../../includes/auth.php';
    auth_require_login();
    header('Content-Type: application/json');
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    try {
        switch ($action) {
            case 'buscar_proveedores': buscar_proveedores(); break;
            case 'get_proveedor':      get_proveedor(); break;
            case 'cuentas':            cuentas_imputables(); break;
            case 'centros_costo':      centros_costo(); break;
            case 'productos':          buscar_productos(); break;
            case 'guardar':            guardar(); break;
            case 'anular':             anular(); break;
            default: fail('Acción inválida: ' . $action);
        }
    } catch (Exception $e) { fail($e->getMessage(), 500); }
    exit;
}

function cp_iso($s) { if ($s === null || $s === '') return null; if (is_numeric($s)) return (int) $s; return (int) (new DateTime('1899-12-30'))->diff(new DateTime($s))->days; }
function cp_txt($v) { $v = trim((string) $v); return ($v === '') ? 'Null' : "'" . db_esc($v) . "'"; }
function cp_num($v) { return (string) round((float) $v, 2); }

function buscar_proveedores() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q); $num = is_numeric($q) ? ' OR CODCUE = ' . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

function get_proveedor() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $c = db_row("SELECT C.CODCUE, C.DENCUE, C.CITCUE, C.SOPCUE, C.SANCUE, C.DCXCUE, C.DNXCUE, C.CODLOC, L.DENLOC, P.DENPRO, C.CODCRI
        FROM ([Tbl Provincias] AS P RIGHT JOIN ([Tbl Localidades] AS L INNER JOIN [Tbl Cuentas Corrientes] AS C ON L.CODLOC=C.CODLOC) ON P.CODPRO=L.CODPRO)
        WHERE C.CODORI='A' AND C.CODCUE=$cc;");
    if (!$c) { fail('Proveedor no encontrado'); return; }
    $cri = db_row("SELECT DENCRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($c['CODCRI'], 0) . ";");
    $c['DENCRI'] = $cri ? trim((string) nz($cri['DENCRI'], '')) : '';
    $c['SALDO'] = round((float) nz($c['SOPCUE'], 0), 2);   // negativo = le debemos
    $c['SALDO_ANTIC'] = round((float) nz($c['SANCUE'], 0), 2);
    $c['DOMICILIO'] = trim(nz($c['DCXCUE'], '') . ' ' . nz($c['DNXCUE'], ''));
    $c['LOCALIDAD'] = trim(nz($c['DENLOC'], '') . (nz($c['DENPRO'], '') ? ' - ' . nz($c['DENPRO'], '') : ''));
    ok($c);
}

/** Cuentas contables imputables (hoja, IMPCUE=true) para la grilla de imputación del DEBE. */
function cuentas_imputables() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $w = ($q !== '') ? " AND (DENCUE Like '%" . db_esc($q) . "%' OR CODCUE Like '" . db_esc($q) . "%')" : '';
    ok(db_query("SELECT TOP 30 CODCUE, DENCUE FROM [Tbl Cuentas Contables] WHERE IMPCUE=True$w ORDER BY CODCUE;"));
}

function centros_costo() { ok(db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo] ORDER BY CODCDC;")); }

/** Productos (para la grilla de stock del CP con productos): código, denominación, costo y unidad/moneda. */
function buscar_productos() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    ok(db_query("SELECT TOP 20 CODPRO, DENPRO, COSPRO, CODUDM, CODMON FROM [Tbl Productos] WHERE DENPRO Is Not Null AND ((DENPRO Like '%$s%') OR (CODPRO Like '$s%')) ORDER BY DENPRO;"));
}

/** Inserta una fila de imputación (DEBE o HABER) + mayoriza DEBCUE/CRECUE. */
function cp_imp(&$ord, &$totDeb, &$totCre, $nummov, $cuenta, $deb, $cre, $codcdc = 1, $ali = null, $iva = null, $tot = null) {
    $deb = round((float) $deb, 2); $cre = round((float) $cre, 2);
    $ord++;
    $cc = db_esc((string) $cuenta);
    $bal = db_row("SELECT DEBCUE, CRECUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
    $soc = $bal ? round((float) nz($bal['DEBCUE'], 0) - (float) nz($bal['CRECUE'], 0), 2) : 0;
    $debSql = ($deb != 0) ? (string) $deb : 'Null';
    $creSql = ($cre != 0) ? (string) $cre : 'Null';
    $aliSql = ($ali === null) ? 'Null' : (string) round((float) $ali, 2);
    $ivaSql = ($iva === null) ? 'Null' : (string) round((float) $iva, 2);
    $totSql = ($tot === null) ? 'Null' : (string) round((float) $tot, 2);
    db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, DEBMOV, CREMOV, CODCDC, SOCMOV, ALIMOV, IVAMOV, TOTMOV)
        VALUES ($nummov, $ord, '$cc', $debSql, $creSql, " . (int) $codcdc . ", $soc, $aliSql, $ivaSql, $totSql);");
    if ($deb != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE + $deb WHERE CODCUE='$cc';");
    if ($cre != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE + $cre WHERE CODCUE='$cc';");
    $totDeb += $deb; $totCre += $cre;
}

function cp_insert($d, $estTrue) {
    $rc = db_row("SELECT CACC_K, CACC_L, CACC_Z FROM [Rec Control];");
    $caccK = trim((string) $rc['CACC_K']);   // Proveedores (HABER)
    $caccL = trim((string) $rc['CACC_L']);   // Anticipo a Proveedores
    $caccZ = trim((string) $rc['CACC_Z']);   // balanceo / redondeo

    $codcue = (int) $d['codcue'];
    $prov = db_row("SELECT DENCUE, DCXCUE, DNXCUE, DDXCUE, DPXCUE, CODLOC, SOPCUE, SANCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='A';");
    if (!$prov) throw new Exception('Proveedor inexistente');

    $fex = cp_iso($d['fexmov']);
    if ($fex === null) throw new Exception('Falta la fecha de emisión');
    $fix = isset($d['fixmov']) && $d['fixmov'] !== '' ? cp_iso($d['fixmov']) : $fex;
    $estSql = $estTrue ? 'True' : 'False';

    // Comprobante del proveedor (externo): tipo/letra/pdv/nro/fecha.
    $cec = strtoupper(trim((string) nz($d['cec'], 'FC')));        // FC / NC / ND
    $cei = strtoupper(trim((string) nz($d['cei'], 'A')));
    $cep = (int) nz($d['cep'], 0);
    $cen = (int) nz($d['cen'], 0);
    $cef = isset($d['cef']) && $d['cef'] !== '' ? cp_iso($d['cef']) : $fex;
    $codcat = (int) nz($d['codcat'], 2);                          // 1=con productos (CODAUX=311) / 2=sin (312)
    $codaux = ($codcat == 1) ? 311 : 312;
    $productos = isset($d['productos']) && is_array($d['productos']) ? $d['productos'] : array();

    // Importes
    $ivas = isset($d['ivas']) && is_array($d['ivas']) ? array_values($d['ivas']) : array();
    $netmov = 0; $irimov = 0;
    foreach ($ivas as $iv) { $netmov += (float) nz($iv['net'], 0); $irimov += (float) nz($iv['iva'], 0); }
    $netmov = round($netmov, 2); $irimov = round($irimov, 2);
    $nogmov = isset($d['nogmov']) ? round((float) nz($d['nogmov'], 0), 2) : 0;
    $ip1 = isset($d['ip1mov']) ? round((float) nz($d['ip1mov'], 0), 2) : 0;
    $ip2 = isset($d['ip2mov']) ? round((float) nz($d['ip2mov'], 0), 2) : 0;
    $total = round((float) nz($d['total'], $netmov + $irimov + $nogmov + $ip1 + $ip2), 2);
    $cotmov = round((float) nz(isset($d['cotmov']) ? $d['cotmov'] : 1, 1), 4);

    // Imputación (DEBE) y vencimientos (HABER) — validaciones del legacy.
    $imps = isset($d['imputaciones']) && is_array($d['imputaciones']) ? $d['imputaciones'] : array();
    if (!count($imps)) throw new Exception('Cargá la imputación contable (al menos una cuenta en el Debe).');
    $sumImp = 0; foreach ($imps as $i) $sumImp += round((float) nz($i['debmov'], 0), 2);
    if (abs(round($sumImp, 2) - $total) >= 0.01) throw new Exception('La imputación (' . number_format($sumImp, 2) . ') no coincide con el total (' . number_format($total, 2) . ').');

    $vtos = isset($d['vencimientos']) && is_array($d['vencimientos']) ? $d['vencimientos'] : array();
    $antics = isset($d['anticipos']) && is_array($d['anticipos']) ? $d['anticipos'] : array();
    $sumAnt = 0; foreach ($antics as $a) $sumAnt += round((float) nz($a['imptmov'], 0), 2);
    $sumAnt = round($sumAnt, 2);
    $sumVto = 0; foreach ($vtos as $v) $sumVto += round((float) nz($v['cremov'], 0), 2);
    $sumVto = round($sumVto, 2);
    if (abs(round($sumVto + $sumAnt, 2) - $total) >= 0.01) throw new Exception('Los vencimientos + anticipos (' . number_format($sumVto + $sumAnt, 2) . ') no cubren el total (' . number_format($total, 2) . ').');

    // Numeración interna (ULTCAP): contador en CODPDV 1 (blanco) / 9999 (capacitación). CIPMOV=0 (sin PDV fiscal).
    $nummov = next_number('ULTMOV');
    $cinmov = next_number_pdv('ULTCAP', $estTrue ? 1 : 9999);
    $sdomov = round(-$sumVto, 2);                                 // negativo = le debemos

    // ── Header ──
    $den = cp_txt(nz($prov['DENCUE'], '')); $cit = cp_txt(nz($d['citmov'], ''));
    $dcx = cp_txt(nz($prov['DCXCUE'], '')); $dnx = cp_txt(nz($prov['DNXCUE'], ''));
    $codloc = (int) nz($prov['CODLOC'], 0); $codcri = (int) nz($d['codcri'], 0);
    $det = cp_txt(isset($d['detmov']) ? $d['detmov'] : '');
    $soc = round((float) nz($prov['SOPCUE'], 0), 2); $sac = round((float) nz($prov['SANCUE'], 0), 2);

    db_exec("INSERT INTO [Tbl Movimientos]
        (NUMMOV, CODORI, FEXMOV, FIXMOV, CODOPE, CODAUX, CICMOV, CIPMOV, CINMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV,
         CODCUE, SOCMOV, SACMOV, DENMOV, DCXMOV, DNXMOV, CODLOC, CODCRI, CITMOV, DETMOV, COTMOV, NETMOV, IRIMOV, NOGMOV, IP1MOV, IP2MOV,
         CREMOV, TOTMOV, SDOMOV, ESTMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($nummov, 'A', $fex, $fix, 310, $codaux, 'CP', 0, $cinmov, '$cec', '$cei', $cep, $cen, $cef,
         $codcue, " . cp_num($soc) . ", " . cp_num($sac) . ", $den, $dcx, $dnx, $codloc, $codcri, $cit, $det, $cotmov, " . ($netmov != 0 ? cp_num($netmov) : 'Null') . ", " . ($irimov != 0 ? cp_num($irimov) : 'Null') . ", " . ($nogmov != 0 ? cp_num($nogmov) : 'Null') . ", " . ($ip1 != 0 ? cp_num($ip1) : 'Null') . ", " . ($ip2 != 0 ? cp_num($ip2) : 'Null') . ",
         " . cp_num($total) . ", " . cp_num($total) . ", " . cp_num($sdomov) . ", $estSql, 0, 0, Now());");

    // ── IVA (hasta 2 alícuotas) ──
    $dec = false;
    foreach ($ivas as $iv) {
        $net = round((float) nz($iv['net'], 0), 2); if ($net == 0 && (float) nz($iv['iva'], 0) == 0) continue;
        $ali = round((float) nz($iv['ali'], 0), 2);
        db_exec("INSERT INTO [Tbl Movimientos IVA] (NUMMOV, NETMOV, ALIMOV, IRIMOV, DECMOV)
            VALUES ($nummov, " . cp_num($net) . ", $ali, " . cp_num((float) nz($iv['iva'], 0)) . ", " . ($dec ? 'True' : 'False') . ");");
        $dec = true;
    }

    // ── Productos (CODAUX=311): entra mercadería a stock (EXISTK) + actualiza el costo del producto (v2 pesos) ──
    if ($codcat == 1) {
        $ordP = 0;
        foreach ($productos as $p) {
            $codpro = trim((string) nz($p['codpro'], ''));
            if ($codpro === '') continue;
            $ordP++;
            $ing = round((float) nz($p['ingmov'], 0), 4);
            $pun = round((float) nz($p['punmov'], 0), 4);
            $bon = round((float) nz(isset($p['bonmov']) ? $p['bonmov'] : 0, 0), 2);
            $fct = round((float) nz(isset($p['fctmov']) ? $p['fctmov'] : 1, 1), 4); if ($fct == 0) $fct = 1;
            $codmon = trim((string) nz(isset($p['codmon']) ? $p['codmon'] : 'P', 'P'));
            $codudm = (int) nz(isset($p['codudm']) ? $p['codudm'] : 1, 1);
            $dummov = (int) nz(isset($p['dummov']) ? $p['dummov'] : 0, 0);
            $stk = (isset($p['stkmov']) && ($p['stkmov'] === true || $p['stkmov'] == 1));
            $cpe = db_esc($codpro);
            $ingSql = $stk ? cp_num($ing) : 'Null';
            $svcSql = $stk ? 'Null' : cp_num($ing);
            db_exec("INSERT INTO [Tbl Movimientos Stock]
                (NUMMOV, ORDMOV, CODPRO, CODSUC, DENMOV, CODMON, PUNMOV, COSMOV, BONMOV, INGMOV, SVCMOV, STKMOV, FCTMOV, DUMMOV, CODUDM, DECMOV)
                VALUES ($nummov, $ordP, '$cpe', 1, " . cp_txt(nz($p['denmov'], '')) . ", '" . db_esc($codmon) . "', " . cp_num($pun) . ", " . cp_num($pun) . ", " . cp_num($bon) . ", $ingSql, $svcSql, " . ($stk ? 'True' : 'False') . ", $fct, $dummov, $codudm, False);");
            if ($stk) db_exec("UPDATE [Tbl Stock] SET EXISTK = EXISTK + " . round($ing * $fct, 4) . " WHERE CODSUC=1 AND CODPRO='$cpe';");
            // Costo del producto: COSPRO = costo neto unitario (unidad base), FUCPRO = fecha del comprobante.
            db_exec("UPDATE [Tbl Productos] SET FUCPRO=$cef, COSPRO=" . round($pun * (1 - $bon / 100) / $fct, 4) . ", PLCPRO=" . round($pun / $fct, 4) . " WHERE CODPRO='$cpe';");
        }
    }

    // ── Vencimientos (lo que pagaremos: CREMOV) ──
    foreach ($vtos as $v) {
        $fvx = cp_iso(isset($v['fvxmov']) ? $v['fvxmov'] : null); if ($fvx === null) $fvx = $fex;
        $cre = round((float) nz($v['cremov'], 0), 2); if ($cre == 0) continue;
        db_exec("INSERT INTO [Tbl Movimientos Vencimientos] (NUMMOV, FVXMOV, DETMOV, CREMOV)
            VALUES ($nummov, $fvx, " . cp_txt(isset($v['detmov']) ? $v['detmov'] : '') . ", " . cp_num($cre) . ");");
    }

    // ── Anticipos (saldo a favor del proveedor aplicado): Tbl Movimientos Anticipos + reducir el SDOMOV del origen ──
    foreach ($antics as $a) {
        $ant = (int) nz($a['anttmov'], 0); $imp = round((float) nz($a['imptmov'], 0), 2);
        if ($ant <= 0 || $imp == 0) continue;
        db_exec("INSERT INTO [Tbl Movimientos Anticipos] (NUMMOV, ANTMOV, IMPMOV) VALUES ($nummov, $ant, " . cp_num($imp) . ");");
        $s = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV=$ant;");
        db_exec("UPDATE [Tbl Movimientos] SET SDOMOV=" . cp_num(round((float) nz($s ? $s['SDOMOV'] : 0, 0) - $imp, 2)) . " WHERE NUMMOV=$ant;");
    }

    // ── Asiento: DEBE imputación del usuario / HABER (Anticipo + Proveedores) + balanceo ──
    $ord = 0; $totDeb = 0; $totCre = 0;
    foreach ($imps as $i) {
        $deb = round((float) nz($i['debmov'], 0), 2); if ($deb == 0) continue;
        cp_imp($ord, $totDeb, $totCre, $nummov, trim((string) $i['codcue']), $deb, 0, (int) nz(isset($i['codcdc']) ? $i['codcdc'] : 1, 1),
            isset($i['alimov']) ? $i['alimov'] : null, isset($i['ivamov']) ? $i['ivamov'] : null, isset($i['totmov']) ? $i['totmov'] : null);
    }
    if ($sumAnt > 0) cp_imp($ord, $totDeb, $totCre, $nummov, $caccL, 0, $sumAnt);          // HABER Anticipo a Proveedores
    $haberProv = round($total - $sumAnt, 2);
    if ($haberProv > 0) cp_imp($ord, $totDeb, $totCre, $nummov, $caccK, 0, $haberProv);    // HABER Proveedores
    $dif = round($totDeb - $totCre, 2);
    if (abs($dif) >= 0.005) { if ($dif > 0) cp_imp($ord, $totDeb, $totCre, $nummov, $caccZ, 0, $dif); else cp_imp($ord, $totDeb, $totCre, $nummov, $caccZ, -$dif, 0); }

    // ── Cuenta corriente: la compra aumenta la deuda → SOPCUE -= total (SANCUE -= anticipos) ──
    db_exec("UPDATE [Tbl Cuentas Corrientes] SET FUOCUE=$fex, SOPCUE=" . cp_num(round($soc - $total, 2)) . ($sumAnt > 0 ? ", SANCUE=" . cp_num(round($sac - $sumAnt, 2)) : '') . " WHERE CODCUE=$codcue;");

    return array('nummov' => $nummov, 'cinmov' => $cinmov, 'total' => $total, 'sdomov' => $sdomov, 'balanceo' => round($totDeb - $totCre, 2));
}

function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? json_decode($_POST['data'], true) : null;
    if (!is_array($raw)) { fail('Datos inválidos'); return; }
    $estTrue = (auth_modo() !== 'capacitacion');
    db_begin();
    try {
        $res = cp_insert($raw, $estTrue);
        db_commit();
        $res['anulable'] = true;   // CP no es electrónico → siempre anulable por admin (se agregará el botón)
        ok($res);
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar el comprobante a pagar: ' . $e->getMessage(), 500);
    }
}

/** Anula un CP (admin · no tiene CAE → siempre anulable · transacción · revierte asiento/cta cte/vencimientos). */
function anular() {
    require_once __DIR__ . '/../../includes/comprobante_anular.php';
    $num = isset($_POST['nummov']) ? (int) $_POST['nummov'] : 0;
    try {
        anular_check($num, 310, 'Comprobante a pagar');
        db_begin();
        try { anular_comprobante($num, 310); db_commit(); }
        catch (Exception $e) { db_rollback(); throw $e; }
        ok(array('anulado' => $num));
    } catch (Exception $e) {
        fail('No se pudo anular el comprobante: ' . $e->getMessage(), 400);
    }
}
