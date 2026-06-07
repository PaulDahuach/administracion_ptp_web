<?php
/**
 * Notas de Débito (deudores) — emisión electrónica (ND clase A, AFIP tipo 2).
 * Porta `Frm CD Debitos NF`. Espejo de la NC pero DEBITA al cliente por CONCEPTO (Tbl Operaciones
 * Auxiliares, CODOPE=440): el concepto da la cuenta del HABER (CODCUE) y si lleva IVA (IVAAUX). Asiento
 * en sentido FV: DEBE deudores (total) / HABER cuenta-concepto (neto) + IVA débito + percep. La ND crea su
 * PROPIO vencimiento (A DEBITAR) y suma a la deuda: DEBMOV=TOTMOV=total, SDOMOV=total, SOPCUE += total.
 * Opcionalmente referencia una FV (CbtesAsoc de AFIP) sin tocar su saldo.
 *
 * Alcance v1 (caso típico, como la ND 239815): cuenta corriente, sin saldo a favor del cliente (SOCMOV≥0)
 * ni contado con cheque. Esos dos casos del VBA (anticipos por SDOMOV<0 / recibo RC vinculado) quedan TODO.
 *
 * nd_insert($d, $estTrue, $afip): SIN control de transacción (el caller envuelve). $afip = {cinmov, cae,
 * cae_vto, coddoc} (nº + CAE de AFIP) o null (negro/borrador, sin CAE).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/percep.php';

if (!defined('ND_LIB')) {
    require_once __DIR__ . '/../../includes/auth.php';
    auth_require_login();
    header('Content-Type: application/json');
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    try {
        switch ($action) {
            case 'conceptos':       listar_conceptos(); break;
            case 'buscar_clientes': buscar_clientes(); break;
            case 'get_cliente':     get_cliente(); break;
            case 'facturas':        facturas(); break;
            case 'guardar':         guardar(); break;
            case 'anular':          anular(); break;
            default: fail('Acción inválida: ' . $action);
        }
    } catch (Exception $e) { fail($e->getMessage(), 500); }
    exit;
}

function nd_iso($s) { if ($s === null || $s === '') return null; if (is_numeric($s)) return (int) $s; return (int) (new DateTime('1899-12-30'))->diff(new DateTime($s))->days; }
function nd_ymd_serial($v) { $v = (string) $v; if (preg_match('/^\d{8}$/', $v)) { $dt = DateTime::createFromFormat('Ymd', $v); return $dt ? (int) (new DateTime('1899-12-30'))->diff($dt)->days : null; } return nd_iso($v); }
function nd_txt($v) { $v = trim((string) $v); return ($v === '') ? 'Null' : "'" . db_esc($v) . "'"; }
function nd_num($v) { return (string) round((float) $v, 2); }
function nd_estmov_w() { $l = auth_libro_unico(); if ($l === 'blanco') return ' AND M.ESTMOV=True'; if ($l === 'negro') return ' AND M.ESTMOV=False'; return ''; }

/** Conceptos de ND (CODOPE=440): cada uno con su cuenta (CODCUE) y si lleva IVA (IVAAUX). */
function listar_conceptos() {
    $rows = db_query("SELECT CODAUX, DENAUX, IVAAUX, CODCUE FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=440 AND CODCUE Is Not Null AND CODCUE <> '' ORDER BY DENAUX;");
    $out = array();
    foreach ($rows as $r) $out[] = array('CODAUX' => (int) $r['CODAUX'], 'DENAUX' => trim((string) nz($r['DENAUX'], '')),
        'IVA' => ($r['IVAAUX'] === true || $r['IVAAUX'] == -1), 'CUENTA' => trim((string) nz($r['CODCUE'], '')));
    ok($out);
}

function buscar_clientes() {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 1) { ok(array()); return; }
    $s = db_esc($q); $num = is_numeric($q) ? ' OR CODCUE = ' . (int) $q : '';
    ok(db_query("SELECT TOP 20 CODCUE, DENCUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODORI='D' AND ((DENCUE Like '%$s%')$num) ORDER BY DENCUE;"));
}

function get_cliente() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $c = db_row("SELECT C.CODCUE, C.DENCUE, C.CITCUE, C.SOPCUE, C.DCXCUE, C.DNXCUE, C.CODLOC, L.DENLOC, P.DENPRO, C.CODCRI, C.CODCDV, C.SPICUE
        FROM ([Tbl Provincias] AS P RIGHT JOIN ([Tbl Localidades] AS L INNER JOIN [Tbl Cuentas Corrientes] AS C ON L.CODLOC=C.CODLOC) ON P.CODPRO=L.CODPRO)
        WHERE C.CODORI='D' AND C.CODCUE=$cc;");
    if (!$c) { fail('Cliente no encontrado'); return; }
    $cri = db_row("SELECT DENCRI, ICXCRI FROM [Tbl Categorias Responsabilidad IVA] WHERE CODCRI=" . (int) nz($c['CODCRI'], 0) . ";");
    $c['LETRA'] = $cri ? strtoupper(trim((string) nz($cri['ICXCRI'], 'A'))) : 'A';
    $c['DENCRI'] = $cri ? trim((string) nz($cri['DENCRI'], '')) : '';
    $c['COND_IVA'] = ((int) nz($c['CODCRI'], 0) == 1) ? 1 : 5;
    $c['SALDO'] = round((float) nz($c['SOPCUE'], 0), 2);
    $c['DOMICILIO'] = trim(nz($c['DCXCUE'], '') . ' ' . nz($c['DNXCUE'], ''));
    $c['LOCALIDAD'] = trim(nz($c['DENLOC'], '') . (nz($c['DENPRO'], '') ? ' - ' . nz($c['DENPRO'], '') : ''));
    $perc = percep_calc(999999999, $c['CITCUE'], $c['CODCRI'], $c['SPICUE'], true);
    $c['PERCEP'] = array('activa' => $perc['spimov'], 'alipix' => $perc['alipix'], 'mnppix' => $perc['mnppix']);
    ok($c);
}

/** FV(s) del cliente para asociar la ND a AFIP (CbtesAsoc). La ND NO toca el saldo de la FV. */
function facturas() {
    $cc = isset($_GET['codcue']) ? (int) $_GET['codcue'] : 0;
    $rows = db_query("SELECT TOP 30 M.NUMMOV, M.CICMOV, M.CIIMOV, M.CIPMOV, M.CINMOV, M.FEXMOV, M.TOTMOV
        FROM [Tbl Movimientos] AS M WHERE M.CODORI='D' AND M.CODOPE=420 AND M.CODCUE=$cc" . nd_estmov_w() . " ORDER BY M.NUMMOV DESC;");
    $out = array();
    foreach ($rows as $r) $out[] = array(
        'NUMMOV' => (int) $r['NUMMOV'],
        'COMP'   => trim((string) nz($r['CICMOV'], '')) . ' ' . trim((string) nz($r['CIIMOV'], '')) . ' ' . str_pad((string) (int) nz($r['CIPMOV'], 0), 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) (int) nz($r['CINMOV'], 0), 8, '0', STR_PAD_LEFT),
        'FEXMOV' => fecha_serial($r['FEXMOV']),
        'TOTMOV' => round((float) nz($r['TOTMOV'], 0), 2));
    ok($out);
}

/** Imputación contable + mayorización (DEBCUE/CRECUE). $keepZero: guarda el 0 explícito (fila percep). */
function nd_imp(&$ord, &$totDeb, &$totCre, $nummov, $cuenta, $deb, $cre, $keepZero = false) {
    $deb = round((float) $deb, 2); $cre = round((float) $cre, 2);
    $ord++;
    $cc = db_esc((string) $cuenta);
    $bal = db_row("SELECT DEBCUE, CRECUE FROM [Tbl Cuentas Contables] WHERE CODCUE='$cc';");
    $soc = $bal ? round((float) nz($bal['DEBCUE'], 0) - (float) nz($bal['CRECUE'], 0), 2) : 0;
    $debSql = ($deb != 0) ? (string) $deb : (($keepZero && $cre == 0) ? '0' : 'Null');
    $creSql = ($cre != 0) ? (string) $cre : (($keepZero && $deb == 0) ? '0' : 'Null');
    db_exec("INSERT INTO [Tbl Movimientos Imputaciones] (NUMMOV, ORDMOV, CODCUE, DEBMOV, CREMOV, CODCDC, SOCMOV)
        VALUES ($nummov, $ord, '$cc', $debSql, $creSql, 1, $soc);");
    if ($deb != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE + $deb WHERE CODCUE='$cc';");
    if ($cre != 0) db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE + $cre WHERE CODCUE='$cc';");
    $totDeb += $deb; $totCre += $cre;
}

function nd_insert($d, $estTrue, $afip) {
    $rc = db_row("SELECT CACC_A, CACC_C, CACC_N, CACC_Z FROM [Rec Control];");
    $caccA = trim((string) $rc['CACC_A']); $caccC = trim((string) $rc['CACC_C']);
    $caccN = trim((string) $rc['CACC_N']); $caccZ = trim((string) $rc['CACC_Z']);

    $codcue = (int) $d['codcue'];
    $cli = db_row("SELECT DENCUE, SOPCUE, SPICUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=$codcue AND CODORI='D';");
    if (!$cli) throw new Exception('Cliente inexistente');

    $fex = nd_iso($d['fexmov']);
    if ($fex === null) throw new Exception('Falta la fecha de emisión');
    $ciimov = strtoupper(trim((string) nz($d['ciimov'], 'A')));
    $cipmov = (isset($d['cipmov']) && (int) $d['cipmov'] > 0) ? (int) $d['cipmov'] : null;
    $cipSql = ($cipmov === null) ? 'Null' : (string) $cipmov;
    $codcdv = (int) nz($d['codcdv'], 2);
    $codaux = (int) $d['codaux'];
    $estSql = $estTrue ? 'True' : 'False';

    // Concepto: cuenta del HABER (CODCUE) + si lleva IVA (IVAAUX).
    $aux = db_row("SELECT DENAUX, IVAAUX, CODCUE FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=440 AND CODAUX=$codaux;");
    if (!$aux) throw new Exception('Concepto (CODAUX) inválido: ' . $codaux);
    $ctaConcepto = trim((string) nz($aux['CODCUE'], ''));
    $tieneIva = ($aux['IVAAUX'] === true || $aux['IVAAUX'] == -1);

    $netmov = round((float) nz($d['netmov'], 0), 2);                  // neto gravado (si IVAAUX) o no gravado
    $irimov = $tieneIva ? round((float) nz($d['irimov'], 0), 2) : 0;  // IVA débito
    $nogmov = $tieneIva ? 0 : $netmov;                               // sin IVA → no gravado
    $perc   = percep_calc($netmov, nz($d['citmov'], $cli['CITCUE']), (int) nz($d['codcri'], 0), $cli['SPICUE'], $estTrue);
    $pixmov = $perc['pixmov'];
    $spimov = $perc['spimov'] ? 'True' : 'False';
    $apimov = $perc['alipix'];
    $mpimov = $perc['mnppix'];
    $total  = round($netmov + $irimov + $pixmov, 2);                  // total = neto + IVA + percep (debita al cliente)
    $soc    = isset($d['soc']) ? round((float) nz($d['soc'], 0), 2) : 0;
    if ($soc < 0) throw new Exception('La ND con saldo a favor del cliente (anticipos) no está soportada en v1.');

    // Vencimientos propios (A DEBITAR). Default: uno por el total a la fecha indicada (o emisión).
    $vtos = isset($d['vtos']) && is_array($d['vtos']) ? $d['vtos'] : array();
    if (!count($vtos)) $vtos = array(array('fvxmov' => isset($d['fvxmov']) ? $d['fvxmov'] : $d['fexmov'], 'detmov' => '', 'debmov' => $total));

    // FV referenciada (opcional): para CbtesAsoc de AFIP + traza en Tbl Movimientos Referencias.
    $refs = isset($d['refs']) && is_array($d['refs']) ? $d['refs'] : array();

    // Numeración: NUMMOV interno; CINMOV = nº de AFIP (electrónico) o contador local (negro).
    $nummov = next_number('ULTMOV');
    $cinmov = ($afip && isset($afip['cinmov']) && $afip['cinmov']) ? (int) $afip['cinmov'] : next_number_pdv('ULTND' . $ciimov, $cipmov);
    $coddoc = (int) nz(($afip && isset($afip['coddoc'])) ? $afip['coddoc'] : (isset($d['coddoc']) ? $d['coddoc'] : 80), 80);
    $caeSql = ($afip && !empty($afip['cae'])) ? "'" . db_esc($afip['cae']) . "'" : 'Null';
    $fvcSerial = ($afip && !empty($afip['cae_vto'])) ? nd_ymd_serial($afip['cae_vto']) : null;
    $fvcSql = ($fvcSerial === null) ? 'Null' : (string) $fvcSerial;

    $sdomov = $total;   // ND a cuenta corriente: saldo pendiente = total (SOCMOV≥0, sin saldo a favor)

    // ── Header ──
    $denSql = nd_txt(nz($cli['DENCUE'], '')); $citSql = nd_txt(nz($d['citmov'], ''));
    $dcx = nd_txt(nz($d['dcxmov'], '')); $dnx = nd_txt(nz(isset($d['dnxmov']) ? $d['dnxmov'] : '', ''));
    $codloc = (int) nz($d['codloc'], 0); $codcri = (int) nz($d['codcri'], 0);
    $detSql = nd_txt(isset($d['detmov']) ? $d['detmov'] : '');
    $cotmov = round((float) nz(isset($d['cotmov']) ? $d['cotmov'] : 1, 1), 4);

    db_exec("INSERT INTO [Tbl Movimientos]
        (NUMMOV, CODORI, FEXMOV, FIXMOV, CODOPE, CODAUX, CICMOV, CIIMOV, CIPMOV, CINMOV, CECMOV, CEIMOV, CEPMOV, CENMOV, CEFMOV,
         CODCUE, SOCMOV, DENMOV, DCXMOV, DNXMOV, CODLOC, CODCRI, CITMOV, CODCDV, DETMOV, COTMOV, NETMOV, IRIMOV, NOGMOV, SPIMOV, APIMOV, MPIMOV, PIXMOV,
         DEBMOV, TOTMOV, SDOMOV, CODDOC, CAEMOV, FVCMOV, ESTMOV, NUIMOV, NMIMOV, NOWMOV)
        VALUES ($nummov, 'D', $fex, $fex, 440, $codaux, 'ND', '$ciimov', $cipSql, $cinmov, 'ND', '$ciimov', $cipSql, $cinmov, $fex,
         $codcue, " . nd_num($soc) . ", $denSql, $dcx, $dnx, $codloc, $codcri, $citSql, $codcdv, $detSql, $cotmov, " . ($netmov != 0 && $tieneIva ? nd_num($netmov) : 'Null') . ", " . ($irimov != 0 ? nd_num($irimov) : 'Null') . ", " . ($nogmov != 0 ? nd_num($nogmov) : 'Null') . ", $spimov, " . ($apimov > 0 ? (string) $apimov : 'Null') . ", " . nd_num($mpimov) . ", " . nd_num($pixmov) . ",
         " . nd_num($total) . ", " . nd_num($total) . ", " . nd_num($sdomov) . ", $coddoc, $caeSql, $fvcSql, $estSql, 0, 0, Now());");

    // ── IVA (si el concepto lleva IVA) ──
    if ($tieneIva && ($netmov != 0 || $irimov != 0)) {
        $ali = round((float) nz(isset($d['ali']) ? $d['ali'] : 21, 21), 2);
        db_exec("INSERT INTO [Tbl Movimientos IVA] (NUMMOV, ALIMOV, NETMOV, IRIMOV, DECMOV)
            VALUES ($nummov, $ali, " . nd_num($netmov) . ", " . nd_num($irimov) . ", False);");
    }

    // ── Vencimientos propios (A DEBITAR) ──
    foreach ($vtos as $v) {
        $fvx = nd_iso(isset($v['fvxmov']) ? $v['fvxmov'] : null); if ($fvx === null) $fvx = $fex;
        $deb = round((float) nz($v['debmov'], 0), 2); if ($deb == 0) continue;
        db_exec("INSERT INTO [Tbl Movimientos Vencimientos] (NUMMOV, FVXMOV, DETMOV, DEBMOV)
            VALUES ($nummov, $fvx, " . nd_txt(isset($v['detmov']) ? $v['detmov'] : '') . ", " . nd_num($deb) . ");");
    }

    // ── Asiento (sentido FV): DEBE deudores (total) / HABER concepto (neto) + IVA débito + percep ──
    $ord = 0; $totDeb = 0; $totCre = 0;
    nd_imp($ord, $totDeb, $totCre, $nummov, $caccA, $total, 0);              // DEBE deudores
    nd_imp($ord, $totDeb, $totCre, $nummov, $ctaConcepto, 0, $netmov);       // HABER cuenta del concepto (neto/no gravado)
    if ($tieneIva && $irimov != 0) nd_imp($ord, $totDeb, $totCre, $nummov, $caccC, 0, $irimov);   // HABER IVA débito
    if ($pixmov > 0) nd_imp($ord, $totDeb, $totCre, $nummov, $caccN, 0, $pixmov);                 // HABER percep IIBB
    $dif = round($totDeb - $totCre, 2);
    if (abs($dif) >= 0.005) { if ($dif > 0) nd_imp($ord, $totDeb, $totCre, $nummov, $caccZ, 0, $dif); else nd_imp($ord, $totDeb, $totCre, $nummov, $caccZ, -$dif, 0); }

    // ── Referencias a la(s) FV(s) asociada(s) (solo traza + AFIP; NO toca el saldo de la FV) ──
    foreach ($refs as $r) {
        $refMov = (int) nz(isset($r['nummov']) ? $r['nummov'] : (isset($r['refmov']) ? $r['refmov'] : 0), 0);
        if ($refMov <= 0) continue;
        $imp = round((float) nz(isset($r['imp']) ? $r['imp'] : $total, 0), 2);
        $fvx = (isset($r['fvxmov']) && $r['fvxmov'] !== '') ? nd_iso($r['fvxmov']) : null;
        $fvxSql = ($fvx === null) ? 'Null' : (string) $fvx;
        db_exec("INSERT INTO [Tbl Movimientos Referencias] (NUMMOV, REFMOV, FVXMOV, IMPMOV) VALUES ($nummov, $refMov, $fvxSql, " . nd_num($imp) . ");");
    }

    // ── Cuenta corriente: la ND aumenta la deuda → SOPCUE += total ──
    db_exec("UPDATE [Tbl Cuentas Corrientes] SET FUOCUE=$fex, SOPCUE = " . round((float) nz($cli['SOPCUE'], 0) + $total, 2) . " WHERE CODCUE=$codcue;");

    return array('nummov' => $nummov, 'cinmov' => $cinmov, 'total' => $total, 'sdomov' => $sdomov, 'balanceo' => round($totDeb - $totCre, 2),
        'cuenta_concepto' => $ctaConcepto, 'tiene_iva' => $tieneIva);
}

// ───────────────────────── Orquestación AFIP (WSFE + CbtesAsoc) ─────────────────────────

/** Mapea la ND ($d) al request de solicitarCAE (ND tipo 2), con los CbtesAsoc de las FV referenciadas. */
function nd_afip_request($d) {
    require_once __DIR__ . '/../../config/afip.php';
    $letra = strtoupper(trim((string) nz($d['ciimov'], 'A')));
    $cbteTipo = afip_cbte_tipo('ND', $letra);
    if (!$cbteTipo) throw new Exception('Clase de ND inválida: ' . $letra);
    $codaux = (int) $d['codaux'];
    $aux = db_row("SELECT IVAAUX FROM [Tbl Operaciones Auxiliares] WHERE CODOPE=440 AND CODAUX=$codaux;");
    $tieneIva = $aux && ($aux['IVAAUX'] === true || $aux['IVAAUX'] == -1);

    $neto = round((float) nz($d['netmov'], 0), 2);
    $iva  = $tieneIva ? round((float) nz($d['irimov'], 0), 2) : 0;
    $cliP = db_row("SELECT SPICUE, CITCUE FROM [Tbl Cuentas Corrientes] WHERE CODCUE=" . (int) $d['codcue'] . ";");
    $perc = percep_calc($neto, nz($d['citmov'], $cliP ? $cliP['CITCUE'] : ''), (int) nz($d['codcri'], 0), $cliP ? $cliP['SPICUE'] : false, true);
    $pix  = $perc['pixmov'];
    $total = round($neto + $iva + $pix, 2);
    $coddoc = (int) nz(isset($d['coddoc']) ? $d['coddoc'] : 80, 80);
    $docnro = preg_replace('/[^0-9]/', '', (string) nz($d['citmov'], ''));
    if ($docnro === '') { $docnro = '0'; $coddoc = 99; }
    $cbteFch = (new DateTime('1899-12-30'))->modify('+' . (int) nd_iso($d['fexmov']) . ' days')->format('Ymd');

    $cbtesAsoc = array();
    foreach ((isset($d['refs']) && is_array($d['refs']) ? $d['refs'] : array()) as $r) {
        $fv = db_row("SELECT CICMOV, CIIMOV, CIPMOV, CINMOV, FEXMOV FROM [Tbl Movimientos] WHERE NUMMOV=" . (int) $r['nummov'] . ";");
        if (!$fv) continue;
        $t = afip_cbte_tipo(trim((string) $fv['CICMOV']), trim((string) nz($fv['CIIMOV'], 'A')));
        if (!$t) continue;
        $cbtesAsoc[] = array('Tipo' => $t, 'PtoVta' => (int) nz($fv['CIPMOV'], 0), 'Nro' => (int) nz($fv['CINMOV'], 0),
            'CbteFch' => (new DateTime('1899-12-30'))->modify('+' . (int) nz($fv['FEXMOV'], 0) . ' days')->format('Ymd'));
    }

    $req = array(
        'pto_vta' => AFIP_PTO_VTA, 'cbte_tipo' => $cbteTipo, 'concepto' => AFIP_CONCEPTO,
        'doc_tipo' => $coddoc, 'doc_nro' => $docnro, 'cbte_fch' => $cbteFch,
        'imp_total' => $total, 'imp_trib' => $pix, 'imp_op_ex' => 0,
        'cond_iva_receptor' => (int) nz(isset($d['cond_iva']) ? $d['cond_iva'] : ((int) nz($d['codcri'], 0) == 1 ? 1 : 5), 1),
        '_coddoc' => $coddoc,
    );
    if ($tieneIva) {
        $req['imp_neto'] = $neto; $req['imp_iva'] = $iva; $req['imp_tot_conc'] = 0;
        $req['iva_array'] = array(array('Id' => afip_iva_id(isset($d['ali']) ? $d['ali'] : 21), 'BaseImp' => $neto, 'Importe' => $iva));
    } else {
        $req['imp_neto'] = 0; $req['imp_iva'] = 0; $req['imp_tot_conc'] = $neto;
    }
    if ($pix > 0) $req['trib_array'] = array(array('Id' => 7, 'Desc' => 'Percepcion IIBB', 'BaseImp' => $neto, 'Alic' => round($perc['alipix'], 2), 'Importe' => $pix));
    // AFIP (err 10197): la ND debe llevar CbtesAsoc (FV asociada) O PeriodoAsoc. Sin FV → período = fecha de emisión.
    if (count($cbtesAsoc)) $req['cbtes_asoc'] = $cbtesAsoc;
    else $req['periodo_asoc'] = array('FchDesde' => $cbteFch, 'FchHasta' => $cbteFch);
    return $req;
}

/** Pide el CAE de la ND a AFIP (NO toca la DB). Devuelve {cinmov, cae, cae_vto, coddoc, pto_vta}. */
function nd_solicitar_cae($d) {
    require_once __DIR__ . '/../../includes/afip_wsfe.php';
    $req = nd_afip_request($d);
    $coddoc = $req['_coddoc']; unset($req['_coddoc']);
    $wsfe = new AfipWsfe();
    $prox = $wsfe->ultimoAutorizado($req['pto_vta'], $req['cbte_tipo']) + 1;
    $req['cbte_desde'] = $prox; $req['cbte_hasta'] = $prox;
    $r = $wsfe->solicitarCAE($req);
    return array('cinmov' => (int) $r['cbte_desde'], 'cae' => $r['cae'], 'cae_vto' => $r['cae_vencimiento'], 'coddoc' => $coddoc, 'pto_vta' => $req['pto_vta']);
}

/** Emite la ND: pide el CAE (fuera de tx) y graba (en tx). Sincroniza el contador local con AFIP. */
function nd_emitir($d, $estTrue) {
    require_once __DIR__ . '/../../config/afip.php';
    $afip = nd_solicitar_cae($d);
    $d['cipmov'] = $afip['pto_vta'];
    db_begin();
    try {
        $res = nd_insert($d, $estTrue, $afip);
        $letra = strtoupper(trim((string) nz($d['ciimov'], 'A')));
        @db_exec("UPDATE [Tbl Puntos de Venta] SET ULTND$letra=" . (int) $afip['cinmov'] . " WHERE CODPDV=" . (int) $afip['pto_vta'] . ";");
        db_commit();
        $res['cae'] = $afip['cae']; $res['cae_vto'] = $afip['cae_vto'];
        return $res;
    } catch (Exception $e) { db_rollback(); throw $e; }
}

function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $raw = isset($_POST['data']) ? json_decode($_POST['data'], true) : null;
    if (!is_array($raw)) { fail('Datos inválidos'); return; }
    // BLANCO (operador/integral) = ND electrónica con CAE. NEGRO (capacitación) = sin CAE, pdv 9999.
    $estTrue = (auth_modo() !== 'capacitacion');
    try {
        if ($estTrue) {
            $res = nd_emitir($raw, true);
        } else {
            unset($raw['cipmov']);
            db_begin();
            try { $res = nd_insert($raw, false, null); db_commit(); }
            catch (Exception $e) { db_rollback(); throw $e; }
        }
        require_once __DIR__ . '/../../includes/comprobante_anular.php';
        $res['anulable'] = anular_es_anulable($estTrue, isset($res['cae']) ? $res['cae'] : '');
        ok($res);
    } catch (Exception $e) {
        fail('No se pudo ' . ($estTrue ? 'emitir' : 'grabar') . ' la nota de débito: ' . $e->getMessage(), 500);
    }
}

/** Anula una ND (admin + capacitación/sin-CAE/homologación · transacción · revierte todo). */
function anular() {
    require_once __DIR__ . '/../../includes/comprobante_anular.php';
    $num = isset($_POST['nummov']) ? (int) $_POST['nummov'] : 0;
    try {
        anular_check($num, 440, 'Nota de débito');
        db_begin();
        try { anular_comprobante($num, 440); db_commit(); }
        catch (Exception $e) { db_rollback(); throw $e; }
        ok(array('anulado' => $num));
    } catch (Exception $e) {
        fail('No se pudo anular la nota de débito: ' . $e->getMessage(), 400);
    }
}
