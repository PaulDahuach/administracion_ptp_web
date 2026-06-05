<?php
/**
 * Remitos deudores (CODOPE=410, CICMOV=RV) — API. Primer transaccional (escritura).
 * Porta Frm CD Remitos NF (SetData Case "A"): inserta header en [Tbl Movimientos] + líneas en
 * [Tbl Movimientos Stock] + descarga [Tbl Stock] (si el producto controla stock), en transacción.
 * No mueve cuenta corriente (410 no lleva DEBMOV/CREMOV). Numeración: NUMMOV global (ULTMOV),
 * CINMOV por PDV (ULTRMV; 9999 en Capacitación). ESTMOV/PDV según el MODO activo (doble libro).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {
        case 'buscar_clientes':  buscar_clientes();  break;
        case 'get_cliente':      get_cliente();      break;
        case 'buscar_productos': buscar_productos(); break;
        case 'get_producto':     get_producto();     break;
        case 'pdvs':             listar_pdvs();      break;
        case 'guardar':          guardar();          break;
        case 'listar':           listar();           break;
        case 'detalle':          detalle();          break;
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

/** Literal SQL de texto: 'escapado' o Null si vacío (varios campos de Access no admiten cadena vacía). */
function sql_txt($s) {
    $s = trim((string) $s);
    return $s === '' ? 'Null' : "'" . db_esc($s) . "'";
}

/** Datos del cliente para el header (replica CODCUE_AfterUpdate). */
function cliente_datos($codcue) {
    $cc = (int) $codcue;
    return db_row("SELECT C.CODCUE, C.DENCUE, C.CODCRI, C.CITCUE, C.DCXCUE, C.DNXCUE, C.DPXCUE, C.DDXCUE,
            C.CODLOC, L.DENLOC, P.DENPRO, C.CODTRA, Cat.CODCAT, Cat.LDPCAT
        FROM ([Tbl Provincias] AS P RIGHT JOIN ([Tbl Localidades] AS L INNER JOIN [Tbl Cuentas Corrientes] AS C ON L.CODLOC = C.CODLOC) ON P.CODPRO = L.CODPRO)
            INNER JOIN [Tbl Categorias Cuentas Corrientes] AS Cat ON C.CODCAT = Cat.CODCAT
        WHERE C.CODCUE = $cc;");
}

/** Saldo del cliente en el libro activo (replica ESTMOV_AfterUpdate: ΣDEB−ΣCRE filtrado por ESTMOV). */
function cliente_saldo($codcue, $estmov_true) {
    $cc = (int) $codcue;
    $est = $estmov_true ? 'True' : 'False';
    $r = db_row("SELECT SUM(DEBMOV) AS D, SUM(CREMOV) AS C FROM [Tbl Movimientos]
        WHERE CODCUE = $cc AND ESTMOV = $est;");
    return round((float) nz($r['D'], 0) - (float) nz($r['C'], 0), 2);
}

function buscar_clientes() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    $num = is_numeric($q) ? " OR CODCUE = " . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes]
        WHERE CODORI='D' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

function get_cliente() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $d = cliente_datos($cc);
    if (!$d) { fail('Cliente no encontrado'); return; }
    $estTrue = (auth_modo() !== 'capacitacion');   // operador/integral → blanco; capacitación → negro
    $d['SALDO'] = cliente_saldo($cc, $estTrue);
    $d['DOMICILIO'] = trim(nz($d['DCXCUE'], '') . ' ' . nz($d['DNXCUE'], ''));
    $d['LOCALIDAD'] = trim(nz($d['DENLOC'], '') . ' - ' . nz($d['DENPRO'], ''));
    ok($d);
}

function buscar_productos() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    ok(db_query("SELECT TOP 20 CODPRO, DENPRO, CODCAT FROM [Tbl Productos]
        WHERE (CODPRO Like '%$s%') OR (DENPRO Like '%$s%') ORDER BY DENPRO;"));
}

/**
 * Precio neto unitario SUGERIDO (replica RutGetPrecio del subform):
 *   base = exclusión del cliente (Tbl Cuentas Corrientes Exclusiones.PUNPRO) si existe, si no PLVPRO.
 *   pun  = base × factor de la unidad × cotización de la moneda (Tbl Monedas.COTMON; P=1).
 *   si NO hay exclusión → restar la bonificación del cliente (LDPCAT de su categoría).
 * (El dólar del legacy usa la cotización del propio remito; acá usamos Tbl Monedas. Editable igual.)
 */
function precio_sugerido($codpro, $codcue, $plvpro, $fct, $codmon) {
    $cp = db_esc($codpro);
    $excl = null;
    if ($codcue > 0) {
        $ex = db_row("SELECT PUNPRO FROM [Tbl Cuentas Corrientes Exclusiones] WHERE CODCUE=" . (int) $codcue . " AND CODPRO='$cp';");
        if ($ex && $ex['PUNPRO'] !== null && $ex['PUNPRO'] !== '') $excl = (float) $ex['PUNPRO'];
    }
    $pun = (($excl !== null) ? $excl : (float) $plvpro) * (float) $fct;
    $cot = db_row("SELECT COTMON FROM [Tbl Monedas] WHERE CODMON='" . db_esc($codmon) . "';");
    $pun *= (float) nz($cot ? $cot['COTMON'] : 1, 1);
    if ($excl === null && $codcue > 0) {
        $cli = db_row("SELECT Cat.LDPCAT FROM [Tbl Cuentas Corrientes] AS C INNER JOIN [Tbl Categorias Cuentas Corrientes] AS Cat ON C.CODCAT=Cat.CODCAT WHERE C.CODCUE=" . (int) $codcue . ";");
        $pdc = (float) nz($cli ? $cli['LDPCAT'] : 0, 0);
        $pun -= $pun * $pdc / 100;
    }
    return round($pun, 4);
}

/** Datos de un producto para una línea: unidad/factor/decimales/stock/existencia/costo/lista + precio sugerido. */
function get_producto() {
    $cp = isset($_GET['codpro']) ? db_esc(trim($_GET['codpro'])) : '';
    $suc = isset($_GET['codsuc']) ? (int) $_GET['codsuc'] : 1;
    $cue = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $pro = db_row("SELECT P.CODPRO, P.DENPRO, P.CODCAT, P.CODUDM, P.COSPRO, P.PLVPRO, P.CODMON, Cat.STKCAT
        FROM [Tbl Productos] AS P INNER JOIN [Tbl Categorias Productos] AS Cat ON P.CODCAT = Cat.CODCAT
        WHERE P.CODPRO = '$cp';");
    if (!$pro) { fail('Producto no encontrado'); return; }
    $udm = (int) nz($pro['CODUDM'], 0);
    $pu = db_row("SELECT FCTPUM FROM [Tbl Productos Unidades] WHERE CODPRO='$cp' AND CODUDM=$udm;");
    $um = db_row("SELECT DENUDM, DECUDM FROM [Tbl Unidades de Medida] WHERE CODUDM=$udm;");
    $stk = ($pro['STKCAT'] === true || $pro['STKCAT'] == -1);
    $fct = (float) nz($pu['FCTPUM'], 1);
    $exi = null;
    if ($stk) {
        $e = db_row("SELECT EXISTK FROM [Tbl Stock] WHERE CODPRO='$cp' AND CODSUC=$suc;");
        $exi = $e ? round((float) nz($e['EXISTK'], 0) / ($fct ?: 1), 4) : 0;
    }
    $mon = trim((string) nz($pro['CODMON'], 'P'));
    $pul = round((float) nz($pro['PLVPRO'], 0), 4);
    ok(array(
        'CODPRO' => trim($pro['CODPRO']), 'DENPRO' => trim(nz($pro['DENPRO'], '')),
        'CODUDM' => $udm, 'DENUDM' => trim(nz($um['DENUDM'], '')),
        'FCTPUM' => $fct, 'DECUDM' => (int) nz($um['DECUDM'], 2),
        'COSPRO' => round((float) nz($pro['COSPRO'], 0), 4), 'PLVPRO' => $pul,
        'STK' => $stk ? 1 : 0, 'CODMON' => $mon, 'EXISTENCIA' => $exi,
        'PUN_SUG' => precio_sugerido($cp, $cue, $pul, $fct, $mon),
    ));
}

/** PDVs válidos (para el combo en modo Operador). 9999 (capacitación) se excluye. */
function listar_pdvs() {
    $rows = db_query("SELECT CODPDV, NOMPDV FROM [Tbl Puntos de Venta] WHERE CODPDV <> 9999 ORDER BY CODPDV;");
    ok($rows);
}

/**
 * Graba un remito. Espera $_POST['data'] = JSON {codcue, fexmov(iso), frvmov(iso), coddst, codtra,
 * detmov, cotmov, vdxmov, cipmov(PDV; operador), lineas:[{codpro,denmov,codudm,fctmov,dummov,codmon,
 * cosmov,punmov,pucmov,pulmov,stk(bool),cant,odcmov,odpmov,pdlmov}]}.
 * Devuelve {nummov, cipmov, cinmov}. Numeración/ESTMOV/PDV según el modo activo.
 */
function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? $_POST['data'] : '';
    $d = json_decode($raw, true);
    if (!is_array($d)) { fail('Datos inválidos'); return; }

    $codcue = isset($d['codcue']) ? (int) $d['codcue'] : 0;
    $lineas = (isset($d['lineas']) && is_array($d['lineas'])) ? $d['lineas'] : array();
    if ($codcue <= 0)   { fail('Falta el cliente'); return; }
    if (!count($lineas)) { fail('El remito no tiene productos'); return; }

    $cli = cliente_datos($codcue);
    if (!$cli) { fail('Cliente inexistente'); return; }

    // Doble libro: ESTMOV y PDV según el modo. Capacitación → ESTMOV=False, PDV null (acumula en 9999).
    $modo = auth_modo();
    $estTrue = ($modo !== 'capacitacion');
    $estSql  = $estTrue ? 'True' : 'False';

    $fex = iso_to_serial(isset($d['fexmov']) ? $d['fexmov'] : '');
    $frv = iso_to_serial(isset($d['frvmov']) ? $d['frvmov'] : '');
    if ($fex === null) { fail('Falta la fecha de emisión'); return; }
    if ($frv === null) $frv = $fex;
    $coddst = isset($d['coddst']) ? (int) $d['coddst'] : 1;
    $pdcmov = round((float) nz($cli['LDPCAT'], 0), 2);   // bonificación = de la categoría del cliente
    $codtra = isset($d['codtra']) && $d['codtra'] !== '' ? (int) $d['codtra'] : (nz($cli['CODTRA'], null));
    $detmov = isset($d['detmov']) ? trim($d['detmov']) : '';
    $cotmov = isset($d['cotmov']) ? trim((string) $d['cotmov']) : '';   // COTMOV = cotización del dólar (numérico)
    $vdxIn  = isset($d['vdxmov']) ? trim((string) $d['vdxmov']) : '';
    $vdxSql = ($vdxIn === '' || (float) $vdxIn == 0) ? 'Null' : (string) round((float) $vdxIn, 2);  // sin valor declarado → Null

    // Total = Σ (cant × precio unit neto) de las líneas.
    $total = 0.0;
    foreach ($lineas as $l) $total += (float) nz($l['cant'], 0) * (float) nz($l['punmov'], 0);
    $total = round($total, 2);

    db_begin();
    try {
        $nummov = next_number('ULTMOV');
        if ($estTrue) {
            $cipmov = isset($d['cipmov']) ? (int) $d['cipmov'] : 0;
            if ($cipmov <= 0) throw new Exception('Elegí un punto de venta');
            $cinmov = next_number_pdv('ULTRMV', $cipmov);
            $cipSql = (string) $cipmov;
        } else {
            $cipmov = null;
            $cinmov = next_number_pdv('ULTRMV', null);   // 9999
            $cipSql = 'Null';
        }

        $saldo = cliente_saldo($codcue, $estTrue);
        $denSql = sql_txt(nz($cli['DENCUE'], ''));
        $citSql = sql_txt(nz($cli['CITCUE'], ''));
        $dcxSql = sql_txt(nz($cli['DCXCUE'], ''));
        $dnxSql = sql_txt(nz($cli['DNXCUE'], ''));
        $dpxSql = sql_txt(nz($cli['DPXCUE'], ''));
        $ddxSql = sql_txt(nz($cli['DDXCUE'], ''));
        $codloc = (int) nz($cli['CODLOC'], 0);
        $codcri = (int) nz($cli['CODCRI'], 0);
        $codcat = (int) nz($cli['CODCAT'], 0);
        $detSql = sql_txt($detmov);
        $cotSql = (is_numeric($cotmov) && (float) $cotmov != 0) ? (string) round((float) $cotmov, 4) : 'Null';  // cotización u$s
        $traSql = ($codtra === null || $codtra === '') ? 'Null' : (string) (int) $codtra;

        // --- Header en Tbl Movimientos (CODOPE=410, CICMOV='RV') ---
        db_exec("INSERT INTO [Tbl Movimientos]
            (NUMMOV, CODORI, CODOPE, FEXMOV, CICMOV, CIPMOV, CINMOV, CECMOV, CEPMOV, CENMOV, CEFMOV,
             CODCUE, DENMOV, SOCMOV, PDCMOV, DCXMOV, DNXMOV, DPXMOV, DDXMOV, CODLOC, CODCRI, CITMOV,
             CODCAT, CODTRA, CODDST, DETMOV, FRVMOV, SRPMOV, COTMOV, VDXMOV, TOTMOV, NUIMOV, NMIMOV,
             NOWMOV, ANUMOV, ESTMOV)
            VALUES ($nummov, 'D', 410, $fex, 'RV', $cipSql, $cinmov, 'RV', $cipSql, $cinmov, $fex,
             $codcue, $denSql, $saldo, $pdcmov, $dcxSql, $dnxSql, $dpxSql, $ddxSql, $codloc, $codcri, $citSql,
             $codcat, $traSql, $coddst, $detSql, $frv, True, $cotSql, $vdxSql, $total, 0, 0,
             Now(), False, $estSql);");

        // --- Líneas en Tbl Movimientos Stock + descarga de stock ---
        $ord = 0;
        foreach ($lineas as $l) {
            $ord++;
            $cp   = db_esc(trim(nz($l['codpro'], '')));
            $denSqlL = sql_txt(nz($l['denmov'], ''));
            $udm  = (int) nz($l['codudm'], 0);
            $fct  = (float) nz($l['fctmov'], 1);
            $dum  = (int) nz($l['dummov'], 2);
            $mon  = db_esc(trim(nz($l['codmon'], 'P')));
            $cos  = round((float) nz($l['cosmov'], 0), 4);
            $pun  = round((float) nz($l['punmov'], 0), 4);
            $puc  = round((float) nz($l['pucmov'], $pun), 4);
            $pul  = round((float) nz($l['pulmov'], 0), 4);
            $cant = round((float) nz($l['cant'], 0), 4);
            $stk  = !empty($l['stk']);
            $odc  = isset($l['odcmov']) && $l['odcmov'] !== '' ? (int) $l['odcmov'] : null;
            $odp  = isset($l['odpmov']) && $l['odpmov'] !== '' ? (int) $l['odpmov'] : null;
            $pdl  = isset($l['pdlmov']) && $l['pdlmov'] !== '' ? (int) $l['pdlmov'] : null;
            $odcSql = $odc === null ? 'Null' : (string) $odc;
            $odpSql = $odp === null ? 'Null' : (string) $odp;
            $pdlSql = $pdl === null ? 'Null' : (string) $pdl;
            // RV: si controla stock → EGRMOV=cant (y descarga Existencia); si no → SVCMOV=-cant.
            $egr = $stk ? "EGRMOV, " : "SVCMOV, ";
            $egrVal = $stk ? "$cant, " : (-$cant) . ", ";

            db_exec("INSERT INTO [Tbl Movimientos Stock]
                (NUMMOV, ORDMOV, CODSUC, CODPRO, DENMOV, CODUDM, FCTMOV, DUMMOV, CODMON, COSMOV,
                 {$egr}PUNMOV, PUCMOV, PULMOV, STKMOV, ODCMOV, ODPMOV, PDLMOV, CFVMOV)
                VALUES ($nummov, $ord, $coddst, '$cp', $denSqlL, $udm, $fct, $dum, '$mon', $cos,
                 {$egrVal}$pun, $puc, $pul, " . ($stk ? 'True' : 'False') . ", $odcSql, $odpSql, $pdlSql, 0);");

            if ($stk) {
                $desc = round($cant * $fct, 4);
                db_exec("UPDATE [Tbl Stock] SET EXISTK = EXISTK - $desc WHERE CODSUC=$coddst AND CODPRO='$cp';");
            }
        }

        db_commit();
        ok(array('nummov' => $nummov, 'cipmov' => $cipmov, 'cinmov' => $cinmov, 'total' => $total));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar el remito: ' . $e->getMessage(), 500);
    }
}

/** Filtro SQL de ESTMOV según el modo activo (doble libro). '' en integral/sistemas sin doble libro. */
function estmov_w() {
    $l = auth_libro_unico();
    if ($l === 'blanco') return ' AND ESTMOV=True';
    if ($l === 'negro')  return ' AND ESTMOV=False';
    return '';
}

function comp_str($cip, $cin) {
    $pdv = str_pad((string) (int) nz($cip, 0), 4, '0', STR_PAD_LEFT);
    $nro = str_pad((string) (int) nz($cin, 0), 8, '0', STR_PAD_LEFT);
    return 'RV ' . $pdv . '-' . $nro;
}

/** Listado de remitos (CODOPE=410) filtrado por el modo + texto/fecha. */
function listar() {
    $q  = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sd = iso_to_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
    $sh = iso_to_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');
    $w  = "CODOPE=410" . estmov_w();
    if ($q !== '') {
        $qs = db_esc($q);
        $cond = "(DENMOV Like '%$qs%' OR CITMOV Like '%$qs%'";
        if (is_numeric($q)) $cond .= " OR CINMOV=" . (int) $q;
        $cond .= ")";
        $w .= " AND $cond";
    }
    if ($sd !== null) $w .= " AND FEXMOV >= $sd";
    if ($sh !== null) $w .= " AND FEXMOV <= $sh";

    $rows = db_query("SELECT TOP 200 NUMMOV, FEXMOV, CIPMOV, CINMOV, CODCUE, DENMOV, TOTMOV, FRVMOV, SRPMOV, ANUMOV, ESTMOV
        FROM [Tbl Movimientos] WHERE $w ORDER BY FEXMOV DESC, NUMMOV DESC;");
    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            'NUMMOV' => (int) $r['NUMMOV'],
            'FEXMOV' => fecha_serial($r['FEXMOV']),
            'FEXMOVO'=> (int) nz($r['FEXMOV'], 0),
            'COMP'   => comp_str($r['CIPMOV'], $r['CINMOV']),
            'CODCUE' => (int) nz($r['CODCUE'], 0),
            'DENMOV' => trim((string) nz($r['DENMOV'], '')),
            'TOTMOV' => round((float) nz($r['TOTMOV'], 0), 2),
            'PEND'   => ($r['SRPMOV'] === true || $r['SRPMOV'] == -1) ? 1 : 0,   // SRPMOV=True → pendiente de facturar
            'ANU'    => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1) ? 1 : 0,
            'EST'    => ($r['ESTMOV'] === true || $r['ESTMOV'] == -1) ? 1 : 0,
        );
    }
    ok(array('remitos' => $out, 'cantidad' => count($out), 'tope' => count($out) >= 200));
}

/** Detalle de un remito: header + líneas de productos. Respeta la visibilidad del modo. */
function detalle() {
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT * FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=410;");
    if (!$h) { fail('Remito no encontrado'); return; }
    $lib = auth_libro_unico();
    $estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
    if (($lib === 'blanco' && !$estTrue) || ($lib === 'negro' && $estTrue)) { fail('Remito no disponible en este libro'); return; }

    $udm = array();
    foreach (db_query("SELECT CODUDM, DENUDM FROM [Tbl Unidades de Medida]") as $u)
        $udm[(int) $u['CODUDM']] = trim((string) nz($u['DENUDM'], ''));

    $lineas = array();
    foreach (db_query("SELECT ORDMOV, CODPRO, DENMOV, CODUDM, EGRMOV, SVCMOV, PUNMOV, ODCMOV, ODPMOV, PDLMOV
        FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num ORDER BY ORDMOV;") as $l) {
        $cant = (float) nz($l['EGRMOV'], 0);
        if ($cant == 0) $cant = -(float) nz($l['SVCMOV'], 0);   // sin stock → SVCMOV negativo
        $pun = (float) nz($l['PUNMOV'], 0);
        $lineas[] = array(
            'CODPRO' => trim((string) nz($l['CODPRO'], '')),
            'DENMOV' => trim((string) nz($l['DENMOV'], '')),
            'UNIDAD' => isset($udm[(int) $l['CODUDM']]) ? $udm[(int) $l['CODUDM']] : '',
            'ODC'    => (int) nz($l['ODCMOV'], 0) ?: '',
            'ODP'    => (int) nz($l['ODPMOV'], 0) ?: '',
            'PDL'    => (int) nz($l['PDLMOV'], 0) ?: '',
            'CANT'   => round($cant, 2),
            'PUN'    => round($pun, 2),
            'TOTAL'  => round($cant * $pun, 2),
        );
    }
    ok(array(
        'NUMMOV' => $num,
        'COMP'   => comp_str($h['CIPMOV'], $h['CINMOV']),
        'FEXMOV' => fecha_serial($h['FEXMOV']),
        'FRVMOV' => fecha_serial($h['FRVMOV']),
        'DENMOV' => trim((string) nz($h['DENMOV'], '')),
        'CITMOV' => trim((string) nz($h['CITMOV'], '')),
        'TOTMOV' => round((float) nz($h['TOTMOV'], 0), 2),
        'DETMOV' => trim((string) nz($h['DETMOV'], '')),
        'COTMOV' => trim((string) nz($h['COTMOV'], '')),
        'EST'    => $estTrue ? 1 : 0,
        'ANU'    => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) ? 1 : 0,
        'lineas' => $lineas,
    ));
}
