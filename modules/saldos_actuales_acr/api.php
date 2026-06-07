<?php
/**
 * Saldos Actuales (Acreedores / Proveedores) — API solo-lectura.
 * Una fila por proveedor con saldo BLANCO/CAPACITACION/TOTAL. Saldo NEGATIVO = le debemos.
 * Ops cta cte: 310=CP, 320=NC, 330=ND, 340=OP, 350=Canc.Anticipos.
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
    $name = array();
    foreach (db_query("SELECT CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A'") as $c) {
        $name[(int) $c['CODCUE']] = array('den' => trim((string) nz($c['DENCUE'], '')), 'cit' => trim((string) nz($c['CITCUE'], '')));
    }

    $ve = auth_ve_ambos();
    $unico = auth_libro_unico();   // '' | 'blanco' | 'capacitacion'
    $estW = ($unico === 'blanco') ? ' AND ESTMOV=True' : (($unico === 'capacitacion') ? ' AND ESTMOV=False' : '');

    $rows = db_query("SELECT CODCUE, ESTMOV, SUM(DEBMOV) AS D, SUM(CREMOV) AS C
        FROM [Tbl Movimientos] WHERE CODORI='A' AND CODOPE IN (310,320,330,340,350)$estW
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
    $tb = 0.0; $tn = 0.0; $aPagar = 0.0;
    foreach ($acc as $cc => $v) {
        $b = round($v['b'], 2); $n = round($v['n'], 2); $t = round($b + $n, 2);
        if (abs($b) < 0.005 && abs($n) < 0.005) continue;
        $tb += $b; $tn += $n;
        $sal = $ve ? $t : (($unico === 'capacitacion') ? $n : $b);
        if ($sal < 0) $aPagar += -$sal;
        $nm = isset($name[$cc]) ? $name[$cc] : array('den' => '(' . $cc . ')', 'cit' => '');
        if ($ve) {
            $out[] = array('codcue' => $cc, 'den' => $nm['den'], 'cit' => $nm['cit'],
                           'blanco' => $b, 'capacitacion' => $n, 'total' => $t);
        } else {
            $out[] = array('codcue' => $cc, 'den' => $nm['den'], 'cit' => $nm['cit'], 'saldo' => round($sal, 2));
        }
    }

    // Orden por saldo ASC (más negativo = mayor deuda nuestra arriba)
    usort($out, function ($a, $b) use ($ve) {
        $ka = $ve ? $a['total'] : $a['saldo'];
        $kb = $ve ? $b['total'] : $b['saldo'];
        if ($ka == $kb) return 0;
        return ($ka < $kb) ? -1 : 1;
    });

    if ($ve) {
        ok(array('clientes' => $out, 'cantidad' => count($out), 've_ambos' => true,
                 'totBlanco' => round($tb, 2), 'totCapacitacion' => round($tn, 2), 'totTotal' => round($tb + $tn, 2),
                 'totPagar' => round($aPagar, 2)));
    } else {
        ok(array('clientes' => $out, 'cantidad' => count($out), 've_ambos' => false,
                 'libro' => $unico, 'totPagar' => round($aPagar, 2)));
    }
}
