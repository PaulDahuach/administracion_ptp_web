<?php
/**
 * inforemp-web-kit — Helpers de presentación y formato.
 * Reúne utilidades que en RDN estaban repetidas en cada api.php.
 */

/** Respuesta JSON de éxito. Uso: ok($data); exit; */
function ok($data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data]);
}

/** Respuesta JSON de error. Uso: fail('mensaje'); exit; */
function fail($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg]);
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
