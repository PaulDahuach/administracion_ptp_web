<?php
/**
 * Remitos Acreedores (CODOPE=300, CICMOV='RA') — API. Porta Frm CA Remitos (SetData Case "A").
 * Registra la mercadería recibida del proveedor: header SIN movimiento de cta cte (TOT/DEB/CRE=0) +
 * líneas en [Tbl Movimientos Stock]; sólo COMPROMETE stock (RMCSTK += cant×fct), NO toca EXISTK. El CP
 * que lo factura después descompromete (RMCSTK -=) + marca ECCMOV. Numeración interna ULTRMC (CODPDV 1
 * blanco / 9999 capacitación; CIPMOV=0/Null), como el CP. dev=copia en readwrite.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'buscar_proveedores': buscar_proveedores(); break;
        case 'productos':          buscar_productos();   break;
        case 'unidades':           unidades();           break;
        case 'guardar':            guardar();            break;
        case 'anular':             anular();             break;
        case 'listar':             listar();             break;
        case 'detalle':            detalle();            break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function ra_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function ra_fiso($s) { $f = fecha_serial($s); if (!$f || strpos($f, '/') === false) return ''; $p = explode('/', $f); return $p[2] . '-' . $p[1] . '-' . $p[0]; }
function ra_txt($s) { $s = trim((string) $s); return $s === '' ? 'Null' : "'" . db_esc($s) . "'"; }
function ra_estmov_w() { $l = auth_libro_unico(); if ($l === 'blanco') return ' AND ESTMOV=True'; if ($l === 'capacitacion') return ' AND ESTMOV=False'; return ''; }
function ra_comp($cip, $cin) { return 'RA ' . str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT); }

function buscar_proveedores() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    $num = is_numeric($q) ? " OR CODCUE = " . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes]
        WHERE CODORI='A' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

/** Productos que controlan stock (Tbl Productos INNER JOIN Tbl Stock CODSUC=1) + su RMCSTK actual (comprometido). */
function buscar_productos() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    ok(db_query("SELECT TOP 20 P.CODPRO, P.DENPRO, P.CODUDM, S.RMCSTK FROM [Tbl Productos] AS P
        INNER JOIN [Tbl Stock] AS S ON P.CODPRO=S.CODPRO
        WHERE S.CODSUC=1 AND P.DENPRO Is Not Null AND ((P.DENPRO Like '%$s%') OR (P.CODPRO Like '$s%'))
        ORDER BY P.DENPRO;"));
}

/** Unidades de medida de un producto (Tbl Productos Unidades) con factor + decimales — para el combo Unidad. */
function unidades() {
    $cp = isset($_GET['codpro']) ? db_esc(trim($_GET['codpro'])) : '';
    if ($cp === '') { ok(array()); return; }
    ok(db_query("SELECT U.CODUDM, U.DENUDM, PU.FCTPUM, U.DECUDM FROM [Tbl Unidades de Medida] AS U
        INNER JOIN [Tbl Productos Unidades] AS PU ON U.CODUDM=PU.CODUDM
        WHERE PU.CODPRO='$cp' ORDER BY U.DENUDM;"));
}

/**
 * Graba un remito acreedor. $_POST['data'] = JSON {codcue, fexmov(iso), cep, cen, cef(iso),
 * lineas:[{codpro,denmov,codudm,fctmov,dummov,eximov,cant}]}. Devuelve {nummov, cipmov, cinmov}.
 */
function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? $_POST['data'] : '';
    $d = json_decode($raw, true);
    if (!is_array($d)) { fail('Datos inválidos'); return; }

    $codcue = isset($d['codcue']) ? (int) $d['codcue'] : 0;
    $lineas = (isset($d['lineas']) && is_array($d['lineas'])) ? $d['lineas'] : array();
    if ($codcue <= 0)    { fail('Falta el proveedor'); return; }
    if (!count($lineas)) { fail('El remito no tiene productos'); return; }
    $prov = db_row("SELECT DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='A';");
    if (!$prov) { fail('Proveedor inexistente'); return; }

    $modo = auth_modo();
    $estTrue = ($modo !== 'capacitacion');
    $estSql  = $estTrue ? 'True' : 'False';
    $fex = ra_serial(isset($d['fexmov']) ? $d['fexmov'] : '');
    if ($fex === null) { fail('Falta la fecha de emisión'); return; }
    $cef = ra_serial(isset($d['cef']) ? $d['cef'] : '');
    $cep = isset($d['cep']) ? (int) $d['cep'] : 0;
    $cen = isset($d['cen']) ? (int) $d['cen'] : 0;

    // Comprobante del proveedor único: no repetir PDV+Número en otro remito 300 vigente.
    if ($cen > 0) {
        $dup = db_row("SELECT NUMMOV FROM [Tbl Movimientos]
            WHERE CODOPE=300 AND CEPMOV=$cep AND CENMOV=$cen AND CODCUE=$codcue AND (ANUMOV=False OR ANUMOV Is Null);");
        if ($dup) { fail('Ya existe un remito con ese comprobante del proveedor (' . str_pad((string) $cep, 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) $cen, 8, '0', STR_PAD_LEFT) . ')'); return; }
    }

    db_begin();
    try {
        $nummov = next_number('ULTMOV');
        $cinmov = next_number_pdv('ULTRMC', $estTrue ? 1 : 9999);
        $cipSql = $estTrue ? '0' : 'Null';
        $cipmov = $estTrue ? 0 : null;
        $denSql = ra_txt(nz($prov['DENCUE'], ''));
        $cefSql = $cef === null ? 'Null' : (string) $cef;
        $cepSql = $cep > 0 ? (string) $cep : 'Null';
        $cenSql = $cen > 0 ? (string) $cen : 'Null';

        // Header: CODORI='A', CODOPE=300, CICMOV='RA', CECMOV='RM'. Sin cta cte (TOT/DEB/CRE=0).
        db_exec("INSERT INTO [Tbl Movimientos]
            (NUMMOV, CODORI, CODOPE, FEXMOV, CICMOV, CIPMOV, CINMOV, CECMOV, CEPMOV, CENMOV, CEFMOV,
             CODCUE, DENMOV, TOTMOV, DEBMOV, CREMOV, NOWMOV, ANUMOV, ESTMOV)
            VALUES ($nummov, 'A', 300, $fex, 'RA', $cipSql, $cinmov, 'RM', $cepSql, $cenSql, $cefSql,
             $codcue, $denSql, 0, 0, 0, Now(), False, $estSql);");

        // Líneas en Tbl Movimientos Stock + COMPROMETE stock (RMCSTK += cant×fct). NO toca EXISTK.
        $ord = 0;
        foreach ($lineas as $l) {
            $cp = db_esc(trim((string) nz($l['codpro'], ''))); if ($cp === '') continue;
            $cant = round((float) nz($l['cant'], 0), 4); if ($cant <= 0) continue;
            $ord++;
            $denL = ra_txt(nz($l['denmov'], ''));
            $udm = (int) nz($l['codudm'], 1);
            $fct = round((float) nz($l['fctmov'], 1), 4); if ($fct == 0) $fct = 1;
            $dum = (int) nz($l['dummov'], 2);
            $exi = round((float) nz($l['eximov'], 0), 4);
            db_exec("INSERT INTO [Tbl Movimientos Stock]
                (NUMMOV, ORDMOV, CODSUC, CODPRO, DENMOV, CODUDM, FCTMOV, DUMMOV, EXIMOV, ICCMOV, STKMOV)
                VALUES ($nummov, $ord, 1, '$cp', $denL, $udm, " . cp_n($fct) . ", $dum, " . cp_n($exi) . ", " . cp_n($cant) . ", True);");
            db_exec("UPDATE [Tbl Stock] SET RMCSTK = RMCSTK + " . cp_n(round($cant * $fct, 4)) . " WHERE CODSUC=1 AND CODPRO='$cp';");
        }
        if ($ord === 0) { db_rollback(); fail('El remito no tiene productos válidos'); return; }

        db_commit();
        ok(array('nummov' => $nummov, 'cipmov' => $cipmov, 'cinmov' => $cinmov));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar el remito: ' . $e->getMessage(), 500);
    }
}
function cp_n($v) { return (string) round((float) $v, 4); }

/** Anular un remito (admin). Revierte el compromiso de stock (RMCSTK -= ICCMOV×FCT) y marca ANUMOV.
 *  NO se puede anular si ya fue facturado por un CP (alguna línea con ECCMOV no null). */
function anular() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    if (!auth_is_admin()) { fail('Solo un administrador puede anular remitos', 403); return; }
    $num = isset($_POST['nummov']) ? (int) $_POST['nummov'] : (isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0);
    $h = db_row("SELECT ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=300;");
    if (!$h) { fail('Remito no encontrado'); return; }
    if ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) { fail('El remito ya está anulado'); return; }
    $fact = db_row("SELECT COUNT(*) AS n FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num AND ECCMOV Is Not Null;");
    if ((int) nz($fact['n'], 0) > 0) { fail('El remito ya fue facturado por un CP; anulá primero ese CP.'); return; }

    db_begin();
    try {
        foreach (db_query("SELECT CODPRO, ICCMOV, FCTMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num;") as $s) {
            $cp = db_esc(trim((string) nz($s['CODPRO'], ''))); if ($cp === '') continue;
            $rev = round((float) nz($s['ICCMOV'], 0) * (float) nz($s['FCTMOV'], 1), 4);
            if ($rev != 0) db_exec("UPDATE [Tbl Stock] SET RMCSTK = RMCSTK - " . cp_n($rev) . " WHERE CODSUC=1 AND CODPRO='$cp';");
        }
        db_exec("UPDATE [Tbl Movimientos Stock] SET ICCMOV=0 WHERE NUMMOV=$num;");
        db_exec("UPDATE [Tbl Movimientos] SET ANUMOV=True WHERE NUMMOV=$num;");
        db_commit();
        ok(array('anulado' => $num));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo anular el remito: ' . $e->getMessage(), 500);
    }
}

/** Listado de remitos acreedores (CODOPE=300) filtrado por el modo + texto/fecha. */
function listar() {
    $q  = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sd = ra_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
    $sh = ra_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');
    $w  = "CODOPE=300" . ra_estmov_w();
    if ($q !== '') {
        $qs = db_esc($q);
        $cond = "(DENMOV Like '%$qs%'";
        if (is_numeric($q)) $cond .= " OR CINMOV=" . (int) $q . " OR CENMOV=" . (int) $q;
        $cond .= ")";
        $w .= " AND $cond";
    }
    if ($sd !== null) $w .= " AND FEXMOV >= $sd";
    if ($sh !== null) $w .= " AND FEXMOV <= $sh";
    $out = array();
    foreach (db_query("SELECT TOP 200 NUMMOV, CINMOV, CIPMOV, FEXMOV, CODCUE, DENMOV, CEPMOV, CENMOV, ANUMOV
        FROM [Tbl Movimientos] WHERE $w ORDER BY FEXMOV DESC, NUMMOV DESC;") as $r) {
        $out[] = array(
            'NUMMOV' => (int) $r['NUMMOV'],
            'NUMERO' => ra_comp($r['CIPMOV'], $r['CINMOV']),
            'FECHA'  => fecha_serial($r['FEXMOV']),
            'PROVEEDOR' => trim((string) nz($r['DENMOV'], '')),
            'COMP'   => 'RM ' . str_pad((string) (int) nz($r['CEPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CENMOV'], 0), 8, '0', STR_PAD_LEFT),
            'ANULADO' => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1),
        );
    }
    ok($out);
}

/** Detalle de un remito para cargarlo en el form en sólo-lectura (cabecera + líneas). */
function detalle() {
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT CINMOV, CIPMOV, FEXMOV, CODCUE, DENMOV, CITMOV, CEPMOV, CENMOV, CEFMOV, ESTMOV, ANUMOV
        FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=300;");
    if (!$h) { fail('Remito no encontrado'); return; }
    $fact = (int) nz(db_row("SELECT COUNT(*) AS n FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num AND ECCMOV Is Not Null;")['n'], 0);
    $r = array(
        'NUMMOV' => $num,
        'NUMERO' => str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        'CIPMOV' => str_pad((string) (int) nz($h['CIPMOV'], 0), 4, '0', STR_PAD_LEFT),
        'FEXISO' => ra_fiso($h['FEXMOV']),
        'CODCUE' => (int) $h['CODCUE'],
        'PROVEEDOR' => trim((string) nz($h['DENMOV'], '')),
        'CUIT' => trim((string) nz($h['CITMOV'], '')),
        'CEP' => (int) nz($h['CEPMOV'], 0), 'CEN' => (int) nz($h['CENMOV'], 0), 'CEFISO' => ra_fiso($h['CEFMOV']),
        'ANULADO' => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1),
        'FACTURADO' => ($fact > 0),
        'ANULABLE' => (auth_is_admin() && $fact === 0 && !($h['ANUMOV'] === true || $h['ANUMOV'] == -1)),
        'lineas' => array(),
    );
    foreach (db_query("SELECT S.CODPRO, S.DENMOV, S.CODUDM, U.DENUDM, S.FCTMOV, S.DUMMOV, S.EXIMOV, S.ICCMOV, S.ECCMOV
        FROM [Tbl Movimientos Stock] AS S LEFT JOIN [Tbl Unidades de Medida] AS U ON S.CODUDM=U.CODUDM
        WHERE S.NUMMOV=$num ORDER BY S.ORDMOV;") as $l) {
        $r['lineas'][] = array(
            'codpro' => trim((string) nz($l['CODPRO'], '')),
            'denmov' => trim((string) nz($l['DENMOV'], '')),
            'codudm' => (int) nz($l['CODUDM'], 1),
            'unidad' => trim((string) nz($l['DENUDM'], '')),
            'fctmov' => round((float) nz($l['FCTMOV'], 1), 4),
            'dummov' => (int) nz($l['DUMMOV'], 2),
            'eximov' => round((float) nz($l['EXIMOV'], 0), 4),
            'cant'   => round((float) nz($l['ICCMOV'], 0), 4),
            'facturado' => ($l['ECCMOV'] !== null && $l['ECCMOV'] !== ''),
        );
    }
    ok($r);
}
