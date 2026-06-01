<?php
/**
 * Saldos Actuales (Deudores) — API solo-lectura.
 * Una fila por deudor con saldo BLANCO (ESTMOV=-1) / NEGRO (ESTMOV=0) / TOTAL.
 * El saldo se lleva por libro separado (ver Frm CD Facturas/Recibos: SOCMOV se
 * calcula con DSum filtrado por ESTMOV). Total = blanco + negro = SOPCUE.
 * Ops que mueven cta cte: 420=FV, 440=ND (debe), 460=NC, 480=RC (haber).
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

function listar() {
    // Nombres de deudores
    $name = array();
    foreach (db_query("SELECT CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='D'") as $c) {
        $name[(int) $c['CODCUE']] = array('den' => trim((string) nz($c['DENCUE'], '')), 'cit' => trim((string) nz($c['CITCUE'], '')));
    }

    // Saldo neto por cliente y por libro (ESTMOV)
    $rows = db_query("SELECT CODCUE, ESTMOV, SUM(DEBMOV) AS D, SUM(CREMOV) AS C
        FROM [Tbl Movimientos] WHERE CODORI='D' AND CODOPE IN (420,440,460,480)
        GROUP BY CODCUE, ESTMOV");

    $acc = array();
    foreach ($rows as $r) {
        $cc = (int) $r['CODCUE'];
        $neto = (float) nz($r['D'], 0) - (float) nz($r['C'], 0);
        $blanco = ($r['ESTMOV'] === true || $r['ESTMOV'] == -1);
        if (!isset($acc[$cc])) $acc[$cc] = array('b' => 0.0, 'n' => 0.0);
        if ($blanco) $acc[$cc]['b'] += $neto; else $acc[$cc]['n'] += $neto;
    }

    $out = array();
    $tb = 0.0; $tn = 0.0;
    foreach ($acc as $cc => $v) {
        $b = round($v['b'], 2); $n = round($v['n'], 2); $t = round($b + $n, 2);
        if (abs($b) < 0.005 && abs($n) < 0.005) continue; // sin saldo en ningún libro
        $tb += $b; $tn += $n;
        $nm = isset($name[$cc]) ? $name[$cc] : array('den' => '(' . $cc . ')', 'cit' => '');
        $out[] = array('codcue' => $cc, 'den' => $nm['den'], 'cit' => $nm['cit'],
                       'blanco' => $b, 'negro' => $n, 'total' => $t);
    }

    // Orden por total desc (PHP 5.5: sin spaceship)
    usort($out, function ($a, $b) {
        if ($a['total'] == $b['total']) return 0;
        return ($a['total'] < $b['total']) ? 1 : -1;
    });

    ok(array(
        'clientes'  => $out,
        'cantidad'  => count($out),
        'totBlanco' => round($tb, 2),
        'totNegro'  => round($tn, 2),
        'totTotal'  => round($tb + $tn, 2),
    ));
}
