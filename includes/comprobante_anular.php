<?php
/**
 * Anulación reversible de comprobantes de venta (FV 420 / ND 440 / NC 460) emitidos en el sistema.
 *
 * anular_comprobante($num,$codope): revierte TODO lo que grabó la emisión — RC de contado vinculado,
 * anticipos (saldo a favor), referencias (la NC restaura la FV), cuenta corriente, asiento contable
 * (saldos cacheados DEBCUE/CRECUE), stock, vencimientos e IVA — y marca el comprobante ANUMOV=True
 * (lo deja como rastro, montos en 0). SIN transacción (el caller envuelve). No valida permisos.
 *
 * anular_check(): gate del endpoint — admin + (capacitación/negro · sin CAE · CAE de homologación no fiscal).
 * Un comprobante electrónico con CAE FISCAL (blanco + producción) NO se anula: se emite una NC/ND.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/**
 * ¿El comprobante es anulable? Solo admin + (capacitación/negro O sin CAE). Un comprobante CON CAE
 * (real o de homologación) NO se anula desde la UI — se protege el dato real (las FV históricas tienen CAE).
 * Para anular un comprobante de prueba, emitirlo en modo Capacitación (negro, sin CAE).
 */
function anular_es_anulable($estTrue, $cae) {
    if (!auth_is_admin()) return false;
    if (!$estTrue) return true;                    // capacitación (negro)
    return (trim((string) $cae) === '');           // sin CAE
}

/** Valida y devuelve el header, o lanza. NO abre transacción. */
function anular_check($num, $codope, $nombre) {
    if (!function_exists('AFIP_MODO') && is_file(__DIR__ . '/../config/afip.php')) @require_once __DIR__ . '/../config/afip.php';
    if (db_readonly()) throw new Exception('Sistema en modo solo-lectura');
    $h = db_row("SELECT ESTMOV, CAEMOV, ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=" . (int) $num . " AND CODOPE=" . (int) $codope . ";");
    if (!$h) throw new Exception($nombre . ' no encontrado');
    if ($h['ANUMOV'] === true || $h['ANUMOV'] == -1) throw new Exception('El comprobante ya está anulado');
    $estTrue = ($h['ESTMOV'] === true || $h['ESTMOV'] == -1);
    if (!anular_es_anulable($estTrue, nz($h['CAEMOV'], ''))) {
        if (!auth_is_admin()) throw new Exception('Solo un administrador puede anular comprobantes');
        throw new Exception('No se puede anular un comprobante electrónico con CAE fiscal. Emití una nota de crédito/débito.');
    }
    return $h;
}

function anular_comprobante($num, $codope) {
    $num = (int) $num; $codope = (int) $codope;
    $h = db_row("SELECT NUMMOV, CODORI, CODOPE, CODCUE, DEBMOV, CREMOV, NRCMOV FROM [Tbl Movimientos] WHERE NUMMOV=$num AND CODOPE=$codope;");
    if (!$h) throw new Exception('Comprobante no encontrado');
    $codcue = (int) $h['CODCUE'];
    $codori = strtoupper(trim((string) nz($h['CODORI'], 'D')));   // D=deudores / A=acreedores (signo de anticipos/SANCUE)
    $deb = round((float) nz($h['DEBMOV'], 0), 2);
    $cre = round((float) nz($h['CREMOV'], 0), 2);
    $nrc = (int) nz($h['NRCMOV'], 0);

    // 1) Recibo de contado vinculado (FV contado con cheque): anularlo primero (revierte sus cheques/asiento).
    if ($nrc > 0) {
        if (!defined('RECIBO_LIB')) define('RECIBO_LIB', 1);
        require_once __DIR__ . '/../modules/recibos/api.php';
        $rc = db_row("SELECT ANUMOV FROM [Tbl Movimientos] WHERE NUMMOV=$nrc AND CODOPE=480;");
        if ($rc && !($rc['ANUMOV'] === true || $rc['ANUMOV'] == -1) && function_exists('recibo_anular')) recibo_anular($nrc);
    }

    // 2) Anticipos (saldo a favor aplicado): restaurar el SDOMOV del origen + (acreedores) el SANCUE.
    // Deudores (FV): el alta hizo origen += imp → revertir -= imp. Acreedores (CP): hizo origen -= imp → += imp.
    $sumAnt = 0;
    foreach (db_query("SELECT ANTMOV, IMPMOV FROM [Tbl Movimientos Anticipos] WHERE NUMMOV=$num;") as $a) {
        $ant = (int) $a['ANTMOV']; $imp = round((float) nz($a['IMPMOV'], 0), 2); $sumAnt += $imp;
        $s = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV=$ant;");
        $delta = ($codori === 'A') ? $imp : -$imp;
        db_exec("UPDATE [Tbl Movimientos] SET SDOMOV=" . round((float) nz($s ? $s['SDOMOV'] : 0, 0) + $delta, 2) . " WHERE NUMMOV=$ant;");
    }
    db_exec("DELETE FROM [Tbl Movimientos Anticipos] WHERE NUMMOV=$num;");
    if ($codori === 'A' && round($sumAnt, 2) != 0) db_exec("UPDATE [Tbl Cuentas Corrientes] SET SANCUE = SANCUE + " . round($sumAnt, 2) . " WHERE CODCUE=$codcue;");

    // 3) Referencias: la NC (460) restauró la FV (SDOMOV-=imp, venc CREMOV+=imp) → revertir. FV/ND solo traza.
    foreach (db_query("SELECT REFMOV, FVXMOV, IMPMOV FROM [Tbl Movimientos Referencias] WHERE NUMMOV=$num;") as $r) {
        if ($codope === 460) {
            $ref = (int) $r['REFMOV']; $imp = round((float) nz($r['IMPMOV'], 0), 2);
            $fvx = ($r['FVXMOV'] !== null && $r['FVXMOV'] !== '') ? (int) $r['FVXMOV'] : null;
            $f = db_row("SELECT SDOMOV FROM [Tbl Movimientos] WHERE NUMMOV=$ref;");
            db_exec("UPDATE [Tbl Movimientos] SET SDOMOV=" . round((float) nz($f ? $f['SDOMOV'] : 0, 0) + $imp, 2) . " WHERE NUMMOV=$ref;");
            if ($fvx !== null) {
                $v = db_row("SELECT CREMOV FROM [Tbl Movimientos Vencimientos] WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
                $nuevo = round((float) nz($v ? $v['CREMOV'] : 0, 0) - $imp, 2);
                db_exec("UPDATE [Tbl Movimientos Vencimientos] SET CREMOV=" . ($nuevo == 0 ? 'Null' : $nuevo) . " WHERE NUMMOV=$ref AND FVXMOV=$fvx;");
            }
        }
    }
    db_exec("DELETE FROM [Tbl Movimientos Referencias] WHERE NUMMOV=$num;");

    // 4) Cuenta corriente: deshacer el efecto sobre la deuda = -(DEBMOV - CREMOV) del header.
    $delta = round($deb - $cre, 2);
    if ($delta != 0) db_exec("UPDATE [Tbl Cuentas Corrientes] SET SOPCUE = SOPCUE - $delta WHERE CODCUE=$codcue;");

    // 5) Imputaciones: revertir saldos contables cacheados + zerar el asiento (se mantienen las filas como rastro).
    foreach (db_query("SELECT CODCUE, DEBMOV, CREMOV FROM [Tbl Movimientos Imputaciones] WHERE NUMMOV=$num;") as $i) {
        $cc = db_esc((string) $i['CODCUE']);
        if ($i['DEBMOV'] !== null && $i['DEBMOV'] !== '') db_exec("UPDATE [Tbl Cuentas Contables] SET DEBCUE = DEBCUE - " . round((float) $i['DEBMOV'], 2) . " WHERE CODCUE='$cc';");
        if ($i['CREMOV'] !== null && $i['CREMOV'] !== '') db_exec("UPDATE [Tbl Cuentas Contables] SET CRECUE = CRECUE - " . round((float) $i['CREMOV'], 2) . " WHERE CODCUE='$cc';");
    }
    db_exec("UPDATE [Tbl Movimientos Imputaciones] SET DEBMOV=0, CREMOV=0 WHERE NUMMOV=$num;");

    // 6) Stock: la NC devolución física acumuló lo devuelto en la FV (CMDMOV += qty) → revertir. Borrar las líneas.
    foreach (db_query("SELECT NMDMOV, OMDMOV, CMDMOV, INGMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num;") as $s) {
        $nmd = (int) nz($s['NMDMOV'], 0); $omd = (int) nz($s['OMDMOV'], 0); $cmd = round((float) nz($s['CMDMOV'], 0), 2);
        if ($nmd > 0 && $omd > 0 && $cmd != 0 && nz($s['INGMOV'], 0) != 0) {
            $fvs = db_row("SELECT CMDMOV FROM [Tbl Movimientos Stock] WHERE NUMMOV=$nmd AND ORDMOV=$omd;");
            db_exec("UPDATE [Tbl Movimientos Stock] SET CMDMOV = " . round((float) nz($fvs ? $fvs['CMDMOV'] : 0, 0) - $cmd, 2) . " WHERE NUMMOV=$nmd AND ORDMOV=$omd;");
        }
    }
    db_exec("DELETE FROM [Tbl Movimientos Stock] WHERE NUMMOV=$num;");

    // 7) Vencimientos propios (FV/ND) + IVA: borrar.
    db_exec("DELETE FROM [Tbl Movimientos Vencimientos] WHERE NUMMOV=$num;");
    db_exec("DELETE FROM [Tbl Movimientos IVA] WHERE NUMMOV=$num;");

    // 8) Marcar anulado (montos en 0, [ANULADO], ANUMOV=True).
    db_exec("UPDATE [Tbl Movimientos] SET DETMOV='[ANULADO]', SDOMOV=0, DEBMOV=0, CREMOV=0, TOTMOV=0,
        NETMOV=Null, IRIMOV=Null, NOGMOV=Null, PIXMOV=Null, SPIMOV=False, ANUMOV=True WHERE NUMMOV=$num;");
}
