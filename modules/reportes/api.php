<?php
/** Reportes/listados (solo lectura). ?action=list&r=<clave> (ver defs.php). */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$DEFS = require __DIR__ . '/defs.php';
$action = (isset($_GET['action']) ? $_GET['action'] : '');
$r = (isset($_GET['r']) ? $_GET['r'] : '');

try {
    if ($action === 'list') {
        if (!isset($DEFS[$r])) { fail('Reporte inválido: ' . $r); }
        else {
            $def = $DEFS[$r];
            $rows = db_query($def['sql']);
            if (!empty($def['fechas'])) {
                foreach ($rows as &$row) foreach ($def['fechas'] as $f) {
                    if (array_key_exists($f, $row)) $row[$f] = to_disp_date($row[$f]);
                }
            }
            ok($rows);
        }
    } else {
        fail('Acción inválida');
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}
