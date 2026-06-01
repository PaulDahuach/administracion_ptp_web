<?php
/**
 * PLANTILLA DE MÓDULO — API (solo-lectura).
 *
 * Copiá la carpeta modules/_template a modules/<tu-modulo> y adaptá:
 *   - TABLE: la tabla/consulta Access de origen
 *   - los campos en list()
 *   - agregá acciones (get, create, ...) según haga falta
 *
 * Convención de respuesta: { ok:true, data:... } | { ok:false, error:... }
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();   // protege también la API

// Tabla de origen en la .mdb (cambiá esto al portar)
define('TABLE', 'Tbl Ejemplo');

$action = (isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : ''));

try {
    switch ($action) {
        case 'list':   list_rows(); break;
        // case 'get':    get_row();   break;   // ← descomentar y portar
        // case 'save':   save_row();  break;   // ← requiere mode=readwrite
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

/** Listado para DataTables. */
function list_rows() {
    $rows = db_query('SELECT * FROM [' . TABLE . '];');
    ok($rows);
}
