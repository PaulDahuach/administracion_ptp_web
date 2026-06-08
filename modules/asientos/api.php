<?php
/**
 * Asientos contables manuales (Imputaciones Contables — porta Frm IC Imputaciones, SetData Case "A").
 * FASE 1: asiento simple. Operación interna (Tbl Operaciones CODORI='I') + imputaciones (cuenta contable ·
 * centro de costo · Debe/Haber) que CUADREN → header en Tbl Movimientos (CODORI='I', CICMOV=ICCOPE, CINMOV del
 * contador ULTOPE de la operación) + Tbl Movimientos Imputaciones + mayoriza DEBCUE/CRECUE de cada cuenta.
 * Sin comprobante externo / IVA / percepciones / cheques / banco (Fase 2/3). dev=copia en readwrite.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'operaciones':   operaciones();        break;
        case 'cuentas':       cuentas_imputables(); break;
        case 'centros_costo': centros_costo();      break;
        case 'guardar':       guardar();            break;
        case 'anular':        anular();             break;
        case 'listar':        listar();             break;
        case 'detalle':       detalle();            break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function as_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function as_fiso($s) { $f = fecha_serial($s); if (!$f || strpos($f, '/') === false) return ''; $p = explode('/', $f); return $p[2] . '-' . $p[1] . '-' . $p[0]; }
function as_txt($s) { $s = trim((string) $s); return $s === '' ? 'Null' : "'" . db_esc($s) . "'"; }
function as_num($v) { return (string) round((float) $v, 2); }
function as_estmov_w() { $l = auth_libro_unico(); if ($l === 'blanco') return ' AND ESTMOV=True'; if ($l === 'capacitacion') return ' AND ESTMOV=False'; return ''; }

/** Operaciones internas (CODORI='I') = los tipos de asiento manual (Asiento Diario, Ajuste, Saldo Inicial, …). */
function operaciones() {
    ok(db_query("SELECT CODOPE, DENOPE, ICCOPE FROM [Tbl Operaciones] WHERE CODORI='I' ORDER BY DENOPE;"));
}

/** Cuentas contables imputables (hojas, IMPCUE=True). */
function cuentas_imputables() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE FROM [Tbl Cuentas Contables]
        WHERE IMPCUE=True AND ((DENCUE Like '%$s%') OR (CODCUE Like '$s%')) ORDER BY CODCUE;"));
}

function centros_costo() {
    ok(db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo] ORDER BY DENCDC;"));
}

/** Inserta una imputación (Debe o Haber) + SOCMOV (saldo cacheado pre-update) + mayoriza DEBCUE/CRECUE. */
function as_imp(&$ord, &$totDeb, &$totCre, $nummov, $cuenta, $deb, $cre, $codcdc) {
    $ord++;
    $cc = db_esc((string) $cuenta);
    $bal = db_row("SELECT DEBCUE, CRECUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
    $soc = $bal ? round((float) nz($bal['DEBCUE'], 0) - (float) nz($bal['CRECUE'], 0), 2) : 0;
    $deb = round((float) $deb, 2); $cre = round((float) $cre, 2);
    $debSql = ($deb != 0) ? (string) $deb : 'Null';
    $creSql = ($cre != 0) ? (string) $cre : 'Null';
    db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, DEBMOV, CREMOV, CODCDC, SOCMOV)
        VALUES ($nummov, $ord, '$cc', $debSql, $creSql, " . (int) $codcdc . ", $soc);");
    if ($deb != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE + $deb WHERE CODCUE='$cc';");
    if ($cre != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE + $cre WHERE CODCUE='$cc';");
    $totDeb += $deb; $totCre += $cre;
}

/**
 * Graba un asiento manual. $_POST['data'] = JSON {codope, fexmov(iso), detmov,
 * lineas:[{codcue, codcdc, debe, cre}]}. Devuelve {nummov, cinmov, total}.
 */
function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? $_POST['data'] : '';
    $d = json_decode($raw, true);
    if (!is_array($d)) { fail('Datos inválidos'); return; }

    $codope = isset($d['codope']) ? (int) $d['codope'] : 0;
    $lineas = (isset($d['lineas']) && is_array($d['lineas'])) ? $d['lineas'] : array();
    if ($codope <= 0)        { fail('Elegí la operación'); return; }
    $op = db_row("SELECT ICCOPE FROM [Tbl Operaciones] WHERE CODOPE=$codope AND CODORI='I';");
    if (!$op) { fail('Operación interna inexistente'); return; }

    // Limpiar líneas válidas + totales para validar el balance ANTES de tocar nada.
    $val = array(); $totDeb = 0.0; $totCre = 0.0;
    foreach ($lineas as $l) {
        $cu = trim((string) nz($l['codcue'], '')); if ($cu === '') continue;
        $deb = round((float) nz($l['debe'], 0), 2); $cre = round((float) nz($l['cre'], 0), 2);
        if ($deb == 0 && $cre == 0) continue;
        $cdc = isset($l['codcdc']) && $l['codcdc'] !== '' ? (int) $l['codcdc'] : 1;
        $val[] = array('codcue' => $cu, 'codcdc' => $cdc, 'debe' => $deb, 'cre' => $cre);
        $totDeb += $deb; $totCre += $cre;
    }
    if (count($val) < 2) { fail('El asiento necesita al menos 2 imputaciones'); return; }
    if (round($totDeb, 2) <= 0) { fail('El asiento está en cero'); return; }
    if (abs(round($totDeb - $totCre, 2)) > 0.009) { fail('El asiento no cuadra: Debe ' . number_format($totDeb, 2) . ' ≠ Haber ' . number_format($totCre, 2)); return; }

    // FASE 1 (candado de seguridad): rechazar imputaciones a cuentas de cheque/banco — valores a depositar
    // (CACC_2), cuentas bancarias (CACC_3) y cheques diferidos (CACC_V). Esas cuentas mueven la cartera de
    // cheques (Tbl Cheques: alta/depósito/emisión/diferidos), que es el subsistema de la FASE 2. Grabar el
    // asiento sin tocar Tbl Cheques dejaría la cartera/conciliación inconsistente.
    $rc = db_row("SELECT CACC_2, CACC_3, CACC_V FROM [Rec Control];");
    $pref = array();
    foreach (array('CACC_2', 'CACC_3', 'CACC_V') as $k) { $p = trim((string) nz($rc[$k], '')); if ($p !== '') $pref[] = $p; }
    foreach ($val as $l) {
        foreach ($pref as $p) {
            if (strpos($l['codcue'], $p) === 0) {
                fail('La cuenta ' . $l['codcue'] . ' es de cheques/banco: la carga simple de asientos (Fase 1) todavía no mueve la cartera de cheques. Esa operación es de la Fase 2.');
                return;
            }
        }
    }

    $modo = auth_modo();
    $estTrue = ($modo !== 'capacitacion');
    $estSql  = $estTrue ? 'True' : 'False';
    $fex = as_serial(isset($d['fexmov']) ? $d['fexmov'] : '');
    if ($fex === null) { fail('Falta la fecha de emisión'); return; }
    $detmov = isset($d['detmov']) ? trim($d['detmov']) : '';
    $cic = trim((string) nz($op['ICCOPE'], ''));

    db_begin();
    try {
        $nummov = next_number('ULTMOV');
        // CINMOV = contador ULTOPE de la operación (Tbl Operaciones), como el legacy.
        $opc = db_row("SELECT ULTOPE FROM [Tbl Operaciones] WHERE CODOPE=$codope;");
        $cinmov = (int) nz($opc['ULTOPE'], 0) + 1;
        db_exec("UPDATE [Tbl Operaciones] SET ULTOPE = $cinmov WHERE CODOPE=$codope;");
        $cipSql = $estTrue ? '0' : 'Null';
        $total = round($totDeb, 2);

        db_exec("INSERT INTO [Tbl Movimientos]
            (NUMMOV, CODORI, CODOPE, FEXMOV, CICMOV, CIPMOV, CINMOV, CODCUE, DETMOV, TOTMOV, NOWMOV, ANUMOV, ESTMOV)
            VALUES ($nummov, 'I', $codope, $fex, " . as_txt($cic) . ", $cipSql, $cinmov, Null, " . as_txt($detmov) . ", " . as_num($total) . ", Now(), False, $estSql);");

        $ord = 0; $td = 0.0; $tc = 0.0;
        foreach ($val as $l) as_imp($ord, $td, $tc, $nummov, $l['codcue'], $l['debe'], $l['cre'], $l['codcdc']);

        db_commit();
        ok(array('nummov' => $nummov, 'cinmov' => $cinmov, 'total' => $total));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar el asiento: ' . $e->getMessage(), 500);
    }
}

/** Anular un asiento (admin). Revierte DEBCUE/CRECUE de cada cuenta + zera las imputaciones + ANUMOV. */
function anular() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    if (!auth_is_admin()) { fail('Solo un administrador puede anular asientos', 403); return; }
    $num = isset($_POST['nummov']) ? (int) $_POST['nummov'] : (isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0);
    $h = db_row("SELECT ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODORI='I';");
    if (!$h) { fail('Asiento no encontrado'); return; }
    if ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) { fail('El asiento ya está anulado'); return; }

    db_begin();
    try {
        foreach (db_query("SELECT CODCUE, DEBMOV, CREMOV FROM [Tbl Movimientos Imputaciones] WHERE NUMMOV=$num;") as $i) {
            $cc = db_esc((string) nz($i['CODCUE'], '')); if ($cc === '') continue;
            if ($i['DEBMOV'] !== null && $i['DEBMOV'] !== '') db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE - " . round((float) $i['DEBMOV'], 2) . " WHERE CODCUE='$cc';");
            if ($i['CREMOV'] !== null && $i['CREMOV'] !== '') db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE - " . round((float) $i['CREMOV'], 2) . " WHERE CODCUE='$cc';");
        }
        db_exec("UPDATE [Tbl Movimientos Imputaciones] SET DEBMOV=0, CREMOV=0 WHERE NUMMOV=$num;");
        db_exec("UPDATE [Tbl Movimientos] SET ANUMOV=True WHERE NUMMOV=$num;");
        db_commit();
        ok(array('anulado' => $num));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo anular el asiento: ' . $e->getMessage(), 500);
    }
}

/** Listado de asientos manuales (CODORI='I') filtrado por el modo + texto/fecha. */
function listar() {
    $q  = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sd = as_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
    $sh = as_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');
    $w  = "M.CODORI='I'" . preg_replace('/ESTMOV/', 'M.ESTMOV', as_estmov_w());
    if ($q !== '') {
        $qs = db_esc($q);
        $cond = "(M.DETMOV Like '%$qs%'";
        if (is_numeric($q)) $cond .= " OR M.CINMOV=" . (int) $q . " OR M.NUMMOV=" . (int) $q;
        $cond .= ")";
        $w .= " AND $cond";
    }
    if ($sd !== null) $w .= " AND M.FEXMOV >= $sd";
    if ($sh !== null) $w .= " AND M.FEXMOV <= $sh";
    $out = array();
    foreach (db_query("SELECT TOP 200 M.NUMMOV, M.CINMOV, M.FEXMOV, M.CODOPE, M.DETMOV, M.TOTMOV, M.ANUMOV, O.DENOPE
        FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Operaciones] AS O ON M.CODOPE=O.CODOPE
        WHERE $w ORDER BY M.FEXMOV DESC, M.NUMMOV DESC;") as $r) {
        $out[] = array(
            'NUMMOV'    => (int) $r['NUMMOV'],
            'NUMERO'    => str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
            'FECHA'     => fecha_serial($r['FEXMOV']),
            'OPERACION' => trim((string) nz($r['DENOPE'], '')),
            'DETALLE'   => trim((string) nz($r['DETMOV'], '')),
            'TOTAL'     => round((float) nz($r['TOTMOV'], 0), 2),
            'ANULADO'   => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1),
        );
    }
    ok($out);
}

/** Detalle de un asiento para cargarlo en el form en sólo-lectura (cabecera + imputaciones). */
function detalle() {
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT M.CINMOV, M.FEXMOV, M.CODOPE, M.DETMOV, M.TOTMOV, M.ANUMOV, O.DENOPE
        FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Operaciones] AS O ON M.CODOPE=O.CODOPE
        WHERE M.NUMMOV=$num AND M.CODORI='I';");
    if (!$h) { fail('Asiento no encontrado'); return; }
    $r = array(
        'NUMMOV'    => $num,
        'NUMERO'    => str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        'FEXISO'    => as_fiso($h['FEXMOV']),
        'CODOPE'    => (int) $h['CODOPE'],
        'OPERACION' => trim((string) nz($h['DENOPE'], '')),
        'DETMOV'    => trim((string) nz($h['DETMOV'], '')),
        'TOTAL'     => round((float) nz($h['TOTMOV'], 0), 2),
        'ANULADO'   => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1),
        'ANULABLE'  => (auth_is_admin() && !($h['ANUMOV'] === true || $h['ANUMOV'] == -1)),
        'lineas'    => array(),
    );
    foreach (db_query("SELECT I.CODCUE, I.DEBMOV, I.CREMOV, I.CODCDC, C.DENCUE, D.DENCDC
        FROM ([Tbl Movimientos Imputaciones] AS I LEFT JOIN [Tbl Cuentas Contables] AS C ON I.CODCUE=C.CODCUE)
        LEFT JOIN [Tbl Centros de Costo] AS D ON I.CODCDC=D.CODCDC
        WHERE I.NUMMOV=$num ORDER BY I.ORDMOV;") as $i) {
        $cu = trim((string) nz($i['CODCUE'], ''));
        $r['lineas'][] = array(
            'codcue'  => $cu,
            'cuenta'  => $cu . ' · ' . trim((string) nz($i['DENCUE'], '')),
            'codcdc'  => (int) nz($i['CODCDC'], 1),
            'centro'  => trim((string) nz($i['DENCDC'], '')),
            'debe'    => round((float) nz($i['DEBMOV'], 0), 2),
            'cre'     => round((float) nz($i['CREMOV'], 0), 2),
        );
    }
    ok($r);
}
