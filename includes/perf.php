<?php
/**
 * Instrumentación de performance — para detectar dónde "se plancha" el sistema.
 * Mide cada query (db.php llama perf_record) y, al terminar el request (página o AJAX),
 * loguea una línea resumen a logs/perf-YYYY-MM.csv y las queries lentas a perf-slow-YYYY-MM.csv.
 * NO toca la .mdb (CSV append, como track.php). Silencioso: nunca rompe la app.
 *
 * El motor Access (COM/ADODB) es lento y serializa en multiusuario; esto da los datos para
 * encontrar los cuellos de botella sin agregar overhead perceptible.
 */
require_once __DIR__ . '/track.php';   // reusa track_module / track_hostname / track_csv

$GLOBALS['__perf'] = array('q' => array(), 'db_ms' => 0.0, 'conn_ms' => 0.0, 'reg' => false);

/** Umbral (ms) para considerar una query "lenta" y loguear su SQL al detalle. */
function perf_slow_threshold() { return 800; }

/**
 * Registra una operación de DB. $type: 'q' (SELECT), 'x' (INSERT/UPDATE/DELETE), 'c' (Open conexión).
 * Se llama desde db_query / db_exec / db_connect.
 */
function perf_record($sql, $ms, $rows, $type) {
    $g = &$GLOBALS['__perf'];
    if ($type === 'c') { $g['conn_ms'] += $ms; }
    else { $g['db_ms'] += $ms; $g['q'][] = array($ms, $rows, $type, $sql); }
    if (!$g['reg']) { $g['reg'] = true; @register_shutdown_function('perf_flush'); }
}

/** Normaliza el SQL para el log (una línea, recortado). */
function perf_sql_short($sql, $max = 240) {
    $s = trim(preg_replace('/\s+/', ' ', (string) $sql));
    if (strlen($s) > $max) $s = substr($s, 0, $max) . '…';
    return $s;
}

/** Al terminar el request: escribe el resumen + las queries lentas. */
function perf_flush() {
    try {
        $g = $GLOBALS['__perf'];
        if (empty($g['q']) && $g['conn_ms'] <= 0) return;   // request sin DB → nada que medir
        $t0     = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0;
        $php_ms = $t0 > 0 ? (microtime(true) - $t0) * 1000 : 0;
        $n      = count($g['q']);
        $slowMs = 0; $slowSql = '';
        foreach ($g['q'] as $q) { if ($q[0] > $slowMs) { $slowMs = $q[0]; $slowSql = $q[3]; } }

        $user = isset($_SESSION['uname']) ? (string) $_SESSION['uname'] : '';
        $ip   = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $path = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $mod  = function_exists('track_module') ? track_module($path) : $path;
        $host = function_exists('track_hostname') ? track_hostname($ip) : '';
        $ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($path, 'api.php') !== false || strpos($path, 'action=') !== false) ? 1 : 0;

        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        // resumen por request
        $line = track_csv(array(
            date('Y-m-d H:i:s'), $user, $host, $mod, $ajax,
            round($php_ms), round($g['db_ms']), round($g['conn_ms']), $n,
            round($slowMs), perf_sql_short($slowSql), $path,
        ));
        @file_put_contents($dir . '/perf-' . date('Y-m') . '.csv', $line, FILE_APPEND | LOCK_EX);

        // queries lentas (detalle, con el SQL completo recortado)
        $thr = perf_slow_threshold(); $det = '';
        foreach ($g['q'] as $q) {
            if ($q[0] >= $thr) $det .= track_csv(array(date('Y-m-d H:i:s'), $user, $mod, $q[2], round($q[0]), $q[1], perf_sql_short($q[3], 500)));
        }
        if ($det !== '') @file_put_contents($dir . '/perf-slow-' . date('Y-m') . '.csv', $det, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) { /* el profiling nunca puede afectar la app */ }
}
