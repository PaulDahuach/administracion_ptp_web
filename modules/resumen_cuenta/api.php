<?php
/**
 * Resumen de Cuenta (Deudores) — API solo-lectura.
 * Porta la lógica de RDN/cuentas (saldo anterior + saldo corrido) al esquema
 * PTP (estilo inside: CODOPE/CICMOV/FEXMOV serial/DEBMOV/CREMOV/ESTMOV bool).
 *
 * Operaciones de deudores que mueven cta cte: 420=FV, 440=ND, 460=NC, 480=RC.
 * (410=Remito y 500=Devolución NO mueven saldo → excluidos.)
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

define('OPS_CC', '420,440,460,480'); // codopes que afectan cuenta corriente (deudores)

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

/** ISO 'YYYY-mm-dd' → serial Access (días desde 1899-12-30). */
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
        WHERE CODORI='D' AND ((DENCUE Like '%$s%') OR (CITCUE Like '%$s%')) ORDER BY DENCUE");
    ok($rows);
}

function get_cliente() {
    $codcue = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    if (!$codcue) { fail('codcue requerido'); return; }
    $row = db_row("SELECT CODCUE, DENCUE, CITCUE, SOPCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='D'");
    if (!$row) { fail('Cliente no encontrado'); return; }
    ok($row);
}

function resumen() {
    $codcue = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $desde  = isset($_GET['desde']) ? $_GET['desde'] : '';
    $hasta  = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $libro  = isset($_GET['libro']) ? $_GET['libro'] : 'todos';  // todos|blanco|capacitacion
    // Visibilidad por categoría: operador→blanco, capacitación→capacitacion (ignora el param).
    $forz = auth_libro_unico();
    if ($forz !== '') $libro = $forz;
    if (!$codcue) { fail('codcue requerido'); return; }

    $sDesde = iso_to_serial($desde);
    $sHasta = iso_to_serial($hasta);

    // ESTMOV = dual-ledger BLANCO (-1) / CAPACITACION (0). 'todos' = ambos (= SOPCUE).
    $base = "CODORI='D' AND CODCUE=$codcue AND CODOPE IN (" . OPS_CC . ")";
    if ($libro === 'blanco')     $base .= " AND ESTMOV=True";
    elseif ($libro === 'capacitacion')  $base .= " AND ESTMOV=False";

    // ── Saldo anterior (movimientos con FEXMOV < desde) ──
    $saldoAnterior = 0;
    if ($sDesde !== null) {
        $r = db_row("SELECT SUM(DEBMOV) AS D, SUM(CREMOV) AS C FROM [Tbl Movimientos]
            WHERE $base AND FEXMOV < $sDesde");
        $saldoAnterior = (float) nz($r['D'], 0) - (float) nz($r['C'], 0);
    }

    // ── Movimientos del período ──
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

        $est = $m['ESTMOV'];
        $estReal = ($est === true || $est === -1 || $est === '-1' || $est === 1 || $est === '1');

        $movs[] = array(
            'NUMMOV' => (int) $m['NUMMOV'],
            'FECHA'  => fecha_serial($m['FEXMOV']),
            'COMP'   => $comp,
            'CIC'    => $cic,
            'DETMOV' => trim((string) nz($m['DETMOV'], '')),
            'DEBE'   => $debe > 0 ? number_format($debe, 2, '.', '') : '',
            'HABER'  => $haber > 0 ? number_format($haber, 2, '.', '') : '',
            'SALDO'  => number_format($saldo, 2, '.', ''),
            'ESTMOV' => $estReal ? 1 : 0,
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
