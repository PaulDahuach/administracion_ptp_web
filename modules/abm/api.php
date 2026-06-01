<?php
/**
 * ABM genérico de maestros (CRUD) + sub-tablas (hijos). Param ?m= (ver defs.php).
 *   defs / list / get / save / delete  → maestro
 *   child_list / child_save / child_delete → filas de un hijo
 * Hijos: 'clave' tipo 'auto' (línea ORDxxx autonum) o 'select' (relación a FK).
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$DEFS = require __DIR__ . '/defs.php';
$action = (isset($_GET['action']) ? $_GET['action'] : '');
$m = (isset($_GET['m']) ? $_GET['m'] : '');
$def = (isset($DEFS[$m]) ? $DEFS[$m] : null);
if (!$def) { fail('Maestro inválido: ' . $m); exit; }

try {
    switch ($action) {
        case 'defs':         defs($def); break;
        case 'list':         listar($def); break;
        case 'get':          obtener($def); break;
        case 'save':         guardar($def); break;
        case 'delete':       borrar($def); break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

// ─────────────────────────── helpers ───────────────────────────
function opciones($lk) {
    return db_query("SELECT [{$lk['pk']}] AS id, [{$lk['den']}] AS den FROM [{$lk['tabla']}] ORDER BY [{$lk['den']}];");
}

/** Literal SQL para un valor según tipo. Setea $err si falta un requerido. */
function val_sql($c, $raw, &$err) {
    $tipo = $c['tipo'];
    if ($tipo === 'bool') {
        return (!empty($raw) && $raw !== '0' && $raw !== 'false') ? 'True' : 'False';
    }
    $v = is_string($raw) ? trim($raw) : $raw;
    $vacio = ($v === '' || $v === null);
    if (!empty($c['req']) && $vacio) { $err = "Falta: {$c['label']}"; return null; }
    if ($vacio) return 'Null';
    if ($tipo === 'number' || $tipo === 'select') return (string) intval($v);
    if ($tipo === 'decimal') return (string) (float) str_replace(',', '.', $v);
    if ($tipo === 'date') {
        $iso = to_iso_date($v);                 // 'YYYY-mm-dd'
        if ($iso === '') return 'Null';
        $p = explode('-', $iso);
        return "#{$p[1]}/{$p[2]}/{$p[0]}#";       // #mm/dd/YYYY# para Access
    }
    return "'" . db_esc($v) . "'";  // text / memo
}

/** Convierte campos date de una fila a formato dado ('iso'|'disp'). */
function conv_fechas(&$row, $campos, $fmt) {
    foreach ($campos as $c) {
        if ($c['tipo'] === 'date' && array_key_exists($c['col'], $row)) {
            $row[$c['col']] = ($fmt === 'iso') ? to_iso_date($row[$c['col']]) : to_disp_date($row[$c['col']]);
        }
    }
}

function buscarHijo($def, $key) {
    foreach (((isset($def['hijos']) ? $def['hijos'] : [])) as $h) if ($h['key'] === $key) return $h;
    return null;
}

// ─────────────────────────── maestro ───────────────────────────
function defs($def) {
    $out = ['titulo' => $def['titulo'], 'pk' => $def['pk'], 'campos' => [], 'hijos' => []];
    foreach ($def['campos'] as $c) {
        if ($c['tipo'] === 'select' && isset($c['lookup'])) $c['options'] = opciones($c['lookup']);
        $out['campos'][] = $c;
    }
    foreach (((isset($def['hijos']) ? $def['hijos'] : [])) as $h) {
        $clave = ['tipo' => $h['clave']['tipo'], 'col' => $h['clave']['col']];
        if ($h['clave']['tipo'] === 'select') {
            $clave['label'] = $h['clave']['label'];
            $clave['options'] = opciones($h['clave']['lookup']);
        }
        $campos = [];
        foreach ($h['campos'] as $c) {
            if ($c['tipo'] === 'select' && isset($c['lookup'])) $c['options'] = opciones($c['lookup']);
            $campos[] = $c;
        }
        $out['hijos'][] = ['key' => $h['key'], 'titulo' => $h['titulo'], 'clave' => $clave, 'campos' => $campos];
    }
    ok($out);
}

function listar($def) {
    $pk = $def['pk'];
    $sel = ["M.[$pk] AS [$pk]"]; $joins = ''; $i = 0;
    $dateCols = [];
    foreach ($def['campos'] as $c) {
        if (empty($c['list'])) continue;
        if ($c['tipo'] === 'select' && isset($c['lookup'])) {
            $a = 'j' . ($i++); $lk = $c['lookup'];
            $joins .= " LEFT JOIN [{$lk['tabla']}] AS $a ON M.[{$c['col']}] = $a.[{$lk['pk']}]";
            $sel[] = "$a.[{$lk['den']}] AS [{$c['col']}]";
        } else {
            $sel[] = "M.[{$c['col']}] AS [{$c['col']}]";
            if ($c['tipo'] === 'date') $dateCols[] = $c;
        }
    }
    $orden = (isset($def['orden']) ? $def['orden'] : $pk);
    $rows = db_query("SELECT " . implode(', ', $sel) . " FROM [{$def['tabla']}] AS M$joins ORDER BY M.[$orden];");
    if ($dateCols) foreach ($rows as &$r) conv_fechas($r, $dateCols, 'disp');
    ok($rows);
}

function obtener($def) {
    $id = intval((isset($_GET['id']) ? $_GET['id'] : 0));
    $row = db_row("SELECT * FROM [{$def['tabla']}] WHERE [{$def['pk']}] = $id;");
    if (!$row) { fail('Registro no encontrado'); return; }
    conv_fechas($row, $def['campos'], 'iso');
    $row['__hijos'] = [];
    foreach (((isset($def['hijos']) ? $def['hijos'] : [])) as $h) $row['__hijos'][$h['key']] = childRows($h, $id);
    ok($row);
}

/** Filas de un hijo: valor crudo por campo + nombre (_den) para los selects. */
function childRows($h, $pid) {
    $fk = $h['fk']; $clave = $h['clave']; $kc = $clave['col'];
    $sel = ["C.[$kc] AS __key"]; $joins = ''; $i = 0; $orderBy = "C.[$kc]";
    if ($clave['tipo'] === 'select') {
        $lk = $clave['lookup'];
        $joins .= " LEFT JOIN [{$lk['tabla']}] AS k ON C.[$kc] = k.[{$lk['pk']}]";
        $sel[] = "k.[{$lk['den']}] AS __keyden";
        $orderBy = "k.[{$lk['den']}]";
    }
    $dateCols = [];
    foreach ($h['campos'] as $c) {
        $sel[] = "C.[{$c['col']}] AS [{$c['col']}]";
        if ($c['tipo'] === 'select' && isset($c['lookup'])) {
            $a = 'h' . ($i++); $lk = $c['lookup'];
            $joins .= " LEFT JOIN [{$lk['tabla']}] AS $a ON C.[{$c['col']}] = $a.[{$lk['pk']}]";
            $sel[] = "$a.[{$lk['den']}] AS [{$c['col']}__den]";
        } elseif ($c['tipo'] === 'date') { $dateCols[] = $c; }
    }
    $rows = db_query("SELECT " . implode(', ', $sel) . " FROM [{$h['tabla']}] AS C$joins WHERE C.[$fk] = $pid ORDER BY $orderBy;");
    if ($dateCols) foreach ($rows as &$r) conv_fechas($r, $dateCols, 'iso');
    return $rows;
}

function guardar($def) {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $pk = $def['pk'];
    $id = trim((isset($_POST['__id']) ? $_POST['__id'] : ''));
    $cols = []; $vals = [];
    foreach ($def['campos'] as $c) {
        $err = null;
        $lit = val_sql($c, (isset($_POST[$c['col']]) ? $_POST[$c['col']] : ''), $err);
        if ($err) { fail($err); return; }
        $cols[] = $c['col']; $vals[] = $lit;
    }
    if ($id === '') {
        $pid = next_number($def['ult']);
        $allCols = array_merge([$pk], $cols);
        $allVals = array_merge([(string) $pid], $vals);
        db_exec("INSERT INTO [{$def['tabla']}] ([" . implode('],[', $allCols) . "]) VALUES (" . implode(',', $allVals) . ");");
        $nuevo = true;
    } else {
        $pid = intval($id);
        $sets = [];
        foreach ($cols as $k => $col) $sets[] = "[$col]={$vals[$k]}";
        db_exec("UPDATE [{$def['tabla']}] SET " . implode(',', $sets) . " WHERE [$pk]=$pid;");
        $nuevo = false;
    }
    guardarHijos($def, $pid);
    ok(['id' => $pid, 'nuevo' => $nuevo]);
}

/** Reemplaza las filas hijas enviadas (delete-all + reinsert por padre). */
function guardarHijos($def, $pid) {
    $raw = (isset($_POST['__hijos']) ? $_POST['__hijos'] : '');
    if ($raw === '') return;
    $hijos = json_decode($raw, true);
    if (!is_array($hijos)) return;
    foreach (((isset($def['hijos']) ? $def['hijos'] : [])) as $h) {
        if (!array_key_exists($h['key'], $hijos)) continue;   // hijo no enviado → no tocar
        $rows = is_array($hijos[$h['key']]) ? $hijos[$h['key']] : [];
        $fk = $h['fk']; $kc = $h['clave']['col']; $tabla = $h['tabla'];
        db_exec("DELETE FROM [$tabla] WHERE [$fk]=$pid;");
        $line = 0; $seen = [];
        foreach ($rows as $r) {
            if ($h['clave']['tipo'] === 'auto') { $keyVal = ++$line; }
            else {
                $keyVal = intval((isset($r[$kc]) ? $r[$kc] : 0));
                if ($keyVal <= 0 || in_array($keyVal, $seen, true)) continue;
                $seen[] = $keyVal;
            }
            $cols = [$fk, $kc]; $vals = [(string) $pid, (string) $keyVal];
            foreach ($h['campos'] as $c) {
                $err = null;
                $lit = val_sql($c, (isset($r[$c['col']]) ? $r[$c['col']] : ''), $err);
                if ($err) $lit = 'Null';
                $cols[] = $c['col']; $vals[] = $lit;
            }
            db_exec("INSERT INTO [$tabla] ([" . implode('],[', $cols) . "]) VALUES (" . implode(',', $vals) . ");");
        }
    }
}

function borrar($def) {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $id = intval((isset($_POST['__id']) ? $_POST['__id'] : (isset($_GET['id']) ? $_GET['id'] : 0)));
    if ($id <= 0) { fail('Falta id'); return; }
    // Las sub-tablas son propiedad del padre: borrarlas primero.
    foreach (((isset($def['hijos']) ? $def['hijos'] : [])) as $h) {
        try { db_exec("DELETE FROM [{$h['tabla']}] WHERE [{$h['fk']}] = $id;"); } catch (Exception $e) {}
    }
    try {
        db_exec("DELETE FROM [{$def['tabla']}] WHERE [{$def['pk']}] = $id;");
        ok(true);
    } catch (Exception $e) {
        fail('No se puede eliminar: el registro está en uso por otros datos.', 409);
    }
}
