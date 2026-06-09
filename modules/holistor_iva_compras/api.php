<?php
/**
 * Exportación I.V.A. Compras a Holistor — porta `Menu.rutExportacion_HolistorIVACompras` + la query
 * `Qry CA Exportacion IVA Holistor3`. Genera el .txt de ancho fijo que importa Holistor (sistema contable
 * externo). Ver memoria [[imputaciones-holistor]].
 *
 * - Una fila por RENGLÓN DE GASTO (Tbl Movimientos Imputaciones) del libro BLANCO (ESTMOV=True), CODORI A/I,
 *   con la operación/auxiliar IVA-relevante (IVAOPE/IVAAUX), excluyendo IVA Crédito Fiscal (1120210) y el
 *   CUIT propio. DEBE para CP/NC (qryFct=1); HABER e invertido para ND (CODOPE=330) o CODAUX=139 (qryFct=-1).
 * - Cada cuenta → su CODHOL (Tbl Cuentas Contables.CODHOL; tipos en Tbl Holistor). Si falta, no se exporta.
 * - El renglón lleva ALIMOV/IVAMOV/TOTMOV (cableados en la imputación). IVACRI (de la categoría IVA del
 *   proveedor) decide gravado (muestra neto/alíc/IVA) vs no-gravado (sólo el total).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('HOL_LIB')) {
    auth_require_login();
    header('Content-Type: application/json');
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    try {
        switch ($action) {
            case 'preview':     hol_preview();      break;   // filas a exportar + cuentas sin mapear
            case 'tipos':       hol_tipos();        break;   // Tbl Holistor (CODHOL→DENHOL)
            case 'set_codhol':  hol_set_codhol();   break;   // asignar CODHOL a una cuenta
            case 'exportar':    hol_exportar();     break;   // descarga del .txt (no JSON)
            default: fail('Acción inválida: ' . $action);
        }
    } catch (Exception $e) { fail($e->getMessage(), 500); }
}

function hol_serial($iso) {
    if (!$iso) return null;
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    return $d ? (int) (new DateTime('1899-12-30'))->diff($d)->days : null;
}
/** número con punto decimal, 2 dec, sin separador de miles (Format "0.00" + replace "," por ".") */
function hol_num($v) { return number_format((float) $v, 2, '.', ''); }
/** serial Access → 'dd/mm/yyyy' */
function hol_fecha($serial) { $f = fecha_serial($serial); return ($f && strpos($f, '/') !== false) ? $f : '  /  /    '; }
function hol_pad($s, $n) { $s = (string) $s; return strlen($s) > $n ? substr($s, 0, $n) : $s . str_repeat(' ', $n - strlen($s)); }      // izq, rellena a la derecha
function hol_rpad($s, $n) { $s = (string) $s; return strlen($s) > $n ? substr($s, -$n) : str_repeat(' ', $n - strlen($s)) . $s; }       // der, rellena a la izquierda

/** Las filas del período (porta Qry CA Exportacion IVA Holistor3). Sólo libro blanco. */
function hol_query($desde, $hasta) {
    $d1 = hol_serial($desde); $d2 = hol_serial($hasta);
    if ($d1 === null || $d2 === null) throw new Exception('Rango de fechas inválido');
    $sql = "SELECT M.NUMMOV, M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV, M.CEFMOV, M.FIXMOV, M.CODOPE, M.CODAUX, M.CODCRI, M.CODORI,
        M.CITMOV, M.DENMOV, M.DCXMOV, M.DNXMOV, M.DPXMOV, M.DDXMOV, M.CODLOC,
        Cri.INICRI, Cri.IVACRI, L.CPXLOC, P.HMLPRO,
        MI.CODCUE AS QRYCON, MI.DEBMOV, MI.CREMOV, MI.ALIMOV, MI.IVAMOV, MI.TOTMOV AS MITOT, Con.CODHOL
      FROM (((((([Tbl Movimientos] AS M
        INNER JOIN [Tbl Movimientos Imputaciones] AS MI ON MI.NUMMOV=M.NUMMOV)
        INNER JOIN [Tbl Cuentas Contables] AS Con ON Con.CODCUE=MI.CODCUE)
        INNER JOIN [Tbl Categorias Responsabilidad IVA] AS Cri ON Cri.CODCRI=M.CODCRI)
        LEFT JOIN [Tbl Operaciones] AS O ON O.CODOPE=M.CODOPE)
        LEFT JOIN [Tbl Operaciones Auxiliares] AS A ON A.CODAUX=M.CODAUX)
        LEFT JOIN [Tbl Localidades] AS L ON L.CODLOC=M.CODLOC)
        LEFT JOIN [Tbl Provincias] AS P ON P.CODPRO=L.CODPRO
      WHERE M.CODORI IN ('A','I') AND M.ESTMOV=True AND M.CENMOV>0 AND M.CITMOV<>'20-18136376-3'
        AND MI.CODCUE<>'1120210' AND M.FIXMOV>=$d1 AND M.FIXMOV<=$d2
        AND (O.IVAOPE=True OR A.IVAAUX=True)
        AND ( (M.CODAUX<>139 AND M.CODOPE<>330 AND MI.DEBMOV Is Not Null AND MI.CREMOV Is Null)
           OR ((M.CODAUX=139 OR M.CODOPE=330) AND MI.CREMOV Is Not Null AND MI.DEBMOV Is Null) )
      ORDER BY M.FIXMOV, M.CECMOV, M.CEIMOV, M.CEPMOV, M.CENMOV;";
    return db_query($sql);
}

/** CEC del legacy (sbrCEC): mapea el código del comprobante a la inicial Holistor. */
function hol_cec($r) {
    $c = trim((string) nz($r['CECMOV'], '')); $ope = (int) $r['CODOPE']; $cri = (int) nz($r['CODCRI'], 0);
    if ($c === 'FC') return 'F ';
    if ($c === 'NC') return 'C ';
    if ($c === 'ND') return 'D ';
    if ($c === 'RC') return 'RE';
    if ($ope === 310) return 'F ';
    if ($ope === 320) return 'D ';
    if ($ope === 330) return 'C ';
    if ($cri === 1) return 'F ';
    return 'RE';
}
/** CEI del legacy (sbrCEI): la letra; si falta, según la categoría IVA. */
function hol_cei($r) {
    $i = trim((string) nz($r['CEIMOV'], '')); if ($i !== '') return $i;
    $cri = (int) nz($r['CODCRI'], 0);
    if ($cri === 3 || $cri === 6) return 'C';
    if ($cri === 5) return 'B';
    return 'A';
}
/** CRI del legacy (sbrCRI): condición frente al IVA, 4 chars. */
function hol_cri($r) {
    switch ((int) nz($r['CODCRI'], 0)) {
        case 1: return 'RI  '; case 2: return 'RNI '; case 3: return 'EX  ';
        case 4: return 'NR  '; case 5: return 'CF  '; case 6: return 'MT  ';
        default: return hol_pad(trim((string) nz($r['INICRI'], '')), 4);
    }
}

/** Header de ancho fijo (sbrPrintHeader). */
function hol_header() {
    return implode(' ', array(
        'CM', 'I', 'PDV.', 'NUMERO..', 'FECHA.....', 'FECHA.....', 'MER',
        'NETO................', 'IIN', 'NO GRAVADO..........', 'EXC EXEN', 'PERC', 'PERCEPCIONES........',
        'ALIC.IVA.', 'I.V.A. LIQUIDADO....', 'I.V.A. CREDITO FISC.', 'TOTAL...............',
        'COND', 'C.U.I.T..PRV.', hol_pad('NOMBRE PROVEEDOR', 50), hol_pad('DOMICILIO PROVEEDOR', 50),
        'C.P.', 'PR', 'DC'
    ));
}

/** Una línea de datos del .txt (porta el bucle Print #3 de rutExportacion_HolistorIVACompras). */
function hol_line($r, $caccF, $caccG) {
    $ope = (int) $r['CODOPE']; $aux = (int) nz($r['CODAUX'], 0);
    $fct = ($aux === 139 || $ope === 330) ? -1 : 1;
    $qryImp = round((float) nz($r['DEBMOV'], 0) - (float) nz($r['CREMOV'], 0), 2);
    $codhol = trim((string) nz($r['CODHOL'], ''));
    $cuenta = trim((string) nz($r['QRYCON'], ''));
    $ivacri = ($r['IVACRI'] === true || $r['IVACRI'] == -1);
    $mitot  = $r['MITOT'];
    $curSTot = ($mitot === null) ? $qryImp : round((float) $mitot * $fct, 2);

    if ($cuenta === (string) $caccF || $cuenta === (string) $caccG) {           // PERCEPCIONES IVA / IIBB
        $lblNet = '   '; $net = str_repeat(' ', 20); $ali = str_repeat(' ', 10);
        $lblNog = '   '; $nog = str_repeat(' ', 20);
        $capPerc = hol_pad($codhol, 3); $impPerc = hol_rpad(hol_num(((float) nz($mitot, 0)) * $fct), 20); $iva = str_repeat(' ', 20);
    } elseif ($ivacri) {                                                        // GRAVADO
        $lblNet = hol_pad($codhol, 3); $net = hol_rpad(hol_num($qryImp), 20); $ali = hol_rpad(hol_num((float) nz($r['ALIMOV'], 0)), 10);
        $lblNog = '   '; $nog = str_repeat(' ', 20);
        $capPerc = '   '; $impPerc = str_repeat(' ', 20); $iva = hol_rpad(hol_num(((float) nz($r['IVAMOV'], 0)) * $fct), 20);
    } else {                                                                    // NO GRAVADO / no discrimina
        $lblNet = hol_pad($codhol, 3); $net = str_repeat(' ', 20); $ali = str_repeat(' ', 10);
        $lblNog = '   '; $nog = str_repeat(' ', 20);
        $capPerc = '   '; $impPerc = str_repeat(' ', 20); $iva = str_repeat(' ', 20);
    }
    $cit = hol_pad(hol_cuit($r['CITMOV']), 13);
    $den = hol_pad(str_replace(array("\r", "\n"), '', trim((string) nz($r['DENMOV'], ''))), 50);
    $dom = hol_pad(str_replace(array("\r", "\n"), '', hol_dom($r)), 50);
    $cpx = hol_rpad(trim((string) nz($r['CPXLOC'], '')), 4);
    $pro = ($r['CODLOC'] === null) ? '  ' : hol_rpad(str_pad((string) (int) nz($r['HMLPRO'], 0), 2, '0', STR_PAD_LEFT), 2);
    $doc = hol_rpad('80', 2);

    return implode(' ', array(
        hol_cec($r), substr(hol_cei($r) . ' ', 0, 1),
        str_pad((string) (int) nz($r['CEPMOV'], 0), 4, '0', STR_PAD_LEFT),
        str_pad((string) (int) nz($r['CENMOV'], 0), 8, '0', STR_PAD_LEFT),
        hol_fecha($r['CEFMOV']), hol_fecha($r['FIXMOV']),
        $lblNet, $net, $lblNog, $nog, 'EXC 0.00', $capPerc, $impPerc, $ali, $iva, $iva,
        hol_rpad(hol_num($curSTot), 20), hol_cri($r), $cit, $den, $dom, $cpx, $pro, $doc
    ));
}
function hol_cuit($cit) {
    $c = preg_replace('/\D/', '', (string) nz($cit, ''));
    if (strlen($c) < 11) return trim((string) nz($cit, ''));
    return substr($c, 0, 2) . '-' . substr($c, 2, 8) . '-' . substr($c, 10, 1);
}
function hol_dom($r) {
    $p = array(nz($r['DCXMOV'], ''), nz($r['DNXMOV'], ''), nz($r['DPXMOV'], ''), nz($r['DDXMOV'], ''));
    return trim(implode(' ', array_map('trim', $p)));
}

/** Genera el contenido completo del .txt (header opcional + una línea por fila). */
function hol_build($desde, $hasta, $conHeader) {
    $rc = db_row("SELECT CACC_F, CACC_G FROM [Rec Control];");
    $caccF = trim((string) nz($rc['CACC_F'], '')); $caccG = trim((string) nz($rc['CACC_G'], ''));
    $out = array(); if ($conHeader) $out[] = hol_header();
    foreach (hol_query($desde, $hasta) as $r) $out[] = hol_line($r, $caccF, $caccG);
    return implode("\r\n", $out) . "\r\n";
}

/** Vista previa: filas (para la tabla) + cuentas de gasto SIN CODHOL (bloquean el export). */
function hol_preview() {
    $desde = isset($_GET['desde']) ? $_GET['desde'] : ''; $hasta = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $rows = array(); $sin = array(); $n = 0;
    foreach (hol_query($desde, $hasta) as $r) {
        $codhol = trim((string) nz($r['CODHOL'], '')); $cuenta = trim((string) nz($r['QRYCON'], ''));
        $ope = (int) $r['CODOPE']; $aux = (int) nz($r['CODAUX'], 0); $fct = ($aux === 139 || $ope === 330) ? -1 : 1;
        $imp = round(((float) nz($r['DEBMOV'], 0) - (float) nz($r['CREMOV'], 0)), 2);
        if ($codhol === '') $sin[$cuenta] = true;
        $n++;
        if ($n <= 500) $rows[] = array(
            'NUMMOV' => (int) $r['NUMMOV'], 'FECHA' => fecha_serial($r['FIXMOV']),
            'COMP' => trim(trim((string) nz($r['CECMOV'], '')) . ' ' . trim((string) nz($r['CEIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CEPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CENMOV'], 0), 8, '0', STR_PAD_LEFT)),
            'PROVEEDOR' => trim((string) nz($r['DENMOV'], '')), 'CUENTA' => $cuenta,
            'CODHOL' => $codhol, 'ALI' => ($r['ALIMOV'] === null ? null : round((float) $r['ALIMOV'], 2)),
            'IMP' => round($imp * $fct, 2),
        );
    }
    ok(array('total' => $n, 'rows' => $rows, 'sin_mapear' => array_keys($sin)));
}

/** Tbl Holistor — los tipos de movimiento (para el combo del mapeo). */
function hol_tipos() {
    $out = array();
    foreach (db_query("SELECT CODHOL, DENHOL FROM [Tbl Holistor] ORDER BY DENHOL;") as $h)
        $out[] = array('CODHOL' => trim((string) $h['CODHOL']), 'DENHOL' => trim((string) nz($h['DENHOL'], '')));
    ok($out);
}

/** Asigna el CODHOL a una cuenta contable (porta Frm 00 Cuentas Holistor). */
function hol_set_codhol() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $codcue = isset($_POST['codcue']) ? trim((string) $_POST['codcue']) : '';
    $codhol = isset($_POST['codhol']) ? trim((string) $_POST['codhol']) : '';
    if ($codcue === '') { fail('Falta la cuenta'); return; }
    $cta = db_row("SELECT CODCUE FROM [Tbl Cuentas Contables] WHERE CODCUE='" . db_esc($codcue) . "';");
    if (!$cta) { fail('Cuenta inexistente'); return; }
    if ($codhol !== '') {
        $t = db_row("SELECT CODHOL FROM [Tbl Holistor] WHERE CODHOL='" . db_esc($codhol) . "';");
        if (!$t) { fail('Tipo Holistor inexistente'); return; }
    }
    db_exec("UPDATE [Tbl Cuentas Contables] SET CODHOL=" . ($codhol === '' ? 'Null' : "'" . db_esc($codhol) . "'") . " WHERE CODCUE='" . db_esc($codcue) . "';");
    ok(array('codcue' => $codcue, 'codhol' => $codhol));
}

/** Descarga del .txt (header opcional). Bloquea si hay cuentas sin mapear. */
function hol_exportar() {
    $desde = isset($_GET['desde']) ? $_GET['desde'] : ''; $hasta = isset($_GET['hasta']) ? $_GET['hasta'] : '';
    $conHeader = isset($_GET['header']) && $_GET['header'] === '1';
    $rc = db_row("SELECT CACC_F, CACC_G FROM [Rec Control];");
    $caccF = trim((string) nz($rc['CACC_F'], '')); $caccG = trim((string) nz($rc['CACC_G'], ''));
    $sin = array();
    foreach (hol_query($desde, $hasta) as $r) if (trim((string) nz($r['CODHOL'], '')) === '') $sin[trim((string) nz($r['QRYCON'], ''))] = true;
    if (count($sin)) { header('Content-Type: application/json'); fail('Cuentas sin vincular a Holistor: ' . implode(', ', array_keys($sin)), 409); return; }
    $body = hol_build($desde, $hasta, $conHeader);
    header('Content-Type: text/plain; charset=windows-1252');
    header('Content-Disposition: attachment; filename="I.V.A. Compras.txt"');
    echo iconv('UTF-8', 'Windows-1252//TRANSLIT', $body);
}
