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

/** Datos de un producto para una línea: unidad por defecto, factor, decimales, si controla stock, costo. */
function get_producto() {
    $cp = isset($_GET['codpro']) ? db_esc(trim($_GET['codpro'])) : '';
    $suc = isset($_GET['codsuc']) ? (int) $_GET['codsuc'] : 1;
    $pro = db_row("SELECT P.CODPRO, P.DENPRO, P.CODCAT, P.CODUDM, P.COSPRO, Cat.STKCAT
        FROM [Tbl Productos] AS P INNER JOIN [Tbl Categorias Productos] AS Cat ON P.CODCAT = Cat.CODCAT
        WHERE P.CODPRO = '$cp';");
    if (!$pro) { fail('Producto no encontrado'); return; }
    $udm = (int) nz($pro['CODUDM'], 0);
    $pu = db_row("SELECT FCTPUM FROM [Tbl Productos Unidades] WHERE CODPRO='$cp' AND CODUDM=$udm;");
    $um = db_row("SELECT DENUDM, DECUDM FROM [Tbl Unidades de Medida] WHERE CODUDM=$udm;");
    $stk = ($pro['STKCAT'] === true || $pro['STKCAT'] == -1);
    $exi = null;
    if ($stk) {
        $e = db_row("SELECT EXISTK FROM [Tbl Stock] WHERE CODPRO='$cp' AND CODSUC=$suc;");
        $fct = (float) nz($pu['FCTPUM'], 1);
        $exi = $e ? round((float) nz($e['EXISTK'], 0) / ($fct ?: 1), 4) : 0;
    }
    ok(array(
        'CODPRO' => trim($pro['CODPRO']), 'DENPRO' => trim(nz($pro['DENPRO'], '')),
        'CODUDM' => $udm, 'DENUDM' => trim(nz($um['DENUDM'], '')),
        'FCTPUM' => (float) nz($pu['FCTPUM'], 1), 'DECUDM' => (int) nz($um['DECUDM'], 2),
        'COSPRO' => round((float) nz($pro['COSPRO'], 0), 4), 'STK' => $stk ? 1 : 0,
        'CODMON' => 'P', 'EXISTENCIA' => $exi,
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
    $cotmov = isset($d['cotmov']) ? trim($d['cotmov']) : '';
    $vdxmov = round((float) (isset($d['vdxmov']) ? $d['vdxmov'] : 0), 2);

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
        $denmov = db_esc(trim(nz($cli['DENCUE'], '')));
        $cit    = db_esc(trim(nz($cli['CITCUE'], '')));
        $dcx    = db_esc(trim(nz($cli['DCXCUE'], '')));
        $dnx    = db_esc(trim(nz($cli['DNXCUE'], '')));
        $dpx    = db_esc(trim(nz($cli['DPXCUE'], '')));
        $ddx    = db_esc(trim(nz($cli['DDXCUE'], '')));
        $codloc = (int) nz($cli['CODLOC'], 0);
        $codcri = (int) nz($cli['CODCRI'], 0);
        $codcat = (int) nz($cli['CODCAT'], 0);
        $detSql = $detmov !== '' ? "'" . db_esc($detmov) . "'" : 'Null';
        $cotSql = $cotmov !== '' ? "'" . db_esc($cotmov) . "'" : 'Null';
        $traSql = ($codtra === null || $codtra === '') ? 'Null' : (string) (int) $codtra;

        // --- Header en Tbl Movimientos (CODOPE=410, CICMOV='RV') ---
        db_exec("INSERT INTO [Tbl Movimientos]
            (NUMMOV, CODORI, CODOPE, FEXMOV, CICMOV, CIPMOV, CINMOV, CECMOV, CEPMOV, CENMOV, CEFMOV,
             CODCUE, DENMOV, SOCMOV, PDCMOV, DCXMOV, DNXMOV, DPXMOV, DDXMOV, CODLOC, CODCRI, CITMOV,
             CODCAT, CODTRA, CODDST, DETMOV, FRVMOV, SRPMOV, COTMOV, VDXMOV, TOTMOV, NUIMOV, NMIMOV,
             NOWMOV, ANUMOV, ESTMOV)
            VALUES ($nummov, 'D', 410, $fex, 'RV', $cipSql, $cinmov, 'RV', $cipSql, $cinmov, $fex,
             $codcue, '$denmov', $saldo, $pdcmov, '$dcx', '$dnx', '$dpx', '$ddx', $codloc, $codcri, '$cit',
             $codcat, $traSql, $coddst, $detSql, $frv, True, $cotSql, $vdxmov, $total, 0, 0,
             Now(), False, $estSql);");

        // --- Líneas en Tbl Movimientos Stock + descarga de stock ---
        $ord = 0;
        foreach ($lineas as $l) {
            $ord++;
            $cp   = db_esc(trim(nz($l['codpro'], '')));
            $den  = db_esc(trim(nz($l['denmov'], '')));
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
                VALUES ($nummov, $ord, $coddst, '$cp', '$den', $udm, $fct, $dum, '$mon', $cos,
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
