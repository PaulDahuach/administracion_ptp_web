<?php
/**
 * Balance de Sumas y Saldos (Imputaciones Contables) — API solo-lectura.
 * Porta "Rpt IC Balance de Sumas y Saldos". Por cuenta y período (FEXMOV):
 *   Saldo Anterior = Σ(DEB−CRE) antes de 'desde'  (SIN INICUE: el legacy no lo suma;
 *     así el balance cierra a cero — Σ INICUE=145.070,86 ≠ 0 lo desbalancearía).
 *   Debe/Haber = sumas del período. Saldo = Anterior + Debe − Haber.
 * Roll-up jerárquico: cada hoja (imputable) se acumula en sí y en sus cuentas padre
 * (CN1CUE..CN5CUE). Verifica partida doble: Σ Debe = Σ Haber, Σ Saldo ≈ 0.
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
    $desde = isset($_GET['desde']) ? $_GET['desde'] : '';
    $hasta = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $sd = iso_to_serial($desde);
    $sh = iso_to_serial($hasta);
    if ($sd === null || $sh === null) { fail('Indicá el período (desde / hasta)'); return; }

    // Doble libro: filtra por el ESTMOV del movimiento padre según el modo activo.
    $lib  = auth_libro_unico();
    $estM = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'negro') ? ' AND M.ESTMOV=False' : '');

    // Plan de cuentas: estructura (todas las cuentas)
    $plan = array();   // codcue => row
    foreach (db_query("SELECT CODCUE, CN1CUE, CN2CUE, CN3CUE, CN4CUE, CN5CUE, DENCUE, IMPCUE FROM [Tbl Cuentas Contables] ORDER BY CODCUE") as $r) {
        $cc = trim((string) $r['CODCUE']);
        $nivel = 0;
        foreach (array('CN1CUE','CN2CUE','CN3CUE','CN4CUE','CN5CUE') as $c)
            if (trim((string) nz($r[$c], '')) !== '') $nivel++;
        $plan[$cc] = array(
            'codcue' => $cc,
            'den'    => trim((string) nz($r['DENCUE'], '')),
            'nivel'  => $nivel,
            'imp'    => ($r['IMPCUE'] === true || $r['IMPCUE'] == -1) ? 1 : 0,
            'anc'    => array_values(array_filter(array(
                trim((string) nz($r['CN1CUE'], '')), trim((string) nz($r['CN2CUE'], '')),
                trim((string) nz($r['CN3CUE'], '')), trim((string) nz($r['CN4CUE'], '')),
                trim((string) nz($r['CN5CUE'], '')),
            ), 'strlen')),
            'ant' => 0.0, 'deb' => 0.0, 'cre' => 0.0,
        );
    }

    // Sumas por cuenta hoja (antes de desde, y del período) — un solo escaneo hasta 'hasta'
    $rows = db_query("SELECT MI.CODCUE AS CC,
        SUM(IIf(M.FEXMOV < $sd, MI.DEBMOV, 0)) AS DA, SUM(IIf(M.FEXMOV < $sd, MI.CREMOV, 0)) AS CA,
        SUM(IIf(M.FEXMOV >= $sd AND M.FEXMOV <= $sh, MI.DEBMOV, 0)) AS DP,
        SUM(IIf(M.FEXMOV >= $sd AND M.FEXMOV <= $sh, MI.CREMOV, 0)) AS CP
        FROM [Tbl Movimientos Imputaciones] AS MI INNER JOIN [Tbl Movimientos] AS M ON M.NUMMOV = MI.NUMMOV
        WHERE M.FEXMOV <= $sh$estM
        GROUP BY MI.CODCUE");

    // Roll-up: distribuir cada hoja a sí misma y a sus ancestros
    foreach ($rows as $x) {
        $cc = trim((string) $x['CC']);
        $ant = (float) nz($x['DA'], 0) - (float) nz($x['CA'], 0);
        $deb = (float) nz($x['DP'], 0);
        $cre = (float) nz($x['CP'], 0);
        if (!isset($plan[$cc])) continue; // cuenta sin ficha (raro)
        foreach ($plan[$cc]['anc'] as $code) {
            if (!isset($plan[$code])) continue;
            $plan[$code]['ant'] += $ant;
            $plan[$code]['deb'] += $deb;
            $plan[$code]['cre'] += $cre;
        }
    }

    // Salida: cuentas con algún importe, en orden de código
    $out = array();
    $gAnt = 0.0; $gDeb = 0.0; $gCre = 0.0;
    foreach ($plan as $a) {
        if (abs($a['ant']) < 0.005 && abs($a['deb']) < 0.005 && abs($a['cre']) < 0.005) continue;
        $saldo = $a['ant'] + $a['deb'] - $a['cre'];
        $out[] = array(
            'codcue' => $a['codcue'], 'den' => $a['den'], 'nivel' => $a['nivel'], 'imp' => $a['imp'],
            'ant' => round($a['ant'], 2), 'deb' => round($a['deb'], 2), 'cre' => round($a['cre'], 2),
            'saldo' => round($saldo, 2),
        );
        if ($a['imp']) { $gDeb += $a['deb']; $gCre += $a['cre']; $gAnt += $a['ant']; }
    }

    ok(array(
        'cuentas'  => $out,
        'cantidad' => count($out),
        'totales'  => array(
            'ant'   => round($gAnt, 2),
            'deb'   => round($gDeb, 2),
            'cre'   => round($gCre, 2),
            'saldo' => round($gAnt + $gDeb - $gCre, 2),
        ),
    ));
}
