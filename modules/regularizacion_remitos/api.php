<?php
/**
 * Regularización de Remitos Pendientes de Facturación (Deudores) — API.
 * Porta `Frm CD Remitos Pendientes` (SetData Case "M"): marca remitos de venta como NO pendientes
 * de facturación (**SRPMOV = False**), sacándolos del pool sin emitir factura (muestra, baja, ya
 * facturado a mano, etc.). Pendientes = CODORI='D' AND CODOPE=410 (Remito Venta) AND SRPMOV=True.
 * Respeta el modo doble-libro (auth_libro_unico → ESTMOV). Escribe en transacción. Reversible
 * (volver a poner SRPMOV=True desde el escritorio). dev=readwrite (OJO: apunta al backend real).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'cuentas':     rr_cuentas();     break;
        case 'remitos':     rr_remitos();     break;
        case 'regularizar': rr_regularizar(); break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) { fail($e->getMessage(), 500); }

// $p = prefijo de alias ('M.' cuando hay JOIN; '' en consultas de una sola tabla). CODORI/CODOPE/
// SRPMOV/ESTMOV existen en ambas tablas → sin prefijo el JOIN da "campo ambiguo".
function rr_estmov_w($p = '') { $l = auth_libro_unico(); if ($l === 'blanco') return " AND {$p}ESTMOV=True"; if ($l === 'capacitacion') return " AND {$p}ESTMOV=False"; return ''; }
function rr_base($p = '') { return "{$p}CODORI='D' AND {$p}CODOPE=410 AND {$p}SRPMOV=True" . rr_estmov_w($p); }

/** Cuentas (clientes) con remitos pendientes de facturación + cantidad. */
function rr_cuentas() {
    $rows = db_query("SELECT M.CODCUE, C.DENCUE, C.CITCUE, COUNT(*) AS N
        FROM [Tbl Movimientos] AS M INNER JOIN [Tbl Cuentas Corrientes] AS C ON C.CODCUE=M.CODCUE AND C.CODORI=M.CODORI
        WHERE " . rr_base('M.') . " GROUP BY M.CODCUE, C.DENCUE, C.CITCUE ORDER BY C.DENCUE;");
    ok($rows);
}

/** Remitos pendientes de una cuenta (con la fecha ya formateada dd/mm/yyyy). */
function rr_remitos() {
    $cc = isset($_GET['codcue']) && ctype_digit((string) $_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    if (!$cc) { ok(array()); return; }
    $rows = db_query("SELECT NUMMOV, FEXMOV, CIPMOV, CINMOV, DETMOV FROM [Tbl Movimientos]
        WHERE " . rr_base() . " AND CODCUE=$cc ORDER BY NUMMOV;");
    foreach ($rows as $i => $r) $rows[$i]['FECHA'] = fecha_serial($r['FEXMOV']);
    ok($rows);
}

/** Regulariza (SRPMOV=False) los remitos seleccionados, en transacción. */
function rr_regularizar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura: escritura deshabilitada.'); return; }
    $cc = isset($_POST['codcue']) && ctype_digit((string) $_POST['codcue']) ? (int) $_POST['codcue'] : 0;
    $ids = isset($_POST['nummov']) && is_array($_POST['nummov']) ? $_POST['nummov'] : array();
    $clean = array();
    foreach ($ids as $id) if (ctype_digit((string) $id)) $clean[] = (int) $id;
    if (!$cc || !count($clean)) { fail('No hay remitos seleccionados.'); return; }
    $in = implode(',', $clean);

    db_begin();
    try {
        // El WHERE repite SRPMOV=True + cuenta + libro → sólo toca pendientes reales de esa cuenta/libro.
        $n = db_exec("UPDATE [Tbl Movimientos] SET SRPMOV=False
            WHERE NUMMOV IN ($in) AND " . rr_base() . " AND CODCUE=$cc;");
        db_commit();
        ok(array('regularizados' => (int) $n));
    } catch (Exception $e) { db_rollback(); throw $e; }
}
