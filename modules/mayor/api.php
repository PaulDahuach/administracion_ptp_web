<?php
/**
 * Mayor x Cuenta (Imputaciones Contables) — API solo-lectura.
 * Porta "Imputaciones Contables x Cuenta". Para una cuenta contable y un período:
 * saldo anterior (INICUE + Σ DEB−CRE antes de 'desde') + asientos del período con
 * saldo corrido. Fecha = FEXMOV (comprobante, default) o FIXMOV (movimiento).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {
        case 'buscar_cuentas': buscar_cuentas(); break;
        case 'get_cuenta':     get_cuenta();     break;
        case 'mayor':          mayor();          break;
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

function date_field() {
    return (isset($_GET['fecha']) && $_GET['fecha'] === 'mov') ? 'FIXMOV' : 'FEXMOV';
}

function buscar_cuentas() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    $rows = db_query("SELECT TOP 30 CODCUE, DENCUE FROM [Tbl Cuentas Contables]
        WHERE IMPCUE=True AND ((CODCUE Like '%$s%') OR (DENCUE Like '%$s%')) ORDER BY CODCUE");
    ok($rows);
}

function get_cuenta() {
    $cc = isset($_GET['codcue']) ? db_esc(trim($_GET['codcue'])) : '';
    if ($cc === '') { fail('codcue requerido'); return; }
    $row = db_row("SELECT CODCUE, DENCUE, IMPCUE, INICUE, DEBCUE, CRECUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc'");
    if (!$row) { fail('Cuenta no encontrada'); return; }
    ok($row);
}

function mayor() {
    $cc = isset($_GET['codcue']) ? db_esc(trim($_GET['codcue'])) : '';
    if ($cc === '') { fail('codcue requerido'); return; }
    $desde = isset($_GET['desde']) ? $_GET['desde'] : '';
    $hasta = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $df = date_field();
    $sd = iso_to_serial($desde);
    $sh = iso_to_serial($hasta);

    $cta = db_row("SELECT CODCUE, DENCUE, INICUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc'");
    if (!$cta) { fail('Cuenta no encontrada'); return; }
    $inicue = (float) nz($cta['INICUE'], 0);

    // Centros de costo (map)
    $cdc = array();
    foreach (db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo]") as $x)
        $cdc[(int) $x['CODCDC']] = trim((string) nz($x['DENCDC'], ''));

    // Saldo anterior = INICUE + Σ(DEB−CRE) antes de 'desde'
    $saldoAnterior = $inicue;
    if ($sd !== null) {
        $r = db_row("SELECT SUM(MI.DEBMOV) AS D, SUM(MI.CREMOV) AS C
            FROM [Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV = MI.NUMMOV
            WHERE MI.CODCUE='$cc' AND M.$df < $sd");
        $saldoAnterior += (float) nz($r['D'], 0) - (float) nz($r['C'], 0);
    }

    // Asientos del período
    $w = "MI.CODCUE='$cc'";
    if ($sd !== null) $w .= " AND M.$df >= $sd";
    if ($sh !== null) $w .= " AND M.$df <= $sh";

    $rows = db_query("SELECT MI.NUMMOV, MI.ORDMOV, MI.DEBMOV, MI.CREMOV, MI.CODCDC,
        M.$df AS FECHA, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.CODOPE, M.DENMOV
        FROM [Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV = MI.NUMMOV
        WHERE $w ORDER BY M.$df, MI.NUMMOV, MI.ORDMOV");

    $movs = array();
    $totalDebe = 0; $totalHaber = 0;
    $saldo = $saldoAnterior;
    foreach ($rows as $m) {
        $debe = (float) nz($m['DEBMOV'], 0);
        $haber = (float) nz($m['CREMOV'], 0);
        $saldo += $debe - $haber;
        $totalDebe += $debe; $totalHaber += $haber;

        $cic = trim((string) nz($m['CICMOV'], ''));
        $cii = trim((string) nz($m['CIIMOV'], ''));
        $pdv = str_pad((string) (int) nz($m['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
        $nro = str_pad((string) (int) nz($m['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
        $comp = $cic !== '' ? ($cic . ($cii !== '' ? ' ' . $cii : '') . ' ' . $pdv . '-' . $nro) : '';
        $cdcId = (int) nz($m['CODCDC'], 0);

        $movs[] = array(
            'NUMMOV' => (int) $m['NUMMOV'],
            'FECHA'  => fecha_serial($m['FECHA']),
            'COMP'   => $comp,
            'DETALLE'=> trim((string) nz($m['DENMOV'], '')),
            'CDC'    => isset($cdc[$cdcId]) ? $cdc[$cdcId] : '',
            'DEBE'   => $debe > 0 ? number_format($debe, 2, '.', '') : '',
            'HABER'  => $haber > 0 ? number_format($haber, 2, '.', '') : '',
            'SALDO'  => number_format($saldo, 2, '.', ''),
        );
    }

    ok(array(
        'cuenta'        => array('codcue' => trim((string) $cta['CODCUE']), 'den' => trim((string) nz($cta['DENCUE'], ''))),
        'saldoAnterior' => number_format($saldoAnterior, 2, '.', ''),
        'movimientos'   => $movs,
        'totalDebe'     => number_format($totalDebe, 2, '.', ''),
        'totalHaber'    => number_format($totalHaber, 2, '.', ''),
        'saldo'         => number_format($saldo, 2, '.', ''),
    ));
}
