<?php
/**
 * Búsqueda de Comprobantes — API solo-lectura.
 * Busca en [Tbl Movimientos] por texto (razón social / CUIT / CAE / nº comp. / nº mov.),
 * tipo (CICMOV), importe (TOTMOV ±0,50), rango de fecha (FEXMOV) y libro (ESTMOV).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {
        case 'search': buscar(); break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function iso_to_serial($iso) {
    if (!$iso) return null;
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$d) return null;
    $base = new DateTime('1899-12-30');
    return (int) $base->diff($d)->days;
}

function buscar() {
    $q     = isset($_GET['q']) ? trim($_GET['q']) : '';
    $tipo  = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
    $imp   = isset($_GET['importe']) ? (float) $_GET['importe'] : 0;
    $libro = isset($_GET['libro']) ? $_GET['libro'] : 'todos';
    $forz = auth_libro_unico();
    if ($forz !== '') $libro = $forz;  // operador→blanco, capacitación→capacitacion
    $sd = iso_to_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
    $sh = iso_to_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');

    if ($q === '' && $tipo === '' && $imp <= 0 && $sd === null) {
        fail('Ingresá al menos un criterio de búsqueda.'); return;
    }

    $where = array();
    if ($q !== '') {
        $qs = db_esc($q);
        $parts = "M.DENMOV Like '%$qs%' OR M.CITMOV Like '%$qs%' OR M.CAEMOV Like '%$qs%'";
        if (ctype_digit($q)) $parts .= " OR M.CINMOV=$q OR M.NUMMOV=$q";
        $where[] = "($parts)";
    }
    if ($tipo !== '') $where[] = "M.CICMOV='" . db_esc($tipo) . "'";
    if ($imp > 0)     $where[] = "(M.TOTMOV BETWEEN " . ($imp - 0.5) . " AND " . ($imp + 0.5) . ")";
    if ($sd !== null) $where[] = "M.FEXMOV >= $sd";
    if ($sh !== null) $where[] = "M.FEXMOV <= $sh";
    if ($libro === 'blanco')    $where[] = "M.ESTMOV=True";
    elseif ($libro === 'capacitacion') $where[] = "M.ESTMOV=False";

    // Operaciones (codope -> denope)
    $op = array();
    foreach (db_query("SELECT CODOPE, DENOPE FROM [Tbl Operaciones]") as $o)
        $op[(int) $o['CODOPE']] = trim((string) nz($o['DENOPE'], ''));

    $sql = "SELECT TOP 200 M.NUMMOV, M.FEXMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.CODOPE,
        M.CODORI, M.CODCUE, M.DENMOV, M.CITMOV, M.TOTMOV, M.CAEMOV, M.ANUMOV, M.ESTMOV
        FROM [Tbl Movimientos] AS M
        WHERE " . implode(' AND ', $where) . "
        ORDER BY M.FEXMOV DESC, M.NUMMOV DESC";
    $rows = db_query($sql);

    $out = array();
    foreach ($rows as $m) {
        $cic = trim((string) nz($m['CICMOV'], ''));
        $cii = trim((string) nz($m['CIIMOV'], ''));
        $pdv = str_pad((string) (int) nz($m['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
        $nro = str_pad((string) (int) nz($m['CINMOV'], 0), 8, '0', STR_PAD_LEFT);
        $codope = (int) nz($m['CODOPE'], 0);
        $ori = trim((string) nz($m['CODORI'], ''));
        $anu = ($m['ANUMOV'] === true || $m['ANUMOV'] == -1);
        $out[] = array(
            'NUMMOV' => (int) $m['NUMMOV'],
            'FECHA'  => fecha_serial($m['FEXMOV']),
            'COMP'   => $cic . ($cii !== '' ? ' ' . $cii : '') . ' ' . $pdv . '-' . $nro,
            'OPER'   => isset($op[$codope]) ? $op[$codope] : '',
            'ORI'    => $ori,
            'CODCUE' => (int) nz($m['CODCUE'], 0),
            'DENMOV' => trim((string) nz($m['DENMOV'], '')),
            'CITMOV' => trim((string) nz($m['CITMOV'], '')),
            'TOTAL'  => round((float) nz($m['TOTMOV'], 0), 2),
            'CAE'    => trim((string) nz($m['CAEMOV'], '')),
            'ANULADO'=> $anu ? 1 : 0,
        );
    }

    ok(array('comprobantes' => $out, 'cantidad' => count($out), 'tope' => count($out) >= 200));
}
