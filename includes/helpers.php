<?php
/**
 * inforemp-web-kit — Helpers de presentación y formato.
 * Reúne utilidades que en RDN estaban repetidas en cada api.php.
 */

/**
 * Garantiza UTF-8 válido en todo string (recursivo) para que json_encode no
 * devuelva false (→ body vacío). Los datos de la mdb ya pasan por ado_val, pero
 * los mensajes de com_exception vienen en Windows-1252 y romperían la respuesta.
 */
function utf8_guard($v) {
    if (is_string($v)) {
        return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $vv) $out[$k] = utf8_guard($vv);
        return $out;
    }
    return $v;
}

/** json_encode robusto: si falla por UTF-8, reintenta saneando (camino normal sin costo). */
function json_out($payload) {
    $j = json_encode($payload);
    if ($j === false) $j = json_encode(utf8_guard($payload));
    return $j;
}

/** Respuesta JSON de éxito. Uso: ok($data); exit; */
function ok($data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_out(['ok' => true, 'data' => $data]);
}

/** Respuesta JSON de error. Uso: fail('mensaje'); exit;  $kind opcional (ej 'unreachable'/'rejected') para que el front discrimine. */
function fail($msg, $code = 400, $kind = null) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['ok' => false, 'error' => $msg];
    if ($kind !== null) $payload['kind'] = $kind;
    echo json_out($payload);
}

/** NZ de Access: valor por defecto si es null/''. */
function nz($value, $default = 0) {
    return ($value === null || $value === '') ? $default : $value;
}

/** Fecha Access (texto/COM) → 'dd/mm/YYYY' para mostrar. */
function fecha_es($v) {
    if ($v === null || $v === '') return '';
    $ts = is_numeric($v) ? (int) $v : strtotime((string) $v);
    return $ts ? date('d/m/Y', $ts) : (string) $v;
}

/**
 * Serial de fecha de Access (días desde 1899-12-30) → 'dd/mm/YYYY'.
 * Algunos campos legacy guardan la fecha como número, no como tipo Date.
 */
function fecha_serial($v) {
    if ($v === null || $v === '' || !is_numeric($v) || (int) $v <= 0) return '';
    $d = new DateTime('1899-12-30');
    $d->modify('+' . (int) $v . ' days');
    return $d->format('d/m/Y');
}

/**
 * 'Y-m-d' → serial Access (OLE, base 1899-12-30). Inverso EXACTO de fecha_serial().
 * OJO: usa UTC + hora reseteada ('!Y-m-d') a propósito: createFromFormat('Y-m-d') sin hora toma la
 * hora ACTUAL y, con la timezone histórica de Argentina (offset raro en 1899), el diff() salía
 * off-by-one de forma NO determinística (según la hora del día). Esto rompía el borde de los períodos.
 */
function iso_serial($iso) {
    if (!$iso) return null;
    $d = DateTime::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));
    if (!$d) return null;
    return (int) (new DateTime('1899-12-30', new DateTimeZone('UTC')))->diff($d)->days;
}

/** Serial Access (OLE, base 1899-12-30) → 'Y-m-d' (para inputs date). '' si vacío/0. */
function fecha_iso($v) {
    $f = fecha_serial($v);
    if (!$f || strpos($f, '/') === false) return '';
    $p = explode('/', $f);
    return $p[2] . '-' . $p[1] . '-' . $p[0];
}

/** Período predeterminado de TODOS los listados = Rec Control.DESFEC/HASFEC. Devuelve [desdeIso, hastaIso]. */
function rec_periodo() {
    $r = db_row("SELECT DESFEC, HASFEC FROM [Rec Control];");
    return array($r ? fecha_iso($r['DESFEC']) : '', $r ? fecha_iso($r['HASFEC']) : '');
}

/** 'dd/mm/YYYY' del usuario → 'mm/dd/YYYY' que entiende Access en SQL. */
function fecha_access($ddmmyyyy) {
    $p = explode('/', $ddmmyyyy);
    if (count($p) !== 3) return $ddmmyyyy;
    return $p[1] . '/' . $p[0] . '/' . $p[2];
}

/**
 * Normaliza un valor de fecha que puede venir como serial OLE (número, p.ej.
 * 36526), como 'YYYY-mm-dd' o como 'dd/mm/YYYY ...' → DateTime (o null).
 * COM a veces devuelve los campos Date de Access como serial OLE.
 */
function _parse_fecha($v) {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        $d = new DateTime('1899-12-30');
        $d->modify('+' . (int) $v . ' days');
        return $d;
    }
    $s = trim((string) $v);
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $s, $mm)) return new DateTime("{$mm[1]}-{$mm[2]}-{$mm[3]}");
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $mm)) return new DateTime("{$mm[3]}-{$mm[2]}-{$mm[1]}");
    $ts = strtotime($s);
    return $ts ? (new DateTime())->setTimestamp($ts) : null;
}

/** Valor de fecha (serial OLE o string) → 'YYYY-mm-dd' para <input type=date>. */
function to_iso_date($v) {
    $d = _parse_fecha($v);
    return $d ? $d->format('Y-m-d') : '';
}

/** Valor de fecha → 'dd/mm/YYYY' para mostrar. */
function to_disp_date($v) {
    $d = _parse_fecha($v);
    return $d ? $d->format('d/m/Y') : '';
}

/** Número a formato money es-AR. */
function money($n, $dec = 2) {
    return number_format((float) $n, $dec, ',', '.');
}

/** htmlspecialchars corto. */
function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
