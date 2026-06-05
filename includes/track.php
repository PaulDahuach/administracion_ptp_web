<?php
/**
 * Tracking de uso (page views) — para medir adopción del sistema nuevo.
 * Log append en archivo (no toca la mdb, funciona en readonly). Una línea por carga
 * de página: fecha/hora · usuario · IP · host · módulo · ruta.
 * Se invoca desde module_head() (todos los módulos) y desde el dashboard.
 * Pieza del kit: reusable en administracion_ptp / supervisores_ptp.
 */

function track_dir() { return __DIR__ . '/../logs'; }

/** Registra un acceso. Nunca debe romper la página (todo silencioso). */
function track_hit() {
    try {
        $uid  = isset($_SESSION['uid'])   ? (int) $_SESSION['uid']      : 0;
        $user = isset($_SESSION['uname']) ? (string) $_SESSION['uname'] : '';
        $ip   = isset($_SERVER['REMOTE_ADDR'])  ? $_SERVER['REMOTE_ADDR']  : '';
        $path = isset($_SERVER['REQUEST_URI'])  ? $_SERVER['REQUEST_URI']  : '';
        $host = track_hostname($ip);
        $mod  = track_module($path);
        $dir  = track_dir();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/usage-' . date('Y-m') . '.csv';
        $line = track_csv(array(date('Y-m-d H:i:s'), $uid, $user, $ip, $host, $mod, $path));
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) { /* ignorar: el tracking no puede afectar la app */ }
}

function track_csv($cols) {
    $out = array();
    foreach ($cols as $c) { $out[] = '"' . str_replace('"', '""', (string) $c) . '"'; }
    return implode(',', $out) . "\n";
}

/** Deriva el "módulo" desde la ruta: /modules/recepcion/ → recepcion ;
 *  distingue ?modo= (odm_edit) y ?m= (abm) ; /app/ → inicio. */
function track_module($path) {
    $p = parse_url($path, PHP_URL_PATH);
    if ($p === false || $p === null) $p = (string) $path;
    if (preg_match('~/modules/([^/?]+)~', $p, $m)) {
        $mod = $m[1];
        $q = parse_url($path, PHP_URL_QUERY);
        if ($q) { parse_str($q, $qa); if (!empty($qa['modo'])) $mod .= ':' . $qa['modo']; elseif (!empty($qa['m'])) $mod .= ':' . $qa['m']; }
        return $mod;
    }
    if (strpos($p, 'login') !== false) return 'login';
    if (strpos($p, '/app') !== false) return 'inicio';
    return 'otro';
}

/** IP → nombre de host (DNS inverso), cacheado en logs/hosts.json para no resolver cada vez. */
function track_hostname($ip) {
    if ($ip === '') return '';
    if ($ip === '127.0.0.1' || $ip === '::1') return 'localhost';
    $dir = track_dir();
    $cf  = $dir . '/hosts.json';
    $cache = array();
    if (is_file($cf)) { $j = @json_decode(@file_get_contents($cf), true); if (is_array($j)) $cache = $j; }
    if (array_key_exists($ip, $cache)) return $cache[$ip];
    $host = @gethostbyaddr($ip);
    if ($host === false || $host === $ip) $host = '';
    $cache[$ip] = $host;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($cf, json_encode($cache), LOCK_EX);
    return $host;
}
