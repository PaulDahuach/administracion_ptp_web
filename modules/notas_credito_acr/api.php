<?php
/**
 * Notas de Crédito Acreedoras — registración de la NC del PROVEEDOR. Porta `Frm CA Creditos`.
 * CODORI='A', CODOPE=320, CICMOV='NC', CODAUX=321, contador ULTNCI. Sin AFIP/CAE (es el comprobante
 * del proveedor, no se emite). Reduce/ajusta lo que le debemos al proveedor (mueve la cta cte).
 *
 * Misma anatomía que el CP (Comprobantes a Pagar) sin el bloque de productos → reusa cp_insert +
 * los helpers (buscar_proveedores/get_proveedor/cuentas/centros_costo/anticipos/listar/detalle/anular)
 * incluyendo el CP como librería (CP_LIB) con CP_CODOPE=320. Ver memoria transaccional-nc-acreedoras.
 */
$GLOBALS['CP_CODOPE'] = 320;                              // listar/cp_detalle/anular filtran por este CODOPE
define('CP_LIB', 1);
require_once __DIR__ . '/../comprobantes_pagar/api.php';  // helpers + cp_insert + listar + cp_detalle + anular
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();
header('Content-Type: application/json');

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$NC_OP  = array('codope' => 320, 'cicmov' => 'NC', 'codaux' => 321, 'contador' => 'ULTNCI');

try {
    switch ($action) {
        case 'buscar_proveedores':   buscar_proveedores();    break;   // reusados del CP (op-agnósticos)
        case 'get_proveedor':        get_proveedor();         break;
        case 'cuentas':              cuentas_imputables();    break;
        case 'centros_costo':        centros_costo();         break;
        case 'auto_imputar':         auto_imputar_ep();       break;
        case 'anticipos_pendientes': anticipos_pendientes();  break;
        case 'guardar':              nc_acr_guardar($NC_OP);  break;
        case 'anular':               anular();                break;   // CP_CODOPE=320
        case 'listar':               listar();                break;   // CP_CODOPE=320
        case 'detalle':              cp_detalle();            break;   // CP_CODOPE=320
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) { fail($e->getMessage(), 500); }

/** Graba la NC Acreedora reusando cp_insert con el op de NC (320/'NC'/321/ULTNCI). */
function nc_acr_guardar($op) {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? json_decode($_POST['data'], true) : null;
    if (!is_array($raw)) { fail('Datos inválidos'); return; }
    $estTrue = (auth_modo() !== 'capacitacion');
    db_begin();
    try {
        $res = cp_insert($raw, $estTrue, $op);
        db_commit();
        $res['anulable'] = true;   // no electrónico → anulable por admin
        ok($res);
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar la nota de crédito acreedora: ' . $e->getMessage(), 500);
    }
}
