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
 * Visibilidad blanco/negro (ESTMOV) por categoría de usuario (col 'col_cat' en 'auth').
 * Operador (O) → sólo blanco · Capacitación (C) → sólo negro · Supervisor (S)/Admin (A) → ambos.
 * Sin col_cat configurado → sin restricción (otros sistemas no exponen blanco/negro).
 * Default seguro: categoría desconocida ve sólo blanco (no se filtra negro sin querer).
 */
function auth_libro_unico() {       // '' = ve ambos | 'blanco' | 'negro'
    $a = sys('auth');
    if (empty($a['col_cat'])) return '';
    $c = isset($_SESSION['ucat']) ? strtoupper(trim($_SESSION['ucat'])) : '';
    if ($c === 'S' || $c === 'A') return '';
    if ($c === 'C') return 'negro';
    return 'blanco';
}
function auth_ve_ambos() { return auth_libro_unico() === ''; }

/** Condición SQL de ESTMOV según categoría ('' = sin filtro). */
function auth_estmov_filter() {
    $l = auth_libro_unico();
    if ($l === 'blanco') return 'ESTMOV=True';
    if ($l === 'negro')  return 'ESTMOV=False';
    return '';
}

/** Cierra la sesión. */
function auth_logout() {
    $_SESSION = [];
    session_destroy();
}
