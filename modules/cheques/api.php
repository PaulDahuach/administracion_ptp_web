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
    $base = isset($_GET['base']) ? $_GET['base'] : 'emi';
    $sd = iso_to_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
    $sh = iso_to_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');

    // Fecha de INGRESO a cartera = FEXMOV del movimiento imputado a la cuenta de cheques en
    // cartera (Rec Control.CACC_2). Subconsulta agregada (Min de la fecha de ingreso por cheque).
    // Doble libro: el "libro" de un cheque = el ESTMOV de su movimiento de INGRESO a cartera.
    // Filtramos el ingreso por el modo activo y luego exigimos que el cheque tenga ingreso en ese
    // libro (Ent.CC IS NOT NULL) → un Operador no ve cheques de Capacitación.
    $lib    = auth_libro_unico();   // 'blanco' | 'negro' | ''
    $estIng = ($lib === 'blanco') ? ' AND M.ESTMOV=True' : (($lib === 'negro') ? ' AND M.ESTMOV=False' : '');
    $rc     = db_row("SELECT CACC_2 FROM [Rec Control];");
    $cacc2  = "'" . db_esc(isset($rc['CACC_2']) ? (string) $rc['CACC_2'] : '') . "'";
    $entSub = "(SELECT MoC.CODCHQ AS CC, Min(M.FEXMOV) AS FE
                FROM [Tbl Movimientos Imputaciones] AS MoC INNER JOIN [Tbl Movimientos] AS M ON MoC.NUMMOV = M.NUMMOV
                WHERE MoC.CODCUE = $cacc2 AND MoC.DEBMOV > 0 AND MoC.CODCHQ > 0$estIng
                GROUP BY MoC.CODCHQ)";
    // Base del filtro desde/hasta: emisión (FEXCHQ), acreditación (FAXCHQ) o ingreso (Ent.FE).
    if ($base === 'acred')       $dbasis = 'Chq.FAXCHQ';
    elseif ($base === 'entrada') $dbasis = 'Ent.FE';
    else                         $dbasis = 'Chq.FEXCHQ';

    $where = array();
    if ($lib !== '') $where[] = "Ent.CC IS NOT NULL";   // sólo cheques del libro activo (por su ingreso)
    if ($estado === 'depositar')      $where[] = "Chq.VADCHQ=True";
    elseif ($estado === 'diferidos')  $where[] = "Chq.DIFCHQ=True";
    elseif ($estado === 'cartera')    $where[] = "(Chq.VADCHQ=True OR Chq.DIFCHQ=True)";
    // 'todos' → sin filtro de estado

    if ($q !== '') {
        $qs = db_esc($q);
        $where[] = "(Chq.SYNCHQ Like '%$qs%' OR Chq.LIBCHQ Like '%$qs%' OR Chq.CITCHQ Like '%$qs%')";
    }
    if ($imp > 0)     $where[] = "(Chq.IMPCHQ BETWEEN " . ($imp - 0.5) . " AND " . ($imp + 0.5) . ")";
    if ($sd !== null) $where[] = "$dbasis >= $sd";
    if ($sh !== null) $where[] = "$dbasis <= $sh";

    $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    // Orden: si viene del menú (?orden=acred|entrada) se respeta; si no, por estado.
    $ordp = isset($_GET['orden']) ? $_GET['orden'] : '';
    if ($ordp === 'acred')        $orden = 'Chq.FAXCHQ ASC';
    elseif ($ordp === 'entrada')  $orden = 'Ent.FE ASC';
    elseif ($ordp === 'emi')      $orden = 'Chq.FEXCHQ ASC';
    else $orden = in_array($estado, array('depositar', 'diferidos', 'cartera')) ? 'Chq.FAXCHQ ASC' : 'Chq.FEXCHQ DESC';

    // Bancos
    $ban = array();
    foreach (db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos]") as $b)
        $ban[(int) $b['CODBAN']] = trim((string) nz($b['DENBAN'], ''));

    $rows = db_query("SELECT TOP 500 Chq.CODCHQ, Chq.CODBAN, Chq.SYNCHQ, Chq.FEXCHQ, Chq.FAXCHQ, Chq.PLZCHQ, Chq.LIBCHQ, Chq.CITCHQ, Chq.LOCCHQ, Chq.IMPCHQ, Chq.VADCHQ, Chq.DIFCHQ, Ent.FE AS FENTR
        FROM [Tbl Cheques] AS Chq LEFT JOIN $entSub AS Ent ON Chq.CODCHQ = Ent.CC
        $wsql ORDER BY $orden");

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
            'FENT'   => fecha_serial($c['FENTR']),   // fecha de ingreso a cartera (FEXMOV)
            'FENTO'  => (int) nz($c['FENTR'], 0),    // serial para ordenar la columna Ingreso
            'FACR'   => fecha_serial($c['FAXCHQ']),
            'FACRO'  => (int) nz($c['FAXCHQ'], 0),   // serial para ordenar la columna Acred.
            'IMP'    => round($imc, 2),
            'VAD'    => ($c['VADCHQ'] === true || $c['VADCHQ'] == -1) ? 1 : 0,
            'DIF'    => ($c['DIFCHQ'] === true || $c['DIFCHQ'] == -1) ? 1 : 0,
        );
    }

    ok(array('cheques' => $out, 'cantidad' => count($out), 'total' => round($total, 2), 'tope' => count($out) >= 500));
}
