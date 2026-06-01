<?php
/**
 * inforemp-web-kit — Endpoint de autenticación.
 * Reemplaza al service.php monolítico de RDN para login/logout.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$action = (isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : ''));

try {
    switch ($action) {
        case 'get_usuario':
            // Paso 1: identificar usuario por contraseña.
            $row = auth_lookup_by_pass((isset($_POST['pass']) ? $_POST['pass'] : ''));
            if ($row) ok(['id' => $row['id'], 'name' => $row['name']]);
            else fail('Usuario no encontrado', 401);
            break;

        case 'login':
            // Paso 2: validar credenciales completas.
            if (auth_login((isset($_POST['id']) ? $_POST['id'] : 0), (isset($_POST['name']) ? $_POST['name'] : ''), (isset($_POST['pass']) ? $_POST['pass'] : ''))) {
                $dest = auth_sector_login() ? bu('/app/sector.php') : bu('/app/index.php');
                ok(['redirect' => $dest]);
            } else {
                fail('Credenciales inválidas', 401);
            }
            break;

        case 'sectores':
            // Sectores del usuario logueado (paso intermedio del login sectorizado).
            if (!auth_logged_in()) { fail('No autenticado', 401); break; }
            ok(auth_sectors());
            break;

        case 'set_sector':
            if (!auth_logged_in()) { fail('No autenticado', 401); break; }
            auth_set_sector(intval((isset($_POST['cod']) ? $_POST['cod'] : 0)), (isset($_POST['name']) ? $_POST['name'] : ''));
            ok(['redirect' => bu('/app/index.php')]);
            break;

        case 'logout':
            auth_logout();
            ok(['redirect' => bu('/app/login.php')]);
            break;

        default:
            fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}
