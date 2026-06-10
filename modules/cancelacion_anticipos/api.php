<?php
/**
 * Cancelación de Anticipos (Acreedores) — API. Porta Frm CA Cancelacion de Anticipos.
 * Aplica anticipos/acreditaciones del proveedor (OP/ND con SDOMOV>0) contra comprobantes pendientes
 * (CP/FC/ND con vencimientos adeudados). Grabación (CODOPE=350, CICMOV='CA'):
 *  - Header en [Tbl Movimientos] (DEB=CRE=TOT, SDOMOV=0).
 *  - [Tbl Movimientos Anticipos] (link) + baja SDOMOV de cada anticipo.
 *  - [Tbl Movimientos Referencias] (link) + sube DEBMOV del vencimiento + SDOMOV del comprobante.
 *  - Asiento: Debe PROVEEDORES (CACC_K) / Haber ANTICIPOS A PROVEEDORES (CACC_L) + mayoriza DEBCUE/CRECUE.
 *  - Cta cte: SANCUE -= TOT, FUOCUE = fecha.
 * Σ anticipos aplicados DEBE igualar Σ referencias aplicadas. FVXMOV = serial numérico.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'proveedores': proveedores(); break;
        case 'datos':       datos();       break;
        case 'guardar':     guardar();     break;
        case 'listar':      listar();      break;
        case 'detalle':     detalle();     break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function ca_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function ca_n($v) { return (string) round((float) $v, 2); }
function ca_disp($v) { return number_format((float) $v, 2, '.', ','); }
function ca_estTrue() { return auth_modo() !== 'capacitacion'; }
function ca_comp($cic, $cii, $cip, $cin) {
    $cic = trim((string) nz($cic, '')); if ($cic === '') return '';
    return $cic . ($cii !== null && trim((string) $cii) !== '' ? ' ' . trim((string) $cii) : '') . ' '
        . str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT);
}

function proveedores() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q); $num = is_numeric($q) ? ' OR CODCUE=' . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE AS id, DENCUE AS den, CITCUE AS cod FROM [Tbl Cuentas Corrientes]
        WHERE CODORI='A' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

/** Saldos del proveedor + anticipos disponibles + comprobantes pendientes (vencimientos adeudados). */
function datos() {
    $cc = (int) (isset($_GET['codcue']) ? $_GET['codcue'] : 0);
    if ($cc <= 0) { fail('Falta el proveedor'); return; }
    $cta = db_row("SELECT DENCUE, SOPCUE, SANCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$cc AND CODORI='A';");
    if (!$cta) { fail('Proveedor no encontrado'); return; }
    $est = ca_estTrue() ? 'True' : 'False';

    $ant = array();
    foreach (db_query("SELECT NUMMOV, CICMOV, CIIMOV, CIPMOV, CINMOV, FEXMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV, DETMOV, SDOMOV
        FROM [Tbl Movimientos] WHERE CODOPE IN (340,330) AND (IIf(IsNull(SDOMOV),0,SDOMOV))>0 AND CODCUE=$cc AND ESTMOV=$est
        ORDER BY FEXMOV, NUMMOV;") as $r) {
        $ant[] = array(
            'nummov' => (int) $r['NUMMOV'],
            'interno' => ca_comp($r['CICMOV'], $r['CIIMOV'], $r['CIPMOV'], $r['CINMOV']),
            'emision' => fecha_serial($r['FEXMOV']),
            'externo' => ca_comp($r['CECMOV'], $r['CEIMOV'], $r['CEPMOV'], $r['CENMOV']),
            'detalle' => trim((string) nz($r['DETMOV'], '')),
            'saldo' => round((float) nz($r['SDOMOV'], 0), 2),
        );
    }

    $cmp = array();
    foreach (db_query("SELECT M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.FEXMOV, M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV, M.CEFMOV, M.DETMOV,
        V.FVXMOV, V.DEBMOV, V.CREMOV
        FROM [Tbl Movimientos] AS M INNER JOIN [Tbl Movimientos Vencimientos] AS V ON M.NUMMOV=V.NUMMOV
        WHERE M.CODCUE=$cc AND M.ESTMOV=$est AND (IIf(IsNull(V.DEBMOV),0,V.DEBMOV)-IIf(IsNull(V.CREMOV),0,V.CREMOV))<0
        ORDER BY V.FVXMOV, M.NUMMOV;") as $r) {
        $saldo = round((float) nz($r['DEBMOV'], 0) - (float) nz($r['CREMOV'], 0), 2);   // negativo = adeudado
        $cmp[] = array(
            'nummov' => (int) $r['NUMMOV'], 'fvx' => (int) $r['FVXMOV'],
            'vencimiento' => fecha_serial($r['FVXMOV']),
            'interno' => ca_comp($r['CICMOV'], $r['CIIMOV'], $r['CIPMOV'], $r['CINMOV']),
            'emision' => fecha_serial($r['FEXMOV']),
            'externo' => ca_comp($r['CECMOV'], $r['CEIMOV'], $r['CEPMOV'], $r['CENMOV']),
            'detalle' => trim((string) nz($r['DETMOV'], '')),
            'saldo' => abs($saldo),   // monto adeudado (positivo)
        );
    }

    ok(array(
        'den' => trim((string) nz($cta['DENCUE'], '')),
        'sopcue' => ca_disp($cta['SOPCUE']), 'sancue' => ca_disp($cta['SANCUE']),
        'anticipos' => $ant, 'comprobantes' => $cmp,
    ));
}

function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $d = json_decode(isset($_POST['data']) ? $_POST['data'] : '', true);
    if (!is_array($d)) { fail('Datos inválidos'); return; }
    $cc = (int) nz($d['codcue'], 0);
    $fex = ca_serial(isset($d['fexmov']) ? $d['fexmov'] : '');
    $ants = (isset($d['anticipos']) && is_array($d['anticipos'])) ? $d['anticipos'] : array();
    $refs = (isset($d['referencias']) && is_array($d['referencias'])) ? $d['referencias'] : array();
    if ($cc <= 0) { fail('Falta el proveedor'); return; }
    if ($fex === null) { fail('Falta la fecha'); return; }

    // normalizar + totales
    $aList = array(); $totA = 0;
    foreach ($ants as $a) { $n = (int) nz($a['nummov'], 0); $imp = round((float) nz($a['imp'], 0), 2); if ($n > 0 && $imp > 0) { $aList[] = array($n, $imp); $totA += $imp; } }
    $rList = array(); $totR = 0;
    foreach ($refs as $r) { $n = (int) nz($r['nummov'], 0); $fv = (int) nz($r['fvx'], 0); $imp = round((float) nz($r['imp'], 0), 2); if ($n > 0 && $imp > 0) { $rList[] = array($n, $fv, $imp); $totR += $imp; } }
    $totA = round($totA, 2); $totR = round($totR, 2);
    if (!count($aList)) { fail('No hay anticipos aplicados'); return; }
    if (!count($rList)) { fail('No hay comprobantes referenciados'); return; }
    if (abs($totA - $totR) > 0.005) { fail('El total de anticipos (' . ca_disp($totA) . ') no coincide con el de comprobantes (' . ca_disp($totR) . ')'); return; }
    $tot = $totA;

    $cta = db_row("SELECT DENCUE, SOPCUE, SANCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$cc AND CODORI='A';");
    if (!$cta) { fail('Proveedor inexistente'); return; }
    $rc = db_row("SELECT CACC_K, CACC_L FROM [Rec Control];");
    $ckK = db_esc(trim((string) $rc['CACC_K']));   // PROVEEDORES (debe)
    $ckL = db_esc(trim((string) $rc['CACC_L']));   // ANTICIPOS A PROVEEDORES (haber)
    $est = ca_estTrue() ? 'True' : 'False';

    db_begin();
    try {
        // validar saldos disponibles antes de tocar nada
        foreach ($aList as $a) {
            $m = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV={$a[0]} AND CODCUE=$cc AND CODOPE IN (340,330);");
            if (!$m) { db_rollback(); fail("Anticipo {$a[0]} inválido"); return; }
            if (round((float) nz($m['SDOMOV'], 0), 2) + 0.005 < $a[1]) { db_rollback(); fail("El anticipo {$a[0]} no tiene saldo suficiente"); return; }
        }

        $nummov = next_number('ULTMOV');
        $den = "'" . db_esc(trim((string) nz($cta['DENCUE'], ''))) . "'";
        // Header: CODORI='A', CODOPE=350, CICMOV='CA' (interno y externo CA, sin numerar: CIP/CIN=0)
        db_exec("INSERT INTO [Tbl Movimientos]
            (NUMMOV, CODORI, CODOPE, FEXMOV, CICMOV, CIPMOV, CINMOV, CECMOV, CEPMOV, CENMOV, CEFMOV,
             CODCUE, DENMOV, SOCMOV, SACMOV, TOTMOV, DEBMOV, CREMOV, SDOMOV, NUIMOV, NOWMOV, ANUMOV, ESTMOV)
            VALUES ($nummov, 'A', 350, $fex, 'CA', 0, 0, 'CA', 0, 0, $fex,
             $cc, $den, " . ca_n($cta['SOPCUE']) . ", " . ca_n($cta['SANCUE']) . ", " . ca_n($tot) . ", " . ca_n($tot) . ", " . ca_n($tot) . ", 0, " .
            ((isset($_SESSION['uid']) && $_SESSION['uid'] !== '') ? "'" . db_esc((string) $_SESSION['uid']) . "'" : 'Null') . ", Now(), False, $est);");

        // Anticipos: link + baja SDOMOV del anticipo
        foreach ($aList as $a) {
            db_exec("INSERT INTO [Tbl Movimientos Anticipos] (NUMMOV, ANTMOV, IMPMOV) VALUES ($nummov, {$a[0]}, " . ca_n($a[1]) . ");");
            db_exec("UPDATE [Tbl Movimientos] SET SDOMOV = IIf(IsNull(SDOMOV),0,SDOMOV) - " . ca_n($a[1]) . " WHERE NUMMOV={$a[0]};");
        }
        // Referencias: link + sube DEBMOV del vencimiento + SDOMOV del comprobante
        foreach ($rList as $r) {
            db_exec("INSERT INTO [Tbl Movimientos Referencias] (NUMMOV, REFMOV, FVXMOV, IMPMOV) VALUES ($nummov, {$r[0]}, {$r[1]}, " . ca_n($r[2]) . ");");
            db_exec("UPDATE [Tbl Movimientos Vencimientos] SET DEBMOV = IIf(IsNull(DEBMOV),0,DEBMOV) + " . ca_n($r[2]) . " WHERE NUMMOV={$r[0]} AND FVXMOV={$r[1]};");
            db_exec("UPDATE [Tbl Movimientos] SET SDOMOV = IIf(IsNull(SDOMOV),0,SDOMOV) + " . ca_n($r[2]) . " WHERE NUMMOV={$r[0]};");
        }
        // Asiento: Debe PROVEEDORES (CACC_K) / Haber ANTICIPOS A PROVEEDORES (CACC_L) + mayoriza
        $socK = db_row("SELECT DEBCUE-CRECUE AS s FROM [Tbl Cuentas Contables] WHERE CODCUE='$ckK';");
        $socL = db_row("SELECT DEBCUE-CRECUE AS s FROM [Tbl Cuentas Contables] WHERE CODCUE='$ckL';");
        db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, DEBMOV, SOCMOV, CODCDC) VALUES ($nummov, 1, '$ckK', " . ca_n($tot) . ", " . ca_n(nz($socK['s'], 0)) . ", 1);");
        db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, CREMOV, SOCMOV, CODCDC) VALUES ($nummov, 2, '$ckL', " . ca_n($tot) . ", " . ca_n(nz($socL['s'], 0)) . ", 1);");
        db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = IIf(IsNull(DEBCUE),0,DEBCUE) + " . ca_n($tot) . " WHERE CODCUE='$ckK';");
        db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = IIf(IsNull(CRECUE),0,CRECUE) + " . ca_n($tot) . " WHERE CODCUE='$ckL';");
        // Cta cte: baja el saldo de anticipos
        db_exec("UPDATE [Tbl Cuentas Corrientes] SET SANCUE = IIf(IsNull(SANCUE),0,SANCUE) - " . ca_n($tot) . ", FUOCUE = $fex WHERE CODCUE=$cc;");

        db_commit();
        ok(array('nummov' => $nummov, 'total' => ca_disp($tot)));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar la cancelación: ' . $e->getMessage(), 500);
    }
}

/** Listado de cancelaciones (CODOPE=350) + texto/fecha. */
function listar() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sd = ca_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
    $sh = ca_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');
    $w = 'CODOPE=350';
    if ($q !== '') { $qs = db_esc($q); $w .= " AND (DENMOV Like '%$qs%'" . (is_numeric($q) ? " OR NUMMOV=" . (int) $q : '') . ")"; }
    if ($sd !== null) $w .= " AND FEXMOV >= $sd";
    if ($sh !== null) $w .= " AND FEXMOV <= $sh";
    $out = array();
    foreach (db_query("SELECT TOP 200 NUMMOV, FEXMOV, CODCUE, DENMOV, TOTMOV, DETMOV, ANUMOV FROM [Tbl Movimientos] WHERE $w ORDER BY FEXMOV DESC, NUMMOV DESC;") as $r) {
        $out[] = array('NUMMOV' => (int) $r['NUMMOV'], 'FECHA' => fecha_serial($r['FEXMOV']),
            'PROVEEDOR' => trim((string) nz($r['DENMOV'], '')), 'TOTAL' => ca_disp($r['TOTMOV']),
            'DETALLE' => trim((string) nz($r['DETMOV'], '')), 'ANULADO' => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1));
    }
    ok($out);
}

/** Detalle de una cancelación (cabecera + anticipos + referencias) para verla en sólo-lectura. */
function detalle() {
    $num = (int) (isset($_GET['nummov']) ? $_GET['nummov'] : 0);
    $h = db_row("SELECT FEXMOV, CODCUE, DENMOV, TOTMOV, DETMOV, ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=350;");
    if (!$h) { fail('Cancelación no encontrada'); return; }
    $r = array('NUMMOV' => $num, 'FECHA' => fecha_serial($h['FEXMOV']), 'PROVEEDOR' => trim((string) nz($h['DENMOV'], '')),
        'TOTAL' => ca_disp($h['TOTMOV']), 'DETALLE' => trim((string) nz($h['DETMOV'], '')),
        'ANULADO' => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1), 'anticipos' => array(), 'referencias' => array());
    foreach (db_query("SELECT A.ANTMOV, A.IMPMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.FEXMOV
        FROM [Tbl Movimientos Anticipos] AS A INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=A.ANTMOV WHERE A.NUMMOV=$num;") as $a)
        $r['anticipos'][] = array('comp' => ca_comp($a['CICMOV'], $a['CIIMOV'], $a['CIPMOV'], $a['CINMOV']), 'fecha' => fecha_serial($a['FEXMOV']), 'imp' => ca_disp($a['IMPMOV']));
    foreach (db_query("SELECT R.REFMOV, R.FVXMOV, R.IMPMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV
        FROM [Tbl Movimientos Referencias] AS R INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=R.REFMOV WHERE R.NUMMOV=$num;") as $x)
        $r['referencias'][] = array('comp' => ca_comp($x['CICMOV'], $x['CIIMOV'], $x['CIPMOV'], $x['CINMOV']),
            'externo' => ca_comp($x['CECMOV'], $x['CEIMOV'], $x['CEPMOV'], $x['CENMOV']),
            'vencimiento' => fecha_serial($x['FVXMOV']), 'imp' => ca_disp($x['IMPMOV']));
    ok($r);
}
