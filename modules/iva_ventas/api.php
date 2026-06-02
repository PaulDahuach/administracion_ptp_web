<?php
/**
 * I.V.A. Ventas — API solo-lectura. Porta el "Rpt CD IVA" del legacy.
 *
 * Inclusión: JOIN [Tbl Operaciones Auxiliares] por CODAUX con IVAAUX=True
 * (NO por codope). Columnas por comprobante (NC=460 negado):
 *   Neto Gravado = NETMOV · IVA = IRIMOV · No Gravado = NOGMOV
 *   Ajustes = ABIMOV+ARDMOV · Percep. IIBB = PIXMOV · Total = TOTMOV
 * Cond. IVA = INICRI (Tbl Categorias Responsabilidad IVA por CODCRI).
 * Filtra por ESTMOV (libro blanco/negro/todos). Validado vs PDF Ago-2023.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {
        case 'list': listar(); break;
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

function listar() {
    $desde = isset($_GET['desde']) ? $_GET['desde'] : '';
    $hasta = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $libro = isset($_GET['libro']) ? $_GET['libro'] : 'blanco'; // IVA: por defecto el libro declarado
    $sd = iso_to_serial($desde);
    $sh = iso_to_serial($hasta);
    if ($sd === null || $sh === null) { fail('Indicá el período (desde / hasta)'); return; }

    $w = "M.CODORI='D' AND O.IVAAUX=True AND M.FEXMOV BETWEEN $sd AND $sh";
    if ($libro === 'blanco')    $w .= " AND M.ESTMOV=True";
    elseif ($libro === 'negro') $w .= " AND M.ESTMOV=False";

    $rows = db_query("SELECT M.NUMMOV, M.FEXMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DENMOV,
        M.CITMOV, M.CODOPE, C.INICRI, M.NETMOV, M.IRIMOV, M.NOGMOV, M.ABIMOV, M.ARDMOV, M.PIXMOV, M.TOTMOV
        FROM (([Tbl Movimientos] AS M
          LEFT JOIN [Tbl Categorias Responsabilidad IVA] AS C ON C.CODCRI = M.CODCRI)
          INNER JOIN [Tbl Operaciones Auxiliares] AS O ON O.CODAUX = M.CODAUX)
        WHERE $w ORDER BY M.FEXMOV, M.NUMMOV");

    $comps = array();
    $T = array('neto' => 0.0, 'iva' => 0.0, 'nograv' => 0.0, 'ajuste' => 0.0, 'percep' => 0.0, 'total' => 0.0);
    $resumen = array(); // clave "TIPO|ALIC" => totales

    foreach ($rows as $m) {
        $sg = ((int) $m['CODOPE'] == 460) ? -1 : 1;
        $neto   = $sg * (float) nz($m['NETMOV'], 0);
        $iva    = $sg * (float) nz($m['IRIMOV'], 0);
        $nograv = $sg * (float) nz($m['NOGMOV'], 0);
        $ajuste = $sg * ((float) nz($m['ABIMOV'], 0) + (float) nz($m['ARDMOV'], 0));
        $percep = $sg * (float) nz($m['PIXMOV'], 0);
        $total  = $sg * (float) nz($m['TOTMOV'], 0);

        $T['neto'] += $neto; $T['iva'] += $iva; $T['nograv'] += $nograv;
        $T['ajuste'] += $ajuste; $T['percep'] += $percep; $T['total'] += $total;

        $cic = trim((string) nz($m['CICMOV'], ''));
        $cii = trim((string) nz($m['CIIMOV'], ''));
        $pdv = str_pad((string) (int) nz($m['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
        $nro = str_pad((string) (int) nz($m['CINMOV'], 0), 8, '0', STR_PAD_LEFT);

        // Alícuota efectiva (IVA / Neto). Textil PTP casi siempre 21%.
        $alic = (abs($neto) > 0.005) ? round(abs($iva) / abs($neto) * 100, 1) : 0.0;
        $rk = $cic . '|' . number_format($alic, 2, '.', '');
        if (!isset($resumen[$rk])) $resumen[$rk] = array('tipo' => $cic, 'alicuota' => $alic,
            'neto' => 0.0, 'iva' => 0.0, 'nograv' => 0.0, 'ajuste' => 0.0, 'percep' => 0.0, 'total' => 0.0, 'n' => 0);
        $resumen[$rk]['neto'] += $neto; $resumen[$rk]['iva'] += $iva; $resumen[$rk]['nograv'] += $nograv;
        $resumen[$rk]['ajuste'] += $ajuste; $resumen[$rk]['percep'] += $percep; $resumen[$rk]['total'] += $total;
        $resumen[$rk]['n']++;

        $comps[] = array(
            'NUMMOV'  => (int) $m['NUMMOV'],
            'FECHA'   => fecha_serial($m['FEXMOV']),
            'COMP'    => $cic . ($cii !== '' ? ' ' . $cii : '') . ' ' . $pdv . '-' . $nro,
            'TIPO'    => $cic,
            'DENMOV'  => trim((string) nz($m['DENMOV'], '')),
            'CITMOV'  => trim((string) nz($m['CITMOV'], '')),
            'INICRI'  => trim((string) nz($m['INICRI'], '')),
            'NETO'    => round($neto, 2),
            'IVA'     => round($iva, 2),
            'NOGRAV'  => round($nograv, 2),
            'AJUSTE'  => round($ajuste, 2),
            'PERCEP'  => round($percep, 2),
            'TOTAL'   => round($total, 2),
        );
    }

    // ordenar resumen por tipo, alícuota
    $res = array_values($resumen);
    usort($res, function ($a, $b) {
        if ($a['tipo'] !== $b['tipo']) return strcmp($a['tipo'], $b['tipo']);
        if ($a['alicuota'] == $b['alicuota']) return 0;
        return ($a['alicuota'] < $b['alicuota']) ? -1 : 1;
    });
    foreach ($res as &$r) { foreach (array('neto','iva','nograv','ajuste','percep','total') as $k) $r[$k] = round($r[$k], 2); }
    unset($r);
    foreach ($T as $k => $v) $T[$k] = round($v, 2);

    ok(array(
        'comprobantes' => $comps,
        'cantidad'     => count($comps),
        'totales'      => $T,
        'resumen'      => $res,
    ));
}
