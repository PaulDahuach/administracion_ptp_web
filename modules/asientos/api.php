<?php
/**
 * Asientos contables manuales (Imputaciones Contables — porta Frm IC Imputaciones, SetData Case "A").
 * FASE 1: asiento simple. Operación interna (Tbl Operaciones CODORI='I') + imputaciones (cuenta contable ·
 * centro de costo · Debe/Haber) que CUADREN → header en Tbl Movimientos (CODORI='I', CICMOV=ICCOPE, CINMOV del
 * contador ULTOPE de la operación) + Tbl Movimientos Imputaciones + mayoriza DEBCUE/CRECUE de cada cuenta.
 * Sin comprobante externo / IVA / percepciones / cheques / banco (Fase 2/3). dev=copia en readwrite.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'operaciones':   operaciones();        break;
        case 'cuentas':       cuentas_imputables(); break;
        case 'centros_costo': centros_costo();      break;
        case 'bancos':        bancos();             break;
        case 'cheque_lookup': cheque_lookup();      break;
        case 'cuenta_banco':  cuenta_banco();       break;
        case 'cuenta_cbx':    cuenta_cbx();          break;
        case 'auxiliares':    auxiliares();          break;
        case 'op_config':     op_config();           break;
        case 'categorias_iva': categorias_iva();     break;
        case 'guardar':       guardar();            break;
        case 'anular':        anular();             break;
        case 'listar':        listar();             break;
        case 'detalle':       detalle();            break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function as_serial($iso) { if (!$iso) return null; $d = DateTime::createFromFormat('Y-m-d', $iso); if (!$d) return null; return (int) (new DateTime('1899-12-30'))->diff($d)->days; }
function as_fiso($s) { $f = fecha_serial($s); if (!$f || strpos($f, '/') === false) return ''; $p = explode('/', $f); return $p[2] . '-' . $p[1] . '-' . $p[0]; }
function as_txt($s) { $s = trim((string) $s); return $s === '' ? 'Null' : "'" . db_esc($s) . "'"; }
function as_num($v) { return (string) round((float) $v, 2); }
function as_estmov_w() { $l = auth_libro_unico(); if ($l === 'blanco') return ' AND ESTMOV=True'; if ($l === 'capacitacion') return ' AND ESTMOV=False'; return ''; }

/** Operaciones internas (CODORI='I') = los tipos de asiento manual (Asiento Diario, Ajuste, Saldo Inicial, …). */
function operaciones() {
    ok(db_query("SELECT CODOPE, DENOPE, ICCOPE FROM [Tbl Operaciones] WHERE CODORI='I' ORDER BY DENOPE;"));
}

/** Cuentas contables imputables (hojas, IMPCUE=True). */
function cuentas_imputables() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q);
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE FROM [Tbl Cuentas Contables]
        WHERE IMPCUE=True AND ((DENCUE Like '%$s%') OR (CODCUE Like '$s%')) ORDER BY CODCUE;"));
}

function centros_costo() {
    ok(db_query("SELECT CODCDC, DENCDC FROM [Tbl Centros de Costo] ORDER BY DENCDC;"));
}

/** Bancos (combo del cheque). */
function bancos() {
    ok(db_query("SELECT CODBAN, DENBAN FROM [Tbl Bancos] ORDER BY DENBAN;"));
}

/** Buscar un cheque por banco + número. null = no existe (→ alta); sino sus datos + si está en cartera (→ depósito). */
function cheque_lookup() {
    $codban = isset($_GET['codban']) ? (int) $_GET['codban'] : 0;
    $syn = isset($_GET['syn']) ? trim($_GET['syn']) : '';
    if ($codban <= 0 || $syn === '') { ok(null); return; }
    $c = db_row("SELECT CODCHQ, IMPCHQ, FEXCHQ, FAXCHQ, PLZCHQ, LIBCHQ, CITCHQ, LOCCHQ, VADCHQ, DIFCHQ FROM [Tbl Cheques] WHERE CODBAN=$codban AND SYNCHQ='" . db_esc($syn) . "';");
    if (!$c) { ok(null); return; }
    ok(array(
        'codchq' => (int) $c['CODCHQ'],
        'enCartera' => ($c['VADCHQ'] === true || $c['VADCHQ'] == -1),
        'diferido' => ($c['DIFCHQ'] === true || $c['DIFCHQ'] == -1),
        'imp' => round((float) nz($c['IMPCHQ'], 0), 2),
        'fde' => as_fiso($c['FEXCHQ']), 'fda' => as_fiso($c['FAXCHQ']), 'plz' => (int) nz($c['PLZCHQ'], 0),
        'lib' => trim((string) nz($c['LIBCHQ'], '')), 'cit' => trim((string) nz($c['CITCHQ'], '')), 'loc' => trim((string) nz($c['LOCCHQ'], '')),
    ));
}

/** Banco de una cuenta bancaria (CACC_3): CODCBX → Tbl Cuentas Bancarias → CODBAN/DENBAN. Para el cheque propio. */
function cuenta_banco() {
    $cu = isset($_GET['codcue']) ? trim($_GET['codcue']) : '';
    if ($cu === '') { ok(null); return; }
    $cbx = db_row("SELECT CODCBX FROM [Tbl Cuentas Contables] WHERE CODCUE='" . db_esc($cu) . "';");
    $codcbx = $cbx ? (int) nz($cbx['CODCBX'], 0) : 0;
    if ($codcbx <= 0) { ok(null); return; }
    $bk = db_row("SELECT B.CODBAN, N.DENBAN FROM [Tbl Cuentas Bancarias] AS B LEFT JOIN [Tbl Bancos] AS N ON B.CODBAN=N.CODBAN WHERE B.CODCBX=$codcbx;");
    if (!$bk) { ok(null); return; }
    ok(array('codban' => (int) nz($bk['CODBAN'], 0), 'denban' => trim((string) nz($bk['DENBAN'], ''))));
}

/** Cuenta contable del banco (11104*) para una cuenta bancaria CODCBX — para el Depósito Bancario (DEBE banco). */
function cuenta_cbx() {
    $codcbx = isset($_GET['codcbx']) ? (int) $_GET['codcbx'] : 0;
    if ($codcbx <= 0) { ok(null); return; }
    $bankP = trim((string) nz(db_row("SELECT CACC_3 FROM [Rec Control];")['CACC_3'], ''));
    $c = db_row("SELECT TOP 1 CODCUE, DENCUE FROM [Tbl Cuentas Contables] WHERE CODCBX=$codcbx AND CODCUE Like '" . db_esc($bankP) . "%';");
    if (!$c) { ok(null); return; }
    ok(array('codcue' => trim((string) nz($c['CODCUE'], '')), 'dencue' => trim((string) nz($c['DENCUE'], ''))));
}

/** Auxiliares (gravada / no gravada) de una operación interna — para el comprobante de la OP Contado. */
function auxiliares() {
    $codope = isset($_GET['codope']) ? (int) $_GET['codope'] : 0;
    ok(db_query("SELECT CODAUX, DENAUX, IVAAUX FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=$codope ORDER BY CODAUX;"));
}

/** Categorías de responsabilidad IVA (para el comprobante). */
function categorias_iva() {
    ok(db_query("SELECT CODCRI, DENCRI FROM [Tbl Categorias Responsabilidad IVA] ORDER BY CODCRI;"));
}

/** Config del header según la operación: auxiliares + modelos + si habilita cuenta corriente (CUEOPE) / cuenta bancaria (Depósito 125). */
function op_config() {
    $codope = isset($_GET['codope']) ? (int) $_GET['codope'] : 0;
    $op = db_row("SELECT CUEOPE, ICCOPE FROM [Tbl Operaciones] WHERE CODOPE=$codope;");
    $aux = array();
    foreach (db_query("SELECT CODAUX, DENAUX, IVAAUX, CUEAUX FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=$codope ORDER BY CODAUX;") as $a)
        $aux[] = array('CODAUX' => (int) $a['CODAUX'], 'DENAUX' => trim((string) nz($a['DENAUX'], '')),
            'IVAAUX' => ($a['IVAAUX'] === true || $a['IVAAUX'] == -1), 'CUEAUX' => ($a['CUEAUX'] === true || $a['CUEAUX'] == -1));
    $mod = array();
    foreach (db_query("SELECT CODMOD, DENMOD FROM [Tbl Modelos] WHERE CODOPE=$codope ORDER BY DENMOD;") as $m)
        $mod[] = array('CODMOD' => (int) $m['CODMOD'], 'DENMOD' => trim((string) nz($m['DENMOD'], '')));
    ok(array(
        'iccope'       => $op ? trim((string) nz($op['ICCOPE'], '')) : '',
        'cueEnabled'   => ($op && ($op['CUEOPE'] === true || $op['CUEOPE'] == -1)),
        'bancoEnabled' => ($codope === 125),
        'auxiliares'   => $aux,
        'modelos'      => $mod,
    ));
}

/** Inserta una imputación (Debe o Haber) + SOCMOV (saldo cacheado pre-update) + mayoriza DEBCUE/CRECUE.
 *  $codchq/$fax (opcionales) = link al cheque (Tbl Cheques) + fecha de acreditación, para líneas de cheque. */
function as_imp(&$ord, &$totDeb, &$totCre, $nummov, $cuenta, $deb, $cre, $codcdc, $codchq = null, $fax = null) {
    $ord++;
    $cc = db_esc((string) $cuenta);
    $bal = db_row("SELECT DEBCUE, CRECUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
    $soc = $bal ? round((float) nz($bal['DEBCUE'], 0) - (float) nz($bal['CRECUE'], 0), 2) : 0;
    $deb = round((float) $deb, 2); $cre = round((float) $cre, 2);
    $debSql = ($deb != 0) ? (string) $deb : 'Null';
    $creSql = ($cre != 0) ? (string) $cre : 'Null';
    $chqC = ($codchq === null) ? '' : ', CODCHQ'; $chqV = ($codchq === null) ? '' : ', ' . (int) $codchq;
    $faxC = ($fax === null) ? '' : ', FAXMOV'; $faxV = ($fax === null) ? '' : ', ' . (int) $fax;
    db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, DEBMOV, CREMOV, CODCDC, SOCMOV$chqC$faxC)
        VALUES ($nummov, $ord, '$cc', $debSql, $creSql, " . (int) $codcdc . ", $soc$chqV$faxV);");
    if ($deb != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE + $deb WHERE CODCUE='$cc';");
    if ($cre != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE + $cre WHERE CODCUE='$cc';");
    $totDeb += $deb; $totCre += $cre;
}

/** Cheque de tercero (cuenta CACC_2 = valores a depositar). Debe → ALTA en cartera (crea Tbl Cheques, VADCHQ=True);
 *  Haber → BAJA de cartera (debe existir con VADCHQ=True → VADCHQ=False). Devuelve el CODCHQ. Lanza si no corresponde. */
function as_cheque($l) {
    $codban = (int) $l['codban']; $syns = db_esc($l['syn']);
    $ex = db_row("SELECT CODCHQ, VADCHQ FROM [Tbl Cheques] WHERE CODBAN=$codban AND SYNCHQ='$syns';");
    $inCart = $ex && ($ex['VADCHQ'] === true || $ex['VADCHQ'] == -1);
    if ($l['debe'] > 0) {
        if ($inCart) throw new Exception('El cheque ' . $codban . '-' . $l['syn'] . ' ya está en cartera');
        $codchq = next_number('ULTCHQ');
        db_exec("INSERT INTO [Tbl Cheques] (CODCHQ, CODBAN, SYNCHQ, FEXCHQ, PLZCHQ, FAXCHQ, LIBCHQ, CITCHQ, LOCCHQ, IMPCHQ, VADCHQ, DIFCHQ)
            VALUES ($codchq, $codban, '$syns', " . ($l['fde'] === null ? 'Null' : (int) $l['fde']) . ", " . (int) nz($l['plz'], 0) . ", " . ($l['fda'] === null ? 'Null' : (int) $l['fda']) . ", " . as_txt($l['lib']) . ", " . as_txt($l['cit']) . ", " . as_txt($l['loc']) . ", " . as_num($l['debe']) . ", True, False);");
        return $codchq;
    }
    if (!$inCart) throw new Exception('El cheque ' . $codban . '-' . $l['syn'] . ' no está en cartera (no se puede depositar)');
    $codchq = (int) $ex['CODCHQ'];
    db_exec("UPDATE [Tbl Cheques] SET VADCHQ=False WHERE CODCHQ=$codchq;");
    return $codchq;
}

/** Cheque PROPIO (cuenta banco CACC_3, al Haber = pago). Emite el cheque: banco tomado de la cuenta bancaria
 *  (CODCBX → Tbl Cuentas Bancarias), VADCHQ=False/DIFCHQ=False (no es de cartera ni diferido). Valida re-sale. */
function as_cheque_propio($l) {
    $cu = db_esc((string) $l['codcue']);
    $cbx = db_row("SELECT CODCBX FROM [Tbl Cuentas Contables] WHERE CODCUE='$cu';");
    $codcbx = $cbx ? (int) nz($cbx['CODCBX'], 0) : 0;
    if ($codcbx <= 0) throw new Exception('La cuenta ' . $l['codcue'] . ' no está asociada a una cuenta bancaria');
    $bk = db_row("SELECT CODBAN FROM [Tbl Cuentas Bancarias] WHERE CODCBX=$codcbx;");
    $codban = $bk ? (int) nz($bk['CODBAN'], 0) : 0;
    if ($codban <= 0) throw new Exception('No se encontró el banco de la cuenta ' . $l['codcue']);
    $syns = db_esc($l['syn']);
    if (db_row("SELECT CODCHQ FROM [Tbl Cheques] WHERE CODBAN=$codban AND SYNCHQ='$syns';")) throw new Exception('El cheque propio ' . $codban . '-' . $l['syn'] . ' ya existe (re-emisión)');
    $codchq = next_number('ULTCHQ');
    db_exec("INSERT INTO [Tbl Cheques] (CODCHQ, CODBAN, SYNCHQ, FEXCHQ, FAXCHQ, IMPCHQ, PLZCHQ, VADCHQ, DIFCHQ)
        VALUES ($codchq, $codban, '$syns', " . ($l['fde'] === null ? 'Null' : (int) $l['fde']) . ", " . ($l['fda'] === null ? 'Null' : (int) $l['fda']) . ", " . as_num($l['cre']) . ", " . (int) nz($l['plz'], 0) . ", False, False);");
    return $codchq;
}

/** Cheque PROPIO DIFERIDO (cuenta posdatados CACC_V). Haber → EMITE el cheque diferido (banco de la cuenta,
 *  VADCHQ=False/DIFCHQ=True); Debe → VENCIMIENTO (el cheque deja de ser diferido → DIFCHQ=False). */
function as_cheque_diferido($l) {
    $cu = db_esc((string) $l['codcue']);
    $cbx = db_row("SELECT CODCBX FROM [Tbl Cuentas Contables] WHERE CODCUE='$cu';");
    $codcbx = $cbx ? (int) nz($cbx['CODCBX'], 0) : 0;
    if ($codcbx <= 0) throw new Exception('La cuenta ' . $l['codcue'] . ' no está asociada a una cuenta bancaria');
    $bk = db_row("SELECT CODBAN FROM [Tbl Cuentas Bancarias] WHERE CODCBX=$codcbx;");
    $codban = $bk ? (int) nz($bk['CODBAN'], 0) : 0;
    if ($codban <= 0) throw new Exception('No se encontró el banco de la cuenta ' . $l['codcue']);
    $syns = db_esc($l['syn']);
    $ex = db_row("SELECT CODCHQ, DIFCHQ FROM [Tbl Cheques] WHERE CODBAN=$codban AND SYNCHQ='$syns';");
    if ($l['cre'] > 0) {   // Haber: EMITIR el cheque diferido
        if ($ex) throw new Exception('El cheque diferido ' . $codban . '-' . $l['syn'] . ' ya existe (re-emisión)');
        $codchq = next_number('ULTCHQ');
        db_exec("INSERT INTO [Tbl Cheques] (CODCHQ, CODBAN, SYNCHQ, FEXCHQ, FAXCHQ, IMPCHQ, PLZCHQ, VADCHQ, DIFCHQ)
            VALUES ($codchq, $codban, '$syns', " . ($l['fde'] === null ? 'Null' : (int) $l['fde']) . ", " . ($l['fda'] === null ? 'Null' : (int) $l['fda']) . ", " . as_num($l['cre']) . ", " . (int) nz($l['plz'], 0) . ", False, True);");
        return $codchq;
    }
    // Debe: VENCIMIENTO (el diferido se devenga → deja de ser diferido)
    if (!$ex) throw new Exception('El cheque ' . $codban . '-' . $l['syn'] . ' no existe (no se puede vencer)');
    if (!($ex['DIFCHQ'] === true || $ex['DIFCHQ'] == -1)) throw new Exception('El cheque ' . $codban . '-' . $l['syn'] . ' no está diferido');
    $codchq = (int) $ex['CODCHQ'];
    db_exec("UPDATE [Tbl Cheques] SET DIFCHQ=False WHERE CODCHQ=$codchq;");
    return $codchq;
}

/**
 * Graba un asiento manual. $_POST['data'] = JSON {codope, fexmov(iso), detmov,
 * lineas:[{codcue, codcdc, debe, cre}]}. Devuelve {nummov, cinmov, total}.
 */
function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? $_POST['data'] : '';
    $d = json_decode($raw, true);
    if (!is_array($d)) { fail('Datos inválidos'); return; }

    $codope = isset($d['codope']) ? (int) $d['codope'] : 0;
    $lineas = (isset($d['lineas']) && is_array($d['lineas'])) ? $d['lineas'] : array();
    if ($codope <= 0)        { fail('Elegí la operación'); return; }
    $op = db_row("SELECT ICCOPE FROM [Tbl Operaciones] WHERE CODOPE=$codope AND CODORI='I';");
    if (!$op) { fail('Operación interna inexistente'); return; }

    // Prefijos de cuentas especiales (Rec Control): valores a depositar (CACC_2 = cheques de terceros en cartera),
    // bancos (CACC_3), cheques diferidos (CACC_V). FASE 2a: maneja cheques de terceros (alta/depósito) + banco plano.
    // Cheque propio (banco + nº de cheque) = Fase 2b; cheques diferidos = Fase 2c → todavía rechazados.
    $rc = db_row("SELECT CACC_2, CACC_3, CACC_V FROM [Rec Control];");
    $vadP = trim((string) nz($rc['CACC_2'], '')); $bankP = trim((string) nz($rc['CACC_3'], '')); $difP = trim((string) nz($rc['CACC_V'], ''));

    $val = array(); $totDeb = 0.0; $totCre = 0.0;
    foreach ($lineas as $l) {
        $cu = trim((string) nz($l['codcue'], '')); if ($cu === '') continue;
        $deb = round((float) nz($l['debe'], 0), 2); $cre = round((float) nz($l['cre'], 0), 2);
        if ($deb == 0 && $cre == 0) continue;
        $cdc = isset($l['codcdc']) && $l['codcdc'] !== '' ? (int) $l['codcdc'] : 1;
        $syn = trim((string) nz(isset($l['syn']) ? $l['syn'] : '', ''));
        $isVad = ($vadP !== '' && strpos($cu, $vadP) === 0);
        $isDif = ($difP !== '' && strpos($cu, $difP) === 0);   // 2c: cheque propio diferido (cuenta posdatados)
        $isBank = ($bankP !== '' && strpos($cu, $bankP) === 0 && $syn !== '');   // 2b: cheque propio (cuenta banco + nº)
        if ($isVad) {
            if ($syn === '' || (int) nz(isset($l['codban']) ? $l['codban'] : 0, 0) <= 0) { fail('Falta el banco y/o número del cheque en la cuenta de valores a depositar (' . $cu . ').'); return; }
            if ($deb > 0 && $cre > 0) { fail('El cheque ' . $cu . ' va al Debe O al Haber, no a los dos.'); return; }
        }
        if ($isDif) {
            if ($syn === '') { fail('Falta el número del cheque diferido (' . $cu . ').'); return; }
            if ($deb > 0 && $cre > 0) { fail('El cheque diferido ' . $cu . ' va al Debe (vencimiento) O al Haber (emisión).'); return; }
        }
        if ($isBank && $cre <= 0) { fail('El cheque propio (' . $cu . ') es un pago: va al Haber.'); return; }
        $val[] = array('codcue' => $cu, 'codcdc' => $cdc, 'debe' => $deb, 'cre' => $cre, 'isVad' => $isVad, 'isBank' => $isBank, 'isDif' => $isDif,
            'codban' => (int) nz(isset($l['codban']) ? $l['codban'] : 0, 0), 'syn' => $syn,
            'fde' => as_serial(isset($l['fde']) ? $l['fde'] : ''), 'fda' => as_serial(isset($l['fda']) ? $l['fda'] : ''),
            'plz' => (int) nz(isset($l['plz']) ? $l['plz'] : 0, 0), 'lib' => trim((string) nz(isset($l['lib']) ? $l['lib'] : '', '')),
            'cit' => trim((string) nz(isset($l['cit']) ? $l['cit'] : '', '')), 'loc' => trim((string) nz(isset($l['loc']) ? $l['loc'] : '', '')));
        $totDeb += $deb; $totCre += $cre;
    }
    if (count($val) < 2) { fail('El asiento necesita al menos 2 imputaciones'); return; }
    if (round($totDeb, 2) <= 0) { fail('El asiento está en cero'); return; }
    if (abs(round($totDeb - $totCre, 2)) > 0.009) { fail('El asiento no cuadra: Debe ' . number_format($totDeb, 2) . ' ≠ Haber ' . number_format($totCre, 2)); return; }

    $modo = auth_modo();
    $estTrue = ($modo !== 'capacitacion');
    $estSql  = $estTrue ? 'True' : 'False';
    $fex = as_serial(isset($d['fexmov']) ? $d['fexmov'] : '');
    if ($fex === null) { fail('Falta la fecha de emisión'); return; }
    $detmov = isset($d['detmov']) ? trim($d['detmov']) : '';
    $cic = trim((string) nz($op['ICCOPE'], ''));

    // ── Comprobante con IVA (Fase 3a: OP Contado) — header del comprobante del proveedor + IVA, reusa el modelo del CP ──
    $compCols = ''; $compVals = ''; $ivaRows = array();
    if (isset($d['comprobante']) && is_array($d['comprobante'])) {
        $cmp = $d['comprobante'];
        $codaux = (int) nz($cmp['codaux'], 0);
        if ($codaux <= 0) { fail('Elegí el tipo de comprobante (auxiliar gravada / no gravada).'); return; }
        if (!db_row("SELECT CODAUX FROM [Tbl Operaciones Auxiliares] WHERE CODAUX=$codaux AND CODOPE=$codope;")) { fail('Auxiliar inválido para la operación.'); return; }
        $ivas = (isset($cmp['ivas']) && is_array($cmp['ivas'])) ? array_values($cmp['ivas']) : array();
        $netmov = 0.0; $irimov = 0.0;
        foreach ($ivas as $iv) { $netmov += (float) nz($iv['net'], 0); $irimov += (float) nz($iv['iva'], 0); }
        $netmov = round($netmov, 2); $irimov = round($irimov, 2);
        $nogmov = round((float) nz(isset($cmp['nogmov']) ? $cmp['nogmov'] : 0, 0), 2);
        $ip1 = round((float) nz(isset($cmp['ip1mov']) ? $cmp['ip1mov'] : 0, 0), 2);
        $ip2 = round((float) nz(isset($cmp['ip2mov']) ? $cmp['ip2mov'] : 0, 0), 2);
        $ap1 = round((float) nz(isset($cmp['ap1mov']) ? $cmp['ap1mov'] : 0, 0), 2);
        $ap2 = round((float) nz(isset($cmp['ap2mov']) ? $cmp['ap2mov'] : 0, 0), 2);
        $compTotal = round($netmov + $irimov + $nogmov + $ip1 + $ip2, 2);
        if (abs($compTotal - round($totDeb, 2)) >= 0.01) { fail('El total del comprobante (' . number_format($compTotal, 2) . ') no coincide con la imputación al Debe (' . number_format($totDeb, 2) . ').'); return; }
        $cep = (int) nz(isset($cmp['cep']) ? $cmp['cep'] : 0, 0);
        $cen = (int) nz(isset($cmp['cen']) ? $cmp['cen'] : 0, 0);
        $cefSer = as_serial(isset($cmp['cef']) ? $cmp['cef'] : '');
        $cef = ($cefSer === null) ? $fex : (int) $cefSer;
        $fixSer = as_serial(isset($cmp['fix']) ? $cmp['fix'] : '');
        $fixmov = ($fixSer === null) ? $fex : (int) $fixSer;
        $codcri = (int) nz(isset($cmp['codcri']) ? $cmp['codcri'] : 0, 0);
        $compCols = ', FIXMOV, CODAUX, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV, DENMOV, CODCRI, CITMOV, NETMOV, IRIMOV, NOGMOV, IP1MOV, IP2MOV, AP1MOV, AP2MOV';
        $compVals = ', ' . $fixmov . ', ' . $codaux . ', ' . as_txt(strtoupper(trim((string) nz($cmp['cec'], 'FC')))) . ', ' . as_txt(strtoupper(trim((string) nz($cmp['cei'], '')))) . ', ' . $cep . ', ' . $cen . ', ' . $cef
            . ', ' . as_txt(trim((string) nz(isset($cmp['denmov']) ? $cmp['denmov'] : '', ''))) . ', ' . ($codcri > 0 ? $codcri : 'Null') . ', ' . as_txt(trim((string) nz(isset($cmp['citmov']) ? $cmp['citmov'] : '', '')))
            . ', ' . ($netmov != 0 ? as_num($netmov) : 'Null') . ', ' . ($irimov != 0 ? as_num($irimov) : 'Null') . ', ' . ($nogmov != 0 ? as_num($nogmov) : 'Null')
            . ', ' . ($ip1 != 0 ? as_num($ip1) : 'Null') . ', ' . ($ip2 != 0 ? as_num($ip2) : 'Null') . ', ' . ($ap1 != 0 ? as_num($ap1) : 'Null') . ', ' . ($ap2 != 0 ? as_num($ap2) : 'Null');
        $ivaRows = $ivas;
    }

    // ── Depósito Bancario (125): guardar CODCBX (cuenta bancaria) + CODMOD (modelo Valores/Efectivo) en el movimiento ──
    $depCols = ''; $depVals = '';
    $codcbx = (int) nz(isset($d['codcbx']) ? $d['codcbx'] : 0, 0);
    $codmod = (int) nz(isset($d['codmod']) ? $d['codmod'] : 0, 0);
    if ($codcbx > 0) { $depCols .= ', CODCBX'; $depVals .= ', ' . $codcbx; }
    if ($codmod > 0) { $depCols .= ', CODMOD'; $depVals .= ', ' . $codmod; }

    db_begin();
    try {
        $nummov = next_number('ULTMOV');
        // CINMOV = contador ULTOPE de la operación (Tbl Operaciones), como el legacy.
        $opc = db_row("SELECT ULTOPE FROM [Tbl Operaciones] WHERE CODOPE=$codope;");
        $cinmov = (int) nz($opc['ULTOPE'], 0) + 1;
        db_exec("UPDATE [Tbl Operaciones] SET ULTOPE = $cinmov WHERE CODOPE=$codope;");
        $cipSql = $estTrue ? '0' : 'Null';
        $total = round($totDeb, 2);

        db_exec("INSERT INTO [Tbl Movimientos]
            (NUMMOV, CODORI, CODOPE, FEXMOV, CICMOV, CIPMOV, CINMOV, CODCUE, DETMOV, TOTMOV$compCols$depCols, NOWMOV, ANUMOV, ESTMOV)
            VALUES ($nummov, 'I', $codope, $fex, " . as_txt($cic) . ", $cipSql, $cinmov, Null, " . as_txt($detmov) . ", " . as_num($total) . "$compVals$depVals, Now(), False, $estSql);");

        // IVA por alícuota (Tbl Movimientos IVA), para que la OP Contado entre en el libro IVA Compras.
        $decmov = false;
        foreach ($ivaRows as $iv) {
            $net = round((float) nz($iv['net'], 0), 2); $iva = round((float) nz($iv['iva'], 0), 2);
            if ($net == 0 && $iva == 0) continue;
            db_exec("INSERT INTO [Tbl Movimientos IVA] (NUMMOV, NETMOV, ALIMOV, IRIMOV, DECMOV)
                VALUES ($nummov, " . as_num($net) . ", " . round((float) nz($iv['ali'], 0), 2) . ", " . as_num($iva) . ", " . ($decmov ? 'True' : 'False') . ");");
            $decmov = true;
        }

        $ord = 0; $td = 0.0; $tc = 0.0;
        foreach ($val as $l) {
            if ($l['isVad']) {        // cheque de tercero: alta/baja de cartera + link
                $codchq = as_cheque($l);
                as_imp($ord, $td, $tc, $nummov, $l['codcue'], $l['debe'], $l['cre'], $l['codcdc'], $codchq, $l['fda']);
            } elseif ($l['isBank']) { // cheque propio: lo emitimos (crea el cheque, banco de la cuenta) + link
                $codchq = as_cheque_propio($l);
                as_imp($ord, $td, $tc, $nummov, $l['codcue'], $l['debe'], $l['cre'], $l['codcdc'], $codchq, $l['fda']);
            } elseif ($l['isDif']) {  // cheque propio diferido: emisión (Haber) o vencimiento (Debe) + link
                $codchq = as_cheque_diferido($l);
                as_imp($ord, $td, $tc, $nummov, $l['codcue'], $l['debe'], $l['cre'], $l['codcdc'], $codchq, $l['fda']);
            } else {
                as_imp($ord, $td, $tc, $nummov, $l['codcue'], $l['debe'], $l['cre'], $l['codcdc']);
            }
        }

        db_commit();
        ok(array('nummov' => $nummov, 'cinmov' => $cinmov, 'total' => $total));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo grabar el asiento: ' . $e->getMessage(), 500);
    }
}

/** Anular un asiento (admin). Revierte DEBCUE/CRECUE de cada cuenta + zera las imputaciones + ANUMOV. */
function anular() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    if (!auth_is_admin()) { fail('Solo un administrador puede anular asientos', 403); return; }
    $num = isset($_POST['nummov']) ? (int) $_POST['nummov'] : (isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0);
    $h = db_row("SELECT ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODORI='I';");
    if (!$h) { fail('Asiento no encontrado'); return; }
    if ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) { fail('El asiento ya está anulado'); return; }

    db_begin();
    try {
        $imps = array();
        foreach (db_query("SELECT CODCUE, DEBMOV, CREMOV, CODCHQ FROM [Tbl Movimientos Imputaciones] WHERE NUMMOV=$num;") as $i) $imps[] = $i;
        foreach ($imps as $i) {
            $cc = db_esc((string) nz($i['CODCUE'], '')); if ($cc === '') continue;
            if ($i['DEBMOV'] !== null && $i['DEBMOV'] !== '') db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE - " . round((float) $i['DEBMOV'], 2) . " WHERE CODCUE='$cc';");
            if ($i['CREMOV'] !== null && $i['CREMOV'] !== '') db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE - " . round((float) $i['CREMOV'], 2) . " WHERE CODCUE='$cc';");
        }
        // Sacar el link al cheque + zerar importes ANTES de tocar Tbl Cheques (para no violar la relación).
        db_exec("UPDATE [Tbl Movimientos Imputaciones] SET DEBMOV=0, CREMOV=0, CODCHQ=Null WHERE NUMMOV=$num;");
        // Revertir el cheque según la cuenta + el lado. CREÓ el cheque (→ eliminar si no quedó referenciado):
        // alta de tercero (Debe CACC_2), emisión propia (Haber CACC_3), emisión diferida (Haber CACC_V).
        // MOVIÓ un cheque existente (→ revertir el flag): depósito de tercero (Haber CACC_2 → VADCHQ=True),
        // vencimiento de diferido (Debe CACC_V → DIFCHQ=True).
        $rcA = db_row("SELECT CACC_2, CACC_3, CACC_V FROM [Rec Control];");
        $vadPA = trim((string) nz($rcA['CACC_2'], '')); $bankPA = trim((string) nz($rcA['CACC_3'], '')); $difPA = trim((string) nz($rcA['CACC_V'], ''));
        foreach ($imps as $i) {
            $chq = (int) nz($i['CODCHQ'], 0); if ($chq <= 0) continue;
            $cuA = trim((string) nz($i['CODCUE'], ''));
            $debA = (float) nz($i['DEBMOV'], 0); $creA = (float) nz($i['CREMOV'], 0);
            $isVadA  = ($vadPA  !== '' && strpos($cuA, $vadPA)  === 0);
            $isBankA = ($bankPA !== '' && strpos($cuA, $bankPA) === 0);
            $isDifA  = ($difPA  !== '' && strpos($cuA, $difPA)  === 0);
            $creo = ($isVadA && $debA > 0) || ($isBankA && $creA > 0) || ($isDifA && $creA > 0);
            if ($creo) {
                $oth = (int) nz(db_row("SELECT Count(*) AS N FROM [Tbl Movimientos Imputaciones] WHERE CODCHQ=$chq;")['N'], 0);
                if ($oth > 0) throw new Exception('Un cheque de este asiento se usó en otro movimiento; anulá ese primero.');
                db_exec("DELETE FROM [Tbl Cheques] WHERE CODCHQ=$chq;");
            } elseif ($isVadA) {   // depósito de tercero → vuelve a cartera
                db_exec("UPDATE [Tbl Cheques] SET VADCHQ=True WHERE CODCHQ=$chq;");
            } elseif ($isDifA) {   // vencimiento de diferido → vuelve a diferido
                db_exec("UPDATE [Tbl Cheques] SET DIFCHQ=True WHERE CODCHQ=$chq;");
            }
        }
        db_exec("DELETE FROM [Tbl Movimientos IVA] WHERE NUMMOV=$num;");   // OP Contado: limpiar el IVA del comprobante
        db_exec("UPDATE [Tbl Movimientos] SET ANUMOV=True WHERE NUMMOV=$num;");
        db_commit();
        ok(array('anulado' => $num));
    } catch (Exception $e) {
        db_rollback();
        fail('No se pudo anular el asiento: ' . $e->getMessage(), 500);
    }
}

/** Listado de asientos manuales (CODORI='I') filtrado por el modo + texto/fecha. */
function listar() {
    $q  = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sd = as_serial(isset($_GET['desde']) ? $_GET['desde'] : '');
    $sh = as_serial(isset($_GET['hasta']) ? $_GET['hasta'] : '');
    $w  = "M.CODORI='I'" . preg_replace('/ESTMOV/', 'M.ESTMOV', as_estmov_w());
    if ($q !== '') {
        $qs = db_esc($q);
        $cond = "(M.DETMOV Like '%$qs%'";
        if (is_numeric($q)) $cond .= " OR M.CINMOV=" . (int) $q . " OR M.NUMMOV=" . (int) $q;
        $cond .= ")";
        $w .= " AND $cond";
    }
    if ($sd !== null) $w .= " AND M.FEXMOV >= $sd";
    if ($sh !== null) $w .= " AND M.FEXMOV <= $sh";
    $out = array();
    foreach (db_query("SELECT TOP 200 M.NUMMOV, M.CINMOV, M.FEXMOV, M.CODOPE, M.DETMOV, M.TOTMOV, M.ANUMOV, O.DENOPE
        FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Operaciones] AS O ON M.CODOPE=O.CODOPE
        WHERE $w ORDER BY M.FEXMOV DESC, M.NUMMOV DESC;") as $r) {
        $out[] = array(
            'NUMMOV'    => (int) $r['NUMMOV'],
            'NUMERO'    => str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
            'FECHA'     => fecha_serial($r['FEXMOV']),
            'OPERACION' => trim((string) nz($r['DENOPE'], '')),
            'DETALLE'   => trim((string) nz($r['DETMOV'], '')),
            'TOTAL'     => round((float) nz($r['TOTMOV'], 0), 2),
            'ANULADO'   => ($r['ANUMOV'] === true || $r['ANUMOV'] == -1),
        );
    }
    ok($out);
}

/** Detalle de un asiento para cargarlo en el form en sólo-lectura (cabecera + imputaciones). */
function detalle() {
    $num = isset($_GET['nummov']) ? (int) $_GET['nummov'] : 0;
    $h = db_row("SELECT M.CINMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.FEXMOV, M.CODOPE, M.CODAUX, M.DETMOV, M.TOTMOV, M.ANUMOV, O.DENOPE,
        M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV, M.CEFMOV, M.FIXMOV, M.CITMOV, M.DENMOV, M.CODCRI,
        M.NOGMOV, M.IP1MOV, M.IP2MOV, M.AP1MOV, M.AP2MOV
        FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Operaciones] AS O ON M.CODOPE=O.CODOPE
        WHERE M.NUMMOV=$num AND M.CODORI='I';");
    if (!$h) { fail('Asiento no encontrado'); return; }
    $r = array(
        'NUMMOV'    => $num,
        'NUMERO'    => str_pad((string) (int) nz($h['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        'CIC'       => trim((string) nz($h['CICMOV'], '')),
        'CII'       => trim((string) nz($h['CIIMOV'], '')),
        'CIP'       => (int) nz($h['CIPMOV'], 0),
        'FEXISO'    => as_fiso($h['FEXMOV']),
        'CODOPE'    => (int) $h['CODOPE'],
        'OPERACION' => trim((string) nz($h['DENOPE'], '')),
        'DETMOV'    => trim((string) nz($h['DETMOV'], '')),
        'TOTAL'     => round((float) nz($h['TOTMOV'], 0), 2),
        'ANULADO'   => ($h['ANUMOV'] === true || $h['ANUMOV'] == -1),
        'ANULABLE'  => (auth_is_admin() && !($h['ANUMOV'] === true || $h['ANUMOV'] == -1)),
        'lineas'    => array(),
    );
    foreach (db_query("SELECT I.CODCUE, I.DEBMOV, I.CREMOV, I.CODCDC, I.CODCHQ, C.DENCUE, D.DENCDC
        FROM ([Tbl Movimientos Imputaciones] AS I LEFT JOIN [Tbl Cuentas Contables] AS C ON I.CODCUE=C.CODCUE)
        LEFT JOIN [Tbl Centros de Costo] AS D ON I.CODCDC=D.CODCDC
        WHERE I.NUMMOV=$num ORDER BY I.ORDMOV;") as $i) {
        $cu = trim((string) nz($i['CODCUE'], ''));
        $chq = '';
        if ((int) nz($i['CODCHQ'], 0) > 0) {
            $cq = db_row("SELECT C.SYNCHQ, B.DENBAN FROM [Tbl Cheques] AS C LEFT JOIN [Tbl Bancos] AS B ON C.CODBAN=B.CODBAN WHERE C.CODCHQ=" . (int) $i['CODCHQ'] . ";");
            if ($cq) $chq = trim((string) nz($cq['DENBAN'], '')) . ' Nº ' . trim((string) nz($cq['SYNCHQ'], ''));
        }
        $r['lineas'][] = array(
            'codcue'  => $cu,
            'cuenta'  => $cu . ' · ' . trim((string) nz($i['DENCUE'], '')),
            'codcdc'  => (int) nz($i['CODCDC'], 1),
            'centro'  => trim((string) nz($i['DENCDC'], '')),
            'debe'    => round((float) nz($i['DEBMOV'], 0), 2),
            'cre'     => round((float) nz($i['CREMOV'], 0), 2),
            'cheque'  => $chq,
        );
    }
    // Comprobante con IVA (OP Contado): para que la carga readonly muestre la discriminación neto/alícuota/IVA.
    $codaux = (int) nz($h['CODAUX'], 0);
    if ($codaux > 0) {
        $ivas = array();
        foreach (db_query("SELECT NETMOV, ALIMOV, IRIMOV FROM [Tbl Movimientos IVA] WHERE NUMMOV=$num ORDER BY DECMOV;") as $iv)
            $ivas[] = array('net' => round((float) nz($iv['NETMOV'], 0), 2), 'ali' => round((float) nz($iv['ALIMOV'], 0), 2), 'iva' => round((float) nz($iv['IRIMOV'], 0), 2));
        $r['comprobante'] = array(
            'codaux' => $codaux,
            'cec' => trim((string) nz($h['CECMOV'], '')), 'cei' => trim((string) nz($h['CEIMOV'], '')),
            'cep' => (int) nz($h['CEPMOV'], 0), 'cen' => (int) nz($h['CENMOV'], 0),
            'cef' => as_fiso($h['CEFMOV']), 'fix' => as_fiso($h['FIXMOV']),
            'citmov' => trim((string) nz($h['CITMOV'], '')), 'denmov' => trim((string) nz($h['DENMOV'], '')), 'codcri' => (int) nz($h['CODCRI'], 0),
            'nogmov' => round((float) nz($h['NOGMOV'], 0), 2), 'ip1mov' => round((float) nz($h['IP1MOV'], 0), 2), 'ip2mov' => round((float) nz($h['IP2MOV'], 0), 2),
            'ap1mov' => round((float) nz($h['AP1MOV'], 0), 2), 'ap2mov' => round((float) nz($h['AP2MOV'], 0), 2),
            'ivas' => $ivas,
        );
    }
    ok($r);
}
