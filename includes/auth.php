<?php
/**
 * inforemp-web-kit — Autenticación contra la tabla de usuarios del legacy.
 *
 * Replica el flujo de RDN (clave en texto plano en [Tbl Usuarios]), pero con
 * la tabla/columnas tomadas de config/system.php → 'auth'.
 *
 * NOTA de seguridad: el legacy guarda la clave en texto plano. Lo respetamos
 * para convivir, pero el acceso web SIEMPRE debe ir detrás de HTTPS (Certbot).
 */

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('IWKSESSID');
    session_start();
}

/** True si este sistema usa login sectorizado (opt-in via config 'sector_login'). */
function auth_sector_login() {
    return !empty(sys('sector_login'));
}

/** True si hay sesión iniciada. Robusto ante uid=0 (operarios pueden tener CODOPR=0). */
function auth_logged_in() {
    return isset($_SESSION['uid']) && $_SESSION['uid'] !== '' && $_SESSION['uid'] !== null;
}

/** Exige sesión iniciada (sólo usuario). Para páginas que aún no requieren sector. */
function auth_require_user($login_url = null) {
    if (!auth_logged_in()) {
        header('Location: ' . ($login_url ?: bu('/app/login.php')));
        exit;
    }
}

/**
 * Redirige a login si no hay sesión. Llamar al tope de páginas protegidas.
 * Si el sistema usa sector_login y todavía no se eligió sector, manda a elegirlo.
 */
function auth_require_login($login_url = null) {
    auth_require_user($login_url);
    if (auth_sector_login() && empty($_SESSION['sector'])) {
        header('Location: ' . bu('/app/sector.php'));
        exit;
    }
}

/** Nombre del usuario logueado. */
function auth_user() {
    return (isset($_SESSION['uname']) ? $_SESSION['uname'] : 'Usuario');
}

/** ¿El usuario actual es administrador? Lista en config 'admin_users' (por CODUSR o por
 *  nombre/DENUSR). Si no está configurada, NADIE es admin (default seguro). */
function auth_is_admin() {
    $admins = sys('admin_users', array());
    if (!is_array($admins) || !count($admins)) return false;
    $uid = (isset($_SESSION['uid'])   ? (string) $_SESSION['uid']   : '');
    $un  = (isset($_SESSION['uname']) ? (string) $_SESSION['uname'] : '');
    foreach ($admins as $a) {
        $a = trim((string) $a);
        if ($a !== '' && ($a === $uid || strcasecmp($a, $un) === 0)) return true;
    }
    return false;
}

/** Exige sesión + permiso de admin (para páginas HTML). */
function auth_require_admin() {
    auth_require_login();
    if (!auth_is_admin()) {
        http_response_code(403);
        die('<!doctype html><meta charset="utf-8"><div style="font-family:system-ui;padding:2rem;color:#b91c1c">'
            . 'Acceso restringido. Esta secci&oacute;n es s&oacute;lo para administradores.</div>');
    }
}

/** Sectores que puede operar el usuario actual (config 'sector_login'). */
function auth_sectors() {
    $sl = sys('sector_login');
    if (!$sl) return [];
    $uid = intval((isset($_SESSION['uid']) ? $_SESSION['uid'] : 0));
    $sql = "SELECT DISTINCT S.[{$sl['sec_pk']}] AS id, S.[{$sl['sec_den']}] AS den
            FROM [{$sl['rel_tabla']}] AS R INNER JOIN [{$sl['sec_tabla']}] AS S
              ON R.[{$sl['rel_sector']}] = S.[{$sl['sec_pk']}]
            WHERE R.[{$sl['rel_fk']}] = $uid ORDER BY S.[{$sl['sec_den']}];";
    return db_query($sql);
}

/** Fija el sector activo en la sesión. */
function auth_set_sector($cod, $name) {
    $_SESSION['sector'] = $cod;
    $_SESSION['sector_name'] = $name;
}

function auth_sector()      { return (isset($_SESSION['sector']) ? $_SESSION['sector'] : null); }
function auth_sector_name() { return (isset($_SESSION['sector_name']) ? $_SESSION['sector_name'] : ''); }

/** Busca un usuario por su contraseña (paso 1 del login, como RDN). */
function auth_lookup_by_pass($pass) {
    $a = sys('auth');
    $sql = "SELECT [{$a['col_id']}] AS id, [{$a['col_name']}] AS name "
         . "FROM [{$a['table']}] WHERE [{$a['col_pass']}]='" . db_esc($pass) . "';";
    return db_row($sql);
}

/** Valida id+nombre+clave y abre sesión (paso 2). */
function auth_login($id, $name, $pass) {
    $a = sys('auth');
    $catCol = !empty($a['col_cat']) ? ", [{$a['col_cat']}] AS cat" : '';
    $sql = "SELECT [{$a['col_id']}] AS id{$catCol} FROM [{$a['table']}] WHERE "
         . "[{$a['col_id']}]=" . intval($id) . " AND "
         . "[{$a['col_name']}]='" . db_esc($name) . "' AND "
         . "[{$a['col_pass']}]='" . db_esc($pass) . "';";
    $row = db_row($sql);
    if ($row) {
        $_SESSION['uid']   = $id;
        $_SESSION['uname'] = $name;
        $_SESSION['ucat']  = isset($row['cat']) ? $row['cat'] : '';
        return true;
    }
    return false;
}

/**
 * MODO de trabajo (doble libro). El sistema opera en UN libro a la vez (como el legacy):
 *  - Operador     → libro operativo (ESTMOV=True).
 *  - Capacitación → libro de capacitación (ESTMOV=False).
 * Por categoría (col 'col_cat' en 'auth' → sesión 'ucat'):
 *  - Operador (O)     → fijo en modo Operador.
 *  - Capacitación (C) → fijo en modo Capacitación.
 *  - Supervisor (S) / Administrador / Auditor (A) → pueden ALTERNAR (arrancan en Operador).
 *    El Auditor ve ambos pero es READONLY (no crea/edita; relevante al portar transaccional).
 * El modo Operador muestra únicamente el libro operativo (el de capacitación queda oculto).
 */

/** ¿La categoría del usuario permite alternar de modo? (S/A) */
function auth_puede_cambiar_modo() {
    $a = sys('auth');
    if (empty($a['col_cat'])) return false;
    $c = isset($_SESSION['ucat']) ? strtoupper(trim($_SESSION['ucat'])) : '';
    return ($c === 'S' || $c === 'A');
}

/**
 * Modo activo: 'operador' | 'capacitacion' | 'integral'.
 *  - O fijo en operador, C fijo en capacitacion.
 *  - S/A toman el de sesión (default operador); pueden elegir 'integral' (ambos libros a la vez,
 *    para dueños/socios — como el legacy con chkEst en Null: sin filtro ESTMOV).
 */
function auth_modo() {
    $a = sys('auth');
    if (empty($a['col_cat'])) return 'operador';   // sistemas sin doble libro
    $c = isset($_SESSION['ucat']) ? strtoupper(trim($_SESSION['ucat'])) : '';
    if ($c === 'C') return 'capacitacion';         // capacitación fijo
    if ($c === 'S' || $c === 'A') {
        $m = isset($_SESSION['modo']) ? $_SESSION['modo'] : 'operador';
        return in_array($m, array('capacitacion', 'integral'), true) ? $m : 'operador';
    }
    return 'operador';                             // operador y cualquier otro → operador (default seguro)
}

/** Cambia el modo (solo si la categoría lo permite). Devuelve true si cambió. */
function auth_set_modo($m) {
    if (!auth_puede_cambiar_modo()) return false;
    $_SESSION['modo'] = in_array($m, array('capacitacion', 'integral'), true) ? $m : 'operador';
    return true;
}

/**
 * Libro a filtrar SEGÚN EL MODO ACTIVO:
 *  - operador     → 'blanco' (ESTMOV=True)
 *  - capacitacion → 'capacitacion'  (ESTMOV=False)
 *  - integral     → ''       (sin filtro: ambos libros, como el legacy con chkEst Null)
 * Sistemas sin col_cat (otros del kit) → '' = sin filtro.
 */
function auth_libro_unico() {       // 'blanco' | 'capacitacion' | '' (integral o sistemas sin doble libro)
    $a = sys('auth');
    if (empty($a['col_cat'])) return '';
    $modo = auth_modo();
    if ($modo === 'integral') return '';
    return ($modo === 'capacitacion') ? 'capacitacion' : 'blanco';
}

/** True en modo INTEGRAL: las vistas muestran ambos libros (columnas Blanco/Capacitacion/Total, selector). */
function auth_ve_ambos() { return auth_modo() === 'integral'; }

/** Condición SQL de ESTMOV según el modo activo ('' = sin filtro, solo sistemas sin doble libro). */
function auth_estmov_filter() {
    $l = auth_libro_unico();
    if ($l === 'blanco') return 'ESTMOV=True';
    if ($l === 'capacitacion')  return 'ESTMOV=False';
    return '';
}

/** Estilo (clase, ícono, etiqueta) de cada modo. */
function _modo_estilo($modo) {
    if ($modo === 'capacitacion') return array('bg-warning text-dark', 'bi-mortarboard-fill', 'Capacitación');
    if ($modo === 'integral')     return array('bg-danger', 'bi-layers-fill', 'Integral');
    return array('bg-success', 'bi-briefcase-fill', 'Operador');
}

/**
 * Badge de modo (doble libro) para la topbar. Verde=Operador, ámbar=Capacitación, rojo=Integral.
 * Para S/A es un dropdown con los 3 modos (acceso directo al modo Operador). O/C: solo el badge.
 * Vacío en sistemas sin doble libro (sin col_cat).
 */
function mode_badge_html() {
    $a = sys('auth');
    if (empty($a['col_cat'])) return '';
    $modo = auth_modo();
    list($cls, $ic, $lbl) = _modo_estilo($modo);
    $badge = '<span class="badge ' . $cls . '"><i class="bi ' . $ic . ' me-1"></i>' . $lbl . '</span>';

    if (!auth_puede_cambiar_modo()) return '<span class="iwk-modo">' . $badge . '</span>';

    $items = '';
    foreach (array('operador', 'capacitacion', 'integral') as $k) {
        list(, $ki, $kl) = _modo_estilo($k);
        $extra = ($k === 'integral') ? ' <small class="text-muted">(ambos libros)</small>' : '';
        $items .= '<li><a class="dropdown-item iwk-modo-item' . ($k === $modo ? ' active' : '') . '" href="#" data-modo="' . $k . '">'
                . '<i class="bi ' . $ki . ' me-2"></i>' . $kl . $extra . '</a></li>';
    }
    return '<span class="dropdown iwk-modo">'
         . '<a href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Cambiar libro" style="text-decoration:none">'
         . $badge . ' <i class="bi bi-caret-down-fill ms-1" style="font-size:.6rem"></i></a>'
         . '<ul class="dropdown-menu dropdown-menu-end">' . $items . '</ul></span>';
}

/** Cierra la sesión. */
function auth_logout() {
    $_SESSION = [];
    session_destroy();
}
