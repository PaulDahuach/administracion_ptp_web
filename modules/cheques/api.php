<?php
/**
 * Cheques (de terceros) — API solo-lectura. Sobre [Tbl Cheques].
 * Estados: VADCHQ=true → Valor a Depositar (en cartera); DIFCHQ=true → Diferido.
 * FEXCHQ = fecha emisión/recepción · FAXCHQ = fecha de acreditación · CODBAN → banco.
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
    $estado = isset($_GET['estado']) ? $_GET['estado'] : 'depositar';
    $q      = isset($_GET['q']) ? trim($_GET['q']) : '';
    $imp    = isset($_GET['importe']) ? (float) $_GET['importe'] : 0;
    $dbasis = (isset($_GET['base']) && $_GET['base'] === 'acred') ? 'FAXCHQ' : 'FEXCHQ';
    $sd = iso_to_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
    $sh = iso_to_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');

    $where = array();
    if ($estado === 'depositar')      $where[] = "VADCHQ=True";
    elseif ($estado === 'diferidos')  $where[] = "DIFCHQ=True";
    elseif ($estado === 'cartera')    $where[] = "(VADCHQ=True OR DIFCHQ=True)";
    // 'todos' → sin filtro de estado

    if ($q !== '') {
        $qs = db_esc($q);
        $where[] = "(SYNCHQ Like '%$qs%' OR LIBCHQ Like '%$qs%' OR CITCHQ Like '%$qs%')";
    }
    if ($imp > 0)     $where[] = "(IMPCHQ BETWEEN " . ($imp - 0.5) . " AND " . ($imp + 0.5) . ")";
    if ($sd !== null) $where[] = "$dbasis >= $sd";
    if ($sh !== null) $where[] = "$dbasis <= $sh";

    $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    // Orden: si viene del menú (?orden=acred|entrada) se respeta; si no, por estado.
    $ordp = isset($_GET['orden']) ? $_GET['orden'] : '';
    if ($ordp === 'acred')                          $orden = 'FAXCHQ ASC';
    elseif ($ordp === 'emi' || $ordp === 'entrada') $orden = 'FEXCHQ ASC';
    else $orden = in_array($estado, array('depositar', 'diferidos', 'cartera')) ? 'FAXCHQ ASC' : 'FEXCHQ DESC';

    // Bancos
    $ban = array();
    foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos]") as $b)
        $ban[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));

    $rows = db_query("SELECT TOP 500 CODCHQ, CODBAN, SYNCHQ, FEXCHQ, FAXCHQ, PLZCHQ, LIBCHQ, CITCHQ, LOCCHQ, IMPCHQ, VADCHQ, DIFCHQ
        FROM [Tbl Cheques] $wsql ORDER BY $orden");

    $out = array();
    $total = 0.0;
    foreach ($rows as $c) {
        $imc = (float) nz($c['IMPCHQ'], 0);
        $total += $imc;
        $codban = (int) nz($c['CODBAN'], 0);
        $out[] = array(
            'CODCHQ' => (int) $c['CODCHQ'],
            'BANCO'  => isset($ban[$codban]) ? $ban[$codban] : ('Banco ' . $codban),
            'NRO'    => trim((string) nz($c['SYNCHQ'], '')),
            'LIB'    => trim((string) nz($c['LIBCHQ'], '')),
            'CIT'    => trim((string) nz($c['CITCHQ'], '')),
            'LOC'    => trim((string) nz($c['LOCCHQ'], '')),
            'FEMI'   => fecha_serial($c['FEXCHQ']),
            'FEMIO'  => (int) nz($c['FEXCHQ'], 0),   // serial para ordenar la columna Emisión
            'FACR'   => fecha_serial($c['FAXCHQ']),
            'FACRO'  => (int) nz($c['FAXCHQ'], 0),   // serial para ordenar la columna Acred.
            'IMP'    => round($imc, 2),
            'VAD'    => ($c['VADCHQ'] === true || $c['VADCHQ'] == -1) ? 1 : 0,
            'DIF'    => ($c['DIFCHQ'] === true || $c['DIFCHQ'] == -1) ? 1 : 0,
        );
    }

    ok(array('cheques' => $out, 'cantidad' => count($out), 'total' => round($total, 2), 'tope' => count($out) >= 500));
}
