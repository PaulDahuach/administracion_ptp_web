<?php
/**
 * Cierre Diario de Caja — API. Porta Frm IC Cierres, pero REARMADO:
 * en vez de la cadena de saldos guardados (que se desincroniza cuando se corrige el
 * pasado), la posición de caja se DERIVA DEL LEDGER en el momento → rápida y siempre
 * exacta. Efectivo = saldo corrido de la cuenta CAJA (Rec Control.CACC_1); cheques en
 * cartera = saldo corrido de VALORES A DEPOSITAR (CACC_2). Todo ESTMOV=True (operativo).
 *   resumen?fecha= : posición de caja a una fecha (default = fecha del sistema FECAPE)
 *   cerrar         : avanza la fecha del sistema (FECAPE) + registra el cierre (auditoría)
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'resumen': resumen(); break;
        case 'cerrar':  cerrar();  break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function iso_to_serial($iso) {
    if (!$iso) return null;
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$d) return null;
    $base = new DateTime('1899-12-30');
    return (int) $base->diff($d)->days;
}
function serial_to_iso($s) {
    $d = new DateTime('1899-12-30'); $d->modify('+' . (int) $s . ' days');
    return $d->format('Y-m-d');
}
function caccs() {
    $r = db_row("SELECT CACC_1, CACC_2, FECAPE FROM [Rec Control];");
    return array('caja' => trim((string) $r['CACC_1']), 'val' => trim((string) $r['CACC_2']), 'fecape' => (int) $r['FECAPE']);
}
/** Posición de una cuenta a la fecha (serial): anterior (<f), día (=f), actual (<=f). ESTMOV=True. */
function posicion($cuenta, $f) {
    $cu = db_esc($cuenta);
    $a = db_row("SELECT SUM(MI.DEBMOV) AS d, SUM(MI.CREMOV) AS c FROM [Tbl Movimientos Imputaciones] AS MI
        INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV
        WHERE MI.CODCUE='$cu' AND M.ESTMOV=True AND M.FEXMOV<$f;");
    $d = db_row("SELECT SUM(MI.DEBMOV) AS d, SUM(MI.CREMOV) AS c FROM [Tbl Movimientos Imputaciones] AS MI
        INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV
        WHERE MI.CODCUE='$cu' AND M.ESTMOV=True AND M.FEXMOV=$f;");
    $ant = (float) nz($a['d'], 0) - (float) nz($a['c'], 0);
    $ing = (float) nz($d['d'], 0); $egr = (float) nz($d['c'], 0);
    return array('ant' => $ant, 'ing' => $ing, 'egr' => $egr, 'act' => $ant + $ing - $egr);
}
/** Movimientos del día imputados a la cuenta (detalle). */
function detalle($cuenta, $f) {
    $cu = db_esc($cuenta);
    return db_query("SELECT M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DENMOV, M.DETMOV, MI.DEBMOV, MI.CREMOV, MI.CODCHQ
        FROM [Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV
        WHERE MI.CODCUE='$cu' AND M.ESTMOV=True AND M.FEXMOV=$f ORDER BY M.NUMMOV;");
}
function comp_str($r) {
    $p = str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
    $n = str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
    return trim(nz($r['CICMOV'], '') . ' ' . nz($r['CIIMOV'], '') . ' ' . $p . '-' . $n);
}
function m2($n) { return number_format((float) $n, 2, '.', ','); }   // convención app

function resumen() {
    $c = caccs();
    $iso = isset($_GET['fecha']) && $_GET['fecha'] !== '' ? $_GET['fecha'] : serial_to_iso($c['fecape']);
    $f = iso_to_serial($iso);
    if ($f === null) { fail('Fecha inválida'); return; }

    $caja = posicion($c['caja'], $f);
    $val  = posicion($c['val'], $f);

    // retenciones / interdepósito de recibos (codope 480) del día — info extra (como el legacy)
    $ret = db_row("SELECT SUM(RT1MOV) AS r1, SUM(RT2MOV) AS r2, SUM(RT3MOV) AS r3, SUM(RT4MOV) AS r4
        FROM [Tbl Movimientos] WHERE FEXMOV=$f AND CODOPE=480 AND ESTMOV=True;");
    $totret = (float) nz($ret['r1'], 0) + (float) nz($ret['r2'], 0) + (float) nz($ret['r3'], 0) + (float) nz($ret['r4'], 0);
    $idp = db_row("SELECT SUM(CREMOV) AS cr, SUM(RT1MOV) AS r1, SUM(RT2MOV) AS r2, SUM(RT3MOV) AS r3, SUM(RT4MOV) AS r4
        FROM [Tbl Movimientos] WHERE FEXMOV=$f AND CODOPE=480 AND CODFDP=5 AND ESTMOV=True;");
    $totidp = (float) nz($idp['cr'], 0) - ((float) nz($idp['r1'], 0) + (float) nz($idp['r2'], 0) + (float) nz($idp['r3'], 0) + (float) nz($idp['r4'], 0));

    // detalle
    $efe = array();
    foreach (detalle($c['caja'], $f) as $r) $efe[] = array(
        'num' => (int) $r['NUMMOV'], 'comp' => comp_str($r), 'cta' => trim((string) nz($r['DENMOV'], '')),
        'det' => trim((string) nz($r['DETMOV'], '')), 'ing' => m2(nz($r['DEBMOV'], 0)), 'egr' => m2(nz($r['CREMOV'], 0)),
    );
    // bancos para los cheques (CODBAN→denom)
    $bancos = array();
    foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos];") as $b) $bancos[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));
    $chq = array();
    foreach (detalle($c['val'], $f) as $r) {
        $ban = ''; $nro = '';
        $cod = nz($r['CODCHQ'], '');
        if ($cod !== '') { $ch = db_row("SELECT CODBAN, SYNCHQ FROM [Tbl Cheques] WHERE CODCHQ=" . intval($cod) . ";");
            if ($ch) { $ban = isset($bancos[(int) $ch['CODBAN']]) ? $bancos[(int) $ch['CODBAN']] : ''; $nro = trim((string) nz($ch['SYNCHQ'], '')); } }
        $chq[] = array('num' => (int) $r['NUMMOV'], 'comp' => comp_str($r), 'banco' => $ban, 'nro' => $nro,
            'ing' => m2(nz($r['DEBMOV'], 0)), 'egr' => m2(nz($r['CREMOV'], 0)));
    }

    // cierre guardado para esa fecha (para mostrar la deriva guardado vs ledger)
    $stored = null;
    $sc = db_row("SELECT EFTACT, CHQACT, NOWCIE FROM [Tbl Cierres] WHERE FECCIE=#" . date('m/d/Y', strtotime($iso)) . "#;");
    if ($sc) $stored = array('eft' => m2($sc['EFTACT']), 'chq' => m2($sc['CHQACT']), 'now' => to_disp_date($sc['NOWCIE']));

    ok(array(
        'fecha'   => $iso,
        'fechaDisp' => to_disp_date($iso),
        'sysdate' => serial_to_iso($c['fecape']),
        'sysdateDisp' => to_disp_date(serial_to_iso($c['fecape'])),
        'esSistema' => ($f === $c['fecape']),
        'efectivo' => array('ant' => m2($caja['ant']), 'ing' => m2($caja['ing']), 'egr' => m2($caja['egr']), 'act' => m2($caja['act'])),
        'cheques'  => array('ant' => m2($val['ant']), 'ing' => m2($val['ing']), 'egr' => m2($val['egr']), 'act' => m2($val['act'])),
        'total'    => m2($caja['act'] + $val['act']),
        'totret'   => m2($totret),
        'totidp'   => m2($totidp),
        'detEfectivo' => $efe,
        'detCheques'  => $chq,
        'stored'   => $stored,
    ));
}

function cerrar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $c = caccs();
    $feccie = $c['fecape'];                       // se cierra la fecha del sistema actual
    $nuevaIso = isset($_POST['nueva']) ? $_POST['nueva'] : '';
    $nueva = iso_to_serial($nuevaIso);
    if ($nueva === null) { fail('Falta la nueva fecha del sistema'); return; }
    if ($nueva <= $feccie) { fail('La nueva fecha debe ser posterior a la fecha que se cierra (' . to_disp_date(serial_to_iso($feccie)) . ')'); return; }

    $caja = posicion($c['caja'], $feccie);
    $val  = posicion($c['val'], $feccie);
    $ret = db_row("SELECT SUM(RT1MOV) AS r1, SUM(RT2MOV) AS r2, SUM(RT3MOV) AS r3, SUM(RT4MOV) AS r4 FROM [Tbl Movimientos] WHERE FEXMOV=$feccie AND CODOPE=480 AND ESTMOV=True;");
    $totret = (float) nz($ret['r1'], 0) + (float) nz($ret['r2'], 0) + (float) nz($ret['r3'], 0) + (float) nz($ret['r4'], 0);
    $idp = db_row("SELECT SUM(CREMOV) AS cr, SUM(RT1MOV) AS r1, SUM(RT2MOV) AS r2, SUM(RT3MOV) AS r3, SUM(RT4MOV) AS r4 FROM [Tbl Movimientos] WHERE FEXMOV=$feccie AND CODOPE=480 AND CODFDP=5 AND ESTMOV=True;");
    $totidp = (float) nz($idp['cr'], 0) - ((float) nz($idp['r1'], 0) + (float) nz($idp['r2'], 0) + (float) nz($idp['r3'], 0) + (float) nz($idp['r4'], 0));

    $fc = date('m/d/Y', strtotime(serial_to_iso($feccie)));
    $fa = date('m/d/Y', strtotime($nuevaIso));
    $now = date('m/d/Y H:i:s');
    $vals = array(
        'FECCIE' => "#$fc#", 'EFTANT' => $caja['ant'], 'CHQANT' => $val['ant'],
        'INGDIA' => $caja['ing'], 'EFTDIA' => $caja['ing'] - $caja['egr'],
        'CHQDIA' => $val['ing'], 'EGRCAR' => $val['egr'],
        'TOTRET' => $totret, 'TOTIDP' => $totidp,
        'EFTACT' => $caja['act'], 'CHQACT' => $val['act'], 'NOWCIE' => "#$now#",
    );

    db_begin();
    try {
        $existe = db_row("SELECT FECCIE FROM [Tbl Cierres] WHERE FECCIE=#$fc#;");
        if ($existe) {
            $sets = array();
            foreach ($vals as $k => $v) if ($k !== 'FECCIE') $sets[] = "[$k]=" . (is_string($v) ? $v : (string) $v);
            db_exec("UPDATE [Tbl Cierres] SET " . implode(',', $sets) . " WHERE FECCIE=#$fc#;");
        } else {
            $cols = array_keys($vals);
            $vv = array();
            foreach ($vals as $v) $vv[] = is_string($v) ? $v : (string) $v;
            db_exec("INSERT INTO [Tbl Cierres] ([" . implode('],[', $cols) . "]) VALUES (" . implode(',', $vv) . ");");
        }
        db_exec("UPDATE [Rec Control] SET FECAPE=#$fa#;");
        db_commit();
    } catch (Exception $e) { db_rollback(); throw $e; }

    ok(array('cerrada' => serial_to_iso($feccie), 'nueva' => $nuevaIso, 'nuevaDisp' => to_disp_date($nuevaIso)));
}
