<?php
/**
 * I.V.A. Compras — API solo-lectura. Porta el "Rpt 00 IVA" (Caption I.V.A. Compras).
 *
 * Diferencias vs IVA Ventas:
 *   - Fecha = FIXMOV (fecha de imputación, no FEXMOV).
 *   - CODORI IN ('A','I') (acreedores + internos/gastos con IVA).
 *   - Inclusión = A.IVAAUX=True OR O.IVAOPE=True (join a Operaciones y Op. Auxiliares).
 *   - Negación cuando CODAUX=139 (devoluciones gravadas) o CODOPE=330 (ND compras).
 *   - Dos percepciones: IP1MOV (Percep. IVA) e IP2MOV (Percep. IIBB).
 * Filtra por ESTMOV (libro blanco/negro/todos).
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
    $libro = isset($_GET['libro']) ? $_GET['libro'] : 'blanco';
    $forz = auth_libro_unico();
    if ($forz !== '') $libro = $forz;  // operador→blanco, capacitación→negro
    $sd = iso_to_serial($desde);
    $sh = iso_to_serial($hasta);
    if ($sd === null || $sh === null) { fail('Indicá el período (desde / hasta)'); return; }

    $w = "M.FIXMOV BETWEEN $sd AND $sh AND (M.CODORI='A' OR M.CODORI='I') AND (A.IVAAUX=True OR O.IVAOPE=True)";
    if ($libro === 'blanco')    $w .= " AND M.ESTMOV=True";
    elseif ($libro === 'negro') $w .= " AND M.ESTMOV=False";

    $rows = db_query("SELECT M.FIXMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.DENMOV, M.CITMOV,
        M.CODOPE, M.CODAUX, C.INICRI, M.NETMOV, M.IRIMOV, M.NOGMOV, M.IP1MOV, M.IP2MOV, M.TOTMOV
        FROM ((([Tbl Movimientos] AS M
          LEFT JOIN [Tbl Categorias Responsabilidad IVA] AS C ON C.CODCRI = M.CODCRI)
          LEFT JOIN [Tbl Operaciones Auxiliares] AS A ON A.CODAUX = M.CODAUX)
          LEFT JOIN [Tbl Operaciones] AS O ON O.CODOPE = M.CODOPE)
        WHERE $w ORDER BY M.FIXMOV, M.NUMMOV");

    $comps = array();
    $T = array('neto' => 0.0, 'iva' => 0.0, 'nograv' => 0.0, 'percIva' => 0.0, 'percIib' => 0.0, 'total' => 0.0);

    foreach ($rows as $m) {
        $sg = ((int) $m['CODAUX'] == 139 || (int) $m['CODOPE'] == 330) ? -1 : 1;
        $neto   = $sg * (float) nz($m['NETMOV'], 0);
        $iva    = $sg * (float) nz($m['IRIMOV'], 0);
        $nograv = $sg * (float) nz($m['NOGMOV'], 0);
        $pIva   = $sg * (float) nz($m['IP1MOV'], 0);
        $pIib   = $sg * (float) nz($m['IP2MOV'], 0);
        $total  = $sg * (float) nz($m['TOTMOV'], 0);

        $T['neto'] += $neto; $T['iva'] += $iva; $T['nograv'] += $nograv;
        $T['percIva'] += $pIva; $T['percIib'] += $pIib; $T['total'] += $total;

        $cic = trim((string) nz($m['CICMOV'], ''));
        $cii = trim((string) nz($m['CIIMOV'], ''));
        $pdv = str_pad((string) (int) nz($m['CIPMOV'], 0), 4, '0', STR_PAD_LEFT);
        $nro = str_pad((string) (int) nz($m['CINMOV'], 0), 8, '0', STR_PAD_LEFT);

        $comps[] = array(
            'FECHA'   => fecha_serial($m['FIXMOV']),
            'COMP'    => $cic . ($cii !== '' ? ' ' . $cii : '') . ' ' . $pdv . '-' . $nro,
            'TIPO'    => $cic,
            'DENMOV'  => trim((string) nz($m['DENMOV'], '')),
            'CITMOV'  => trim((string) nz($m['CITMOV'], '')),
            'INICRI'  => trim((string) nz($m['INICRI'], '')),
            'NETO'    => round($neto, 2),
            'IVA'     => round($iva, 2),
            'NOGRAV'  => round($nograv, 2),
            'PERCIVA' => round($pIva, 2),
            'PERCIIB' => round($pIib, 2),
            'TOTAL'   => round($total, 2),
        );
    }

    foreach ($T as $k => $v) $T[$k] = round($v, 2);

    // Resumen EXACTO por comprobante y alícuota desde [Tbl Movimientos IVA].
    $res = resumen_alicuotas_compras($w);

    ok(array(
        'comprobantes' => $comps,
        'cantidad'     => count($comps),
        'totales'      => $T,
        'resumen'      => $res,
    ));
}

/** Split exacto por (comprobante, alícuota) desde [Tbl Movimientos IVA] (compras). */
function resumen_alicuotas_compras($w) {
    $rows = db_query("SELECT M.CICMOV, M.CODOPE AS OPE, M.CODAUX AS AUX, MI.ALIMOV AS ALI,
        SUM(MI.NETMOV) AS NET, SUM(MI.IRIMOV) AS IRI, COUNT(*) AS N
        FROM ((([Tbl Movimientos] AS M
          LEFT JOIN [Tbl Movimientos IVA] AS MI ON M.NUMMOV = MI.NUMMOV)
          LEFT JOIN [Tbl Operaciones Auxiliares] AS A ON A.CODAUX = M.CODAUX)
          LEFT JOIN [Tbl Operaciones] AS O ON O.CODOPE = M.CODOPE)
        WHERE $w GROUP BY M.CICMOV, M.CODOPE, M.CODAUX, MI.ALIMOV");

    $buckets = array();
    foreach ($rows as $r) {
        $sg = ((int) $r['AUX'] == 139 || (int) $r['OPE'] == 330) ? -1 : 1;
        $cic = trim((string) nz($r['CICMOV'], ''));
        $cic = ($cic !== '') ? $cic : 'OTROS';
        $ali = ($r['ALI'] === null) ? 0.0 : round((float) $r['ALI'], 2);
        $k = $cic . '|' . number_format($ali, 2, '.', '');
        if (!isset($buckets[$k])) $buckets[$k] = array('tipo' => $cic, 'alicuota' => $ali, 'neto' => 0.0, 'iva' => 0.0, 'n' => 0);
        $buckets[$k]['neto'] += $sg * (float) nz($r['NET'], 0);
        $buckets[$k]['iva']  += $sg * (float) nz($r['IRI'], 0);
        $buckets[$k]['n']    += (int) $r['N'];
    }
    $res = array_values($buckets);
    usort($res, function ($a, $b) {
        if ($a['tipo'] !== $b['tipo']) return strcmp($a['tipo'], $b['tipo']);
        if ($a['alicuota'] == $b['alicuota']) return 0;
        return ($a['alicuota'] < $b['alicuota']) ? -1 : 1;
    });
    foreach ($res as &$r) { $r['neto'] = round($r['neto'], 2); $r['iva'] = round($r['iva'], 2); }
    unset($r);
    return $res;
}
