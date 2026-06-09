<?php
/**
 * Productos y Servicios — API. Porta Frm SI Productos (el maestro central de stock).
 * Cabecera editable + última compra/stock/precios-por-categoría READ-ONLY (los mueven
 * compras/movimientos). Hijos editables: Equivalencias (Tbl Productos Unidades) y
 * Proveedores (Tbl Productos Proveedores, preservando los datos de última compra).
 * Precios de venta por categoría = DERIVADOS (no tabla): NETO = PLVPRO − PLVPRO*LDPCAT/100.
 *   list / get / save / delete / buscar_prov (autocomplete de proveedores)
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'list':        listar();   break;
        case 'get':         obtener();  break;
        case 'save':        guardar();  break;
        case 'delete':      borrar();   break;
        case 'buscar_prov': buscarProv(); break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function es_true($v) { return $v === true || $v === -1 || $v === '-1' || $v === 1 || $v === '1'; }
function bsql($raw) { return (!empty($raw) && $raw !== '0' && $raw !== 'false') ? 'True' : 'False'; }
function m2($n) { return number_format((float) $n, 2, '.', ','); }
function m4($n) { return number_format((float) $n, 4, '.', ','); }

function listar() {
    $cat = array(); foreach (db_query("SELECT CODCAT, DENCAT FROM [Tbl Categorias Productos];") as $r) $cat[(int) $r['CODCAT']] = trim((string) nz($r['DENCAT'], ''));
    $rub = array(); foreach (db_query("SELECT CODRUB, DENRUB FROM [Tbl Rubros];") as $r) $rub[(int) $r['CODRUB']] = trim((string) nz($r['DENRUB'], ''));
    $rows = db_query("SELECT CODPRO, DENPRO, CODCAT, CODRUB FROM [Tbl Productos] ORDER BY CODPRO;");
    $out = array();
    foreach ($rows as $r) $out[] = array(
        'cod' => trim((string) $r['CODPRO']),
        'den' => trim((string) nz($r['DENPRO'], '')),
        'cat' => isset($cat[(int) $r['CODCAT']]) ? $cat[(int) $r['CODCAT']] : '',
        'rub' => isset($rub[(int) $r['CODRUB']]) ? $rub[(int) $r['CODRUB']] : '',
    );
    ok($out);
}

function obtener() {
    $cod = trim((isset($_GET['cod']) ? $_GET['cod'] : ''));
    $e = db_esc($cod);
    $p = db_row("SELECT * FROM [Tbl Productos] WHERE [CODPRO]='$e';");
    if (!$p) { fail('Producto no encontrado'); return; }

    $mon = monedas();
    $plv = (float) nz($p['PLVPRO'], 0); $plc = (float) nz($p['PLCPRO'], 0);

    // precios de venta por categoría de cliente (derivados)
    $precios = array();
    foreach (db_query("SELECT DENCAT, LDPCAT FROM [Tbl Categorias Cuentas Corrientes] WHERE CODORI='D' ORDER BY DENCAT;") as $c) {
        $ldp = (float) nz($c['LDPCAT'], 0);
        $net = $plv - ($plv * $ldp / 100);
        $gan = ($plc == 0) ? 0 : (($net * 100) / $plc) - 100;
        $precios[] = array('cat' => trim((string) nz($c['DENCAT'], '')), 'dto' => m2($ldp), 'neto' => m4($net), 'util' => m2($gan));
    }

    // stock (Tbl Stock por sucursal)
    $stock = array();
    foreach (db_query("SELECT S.CODSUC, S.MINSTK, S.MAXSTK, S.INISTK, S.EXISTK, S.RMCSTK, S.RMVSTK, U.DENSUC
        FROM [Tbl Stock] AS S LEFT JOIN [Tbl Sucursales] AS U ON S.CODSUC=U.CODSUC WHERE S.CODPRO='$e' ORDER BY S.CODSUC;") as $s) {
        $ex = (float) nz($s['EXISTK'], 0); $rc = (float) nz($s['RMCSTK'], 0); $rv = (float) nz($s['RMVSTK'], 0);
        $stock[] = array('suc' => (int) $s['CODSUC'], 'sucDen' => trim((string) nz($s['DENSUC'], 'Casa Central')),
            'min' => m4($s['MINSTK']), 'max' => m4($s['MAXSTK']), 'ini' => m4($s['INISTK']),
            'exi' => m4($ex), 'rmc' => m4($rc), 'rmv' => m4($rv), 'dsp' => m4($ex + $rc - $rv));
    }

    // equivalencias (editables)
    $equiv = array();
    foreach (db_query("SELECT CODUDM, FCTPUM FROM [Tbl Productos Unidades] WHERE CODPRO='$e' ORDER BY CODUDM;") as $u)
        $equiv[] = array('udm' => (int) $u['CODUDM'], 'factor' => rtrim(rtrim(number_format((float) nz($u['FCTPUM'], 0), 4, '.', ''), '0'), '.'));

    // proveedores (CODCUE + EXTPRO editables; última compra read-only)
    $provs = array();
    foreach (db_query("SELECT CODCUE, EXTPRO, FUCPRO, CODMON, COSPRO, PLCPRO FROM [Tbl Productos Proveedores] WHERE CODPRO='$e' ORDER BY FUCPRO DESC;") as $pr) {
        $cc = (int) $pr['CODCUE'];
        $den = db_row("SELECT DENCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='A' AND CODCUE=$cc;");
        $mc = trim((string) nz($pr['CODMON'], ''));
        $provs[] = array('cue' => $cc, 'cueDen' => $den ? trim((string) nz($den['DENCUE'], '')) : '', 'ext' => trim((string) nz($pr['EXTPRO'], '')),
            'fecha' => to_disp_date($pr['FUCPRO']), 'moneda' => isset($mon[$mc]) ? $mon[$mc] : '',
            'costo' => m2($pr['COSPRO']), 'lista' => m2($pr['PLCPRO']));
    }

    ok(array(
        'cod' => $cod, 'codcat' => (int) $p['CODCAT'], 'codrub' => nz($p['CODRUB'], ''), 'codsub' => nz($p['CODSUB'], ''),
        'codlin' => nz($p['CODLIN'], ''), 'den' => trim((string) nz($p['DENPRO'], '')), 'dec' => (int) nz($p['DECPRO'], 0),
        'codudm' => nz($p['CODUDM'], ''), 'ubi' => trim((string) nz($p['UBIPRO'], '')),
        'obs' => trim((string) nz($p['OBSPRO'], '')), 'dis' => es_true($p['DISPRO']) ? 1 : 0,
        // última compra (editable en alta; PLC/PLV editables también en edición) — valores crudos
        'fuc' => to_iso_date($p['FUCPRO']), 'codmon' => trim((string) nz($p['CODMON'], 'P')),
        'cot' => (float) nz($p['COTPRO'], 1), 'flt' => (float) nz($p['FLTPRO'], 0),
        'cos' => (float) nz($p['COSPRO'], 0), 'plc' => $plc, 'plv' => $plv,
        'precios' => $precios, 'stock' => $stock, 'equiv' => $equiv, 'provs' => $provs,
    ));
}

function monedas() { $m = array(); foreach (db_query("SELECT CODMON, DENMON FROM [Tbl Monedas];") as $r) $m[trim((string) $r['CODMON'])] = trim((string) nz($r['DENMON'], '')); return $m; }   // CODMON = clave string 'P'/'D'

function buscarProv() {
    $q = trim((isset($_GET['q']) ? $_GET['q'] : ''));
    if (strlen($q) < 2) { ok(array()); return; }
    $s = db_esc($q);
    $num = ctype_digit($q) ? " OR CODCUE=" . intval($q) : '';
    ok(db_query("SELECT TOP 30 CODCUE AS id, DENCUE AS den, CITCUE AS cod FROM [Tbl Cuentas Corrientes]
        WHERE CODORI='A' AND (DENCUE Like '%$s%'$num) ORDER BY DENCUE;"));
}

function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $nuevo = (isset($_POST['__nuevo']) && $_POST['__nuevo'] === '1');
    $cod = trim((isset($_POST['cod']) ? $_POST['cod'] : ''));
    if ($cod === '') { fail('Falta: Código'); return; }
    $den = trim((isset($_POST['den']) ? $_POST['den'] : ''));
    if ($den === '') { fail('Falta: Denominación'); return; }
    $codcat = intval((isset($_POST['codcat']) ? $_POST['codcat'] : 0));
    if ($codcat <= 0) { fail('Falta: Categoría'); return; }
    $e = db_esc($cod);

    // helpers de valor
    $intN = function ($k) { $v = isset($_POST[$k]) ? trim($_POST[$k]) : ''; return ($v === '') ? 'Null' : (string) intval($v); };
    $dec0 = function ($k) { $v = isset($_POST[$k]) ? trim($_POST[$k]) : ''; return ($v === '') ? '0' : (string) (float) str_replace(',', '.', $v); };   // NOT NULL → default 0
    $txt  = function ($k) { $v = isset($_POST[$k]) ? trim($_POST[$k]) : ''; return ($v === '') ? 'Null' : "'" . db_esc($v) . "'"; };

    $sets = array(
        "CODRUB=" . $intN('codrub'), "CODSUB=" . $intN('codsub'), "CODLIN=" . $intN('codlin'),
        "DENPRO='" . db_esc($den) . "'", "DECPRO=" . (string) intval(nz($_POST['dec'], 0)),
        "CODUDM=" . $intN('codudm'), "UBIPRO=" . $txt('ubi'),
        "PLVPRO=" . $dec0('plv'), "PLCPRO=" . $dec0('plc'),   // precio venta + compra: editables en alta y edición (NOT NULL, default 0)
        "OBSPRO=" . $txt('obs'), "DISPRO=" . bsql(isset($_POST['dis']) ? $_POST['dis'] : ''),
    );

    db_begin();
    try {
        if ($nuevo) {
            if (db_row("SELECT CODPRO FROM [Tbl Productos] WHERE [CODPRO]='$e';")) { db_rollback(); fail('Ya existe un producto con ese código'); return; }
            // última compra: editable en alta (los valores que carga el usuario al dar de alta)
            $codmon = trim(nz($_POST['codmon'], '')); if ($codmon === '') $codmon = 'P';
            $cot = $dec0('cot'); if ($cot === '0') $cot = '1';
            $fucIso = trim(nz($_POST['fuc'], ''));
            $fuc = ($fucIso === '') ? 'Null' : '#' . date('m/d/Y', strtotime($fucIso)) . '#';
            $cols = "[CODPRO],[CODCAT],[CODMON],[COTPRO],[COSPRO],[FLTPRO],[FUCPRO]," . implode(',', array_map(function ($s) { return '[' . substr($s, 0, strpos($s, '=')) . ']'; }, $sets));
            $vals = "'$e',$codcat,'" . db_esc($codmon) . "',$cot," . $dec0('cos') . "," . $dec0('flt') . ",$fuc," . implode(',', array_map(function ($s) { return substr($s, strpos($s, '=') + 1); }, $sets));
            db_exec("INSERT INTO [Tbl Productos] ($cols) VALUES ($vals);");
            // stock inicial si la categoría maneja stock
            $stk = db_row("SELECT STKCAT FROM [Tbl Categorias Productos] WHERE CODCAT=$codcat;");
            if ($stk && es_true($stk['STKCAT'])) {
                $suc = db_row("SELECT TOP 1 CODSUC FROM [Tbl Sucursales] ORDER BY CODSUC;");
                $cs = $suc ? (int) $suc['CODSUC'] : 1;
                db_exec("INSERT INTO [Tbl Stock] ([CODPRO],[CODSUC],[MINSTK],[MAXSTK],[INISTK],[EXISTK],[RMCSTK],[RMVSTK]) VALUES ('$e',$cs,0,0,0,0,0,0);");
            }
        } else {
            db_exec("UPDATE [Tbl Productos] SET " . implode(',', $sets) . " WHERE [CODPRO]='$e';");
        }

        // equivalencias: borrar-reinsertar (no tienen refs externas)
        guardarEquiv($e);
        // proveedores: sync preservando datos de última compra
        guardarProvs($e);
        // stock min/max
        guardarStock($e);

        db_commit();
    } catch (Exception $ex) { db_rollback(); throw $ex; }
    ok(array('cod' => $cod, 'nuevo' => $nuevo));
}

function guardarEquiv($e) {
    $raw = isset($_POST['equiv']) ? $_POST['equiv'] : '';
    $rows = json_decode($raw, true);
    if (!is_array($rows)) return;
    db_exec("DELETE FROM [Tbl Productos Unidades] WHERE CODPRO='$e';");
    $seen = array();
    foreach ($rows as $r) {
        $udm = intval(isset($r['udm']) ? $r['udm'] : 0);
        if ($udm <= 0 || in_array($udm, $seen, true)) continue;
        $seen[] = $udm;
        $f = (float) str_replace(',', '.', (string) (isset($r['factor']) ? $r['factor'] : 1));
        if ($f == 0) $f = 1;
        db_exec("INSERT INTO [Tbl Productos Unidades] ([CODPRO],[CODUDM],[FCTPUM]) VALUES ('$e',$udm," . $f . ");");
    }
}

function guardarProvs($e) {
    $raw = isset($_POST['provs']) ? $_POST['provs'] : '';
    $rows = json_decode($raw, true);
    if (!is_array($rows)) return;
    $postCues = array();
    foreach ($rows as $r) { $cc = intval(isset($r['cue']) ? $r['cue'] : 0); if ($cc > 0) $postCues[$cc] = isset($r['ext']) ? trim($r['ext']) : ''; }
    // existentes
    $exist = array();
    foreach (db_query("SELECT CODCUE FROM [Tbl Productos Proveedores] WHERE CODPRO='$e';") as $x) $exist[(int) $x['CODCUE']] = true;
    // borrar los que ya no están
    foreach ($exist as $cc => $_) if (!isset($postCues[$cc])) db_exec("DELETE FROM [Tbl Productos Proveedores] WHERE CODPRO='$e' AND CODCUE=$cc;");
    // upsert
    foreach ($postCues as $cc => $ext) {
        $ex = ($ext === '') ? 'Null' : "'" . db_esc($ext) . "'";
        if (isset($exist[$cc])) db_exec("UPDATE [Tbl Productos Proveedores] SET EXTPRO=$ex WHERE CODPRO='$e' AND CODCUE=$cc;");
        else db_exec("INSERT INTO [Tbl Productos Proveedores] ([CODPRO],[CODCUE],[EXTPRO]) VALUES ('$e',$cc,$ex);");
    }
}

function guardarStock($e) {
    $raw = isset($_POST['stock']) ? $_POST['stock'] : '';
    $rows = json_decode($raw, true);
    if (!is_array($rows)) return;
    foreach ($rows as $r) {
        $suc = intval(isset($r['suc']) ? $r['suc'] : 0); if ($suc <= 0) continue;
        $min = (float) str_replace(',', '.', (string) (isset($r['min']) ? $r['min'] : 0));
        $max = (float) str_replace(',', '.', (string) (isset($r['max']) ? $r['max'] : 0));
        db_exec("UPDATE [Tbl Stock] SET MINSTK=$min, MAXSTK=$max WHERE CODPRO='$e' AND CODSUC=$suc;");
    }
}

function borrar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $cod = trim((isset($_POST['cod']) ? $_POST['cod'] : ''));
    $e = db_esc($cod);
    // en uso: movimientos de stock (líneas de remitos/facturas/CP)
    if (db_row("SELECT TOP 1 CODPRO FROM [Tbl Movimientos Stock] WHERE CODPRO='$e';")) { fail('No se puede eliminar: el producto tiene movimientos asociados.', 409); return; }
    db_begin();
    try {
        db_exec("DELETE FROM [Tbl Productos Unidades] WHERE CODPRO='$e';");
        db_exec("DELETE FROM [Tbl Productos Proveedores] WHERE CODPRO='$e';");
        db_exec("DELETE FROM [Tbl Stock] WHERE CODPRO='$e';");
        db_exec("DELETE FROM [Tbl Productos] WHERE CODPRO='$e';");
        db_commit();
    } catch (Exception $ex) { db_rollback(); fail('No se puede eliminar: el producto está en uso por otros datos.', 409); return; }
    ok(true);
}
