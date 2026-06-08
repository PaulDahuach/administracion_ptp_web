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
            case 'producto_por_ext':   producto_por_ext(); break;
            case 'remitos_pendientes': remitos_pendientes(); break;
            case 'anticipos_pendientes': anticipos_pendientes(); break;
            case 'guardar':            guardar(); break;
            case 'anular':             anular(); break;
            case 'listar':             listar(); break;
            case 'detalle':            cp_detalle(); break;
            default: fail('Acción inválida: ' . $action);
        }
    } catch (Exception $e) { fail($e->getMessage(), 500); }
    exit;
}

function cp_iso($s) { if ($s === null || $s === '') return null; if (is_numeric($s)) return (int) $s; return (int) (new DateTime('1899-12-30'))->diff(new DateTime($s))->days; }
function cp_txt($v) { $v = trim((string) $v); return ($v === '') ? 'Null' : "'" . db_esc($v) . "'"; }
function cp_num($v) { return (string) round((float) $v, 2); }
/** CODOPE del comprobante (default 310=Comprobante a Pagar; override por $GLOBALS['CP_CODOPE'], ej 320=NC Acreedora). */
function cp_codope() { return isset($GLOBALS['CP_CODOPE']) ? (int) $GLOBALS['CP_CODOPE'] : 310; }

/** Buscar Comprobantes a Pagar emitidos (CODOPE=310) por proveedor / nº / texto / fecha — filtrado por el libro del modo. */
function listar() {
    $w = array("M.CODORI='A'", "M.CODOPE=" . cp_codope());
    $unico = auth_libro_unico();
    if ($unico === 'blanco') $w[] = "M.ESTMOV=True";
    elseif ($unico === 'capacitacion') $w[] = "M.ESTMOV=False";
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if ($q !== '') {
        $s = db_esc($q);
        $num = is_numeric($q) ? (" OR M.CINMOV=" . (int) $q . " OR M.CENMOV=" . (int) $q . " OR M.NUMMOV=" . (int) $q) : '';
        $w[] = "((M.DENMOV Like '%$s%') OR (M.CITMOV Like '%$s%')$num)";
    }
    if (isset($_GET['codcue']) && (int) $_GET['codcue'] > 0) $w[] = "M.CODCUE=" . (int) $_GET['codcue'];
    $de = cp_iso(isset($_GET['desde']) ? $_GET['desde'] : null); if ($de !== null) $w[] = "M.FEXMOV>=$de";
    $ha = cp_iso(isset($_GET['hasta']) ? $_GET['hasta'] : null); if ($ha !== null) $w[] = "M.FEXMOV<=$ha";
    $where = implode(' AND ', $w);
    $out = array();
    foreach (db_query("SELECT TOP 200 NUMMOV, CINMOV, FEXMOV, CODCUE, DENMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, TOTMOV, ANUMOV FROM [Tbl Movimientos] AS M WHERE $where ORDER BY FEXMOV DESC, NUMMOV DESC;") as $r) {
        $out[] = array(
            'NUMMOV' => (int) $r['NUMMOV'],
            'NUMERO' => str_pad((string) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
            'FECHA' => fecha_serial($r['FEXMOV']),
            'PROVEEDOR' => trim((string) nz($r['DENMOV'], '')),
            'COMP' => trim(trim((string) nz($r['CECMOV'], '')) . ' ' . trim((string) nz($r['CEIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CEPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CENMOV'], 0), 8, '0', STR_PAD_LEFT)),
            'TOTAL' => round((float) nz($r['TOTMOV'], 0), 2),
            'ANULADO' => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1),
        );
    }
    ok($out);
}

/** Serial Access → 'yyyy-mm-dd' (para los <input type=date>), reusando fecha_serial (dd/mm/yyyy). */
function cp_fiso($s) { $f = fecha_serial($s); if (!$f || strpos($f, '/') === false) return ''; $p = explode('/', $f); return $p[2] . '-' . $p[1] . '-' . $p[0]; }

/** Detalle de un CP para CARGARLO en el form en modo sólo-lectura: cabecera, importes y las 5 grillas (formato cp.js). */
function cp_detalle() {
    if (!function_exists('anular_es_anulable')) require_once __DIR__ . '/../../includes/comprobante_anular.php';
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT CINMOV, CIPMOV, FEXMOV, FIXMOV, CODCUE, DENMOV, CITMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV, CODAUX, DETMOV, COTMOV, NETMOV, IRIMOV, NOGMOV, IP1MOV, IP2MOV, AP1MOV, AP2MOV, TOTMOV, ESTMOV, CAEMOV, ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=" . cp_codope() . ";");
    if (!$h) { fail('Comprobante no encontrado'); return; }
    $cc = (int) $h['CODCUE'];
    $pv = db_row("SELECT CODCAT, SANCUE, SOPCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$cc AND CODORI='A';");
    $conprod = ((int) nz($h['CODAUX'], 312) === 311);
    $estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
    $r = array(
        'NUMMOV' => $num,
        'NUMERO' => str_pad((string) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        'CIPMOV' => str_pad((string) (int) nz($h['CIPMOV'], 0), 4, '0', STR_PAD_LEFT),
        'FEXISO' => cp_fiso($h['FEXMOV']), 'CODCUE' => $cc,
        'PROVEEDOR' => trim((string) nz($h['DENMOV'], '')),
        'INFO' => trim((string) nz($h['CITMOV'], '')),
        'SANCUE' => $pv ? round((float) nz($pv['SANCUE'], 0), 2) : 0,
        'SOPCUE' => $pv ? round((float) nz($pv['SOPCUE'], 0), 2) : 0,
        'COTMOV' => round((float) nz($h['COTMOV'], 1), 4),
        'CEC' => trim((string) nz($h['CECMOV'], 'FC')), 'CEI' => trim((string) nz($h['CEIMOV'], 'A')),
        'CEP' => (int) nz($h['CEPMOV'], 0), 'CEN' => (int) nz($h['CENMOV'], 0), 'CEFISO' => cp_fiso($h['CEFMOV']),
        'FIXISO' => cp_fiso($h['FIXMOV']),
        'CODCAT' => $conprod ? 1 : ($pv ? (int) nz($pv['CODCAT'], 2) : 2), 'CONPROD' => $conprod,
        'DETMOV' => trim((string) nz($h['DETMOV'], '')),
        'NOGRAV' => round((float) nz($h['NOGMOV'], 0), 2),
        'AP1' => round((float) nz($h['AP1MOV'], 0), 2), 'IP1' => round((float) nz($h['IP1MOV'], 0), 2),
        'AP2' => round((float) nz($h['AP2MOV'], 0), 2), 'IP2' => round((float) nz($h['IP2MOV'], 0), 2),
        'TOTAL' => round((float) nz($h['TOTMOV'], 0), 2),
        'ANULADO' => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1),
        'ANULABLE' => anular_es_anulable($estTrue, nz($h['CAEMOV'], '')),
        'NET1' => 0, 'ALI1' => 0, 'IRI1' => 0, 'NET2' => 0, 'ALI2' => 0, 'IRI2' => 0,
        'imputacion' => array(), 'vencimientos' => array(), 'productos' => array(), 'anticipos' => array(), 'remitos' => array(),
    );
    // Importes por alícuota (Tbl Movimientos IVA → 1ª / 2ª fila). Sin líneas IVA → todo el neto en la 1ª.
    $ivl = array();
    foreach (db_query("SELECT NETMOV, ALIMOV, IRIMOV FROM [Tbl Movimientos IVA] WHERE NUMMOV=$num ORDER BY DECMOV;") as $iv)
        $ivl[] = array('net' => round((float) nz($iv['NETMOV'], 0), 2), 'ali' => round((float) nz($iv['ALIMOV'], 0), 2), 'iva' => round((float) nz($iv['IRIMOV'], 0), 2));
    if (count($ivl) >= 1) { $r['NET1'] = $ivl[0]['net']; $r['ALI1'] = $ivl[0]['ali']; $r['IRI1'] = $ivl[0]['iva']; }
    else { $r['NET1'] = round((float) nz($h['NETMOV'], 0), 2); $r['IRI1'] = round((float) nz($h['IRIMOV'], 0), 2); }
    if (count($ivl) >= 2) { $r['NET2'] = $ivl[1]['net']; $r['ALI2'] = $ivl[1]['ali']; $r['IRI2'] = $ivl[1]['iva']; }
    // Imputación = filas del DEBE (lo que cargó el usuario; el HABER Proveedores es automático).
    foreach (db_query("SELECT CODCUE, DEBMOV, CODCDC FROM [Tbl Movimientos Imputaciones] WHERE NUMMOV=$num AND DEBMOV>0 ORDER BY ORDMOV;") as $i) {
        $cu = trim((string) nz($i['CODCUE'], '')); $den = db_row("SELECT DENCUE FROM [Tbl Cuentas Contables] WHERE CODCUE='" . db_esc($cu) . "';");
        $r['imputacion'][] = array('codcue' => $cu, 'label' => $cu . ' · ' . trim((string) nz($den ? $den['DENCUE'] : '', '')), 'codcdc' => trim((string) nz($i['CODCDC'], '')), 'debmov' => round((float) nz($i['DEBMOV'], 0), 2));
    }
    foreach (db_query("SELECT FVXMOV, CREMOV FROM [Tbl Movimientos Vencimientos] WHERE NUMMOV=$num;") as $v)
        $r['vencimientos'][] = array('fvxiso' => cp_fiso($v['FVXMOV']), 'cremov' => round((float) nz($v['CREMOV'], 0), 2));
    foreach (db_query("SELECT ANTMOV, IMPMOV FROM [Tbl Movimientos Anticipos] WHERE NUMMOV=$num;") as $a) {
        $o = db_row("SELECT CICMOV, CINMOV FROM [Tbl Movimientos] WHERE NUMMOV=" . (int) $a['ANTMOV'] . ";");
        $r['anticipos'][] = array('comp' => $o ? (trim((string) nz($o['CICMOV'], '')) . ' ' . str_pad((string) nz($o['CINMOV'], 0), 8, '0', STR_PAD_LEFT)) : (string) $a['ANTMOV'], 'importe' => round((float) nz($a['IMPMOV'], 0), 2));
    }
    foreach (db_query("SELECT CODPRO, DENMOV, INGMOV, SVCMOV, PUNMOV, COSMOV, PULMOV, FLTMOV, BONMOV, EXTMOV, APVMOV, DECMOV, CODUDM, CODMON, FCTMOV, STKMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num ORDER BY ORDMOV;") as $p) {
        $mon = trim((string) nz($p['CODMON'], 'P')); $udm = (int) nz($p['CODUDM'], 1);
        $ud = db_row("SELECT DENUDM FROM [Tbl Unidades de Medida] WHERE CODUDM=$udm;");
        $r['productos'][] = array(
            'codpro' => trim((string) nz($p['CODPRO'], '')), 'denpro' => trim((string) nz($p['DENMOV'], '')), 'codmon' => $mon,
            'cant' => round((float) nz($p['INGMOV'], nz($p['SVCMOV'], 0)), 2),
            'cos' => round((float) ($mon === 'P' ? nz($p['PUNMOV'], 0) : nz($p['COSMOV'], 0)), 4),
            'lis' => round((float) nz($p['PULMOV'], 0), 4), 'flt' => round((float) nz($p['FLTMOV'], 0), 4),
            'bon' => round((float) nz($p['BONMOV'], 0), 2), 'fct' => round((float) nz($p['FCTMOV'], 1), 4),
            'ext' => trim((string) nz($p['EXTMOV'], '')), 'apv' => ($p['APVMOV'] === true || $p['APVMOV'] == -1),
            'dec' => ($p['DECMOV'] === true || $p['DECMOV'] == -1), 'codudm' => $udm, 'unidad' => $ud ? trim((string) nz($ud['DENUDM'], '')) : '',
            'stk' => ($p['STKMOV'] === true || $p['STKMOV'] == -1));
    }
    foreach (db_query("SELECT REMMOV FROM [Tbl Movimientos Remitos] WHERE NUMMOV=$num;") as $rm) {
        $o = db_row("SELECT CINMOV FROM [Tbl Movimientos] WHERE NUMMOV=" . (int) $rm['REMMOV'] . ";");
        $r['remitos'][] = $o ? str_pad((string) nz($o['CINMOV'], 0), 8, '0', STR_PAD_LEFT) : (string) $rm['REMMOV'];
    }
    ok($r);
}

function buscar_proveedores() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q); $num = is_numeric($q) ? ' OR CODCUE = ' . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

function get_proveedor() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $c = db_row("SELECT C.CODCUE, C.DENCUE, C.CITCUE, C.SOPCUE, C.SANCUE, C.DCXCUE, C.DNXCUE, C.CODLOC, L.DENLOC, P.DENPRO, C.CODCRI, C.CODCAT, C.APICUE, C.APBCUE
        FROM ([Tbl Provincias] AS P RIGHT JOIN ([Tbl Localidades] AS L INNER JOIN [Tbl Cuentas Corrientes] AS C ON L.CODLOC=C.CODLOC) ON P.CODPRO=L.CODPRO)
        WHERE C.CODORI='A' AND C.CODCUE=$cc;");
    if (!$c) { fail('Proveedor no encontrado'); return; }
    $cri = db_row("SELECT DENCRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($c['CODCRI'], 0) . ";");
    $c['DENCRI'] = $cri ? trim((string) nz($cri['DENCRI'], '')) : '';
    $c['SALDO'] = round((float) nz($c['SOPCUE'], 0), 2);   // negativo = le debemos
    $c['SALDO_ANTIC'] = round((float) nz($c['SANCUE'], 0), 2);
    $c['ES_RI'] = ((string) nz($c['CODCRI'], '') === '1');                      // Resp. Inscripto → discrimina IVA (letra A); si no, letra C sin discriminar
    $c['APLICA_PIVA'] = ($c['APICUE'] === true || $c['APICUE'] == -1);          // habilita percep. IVA
    $c['APLICA_PIIBB'] = ($c['APBCUE'] === true || $c['APBCUE'] == -1);         // habilita percep. Ingresos Brutos
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
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $rows = db_query("SELECT TOP 20 P.CODPRO, P.DENPRO, P.COSPRO, P.PLCPRO, P.COTPRO, P.FLTPRO, P.DECPRO, P.CODUDM, U.DENUDM, P.CODMON FROM [Tbl Productos] AS P LEFT JOIN [Tbl Unidades de Medida] AS U ON P.CODUDM=U.CODUDM WHERE P.DENPRO Is Not Null AND ((P.DENPRO Like '%$s%') OR (P.CODPRO Like '$s%')) ORDER BY P.DENPRO;");
    $out = array();
    foreach ($rows as $r) { $r['EXTPRO'] = $cc > 0 ? prod_extpro(trim((string) nz($r['CODPRO'], '')), $cc) : ''; $out[] = $r; }
    ok($out);
}
/** Código del proveedor (EXTPRO en Tbl Productos Proveedores) para un producto+cuenta. */
function prod_extpro($codpro, $cc) {
    if ($codpro === '') return '';
    $pp = db_row("SELECT EXTPRO FROM [Tbl Productos Proveedores] WHERE CODPRO='" . db_esc($codpro) . "' AND CODCUE=" . (int) $cc . ";");
    return $pp ? trim((string) nz($pp['EXTPRO'], '')) : '';
}
/** Buscar un producto por el CÓDIGO DEL PROVEEDOR (EXTPRO) + cuenta — como el EXTTMP del legacy. */
function producto_por_ext() {
    $ext = isset($_GET['ext']) ? trim($_GET['ext']) : '';
    $cc  = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    if ($ext === '' || $cc <= 0) { ok(null); return; }
    $pp = db_row("SELECT CODPRO FROM [Tbl Productos Proveedores] WHERE EXTPRO='" . db_esc($ext) . "' AND CODCUE=$cc;");
    if (!$pp) { ok(null); return; }
    $cp = db_esc(trim((string) nz($pp['CODPRO'], '')));
    $p = db_row("SELECT P.CODPRO, P.DENPRO, P.COSPRO, P.PLCPRO, P.COTPRO, P.FLTPRO, P.DECPRO, P.CODUDM, U.DENUDM, P.CODMON FROM [Tbl Productos] AS P LEFT JOIN [Tbl Unidades de Medida] AS U ON P.CODUDM=U.CODUDM WHERE P.CODPRO='$cp';");
    if ($p) $p['EXTPRO'] = $ext;
    ok($p);
}

/** Anticipos del proveedor: movimientos acreedores con saldo a favor (SDOMOV>0) para aplicar (debitar) a este comprobante. */
function anticipos_pendientes() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    if ($cc <= 0) { ok(array()); return; }
    $out = array();
    foreach (db_query("SELECT NUMMOV, CICMOV, CINMOV, SDOMOV, FEXMOV FROM [Tbl Movimientos] WHERE CODORI='A' AND CODCUE=$cc AND SDOMOV>0 AND (ANUMOV=False OR ANUMOV Is Null) ORDER BY FEXMOV;") as $r) {
        $out[] = array('NUMMOV' => (int) $r['NUMMOV'], 'COM' => trim((string) nz($r['CICMOV'], '')), 'NUMERO' => str_pad((string) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT), 'SALDO' => round((float) nz($r['SDOMOV'], 0), 2), 'FECHA' => fecha_serial($r['FEXMOV']));
    }
    ok($out);
}

/** Remitos de proveedor (CODOPE=300) pendientes de facturar (con líneas de stock sin marcar, ECCMOV null) para un proveedor. */
function remitos_pendientes() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    if ($cc <= 0) { ok(array()); return; }
    $out = array();
    foreach (db_query("SELECT NUMMOV, FEXMOV, CINMOV, CEPMOV, CENMOV FROM [Tbl Movimientos] WHERE CODORI='A' AND CODOPE=300 AND CODCUE=$cc AND (ANUMOV=False OR ANUMOV Is Null) ORDER BY FEXMOV DESC;") as $r) {
        $num = (int) $r['NUMMOV'];
        $pend = db_query("SELECT CODPRO, DENMOV, ICCMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num AND ECCMOV Is Null;");
        if (!count($pend)) continue;
        $prods = array();
        foreach ($pend as $ps) $prods[] = trim((string) nz($ps['CODPRO'], '')) . ' ' . trim((string) nz($ps['DENMOV'], '')) . ' (' . rtrim(rtrim(number_format((float) nz($ps['ICCMOV'], 0), 2, '.', ''), '0'), '.') . ')';
        $out[] = array('NUMMOV' => $num, 'FECHA' => fecha_serial($r['FEXMOV']), 'NUMERO' => str_pad((string) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT), 'PRODUCTOS' => implode(' · ', $prods));
    }
    ok($out);
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

function cp_insert($d, $estTrue, $op = null) {
    // $op parametriza el tipo de comprobante: codope/cicmov/codaux/contador (default = Comprobante a Pagar
    // 310/'CP'/ULTCAP). Lo reusa Notas de Crédito Acreedoras (320/'NC'/321/ULTNCI), misma anatomía sin productos.
    if (!is_array($op)) $op = array();
    $opCodope = isset($op['codope']) ? (int) $op['codope'] : 310;
    $opCicmov = isset($op['cicmov']) ? db_esc($op['cicmov']) : 'CP';
    $opContador = isset($op['contador']) ? $op['contador'] : 'ULTCAP';
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
    $codcat = (int) nz(isset($d['codcat']) ? $d['codcat'] : 2, 2);   // 1=con productos (CODAUX=311) / 2=sin (312)
    $codaux = isset($op['codaux']) ? (int) $op['codaux'] : (($codcat == 1) ? 311 : 312);
    $productos = isset($d['productos']) && is_array($d['productos']) ? $d['productos'] : array();

    // Importes
    $ivas = isset($d['ivas']) && is_array($d['ivas']) ? array_values($d['ivas']) : array();
    $netmov = 0; $irimov = 0;
    foreach ($ivas as $iv) { $netmov += (float) nz($iv['net'], 0); $irimov += (float) nz($iv['iva'], 0); }
    $netmov = round($netmov, 2); $irimov = round($irimov, 2);
    $nogmov = isset($d['nogmov']) ? round((float) nz($d['nogmov'], 0), 2) : 0;
    $ip1 = isset($d['ip1mov']) ? round((float) nz($d['ip1mov'], 0), 2) : 0;   // percepción IVA (importe $)
    $ip2 = isset($d['ip2mov']) ? round((float) nz($d['ip2mov'], 0), 2) : 0;   // percepción Ingresos Brutos (importe $)
    $ap1 = isset($d['ap1mov']) ? round((float) nz($d['ap1mov'], 0), 2) : 0;   // alícuota percep. IVA (%)
    $ap2 = isset($d['ap2mov']) ? round((float) nz($d['ap2mov'], 0), 2) : 0;   // alícuota percep. IIBB (%)
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
    $cinmov = next_number_pdv($opContador, $estTrue ? 1 : 9999);
    $sdomov = round(-$sumVto, 2);                                 // negativo = le debemos

    // ── Header ──
    $den = cp_txt(nz($prov['DENCUE'], '')); $cit = cp_txt(isset($d['citmov']) ? nz($d['citmov'], '') : '');
    $dcx = cp_txt(nz($prov['DCXCUE'], '')); $dnx = cp_txt(nz($prov['DNXCUE'], ''));
    $codloc = (int) nz($prov['CODLOC'], 0); $codcri = (int) nz($d['codcri'], 0);
    $det = cp_txt(isset($d['detmov']) ? $d['detmov'] : '');
    $soc = round((float) nz($prov['SOPCUE'], 0), 2); $sac = round((float) nz($prov['SANCUE'], 0), 2);

    db_exec("INSERT INTO [Tbl Movimientos]
        (NUMMOV, CODORI, FEXMOV, FIXMOV, CODOPE, CODAUX, CICMOV, CIPMOV, CINMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV,
         CODCUE, SOCMOV, SACMOV, DENMOV, DCXMOV, DNXMOV, CODLOC, CODCRI, CITMOV, DETMOV, COTMOV, NETMOV, IRIMOV, NOGMOV, IP1MOV, IP2MOV, AP1MOV, AP2MOV,
         CREMOV, TOTMOV, SDOMOV, ESTMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($nummov, 'A', $fex, $fix, $opCodope, $codaux, '$opCicmov', 0, $cinmov, '$cec', '$cei', $cep, $cen, $cef,
         $codcue, " . cp_num($soc) . ", " . cp_num($sac) . ", $den, $dcx, $dnx, $codloc, $codcri, $cit, $det, $cotmov, " . ($netmov != 0 ? cp_num($netmov) : 'Null') . ", " . ($irimov != 0 ? cp_num($irimov) : 'Null') . ", " . ($nogmov != 0 ? cp_num($nogmov) : 'Null') . ", " . ($ip1 != 0 ? cp_num($ip1) : 'Null') . ", " . ($ip2 != 0 ? cp_num($ip2) : 'Null') . ", " . ($ap1 != 0 ? cp_num($ap1) : 'Null') . ", " . ($ap2 != 0 ? cp_num($ap2) : 'Null') . ",
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

    // ── Productos (CODAUX=311): entra mercadería a stock (EXISTK) + actualiza costo del producto (pesos o u$s) ──
    // Moneda 'P' (pesos): el costo está en $. Moneda 'D' (u$s): el costo está en u$s y se pasa a $ con la cotización
    // del comprobante (PUNMOV = COSMOV × COTMOV). En Tbl Productos el costo/lista se guardan en la moneda del producto
    // por unidad base (÷ factor): COSPRO=COSMOV/FCT, PLCPRO=PULMOV/FCT; COTPRO=cotización (sólo productos en u$s).
    if ($codcat == 1) {
        $ordP = 0;
        foreach ($productos as $p) {
            $codpro = trim((string) nz($p['codpro'], '')); if ($codpro === '') continue;
            $ordP++; $cpe = db_esc($codpro);
            $codmon = strtoupper(trim((string) nz(isset($p['codmon']) ? $p['codmon'] : 'P', 'P')));
            $ing = round((float) nz($p['ingmov'], 0), 4);
            $cos = round((float) nz(isset($p['cosmov']) ? $p['cosmov'] : 0, 0), 4);   // costo en la moneda del producto
            $pul = round((float) nz(isset($p['pulmov']) ? $p['pulmov'] : 0, 0), 4);   // precio de lista (misma moneda)
            $fct = round((float) nz(isset($p['fctmov']) ? $p['fctmov'] : 1, 1), 4); if ($fct == 0) $fct = 1;
            $bon = round((float) nz(isset($p['bonmov']) ? $p['bonmov'] : 0, 0), 2);
            $flt = round((float) nz(isset($p['fltmov']) ? $p['fltmov'] : 0, 0), 4);
            $pun = ($codmon === 'P') ? $cos : round($cos * $cotmov, 4);               // costo $ (para el neto/comprobante)
            $codudm = (int) nz(isset($p['codudm']) ? $p['codudm'] : 1, 1);
            $dummov = (int) nz(isset($p['dummov']) ? $p['dummov'] : 0, 0);
            $extmov = trim((string) nz(isset($p['extmov']) ? $p['extmov'] : '', ''));
            $apv = (isset($p['apvmov']) && ($p['apvmov'] === true || $p['apvmov'] == 1));
            $stk = (isset($p['stkmov']) && ($p['stkmov'] === true || $p['stkmov'] == 1));
            $ingSql = $stk ? cp_num($ing) : 'Null'; $svcSql = $stk ? 'Null' : cp_num($ing);
            db_exec("INSERT INTO [Tbl Movimientos Stock]
                (NUMMOV, ORDMOV, CODPRO, CODSUC, DENMOV, CODMON, DECMOV, EXTMOV, FLTMOV, PULMOV, COSMOV, PUNMOV, BONMOV, APVMOV, CODUDM, FCTMOV, DUMMOV, INGMOV, SVCMOV, STKMOV)
                VALUES ($nummov, $ordP, '$cpe', 1, " . cp_txt(nz($p['denmov'], '')) . ", '" . db_esc($codmon) . "', False, " . cp_txt($extmov) . ", " . cp_num($flt) . ", " . cp_num($pul) . ", " . cp_num($cos) . ", " . cp_num($pun) . ", " . cp_num($bon) . ", " . ($apv ? 'True' : 'False') . ", $codudm, $fct, $dummov, $ingSql, $svcSql, " . ($stk ? 'True' : 'False') . ");");
            if ($stk) db_exec("UPDATE [Tbl Stock] SET EXISTK = EXISTK + " . round($ing * $fct, 4) . " WHERE CODSUC=1 AND CODPRO='$cpe';");
            // Tbl Productos: costo/lista por unidad base; cotización sólo si el producto es en u$s; precio de venta si APV (mantiene el margen).
            $cospro = round($cos / $fct, 4); $plcpro = round($pul / $fct, 4);
            $pro = db_row("SELECT CODMON, COTPRO, PLCPRO, PLVPRO FROM [Tbl Productos] WHERE CODPRO='$cpe';");
            $setCot = ($pro && strtoupper(trim((string) nz($pro['CODMON'], 'P'))) !== 'P') ? ", COTPRO=" . cp_num($cotmov) : '';
            $setPlv = '';
            if ($apv && $pro && (float) nz($pro['PLCPRO'], 0) != 0) {
                $mk = (float) nz($pro['PLVPRO'], 0) / (float) $pro['PLCPRO'];     // margen actual = PLV/PLC → se mantiene sobre el costo nuevo
                $setPlv = ", PLVPRO=" . round($plcpro * $mk, 4);
            }
            db_exec("UPDATE [Tbl Productos] SET FUCPRO=$cef, FLTPRO=" . cp_num($flt) . ", COSPRO=$cospro, PLCPRO=$plcpro$setCot$setPlv WHERE CODPRO='$cpe';");
            // Tbl Productos Proveedores (costo de ESTE proveedor para ESTE producto)
            $pp = db_row("SELECT CODPRO FROM [Tbl Productos Proveedores] WHERE CODPRO='$cpe' AND CODCUE=$codcue;");
            if ($pp) db_exec("UPDATE [Tbl Productos Proveedores] SET EXTPRO=" . cp_txt($extmov) . ", FUCPRO=$cef, COTPRO=" . cp_num($cotmov) . ", FLTPRO=" . cp_num($flt) . ", COSPRO=$cospro, PLCPRO=$plcpro WHERE CODPRO='$cpe' AND CODCUE=$codcue;");
            else db_exec("INSERT INTO [Tbl Productos Proveedores] (CODPRO, CODCUE, CODMON, EXTPRO, FUCPRO, COTPRO, FLTPRO, COSPRO, PLCPRO)
                VALUES ('$cpe', $codcue, '" . db_esc($codmon) . "', " . cp_txt($extmov) . ", $cef, " . cp_num($cotmov) . ", " . cp_num($flt) . ", $cospro, $plcpro);");
        }
    }

    // ── Remitos del proveedor: facturar remitos pendientes → descomprometer stock (RMCSTK) + marcar facturado (ECCMOV) ──
    $remitos = isset($d['remitos']) && is_array($d['remitos']) ? $d['remitos'] : array();
    foreach ($remitos as $remNum) {
        $remNum = (int) $remNum; if ($remNum <= 0) continue;
        db_exec("INSERT INTO [Tbl Movimientos Remitos] (NUMMOV, REMMOV) VALUES ($nummov, $remNum);");
        $rm = db_row("SELECT DETMOV FROM [Tbl Movimientos] WHERE NUMMOV=$remNum;");
        $note = "CP - " . str_pad((string) $cinmov, 8, '0', STR_PAD_LEFT) . " " . $cec . " - " . $cei . " - " . str_pad((string) $cep, 4, '0', STR_PAD_LEFT) . " - " . str_pad((string) $cen, 8, '0', STR_PAD_LEFT);
        db_exec("UPDATE [Tbl Movimientos] SET DETMOV=" . cp_txt(trim((string) nz($rm['DETMOV'], '')) . $note) . " WHERE NUMMOV=$remNum;");
        foreach (db_query("SELECT CODPRO, ICCMOV, FCTMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$remNum;") as $rs) {
            $icc = round((float) nz($rs['ICCMOV'], 0), 4); $rfct = round((float) nz($rs['FCTMOV'], 1), 4); if ($rfct == 0) $rfct = 1;
            $rcp = db_esc(trim((string) $rs['CODPRO']));
            db_exec("UPDATE [Tbl Stock] SET RMCSTK = RMCSTK - " . round($icc * $rfct, 4) . " WHERE CODSUC=1 AND CODPRO='$rcp';");
        }
        db_exec("UPDATE [Tbl Movimientos Stock] SET ECCMOV = ICCMOV WHERE NUMMOV=$remNum;");
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
        anular_check($num, cp_codope(), 'Comprobante');
        db_begin();
        try { anular_comprobante($num, cp_codope()); db_commit(); }
        catch (Exception $e) { db_rollback(); throw $e; }
        ok(array('anulado' => $num));
    } catch (Exception $e) {
        fail('No se pudo anular el comprobante: ' . $e->getMessage(), 400);
    }
}
