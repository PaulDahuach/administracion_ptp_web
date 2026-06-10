<?php
/**
 * Conciliación Bancaria (Frm IC Conciliacion) — API. Marca movimientos de una cuenta bancaria como
 * conciliados (MI.CONMOV=True) hasta una fecha de corte, y actualiza la cuenta contable:
 * SACCUE (saldo conciliado), UCDCUE (fecha en que se concilió = FECAPE), UCHCUE (fecha de corte).
 * Versión readwrite del módulo Bancos. Opera en el libro operativo (ESTMOV=True). dev=copia readwrite.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'cuentas':   cuentas();   break;
        case 'listar':    listar();    break;
        case 'conciliar': conciliar(); break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function co_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function co_n($v) { return number_format((float) $v, 2, '.', ','); }

/** Cuentas bancarias (Cuentas Contables con CODCBX). */
function cuentas() {
    ok(db_query("SELECT CODCUE AS id, DENCUE AS den FROM [Tbl Cuentas Contables] WHERE CODCBX Is Not Null ORDER BY DENCUE;"));
}

/** Movimientos pendientes de conciliar de una cuenta hasta la fecha de corte + saldos. */
function listar() {
    $cc = isset($_GET['cuenta']) ? db_esc(trim($_GET['cuenta'])) : '';
    if ($cc === '') { fail('Elegí una cuenta'); return; }
    $sh = co_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');
    if ($sh === null) { fail('Falta la fecha de corte'); return; }
    $sd = co_serial(isset($_GET['desde']) ? $_GET['desde'] : '');   // opcional: acota el período
    $TOP = 500;

    $cta = db_row("SELECT CODCUE, DENCUE, INICUE, SACCUE, UCHCUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
    if (!$cta) { fail('Cuenta no encontrada'); return; }
    $inicue = (float) nz($cta['INICUE'], 0);

    // Saldo operativo = INICUE + Σ(DEB−CRE) de TODO el ledger (operativo). Saldo conciliado = ídem sólo CONMOV=True.
    $tot = db_row("SELECT SUM(MI.DEBMOV) AS D, SUM(MI.CREMOV) AS C FROM [Tbl Movimientos Imputaciones] AS MI
        INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV WHERE MI.CODCUE='$cc' AND M.ESTMOV=True;");
    $saldoOper = $inicue + (float) nz($tot['D'], 0) - (float) nz($tot['C'], 0);
    $con = db_row("SELECT SUM(MI.DEBMOV) AS D, SUM(MI.CREMOV) AS C FROM [Tbl Movimientos Imputaciones] AS MI
        INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV WHERE MI.CODCUE='$cc' AND M.ESTMOV=True AND MI.CONMOV=True;");
    $saldoConc = $inicue + (float) nz($con['D'], 0) - (float) nz($con['C'], 0);

    $ban = array();
    foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos];") as $b) $ban[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));

    // Pendientes (CONMOV=False) en el período, operativo. WHERE común + TOP (los más viejos primero).
    $wPend = "MI.CODCUE='$cc' AND M.ESTMOV=True AND (MI.CONMOV=False OR MI.CONMOV Is Null) AND M.FEXMOV <= $sh";
    if ($sd !== null) $wPend .= " AND M.FEXMOV >= $sd";
    $tp = db_row("SELECT COUNT(*) AS n FROM [Tbl Movimientos Imputaciones] AS MI
        INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV WHERE $wPend;");
    $totalPend = (int) nz($tp['n'], 0);
    $rows = db_query("SELECT TOP $TOP MI.NUMMOV, MI.ORDMOV, MI.DEBMOV, MI.CREMOV, MI.FAXMOV AS FACR, MI.CODCHQ,
        M.FEXMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DETMOV, M.DENMOV, M.CITMOV, C.SYNCHQ, C.CODBAN AS CHQBAN, C.LIBCHQ
        FROM (([Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV)
          LEFT JOIN [Tbl Cheques] AS C ON C.CODCHQ=MI.CODCHQ)
        WHERE $wPend ORDER BY M.FEXMOV, MI.NUMMOV, MI.ORDMOV;");

    $movs = array();
    foreach ($rows as $m) {
        $debe = (float) nz($m['DEBMOV'], 0); $haber = (float) nz($m['CREMOV'], 0);
        $cic = trim((string) nz($m['CICMOV'], '')); $cii = trim((string) nz($m['CIIMOV'], ''));
        $pdv = str_pad((string) (int) nz($m['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
        $nro = str_pad((string) (int) nz($m['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
        $comp = $cic !== '' ? ($cic . ($cii !== '' ? ' ' . $cii : '') . ' ' . $pdv . '-' . $nro) : '';
        $syn = trim((string) nz($m['SYNCHQ'], '')); $chqban = (int) nz($m['CHQBAN'], 0);
        $cheque = $syn !== '' ? (($chqban && isset($ban[$chqban]) ? $ban[$chqban] . ' ' : '') . $syn) : '';
        $libr = trim((string) nz($m['LIBCHQ'], ''));
        $movs[] = array(
            'nummov' => (int) $m['NUMMOV'], 'ordmov' => (int) $m['ORDMOV'],
            'fecha'  => fecha_serial($m['FEXMOV']), 'facr' => fecha_serial($m['FACR']),
            'comp'   => $comp, 'cheque' => $cheque,
            'detalle' => trim((string) nz($m['DENMOV'], nz($m['DETMOV'], ''))),
            'librador' => $libr,
            'debe'   => $debe, 'haber' => $haber,
        );
    }

    ok(array(
        'den' => trim((string) nz($cta['DENCUE'], '')),
        'saldoOper' => co_n($saldoOper), 'saldoConc' => co_n($saldoConc),
        'ultima' => fecha_serial($cta['UCHCUE']),
        'movimientos' => $movs, 'total' => $totalPend, 'top' => $TOP,
    ));
}

/** Marca como conciliados los movimientos enviados + actualiza la cuenta (SACCUE/UCDCUE/UCHCUE). */
function conciliar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $cc = isset($_POST['cuenta']) ? db_esc(trim($_POST['cuenta'])) : '';
    $sh = co_serial(isset($_POST['hasta']) ? $_POST['hasta'] : '');
    $items = json_decode(isset($_POST['items']) ? $_POST['items'] : '', true);
    if ($cc === '') { fail('Falta la cuenta'); return; }
    if ($sh === null) { fail('Falta la fecha de corte'); return; }
    if (!is_array($items) || !count($items)) { fail('No hay movimientos tildados'); return; }

    db_begin();
    try {
        foreach ($items as $it) {
            $num = (int) nz($it['nummov'], 0); $ord = (int) nz($it['ordmov'], 0);
            if ($num <= 0) continue;
            db_exec("UPDATE [Tbl Movimientos Imputaciones] SET CONMOV=True, TMPMOV=False
                WHERE NUMMOV=$num AND ORDMOV=$ord AND CODCUE='$cc';");
        }
        // nuevo saldo conciliado = INICUE + Σ(DEB−CRE) de los conciliados (operativo)
        $cta = db_row("SELECT INICUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
        $inicue = (float) nz($cta ? $cta['INICUE'] : 0, 0);
        $con = db_row("SELECT SUM(MI.DEBMOV) AS D, SUM(MI.CREMOV) AS C FROM [Tbl Movimientos Imputaciones] AS MI
            INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV=MI.NUMMOV WHERE MI.CODCUE='$cc' AND M.ESTMOV=True AND MI.CONMOV=True;");
        $saldoConc = $inicue + (float) nz($con['D'], 0) - (float) nz($con['C'], 0);
        $fecape = (int) nz(db_row("SELECT FECAPE FROM [Rec Control];")['FECAPE'], 0);
        db_exec("UPDATE [Tbl Cuentas Contables] SET SACCUE=" . round($saldoConc, 2) . ", UCDCUE=$fecape, UCHCUE=$sh WHERE CODCUE='$cc';");
        db_commit();
        ok(array('saldoConc' => co_n($saldoConc), 'conciliados' => count($items)));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo conciliar: ' . $e->getMessage(), 500);
    }
}
