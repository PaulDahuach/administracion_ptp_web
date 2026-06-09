<?php
/**
 * Operaciones — VISOR DE SOLO LECTURA. Porta Frm IC Operaciones (consulta).
 * Las 27 operaciones (codope 100-500) son config de sistema (SYSOPE) que define cómo
 * cada operación imputa al mayor. No se editan; este módulo permite ver/auditar su
 * configuración: datos, comprobante interno/externo, modelos + plantilla de imputación
 * (debe/haber %), y auxiliares. La lógica de posteo ya vive en los módulos transaccionales.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : '';
try {
    switch ($action) {
        case 'list': listar();   break;
        case 'get':  obtener();  break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

function listar() {
    $ori = mapa('Tbl Origenes', 'CODORI', 'DENORI');
    $rows = db_query("SELECT CODOPE, DENOPE, CODORI FROM [Tbl Operaciones] ORDER BY CODOPE;");
    $out = array();
    foreach ($rows as $r) {
        $oc = trim((string) nz($r['CODORI'], ''));
        $out[] = array(
            'cod'    => (int) $r['CODOPE'],
            'den'    => trim((string) nz($r['DENOPE'], '')),
            'origen' => isset($ori[$oc]) ? $ori[$oc] : $oc,
        );
    }
    ok($out);
}

function obtener() {
    $cod = intval((isset($_GET['cod']) ? $_GET['cod'] : 0));
    $r = db_row("SELECT * FROM [Tbl Operaciones] WHERE [CODOPE]=$cod;");
    if (!$r) { fail('Operación no encontrada'); return; }

    $ori = mapa('Tbl Origenes', 'CODORI', 'DENORI');
    $mod = mapa('Tbl Modulos', 'CODMOD', 'DENMOD');
    $oc = trim((string) nz($r['CODORI'], ''));
    $num = array('A' => 'Automática', 'M' => 'Manual', 'N' => 'Ninguna');
    $icn = trim((string) nz($r['ICNOPE'], ''));

    // modelos + imputaciones
    $modelos = array();
    foreach (db_query("SELECT CODMOD, DENMOD FROM [Tbl Modelos] WHERE [CODOPE]=$cod ORDER BY CODMOD;") as $m) {
        $cm = intval($m['CODMOD']);
        $imps = array();
        foreach (db_query("SELECT ORDMOD, CODCUE, CODCDC, PDBMOD, PHBMOD FROM [Tbl Modelos Imputaciones] WHERE [CODMOD]=$cm ORDER BY ORDMOD;") as $i) {
            $cc = trim((string) nz($i['CODCUE'], ''));
            $imps[] = array(
                'ord'    => (int) $i['ORDMOD'],
                'cuenta' => nivcue_op($cc),
                'cuentaDen' => den_cuenta($cc),
                'centro' => den_centro(nz($i['CODCDC'], '')),
                'debe'   => ($i['PDBMOD'] !== null && $i['PDBMOD'] !== '') ? number_format((float) $i['PDBMOD'], 2, '.', ',') : '',
                'haber'  => ($i['PHBMOD'] !== null && $i['PHBMOD'] !== '') ? number_format((float) $i['PHBMOD'], 2, '.', ',') : '',
            );
        }
        $modelos[] = array('den' => trim((string) nz($m['DENMOD'], '')), 'imputaciones' => $imps);
    }

    // auxiliares
    $aux = array();
    foreach (db_query("SELECT CODAUX, DENAUX, IVAAUX, CODCUE FROM [Tbl Operaciones Auxiliares] WHERE [CODOPE]=$cod ORDER BY CODAUX;") as $a) {
        $cc = trim((string) nz($a['CODCUE'], ''));
        $aux[] = array(
            'cod'    => trim((string) nz($a['CODAUX'], '')),
            'den'    => trim((string) nz($a['DENAUX'], '')),
            'iva'    => (es_true($a['IVAAUX']) ? 1 : 0),
            'cuenta' => $cc !== '' ? (nivcue_op($cc) . ' ' . den_cuenta($cc)) : '',
        );
    }

    ok(array(
        'cod'    => (int) $r['CODOPE'],
        'den'    => trim((string) nz($r['DENOPE'], '')),
        'origen' => isset($ori[$oc]) ? $ori[$oc] : $oc,
        'modulo' => isset($mod[(int) $r['CODMOD']]) ? $mod[(int) $r['CODMOD']] : '',
        'sys'    => es_true($r['SYSOPE']) ? 1 : 0,
        // comprobante interno
        'ci_cod'  => trim((string) nz($r['ICCOPE'], '')),
        'ci_num'  => isset($num[$icn]) ? $num[$icn] : '—',
        'ci_ult'  => (int) nz($r['ULTOPE'], 0),
        'ci_ccte' => es_true($r['CUEOPE']) ? 1 : 0,
        'ci_iden' => es_true($r['ICIOPE']) ? 1 : 0,
        'ci_pdv'  => es_true($r['ICPOPE']) ? 1 : 0,
        // comprobante externo
        'ce_grav' => es_true($r['IVAOPE']) ? 1 : 0,
        'ce_cuit' => es_true($r['CITOPE']) ? 1 : 0,
        'ce_rs'   => es_true($r['RSXOPE']) ? 1 : 0,
        'ce_num'  => es_true($r['ICEOPE']) ? 1 : 0,
        'ce_chq'  => es_true($r['CHQOPE']) ? 1 : 0,
        'ce_cons' => es_true($r['ICROPE']) ? 1 : 0,
        'modelos' => $modelos,
        'auxiliares' => $aux,
    ));
}

// ───────── helpers ─────────
function es_true($v) { return $v === true || $v === -1 || $v === '-1' || $v === 1 || $v === '1'; }
function mapa($tabla, $pk, $den) {
    $m = array();
    foreach (db_query("SELECT [$pk] AS k, [$den] AS d FROM [$tabla];") as $r) $m[trim((string) $r['k'])] = trim((string) nz($r['d'], ''));
    return $m;
}
function den_cuenta($cod) {
    if ($cod === '') return '';
    $r = db_row("SELECT DENCUE FROM [Tbl Cuentas Contables] WHERE [CODCUE]='" . db_esc($cod) . "';");
    return $r ? trim((string) nz($r['DENCUE'], '')) : '';
}
function den_centro($cod) {
    $cod = trim((string) $cod);
    if ($cod === '') return '';
    $r = db_row("SELECT DENCDC FROM [Tbl Centros de Costo] WHERE [CODCDC]=" . intval($cod) . ";");
    return $r ? trim((string) nz($r['DENCDC'], '')) : '';
}
/** "11104" → "1.1.1.04" (NivCue). */
function nivcue_op($s) {
    $s = (string) $s; $n = strlen($s); if ($n === 0) return '';
    $o = substr($s, 0, 1);
    if ($n > 1) $o .= '.' . substr($s, 1, 1);
    if ($n > 2) $o .= '.' . substr($s, 2, 1);
    if ($n > 3) $o .= '.' . substr($s, 3, 2);
    if ($n > 5) $o .= '.' . substr($s, 5, 2);
    return $o;
}
