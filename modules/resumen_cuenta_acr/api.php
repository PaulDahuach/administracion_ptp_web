<?php
/**
 * Resumen de Cuenta (Acreedores / Proveedores) — API solo-lectura.
 * Espejo de resumen_cuenta (deudores) con CODORI='A'.
 * Ops que mueven cta cte: 310=CP (compra), 320=NC, 330=ND, 340=OP (pago), 350=Canc.Anticipos.
 * Saldo = Σ(DEBMOV − CREMOV) — igual que deudores; aquí el saldo NEGATIVO = le debemos
 * al proveedor (validado vs SOPCUE: 42/45). ESTMOV = dual-ledger blanco(-1)/negro(0).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

define('OPS_CC', '310,320,330,340,350');

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {
        case 'buscar_clientes': buscar_clientes(); break;
        case 'get_cliente':     get_cliente();     break;
        case 'resumen':         resumen();         break;
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

function buscar_clientes() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 2) { ok(array()); return; }
    $s = db_esc($q);
    $rows = db_query("SELECT TOP 30 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes]
        WHERE CODORI='A' AND ((DENCUE Like '%$s%') OR (CITCUE Like '%$s%')) ORDER BY DENCUE");
    ok($rows);
}

function get_cliente() {
    $codcue = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    if (!$codcue) { fail('codcue requerido'); return; }
    $row = db_row("SELECT CODCUE, DENCUE, CITCUE, SOPCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='A'");
    if (!$row) { fail('Proveedor no encontrado'); return; }
    ok($row);
}

function resumen() {
    $codcue = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $desde  = isset($_GET['desde']) ? $_GET['desde'] : '';
    $hasta  = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $libro  = isset($_GET['libro']) ? $_GET['libro'] : 'todos';
    $forz = auth_libro_unico();
    if ($forz !== '') $libro = $forz;
    if (!$codcue) { fail('codcue requerido'); return; }

    $sDesde = iso_to_serial($desde);
    $sHasta = iso_to_serial($hasta);

    $base = "CODORI='A' AND CODCUE=$codcue AND CODOPE IN (" . OPS_CC . ")";
    if ($libro === 'blanco')     $base .= " AND ESTMOV=True";
    elseif ($libro === 'negro')  $base .= " AND ESTMOV=False";

    $saldoAnterior = 0;
    if ($sDesde !== null) {
        $r = db_row("SELECT SUM(DEBMOV) AS D, SUM(CREMOV) AS C FROM [Tbl Movimientos]
            WHERE $base AND FEXMOV < $sDesde");
        $saldoAnterior = (float) nz($r['D'], 0) - (float) nz($r['C'], 0);
    }

    $w = $base;
    if ($sDesde !== null) $w .= " AND FEXMOV >= $sDesde";
    if ($sHasta !== null) $w .= " AND FEXMOV <= $sHasta";

    $rows = db_query("SELECT NUMMOV, CODOPE, CICMOV, CIIMOV, CIPMOV, CINMOV, FEXMOV,
        DEBMOV, CREMOV, DETMOV, DENMOV, ESTMOV
        FROM [Tbl Movimientos] WHERE $w ORDER BY FEXMOV, NUMMOV");

    $movs = array();
    $totalDebe = 0; $totalHaber = 0;
    $saldo = $saldoAnterior;

    foreach ($rows as $m) {
        $debe  = (float) nz($m['DEBMOV'], 0);
        $haber = (float) nz($m['CREMOV'], 0);
        $saldo += $debe - $haber;
        $totalDebe += $debe;
        $totalHaber += $haber;

        $cic = trim((string) nz($m['CICMOV'], ''));
        $cii = trim((string) nz($m['CIIMOV'], ''));
        $pdv = str_pad((string) (int) nz($m['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
        $num = str_pad((string) (int) nz($m['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
        $comp = $cic . ($cii !== '' ? ' ' . $cii : '') . ' ' . $pdv . '-' . $num;

        $movs[] = array(
            'NUMMOV' => (int) $m['NUMMOV'],
            'FECHA'  => fecha_serial($m['FEXMOV']),
            'COMP'   => $comp,
            'CIC'    => $cic,
            'DETMOV' => trim((string) nz($m['DETMOV'], '')),
            'DEBE'   => $debe > 0 ? number_format($debe, 2, '.', '') : '',
            'HABER'  => $haber > 0 ? number_format($haber, 2, '.', '') : '',
            'SALDO'  => number_format($saldo, 2, '.', ''),
        );
    }

    ok(array(
        'saldoAnterior' => number_format($saldoAnterior, 2, '.', ''),
        'movimientos'   => $movs,
        'totalDebe'     => number_format($totalDebe, 2, '.', ''),
        'totalHaber'    => number_format($totalHaber, 2, '.', ''),
        'saldo'         => number_format($saldo, 2, '.', ''),
    ));
}
