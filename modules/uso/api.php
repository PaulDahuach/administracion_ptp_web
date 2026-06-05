<?php
/**
 * Estadísticas de uso — lee los logs de tracking (logs/usage-YYYY-MM.csv) y agrega
 * por módulo / usuario / máquina / día para medir adopción del sistema nuevo.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();
if (!auth_is_admin()) { fail('Acceso restringido (solo administradores)', 403); exit; }

$action = (isset($_GET['action']) ? $_GET['action'] : '');
try {
    switch ($action) {
        case 'stats': stats(); break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

/** Sistemas a agregar (carpetas hermanas bajo www/). Solo los que existen.
 *  Override opcional por config 'uso_sistemas' => ['Nombre' => '/ruta/logs', ...]. */
function uso_sistemas() {
    $base = __DIR__ . '/../..';   // .../administracion_ptp
    $cand = array(
        'Administración' => $base . '/logs',
        'Producción'     => $base . '/../produccion_ptp/logs',
        'Supervisores'   => $base . '/../supervisores_ptp/logs',
    );
    $cfg = sys('uso_sistemas', null);
    if (is_array($cfg) && $cfg) $cand = $cfg;
    $out = array();
    foreach ($cand as $nombre => $dir) { if (is_dir($dir)) $out[$nombre] = $dir; }
    return $out;
}

/** Lee las filas del rango [desde, hasta] de TODOS los sistemas (o de uno si $sistemaFiltro). */
function leerFilas($desde, $hasta, $sistemaFiltro = '') {
    $filas = array();
    foreach (uso_sistemas() as $nombre => $dir) {
        if ($sistemaFiltro !== '' && $sistemaFiltro !== $nombre) continue;
        $y = (int) substr($desde, 0, 4); $m = (int) substr($desde, 5, 2);
        $yh = (int) substr($hasta, 0, 4); $mh = (int) substr($hasta, 5, 2);
        while ($y < $yh || ($y === $yh && $m <= $mh)) {
            $f = $dir . '/usage-' . sprintf('%04d-%02d', $y, $m) . '.csv';
            if (is_file($f) && ($fh = @fopen($f, 'r'))) {
                while (($r = fgetcsv($fh)) !== false) {
                    if (count($r) < 7) continue;
                    $ts = $r[0]; $dia = substr($ts, 0, 10);
                    if ($dia < $desde || $dia > $hasta) continue;
                    $filas[] = array('ts' => $ts, 'dia' => $dia, 'uid' => $r[1], 'user' => $r[2],
                        'ip' => $r[3], 'host' => $r[4], 'mod' => $r[5], 'path' => $r[6], 'sistema' => $nombre);
                }
                fclose($fh);
            }
            $m++; if ($m > 12) { $m = 1; $y++; }
        }
    }
    return $filas;
}

function stats() {
    $desde = trim((isset($_GET['desde']) ? $_GET['desde'] : ''));
    $hasta = trim((isset($_GET['hasta']) ? $_GET['hasta'] : ''));
    $sistema = trim((isset($_GET['sistema']) ? $_GET['sistema'] : ''));
    if ($desde === '') $desde = date('Y-m-d', strtotime('-29 days'));
    if ($hasta === '') $hasta = date('Y-m-d');

    $filas = leerFilas($desde, $hasta, $sistema);

    $porMod = array(); $porUsr = array(); $porMaq = array(); $porDia = array(); $porSis = array();
    $usuarios = array(); $maquinas = array();

    foreach ($filas as $f) {
        $u = $f['user'] !== '' ? $f['user'] : ('uid ' . $f['uid']);
        $usuarios[$f['user']] = 1;
        $maquinas[$f['ip']] = 1;

        // por módulo
        $k = $f['mod'];
        if (!isset($porMod[$k])) $porMod[$k] = array('modulo' => $k, 'hits' => 0, 'ultimo' => '');
        $porMod[$k]['hits']++; if ($f['ts'] > $porMod[$k]['ultimo']) $porMod[$k]['ultimo'] = $f['ts'];

        // por usuario
        if (!isset($porUsr[$u])) $porUsr[$u] = array('user' => $u, 'hits' => 0, 'ultimo' => '', '_maq' => array());
        $porUsr[$u]['hits']++; if ($f['ts'] > $porUsr[$u]['ultimo']) $porUsr[$u]['ultimo'] = $f['ts'];
        $porUsr[$u]['_maq'][$f['ip']] = 1;

        // por máquina
        $mq = $f['ip'];
        if (!isset($porMaq[$mq])) $porMaq[$mq] = array('ip' => $mq, 'host' => $f['host'], 'hits' => 0, 'ultimo' => '', '_usr' => array());
        $porMaq[$mq]['hits']++; if ($f['ts'] > $porMaq[$mq]['ultimo']) $porMaq[$mq]['ultimo'] = $f['ts'];
        if ($f['host'] !== '') $porMaq[$mq]['host'] = $f['host'];
        $porMaq[$mq]['_usr'][$u] = 1;

        // por sistema
        $s = $f['sistema'];
        if (!isset($porSis[$s])) $porSis[$s] = array('sistema' => $s, 'hits' => 0, 'ultimo' => '', '_usr' => array());
        $porSis[$s]['hits']++; if ($f['ts'] > $porSis[$s]['ultimo']) $porSis[$s]['ultimo'] = $f['ts'];
        $porSis[$s]['_usr'][$u] = 1;

        // por día
        if (!isset($porDia[$f['dia']])) $porDia[$f['dia']] = 0;
        $porDia[$f['dia']]++;
    }

    // contar distinct y limpiar internos
    foreach ($porUsr as &$x) { $x['maquinas'] = count($x['_maq']); unset($x['_maq']); } unset($x);
    foreach ($porMaq as &$x) { $x['usuarios'] = count($x['_usr']); unset($x['_usr']); } unset($x);
    foreach ($porSis as &$x) { $x['usuarios'] = count($x['_usr']); unset($x['_usr']); } unset($x);

    // ordenar por hits desc
    $byHits = function ($a, $b) { return $b['hits'] - $a['hits']; };
    $vMod = array_values($porMod); usort($vMod, $byHits);
    $vUsr = array_values($porUsr); usort($vUsr, $byHits);
    $vMaq = array_values($porMaq); usort($vMaq, $byHits);
    $vSis = array_values($porSis); usort($vSis, $byHits);
    ksort($porDia);
    $vDia = array();
    foreach ($porDia as $d => $n) $vDia[] = array('dia' => $d, 'hits' => $n);

    ok(array(
        'desde' => $desde, 'hasta' => $hasta, 'sistema' => $sistema,
        'sistemas' => array_keys(uso_sistemas()),   // para el dropdown de filtro
        'kpis' => array(
            'hits' => count($filas),
            'usuarios' => count($usuarios),
            'maquinas' => count($maquinas),
            'dias' => count($porDia),
        ),
        'porSistema' => $vSis,
        'porModulo'  => $vMod,
        'porUsuario' => $vUsr,
        'porMaquina' => $vMaq,
        'porDia'     => $vDia,
    ));
}
