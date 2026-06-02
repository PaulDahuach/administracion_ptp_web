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

    // Visibilidad por categoría: supervisor/admin ven ambos libros; operador/capacitación, uno solo.
    $ve = auth_ve_ambos();
    $unico = auth_libro_unico();   // '' | 'blanco' | 'negro'
    $estW = ($unico === 'blanco') ? ' AND ESTMOV=True' : (($unico === 'negro') ? ' AND ESTMOV=False' : '');

    // Saldo neto por cliente y por libro (ESTMOV). Para un solo libro, el filtro deja sólo ese.
    $rows = db_query("SELECT CODCUE, ESTMOV, SUM(DEBMOV) AS D, SUM(CREMOV) AS C
        FROM [Tbl Movimientos] WHERE CODORI='D' AND CODOPE IN (420,440,460,480)$estW
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
        if (abs($b) < 0.005 && abs($n) < 0.005) continue; // sin saldo
        $tb += $b; $tn += $n;
        $nm = isset($name[$cc]) ? $name[$cc] : array('den' => '(' . $cc . ')', 'cit' => '');
        if ($ve) {
            $out[] = array('codcue' => $cc, 'den' => $nm['den'], 'cit' => $nm['cit'],
                           'blanco' => $b, 'negro' => $n, 'total' => $t);
        } else {
            // un solo libro: enviamos sólo "saldo" (el otro NO viaja al navegador)
            $out[] = array('codcue' => $cc, 'den' => $nm['den'], 'cit' => $nm['cit'],
                           'saldo' => ($unico === 'negro') ? $n : $b);
        }
    }

    usort($out, function ($a, $b) use ($ve) {
        $ka = $ve ? $a['total'] : $a['saldo'];
        $kb = $ve ? $b['total'] : $b['saldo'];
        if ($ka == $kb) return 0;
        return ($ka < $kb) ? 1 : -1;
    });

    if ($ve) {
        ok(array('clientes' => $out, 'cantidad' => count($out), 've_ambos' => true,
                 'totBlanco' => round($tb, 2), 'totNegro' => round($tn, 2), 'totTotal' => round($tb + $tn, 2)));
    } else {
        $tot = ($unico === 'negro') ? $tn : $tb;
        ok(array('clientes' => $out, 'cantidad' => count($out), 've_ambos' => false,
                 'libro' => $unico, 'total' => round($tot, 2)));
    }
}
