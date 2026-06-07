<?php
/**
 * Bancos / Conciliación — API solo-lectura.
 * Ledger de una cuenta contable bancaria (las que tienen CODCBX): movimientos
 * (imputaciones) con débito/crédito, saldo corrido, detalle de cheque (vía CODCHQ)
 * y estado de conciliación (CONMOV). Fecha = FEXMOV (comprobante) o FAXMOV (acreditación).
 * Porta "Movimientos Bancarios" + Frm IC Conciliación.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {
        case 'list': listar(); break;
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

function listar() {
    $cc = isset($_GET['codcue']) ? db_esc(trim($_GET['codcue'])) : '';
    if ($cc === '') { fail('Elegí una cuenta bancaria'); return; }
    $desde  = isset($_GET['desde']) ? $_GET['desde'] : '';
    $hasta  = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $estado = isset($_GET['estado']) ? $_GET['estado'] : 'todos';
    $df = (isset($_GET['base']) && $_GET['base'] === 'acred') ? 'FAXMOV_REF' : 'FEXMOV'; // FAXMOV está en MI
    $sd = iso_to_serial($desde);
    $sh = iso_to_serial($hasta);

    $cta = db_row("SELECT CODCUE, DENCUE, INICUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc'");
    if (!$cta) { fail('Cuenta no encontrada'); return; }
    $inicue = (float) nz($cta['INICUE'], 0);

    // Doble libro: filtra por el ESTMOV del movimiento padre según el modo activo. INICUE (oficial)
    // se atribuye al libro blanco (Operador lo incluye, Capacitación no).
    $lib  = auth_libro_unico();
    $estM = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'capacitacion') ? ' AND M.ESTMOV=False' : '');
    $incluyeIni = ($lib !== 'capacitacion');

    // Campo de fecha: FEXMOV vive en M; FAXMOV vive en MI (fecha de acreditación)
    $dateExpr = ($df === 'FAXMOV_REF') ? 'MI.FAXMOV' : 'M.FEXMOV';

    // Bancos (para nombre del cheque)
    $ban = array();
    foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos]") as $b)
        $ban[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));

    // Saldo anterior = (INICUE si corresponde) + Σ(DEB−CRE) antes de 'desde'
    $saldoAnterior = $incluyeIni ? $inicue : 0;
    if ($sd !== null) {
        $r = db_row("SELECT SUM(MI.DEBMOV) AS D, SUM(MI.CREMOV) AS C
            FROM [Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV = MI.NUMMOV
            WHERE MI.CODCUE='$cc' AND $dateExpr < $sd$estM");
        $saldoAnterior += (float) nz($r['D'], 0) - (float) nz($r['C'], 0);
    }

    $w = "MI.CODCUE='$cc'$estM";
    if ($sd !== null) $w .= " AND $dateExpr >= $sd";
    if ($sh !== null) $w .= " AND $dateExpr <= $sh";
    if ($estado === 'conciliados')    $w .= " AND MI.CONMOV=True";
    elseif ($estado === 'pendientes') $w .= " AND MI.CONMOV=False";

    $rows = db_query("SELECT MI.NUMMOV, MI.DEBMOV, MI.CREMOV, MI.CONMOV, MI.FAXMOV AS FACR, MI.CODCHQ,
        M.FEXMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DETMOV, M.DENMOV, C.SYNCHQ, C.CODBAN AS CHQBAN
        FROM (([Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV = MI.NUMMOV)
          LEFT JOIN [Tbl Cheques] AS C ON C.CODCHQ = MI.CODCHQ)
        WHERE $w ORDER BY $dateExpr, MI.NUMMOV, MI.ORDMOV");

    $movs = array();
    $totalDebe = 0; $totalHaber = 0; $pendN = 0; $pendNeto = 0;
    $saldo = $saldoAnterior;
    foreach ($rows as $m) {
        $debe = (float) nz($m['DEBMOV'], 0);
        $haber = (float) nz($m['CREMOV'], 0);
        $saldo += $debe - $haber;
        $totalDebe += $debe; $totalHaber += $haber;
        $con = ($m['CONMOV'] === true || $m['CONMOV'] == -1);
        if (!$con) { $pendN++; $pendNeto += $debe - $haber; }

        $cic = trim((string) nz($m['CICMOV'], ''));
        $cii = trim((string) nz($m['CIIMOV'], ''));
        $pdv = str_pad((string) (int) nz($m['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
        $nro = str_pad((string) (int) nz($m['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
        $comp = $cic !== '' ? ($cic . ($cii !== '' ? ' ' . $cii : '') . ' ' . $pdv . '-' . $nro) : '';
        $syn = trim((string) nz($m['SYNCHQ'], ''));
        $chqban = (int) nz($m['CHQBAN'], 0);
        $cheque = $syn !== '' ? (($chqban && isset($ban[$chqban]) ? $ban[$chqban] . ' ' : '') . $syn) : '';

        $movs[] = array(
            'NUMMOV' => (int) $m['NUMMOV'],
            'FECHA'  => fecha_serial($m['FEXMOV']),
            'FACR'   => fecha_serial($m['FACR']),
            'COMP'   => $comp,
            'DETALLE'=> trim((string) nz($m['DENMOV'], nz($m['DETMOV'], ''))),
            'CHEQUE' => $cheque,
            'DEBE'   => $debe > 0 ? number_format($debe, 2, '.', '') : '',
            'HABER'  => $haber > 0 ? number_format($haber, 2, '.', '') : '',
            'SALDO'  => number_format($saldo, 2, '.', ''),
            'CONC'   => $con ? 1 : 0,
        );
    }

    ok(array(
        'cuenta'        => array('codcue' => trim((string) $cta['CODCUE']), 'den' => trim((string) nz($cta['DENCUE'], ''))),
        'saldoAnterior' => number_format($saldoAnterior, 2, '.', ''),
        'movimientos'   => $movs,
        'totalDebe'     => number_format($totalDebe, 2, '.', ''),
        'totalHaber'    => number_format($totalHaber, 2, '.', ''),
        'saldo'         => number_format($saldo, 2, '.', ''),
        'pendCant'      => $pendN,
        'pendNeto'      => number_format($pendNeto, 2, '.', ''),
    ));
}
