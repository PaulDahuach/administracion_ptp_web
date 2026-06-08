<?php
/**
 * Pendientes de CAE — API: lista la cola [Web CAE Pendientes] y dispara el resolver (B-php).
 * Ver memoria afip-cae-contingencia.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {
        case 'listar':     listar();     break;
        case 'reintentar': reintentar(); break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

/** Lista los comprobantes en la cola (pendientes y rechazados), con el dato del movimiento. */
function listar() {
    $tipos = array('FV' => 'Factura', 'NC' => 'Nota de Crédito', 'ND' => 'Nota de Débito');
    $out = array();
    foreach (db_query("SELECT P.NUMMOV, P.PTOVTA, P.CBTETIPO, P.NUMERO, P.LETRA, P.ESTADO, P.INTENTOS, P.ULTERR, M.CICMOV, M.TOTMOV, M.DENMOV
        FROM [Web CAE Pendientes] AS P LEFT JOIN [Tbl Movimientos] AS M ON P.NUMMOV=M.NUMMOV
        ORDER BY P.ESTADO DESC, P.PTOVTA, P.CBTETIPO, P.NUMERO;") as $r) {
        $cic = trim((string) nz($r['CICMOV'], ''));
        $lbl = isset($tipos[$cic]) ? $tipos[$cic] : ($cic !== '' ? $cic : 'Comprobante');
        $out[] = array(
            'nummov'      => (int) $r['NUMMOV'],
            'comprobante' => $lbl . ' ' . trim((string) nz($r['LETRA'], '')) . ' ' .
                str_pad((string) (int) $r['PTOVTA'], 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) $r['NUMERO'], 8, '0', STR_PAD_LEFT),
            'cliente'     => trim((string) nz($r['DENMOV'], '')),
            'total'       => round((float) nz($r['TOTMOV'], 0), 2),
            'estado'      => trim((string) nz($r['ESTADO'], '')),
            'intentos'    => (int) nz($r['INTENTOS'], 0),
            'error'       => trim((string) nz($r['ULTERR'], '')),
        );
    }
    ok($out);
}

/** Dispara el resolver (procesa la cola en orden correlativo, con reconciliación). */
function reintentar() {
    require_once __DIR__ . '/../../includes/cae_cola.php';
    ok(cae_resolver());
}
