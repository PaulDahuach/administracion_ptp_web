<?php
/**
 * Ajustes de Stock (CODORI='S', CODOPE=200, CICMOV='AS') — API. Porta Frm SI Ajustes.
 * Registra un ajuste de existencias: header en [Tbl Movimientos] (concepto=CODAUX) + líneas en
 * [Tbl Movimientos Stock] + mueve [Tbl Stock].EXISTK += (ingreso−egreso)×factor (e INISTK si el
 * concepto es STOCK INICIAL, 201). NO genera asiento contable (el legacy lo tiene comentado).
 * Numeración: ULTMOV (movimiento) + ULTAJU (nº de ajuste, CINMOV; CIPMOV=0). ESTMOV=True (stock
 * es físico, siempre operativo, como el legacy). dev=copia en readwrite.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'productos': buscar_productos(); break;
        case 'unidades':  unidades();         break;
        case 'guardar':   guardar();          break;
        case 'anular':    anular();           break;
        case 'listar':    listar();           break;
        case 'detalle':   detalle();          break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function as_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function as_fiso($s) { $f = fecha_serial($s); if (!$f || strpos($f, '/') === false) return ''; $p = explode('/', $f); return $p[2] . '-' . $p[1] . '-' . $p[0]; }
function as_n($v) { return (string) round((float) $v, 4); }
function as_txt($s) { $s = trim((string) $s); return $s === '' ? 'Null' : "'" . db_esc($s) . "'"; }

/** Productos que controlan stock (INNER JOIN Tbl Stock CODSUC=1) + costo/lista/moneda/existencia. */
function buscar_productos() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    ok(db_query("SELECT TOP 20 P.CODPRO, P.DENPRO, P.CODUDM, P.COSPRO, P.PLCPRO, P.CODMON, S.EXISTK
        FROM [Tbl Productos] AS P INNER JOIN [Tbl Stock] AS S ON P.CODPRO=S.CODPRO
        WHERE S.CODSUC=1 AND P.DENPRO Is Not Null AND ((P.DENPRO Like '%$s%') OR (P.CODPRO Like '$s%'))
        ORDER BY P.DENPRO;"));
}

/** Unidades de medida de un producto (Tbl Productos Unidades) con factor + decimales. */
function unidades() {
    $cp = isset($_GET['codpro']) ? db_esc(trim($_GET['codpro'])) : '';
    if ($cp === '') { ok(array()); return; }
    ok(db_query("SELECT U.CODUDM, U.DENUDM, PU.FCTPUM, U.DECUDM FROM [Tbl Unidades de Medida] AS U
        INNER JOIN [Tbl Productos Unidades] AS PU ON U.CODUDM=PU.CODUDM
        WHERE PU.CODPRO='$cp' ORDER BY U.DENUDM;"));
}

/**
 * Graba un ajuste de stock. $_POST['data'] = JSON {codaux, fexmov(iso), detmov, cotmov,
 * lineas:[{codpro, codudm, ing, egr}]}. El server re-lee costo/factor/existencia del producto.
 */
function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? $_POST['data'] : '';
    $d = json_decode($raw, true);
    if (!is_array($d)) { fail('Datos inválidos'); return; }

    $codaux = isset($d['codaux']) ? (int) $d['codaux'] : 0;
    $lineas = (isset($d['lineas']) && is_array($d['lineas'])) ? $d['lineas'] : array();
    if ($codaux <= 0)   { fail('Falta el concepto'); return; }
    if (!count($lineas)) { fail('El ajuste no tiene productos'); return; }
    $con = db_row("SELECT DENAUX FROM [Tbl Operaciones Auxiliares] WHERE CODAUX=$codaux AND CODOPE=200;");
    if (!$con) { fail('Concepto inválido'); return; }

    $fex = as_serial(isset($d['fexmov']) ? $d['fexmov'] : '');
    if ($fex === null) { fail('Falta la fecha'); return; }
    $cotmov = round((float) nz($d['cotmov'], 1), 4); if ($cotmov == 0) $cotmov = 1;
    $detSql = as_txt(nz($d['detmov'], ''));
    $uid = (isset($_SESSION['uid']) && $_SESSION['uid'] !== '') ? "'" . db_esc((string) $_SESSION['uid']) . "'" : 'Null';
    $esInicial = ($codaux === 201);   // STOCK INICIAL → también mueve INISTK

    db_begin();
    try {
        $nummov = next_number('ULTMOV');
        $ajuste = next_number('ULTAJU');

        db_exec("INSERT INTO [Tbl Movimientos]
            (NUMMOV, CODORI, CODOPE, CODAUX, FEXMOV, CICMOV, CIPMOV, CINMOV, CEFMOV, DETMOV, COTMOV,
             TOTMOV, NUIMOV, NOWMOV, ANUMOV, ESTMOV)
            VALUES ($nummov, 'S', 200, $codaux, $fex, 'AS', 0, $ajuste, $fex, $detSql, " . as_n($cotmov) . ",
             0, $uid, Now(), False, True);");

        $ord = 0; $tot = 0;
        foreach ($lineas as $l) {
            $cp = trim((string) nz($l['codpro'], '')); if ($cp === '') continue;
            $ing = round((float) nz($l['ing'], 0), 4);
            $egr = round((float) nz($l['egr'], 0), 4);
            if ($ing <= 0 && $egr <= 0) continue;
            $cpe = db_esc($cp);
            $p = db_row("SELECT DENPRO, COSPRO, PLCPRO, CODMON FROM [Tbl Productos] WHERE CODPRO='$cpe';");
            if (!$p) continue;
            $udm = (int) nz($l['codudm'], 0);
            $fct = 1; $dec = 0;
            if ($udm > 0) {
                $u = db_row("SELECT PU.FCTPUM, U.DECUDM FROM [Tbl Productos Unidades] AS PU
                    INNER JOIN [Tbl Unidades de Medida] AS U ON PU.CODUDM=U.CODUDM
                    WHERE PU.CODPRO='$cpe' AND PU.CODUDM=$udm;");
                if ($u) { $fct = round((float) nz($u['FCTPUM'], 1), 4); $dec = (int) nz($u['DECUDM'], 0); }
            }
            if ($fct == 0) $fct = 1;
            $st = db_row("SELECT EXISTK FROM [Tbl Stock] WHERE CODPRO='$cpe' AND CODSUC=1;");
            $existk = (float) nz($st ? $st['EXISTK'] : 0, 0);
            $exi = round($existk / $fct, 4);
            $cosmov = round((float) nz($p['COSPRO'], 0) * $fct, 4);
            $pulmov = round((float) nz($p['PLCPRO'], 0) * $fct, 4);
            $codmon = trim((string) nz($p['CODMON'], 'P'));
            $punmov = round($pulmov * ($codmon === 'P' ? 1 : $cotmov), 4);
            $ord++;
            db_exec("INSERT INTO [Tbl Movimientos Stock]
                (NUMMOV, ORDMOV, CODSUC, CODPRO, DENMOV, CODUDM, FCTMOV, DUMMOV, CODMON, COSMOV, PULMOV, PUNMOV, EXIMOV, INGMOV, EGRMOV, STKMOV)
                VALUES ($nummov, $ord, 1, '$cpe', " . as_txt(nz($p['DENPRO'], '')) . ", " . ($udm > 0 ? $udm : 'Null') . ", " . as_n($fct) . ", $dec, '" . db_esc($codmon) . "',
                 " . as_n($cosmov) . ", " . as_n($pulmov) . ", " . as_n($punmov) . ", " . as_n($exi) . ", " . as_n($ing) . ", " . as_n($egr) . ", True);");

            $net = round(($ing - $egr) * $fct, 4);
            db_exec("UPDATE [Tbl Stock] SET EXISTK = EXISTK + " . as_n($net) . ($esInicial ? ", INISTK = INISTK + " . as_n($net) : '') . " WHERE CODPRO='$cpe' AND CODSUC=1;");
            $tot += ($ing - $egr) * $cosmov;
        }
        if ($ord === 0) { db_rollback(); fail('El ajuste no tiene productos válidos (ingreso o egreso > 0)'); return; }
        db_exec("UPDATE [Tbl Movimientos] SET TOTMOV = " . as_n($tot) . " WHERE NUMMOV=$nummov;");

        db_commit();
        ok(array('nummov' => $nummov, 'ajuste' => $ajuste, 'concepto' => trim((string) nz($con['DENAUX'], ''))));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar el ajuste: ' . $e->getMessage(), 500);
    }
}

/** Anular un ajuste (admin): revierte EXISTK (e INISTK si era inicial) y marca ANUMOV. */
function anular() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    if (!auth_is_admin()) { fail('Solo un administrador puede anular ajustes', 403); return; }
    $num = isset($_POST['nummov']) ? (int) $_POST['nummov'] : (isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0);
    $h = db_row("SELECT ANUMOV, CODAUX FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=200;");
    if (!$h) { fail('Ajuste no encontrado'); return; }
    if ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) { fail('El ajuste ya está anulado'); return; }
    $esInicial = ((int) nz($h['CODAUX'], 0) === 201);

    db_begin();
    try {
        foreach (db_query("SELECT CODPRO, INGMOV, EGRMOV, FCTMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num;") as $s) {
            $cp = db_esc(trim((string) nz($s['CODPRO'], ''))); if ($cp === '') continue;
            $net = round(((float) nz($s['INGMOV'], 0) - (float) nz($s['EGRMOV'], 0)) * (float) nz($s['FCTMOV'], 1), 4);
            if ($net != 0) db_exec("UPDATE [Tbl Stock] SET EXISTK = EXISTK - " . as_n($net) . ($esInicial ? ", INISTK = INISTK - " . as_n($net) : '') . " WHERE CODPRO='$cp' AND CODSUC=1;");
        }
        db_exec("UPDATE [Tbl Movimientos] SET ANUMOV=True WHERE NUMMOV=$num;");
        db_commit();
        ok(array('anulado' => $num));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo anular el ajuste: ' . $e->getMessage(), 500);
    }
}

/** Listado de ajustes (CODOPE=200) + texto/fecha. */
function listar() {
    $q  = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sd = as_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
    $sh = as_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');
    $w  = "M.CODOPE=200";
    if ($q !== '') {
        $qs = db_esc($q);
        $cond = "(M.DETMOV Like '%$qs%' OR A.DENAUX Like '%$qs%'";
        if (is_numeric($q)) $cond .= " OR M.CINMOV=" . (int) $q;
        $cond .= ")";
        $w .= " AND $cond";
    }
    if ($sd !== null) $w .= " AND M.FEXMOV >= $sd";
    if ($sh !== null) $w .= " AND M.FEXMOV <= $sh";
    $out = array();
    foreach (db_query("SELECT TOP 200 M.NUMMOV, M.CINMOV, M.FEXMOV, M.DETMOV, M.TOTMOV, M.ANUMOV, A.DENAUX
        FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Operaciones Auxiliares] AS A ON M.CODAUX=A.CODAUX
        WHERE $w ORDER BY M.FEXMOV DESC, M.NUMMOV DESC;") as $r) {
        $out[] = array(
            'NUMMOV' => (int) $r['NUMMOV'],
            'NUMERO' => str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
            'FECHA'  => fecha_serial($r['FEXMOV']),
            'CONCEPTO' => trim((string) nz($r['DENAUX'], '')),
            'DETALLE'  => trim((string) nz($r['DETMOV'], '')),
            'TOTAL'  => number_format((float) nz($r['TOTMOV'], 0), 2, '.', ','),
            'ANULADO' => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1),
        );
    }
    ok($out);
}

/** Detalle de un ajuste (cabecera + líneas) para verlo en sólo-lectura. */
function detalle() {
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT M.CINMOV, M.FEXMOV, M.CODAUX, M.DETMOV, M.COTMOV, M.TOTMOV, M.ANUMOV, A.DENAUX
        FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Operaciones Auxiliares] AS A ON M.CODAUX=A.CODAUX
        WHERE M.NUMMOV=$num AND M.CODOPE=200;");
    if (!$h) { fail('Ajuste no encontrado'); return; }
    $r = array(
        'NUMMOV' => $num,
        'NUMERO' => str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        'FEXISO' => as_fiso($h['FEXMOV']),
        'CODAUX' => (int) nz($h['CODAUX'], 0),
        'CONCEPTO' => trim((string) nz($h['DENAUX'], '')),
        'DETMOV' => trim((string) nz($h['DETMOV'], '')),
        'COTMOV' => round((float) nz($h['COTMOV'], 1), 4),
        'TOTAL'  => number_format((float) nz($h['TOTMOV'], 0), 2, '.', ','),
        'ANULADO' => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1),
        'ANULABLE' => (auth_is_admin() && !($h['ANUMOV'] === true || $h['ANUMOV'] == -1)),
        'lineas' => array(),
    );
    foreach (db_query("SELECT S.CODPRO, S.DENMOV, S.CODUDM, U.DENUDM, S.FCTMOV, S.DUMMOV, S.EXIMOV, S.INGMOV, S.EGRMOV
        FROM [Tbl Movimientos Stock] AS S LEFT JOIN [Tbl Unidades de Medida] AS U ON S.CODUDM=U.CODUDM
        WHERE S.NUMMOV=$num ORDER BY S.ORDMOV;") as $l) {
        $r['lineas'][] = array(
            'codpro' => trim((string) nz($l['CODPRO'], '')),
            'denmov' => trim((string) nz($l['DENMOV'], '')),
            'codudm' => (int) nz($l['CODUDM'], 0),
            'unidad' => trim((string) nz($l['DENUDM'], '')),
            'eximov' => round((float) nz($l['EXIMOV'], 0), 4),
            'ing'    => round((float) nz($l['INGMOV'], 0), 4),
            'egr'    => round((float) nz($l['EGRMOV'], 0), 4),
        );
    }
    ok($r);
}
