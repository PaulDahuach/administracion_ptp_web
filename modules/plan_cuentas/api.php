<?php
/**
 * Plan de Cuentas (Imputaciones Contables) — API solo-lectura.
 * Lista [Tbl Cuentas Contables] (480) con su jerarquía de 5 niveles (CN1..CN5).
 * Saldo cacheado = INICUE + DEBCUE − CRECUE. IMPCUE=true → cuenta imputable (hoja).
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
    $rows = db_query("SELECT CODCUE, CN1CUE, CN2CUE, CN3CUE, CN4CUE, CN5CUE, DENCUE, IMPCUE,
        INICUE, DEBCUE, CRECUE FROM [Tbl Cuentas Contables] ORDER BY CODCUE");

    $out = array();
    foreach ($rows as $r) {
        $nivel = 0;
        foreach (array('CN1CUE','CN2CUE','CN3CUE','CN4CUE','CN5CUE') as $c)
            if (trim((string) nz($r[$c], '')) !== '') $nivel++;
        $imp = ($r['IMPCUE'] === true || $r['IMPCUE'] == -1);
        $saldo = (float) nz($r['INICUE'], 0) + (float) nz($r['DEBCUE'], 0) - (float) nz($r['CRECUE'], 0);
        $out[] = array(
            'codcue' => trim((string) $r['CODCUE']),
            'den'    => trim((string) nz($r['DENCUE'], '')),
            'nivel'  => $nivel,
            'imp'    => $imp ? 1 : 0,
            'saldo'  => $imp ? round($saldo, 2) : null,  // sólo imputables tienen saldo propio
        );
    }
    ok(array('cuentas' => $out, 'cantidad' => count($out)));
}
