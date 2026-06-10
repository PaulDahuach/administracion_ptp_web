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
        case 'lookup':       lookup($def); break;
        case 'save':         guardar($def); break;
        case 'delete':       borrar($def); break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

// ─────────────────────────── helpers ───────────────────────────
function opciones($lk) {
    $w = isset($lk['where']) ? ' WHERE ' . $lk['where'] : '';   // scope opcional, ej. "CODORI='D'"
    return db_query("SELECT [{$lk['pk']}] AS id, [{$lk['den']}] AS den FROM [{$lk['tabla']}]$w ORDER BY [{$lk['den']}];");
}

/** Literal SQL para un valor según tipo. Setea $err si falta un requerido. */
function val_sql($c, $raw, &$err) {
    $tipo = $c['tipo'];
    if ($tipo === 'bool') {
        return (!empty($raw) && $raw !== '0' && $raw !== 'false') ? 'True' : 'False';
    }
    $v = is_string($raw) ? trim($raw) : $raw;
    $vacio = ($v === '' || $v === null);
    if ($vacio && isset($c['default'])) { $v = $c['default']; $vacio = false; }   // valor por defecto si viene vacío
    if (!empty($c['req']) && $vacio) { $err = "Falta: {$c['label']}"; return null; }
    if ($vacio) return 'Null';
    if ($tipo === 'select') return !empty($c['strkey']) ? "'" . db_esc($v) . "'" : (string) intval($v);   // strkey: FK con clave texto (ej. cuenta contable "11101")
    if ($tipo === 'number' || $tipo === 'decimal') {
        $num = ($tipo === 'decimal') ? (float) str_replace(',', '.', $v) : intval($v);
        if (isset($c['min']) && $num < $c['min']) { $err = "{$c['label']}: el mínimo es {$c['min']}"; return null; }
        if (isset($c['max']) && $num > $c['max']) { $err = "{$c['label']}: el máximo es {$c['max']}"; return null; }
        return (string) $num;
    }
    if ($tipo === 'date') {
        $iso = to_iso_date($v);                 // 'YYYY-mm-dd'
        if ($iso === '') return 'Null';
        $p = explode('-', $iso);
        return "#{$p[1]}/{$p[2]}/{$p[0]}#";       // #mm/dd/YYYY# para Access
    }
    if (!empty($c['cuit'])) {
        $dig = preg_replace('/[^0-9]/', '', $v);
        if (strlen($dig) !== 11 || !cuit_valido($dig)) { $err = "{$c['label']}: C.U.I.T. inválido"; return null; }
        $v = substr($dig, 0, 2) . '-' . substr($dig, 2, 8) . '-' . substr($dig, 10, 1);
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

/** Literal SQL de un valor 'fijo' (discriminador): entero o texto entre comillas. */
function fijo_lit($v) {
    if (is_int($v) || (is_string($v) && ctype_digit($v))) return (string) intval($v);
    return "'" . db_esc($v) . "'";
}

/** Condición SQL de las columnas 'fijo' (scope del maestro), ej. M.[CODORI]='D'. '' si no hay. */
function fijo_where($def, $alias) {
    if (empty($def['fijo'])) return '';
    $p = ($alias !== '' ? $alias . '.' : '');
    $conds = [];
    foreach ($def['fijo'] as $col => $val) $conds[] = $p . "[$col]=" . fijo_lit($val);
    return implode(' AND ', $conds);
}

/** Busca la def de un campo por su columna (para conocer tipo/label). */
function campo_def($def, $col) {
    foreach ($def['campos'] as $c) if ($c['col'] === $col) return $c;
    foreach (((isset($def['hijos']) ? $def['hijos'] : [])) as $h)   // también campos de hijos (para lookup big en grilla)
        foreach ($h['campos'] as $c) if ($c['col'] === $col) return $c;
    return ['col' => $col, 'tipo' => 'text', 'label' => $col];
}

/** Valida 'unico': que no exista otro registro con el mismo valor (global, como el legacy). */
function check_unico($def, $id) {
    if (empty($def['unico'])) return null;
    $pk = $def['pk'];
    $fw = fijo_where($def, '');
    $scope = ($fw !== '') ? " AND $fw" : '';   // unicidad dentro del scope (ej. solo deudores)
    foreach ($def['unico'] as $u) {
        // entrada: 'COL'  o  ['col'=>'COL','except'=>'valor que puede repetirse', ej. CUIT dummy]
        $col = is_array($u) ? $u['col'] : $u;
        $campo = campo_def($def, $col);
        $err = null;
        $lit = val_sql($campo, (isset($_POST[$col]) ? $_POST[$col] : ''), $err);
        if ($lit === null || $lit === 'Null') continue;   // vacío → no chequear
        if (is_array($u) && isset($u['except']) && trim($lit, "'") === $u['except']) continue;
        $excl = ($id !== '') ? " AND [$pk]<>" . pk_lit($def, $id) : '';
        $r = db_row("SELECT [$pk] AS k FROM [{$def['tabla']}] WHERE [$col]=$lit$excl$scope;");
        if ($r) { $lbl = isset($campo['label']) ? $campo['label'] : $col; return "Ya existe un registro con esa $lbl."; }
    }
    return null;
}

/** Valida 'tope': cantidad máxima de registros dentro del scope 'fijo' (solo al dar de alta). */
function check_tope($def) {
    if (empty($def['tope'])) return null;
    $w = fijo_where($def, '');
    $where = ($w !== '') ? " WHERE $w" : '';
    $r = db_row("SELECT COUNT(*) AS n FROM [{$def['tabla']}]$where;");
    $n = $r ? intval($r['n']) : 0;
    if ($n >= intval($def['tope'])) return "Cantidad máxima permitida: " . intval($def['tope']);
    return null;
}

/** Autocomplete server-side de un lookup 'big': busca por subcadena en su(s) columna(s). */
function lookup($def) {
    $col = (isset($_GET['col']) ? $_GET['col'] : '');
    $q = trim((isset($_GET['q']) ? $_GET['q'] : ''));
    $campo = campo_def($def, $col);
    if (empty($campo['lookup'])) { fail('Campo lookup inválido: ' . $col); return; }
    if (strlen($q) < 2) { ok([]); return; }
    $lk = $campo['lookup'];
    $busca = (isset($campo['search']) ? $campo['search'] : [$lk['den']]);
    $s = db_esc($q);
    $conds = [];
    foreach ($busca as $sc) $conds[] = "[$sc] Like '%$s%'";
    $sel = "[{$lk['pk']}] AS id, [{$lk['den']}] AS den";
    if (isset($lk['cod'])) $sel .= ", [{$lk['cod']}] AS cod";
    $extra = isset($lk['where']) ? ' AND (' . $lk['where'] . ')' : '';   // scope opcional (ej. solo imputables)
    ok(db_query("SELECT TOP 30 $sel FROM [{$lk['tabla']}] WHERE (" . implode(' OR ', $conds) . ")$extra ORDER BY [{$lk['den']}];"));
}

/** Validación de C.U.I.T. (11 dígitos + dígito verificador módulo 11). $s = solo dígitos. */
function cuit_valido($s) {
    if (strlen($s) !== 11) return false;
    $mult = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) $sum += intval($s[$i]) * $mult[$i];
    $ver = 11 - ($sum % 11);
    if ($ver === 11) $ver = 0;
    elseif ($ver === 10) $ver = 9;
    return $ver === intval($s[10]);
}

/** Literal de un default de alta ('alta' del def): valor fijo o ['rec'=>'COL'] desde Rec Control. */
function alta_lit($spec) {
    if (is_array($spec) && isset($spec['rec'])) {
        $r = db_row("SELECT [{$spec['rec']}] AS v FROM [Rec Control];");
        $v = $r ? $r['v'] : null;
        if ($v === null || $v === '') return 'Null';
        if (isset($spec['tipo']) && $spec['tipo'] === 'date') {
            $iso = to_iso_date($v);
            if ($iso === '') return 'Null';
            $p = explode('-', $iso);
            return "#{$p[1]}/{$p[2]}/{$p[0]}#";
        }
        return is_numeric($v) ? (string) $v : "'" . db_esc($v) . "'";
    }
    return is_numeric($spec) ? (string) $spec : "'" . db_esc($spec) . "'";
}

// ─────────────────────────── maestro ───────────────────────────
function defs($def) {
    $out = ['titulo' => $def['titulo'], 'pk' => $def['pk'], 'buscable' => !empty($def['buscable']), 'strpk' => !empty($def['strpk']), 'codlabel' => (isset($def['codlabel']) ? $def['codlabel'] : 'Código'), 'cols' => (isset($def['cols']) ? (int) $def['cols'] : 0), 'campos' => [], 'hijos' => []];
    foreach ($def['campos'] as $c) {
        // 'big' (ej. Localidades, 19k filas) → NO precargar opciones; el JS usa autocomplete server-side.
        if ($c['tipo'] === 'select' && isset($c['lookup']) && empty($c['big'])) $c['options'] = opciones($c['lookup']);
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
            // 'big' (ej. cuentas contables, 441) → NO precargar opciones; autocomplete server-side en la grilla
            if ($c['tipo'] === 'select' && isset($c['lookup']) && empty($c['big'])) $c['options'] = opciones($c['lookup']);
            $campos[] = $c;
        }
        $out['hijos'][] = ['key' => $h['key'], 'titulo' => $h['titulo'], 'clave' => $clave, 'campos' => $campos];
    }
    ok($out);
}

function listar($def) {
    $pk = $def['pk'];
    $sel = ["M.[$pk] AS [$pk]"]; $joins = []; $i = 0;
    $dateCols = [];
    foreach ($def['campos'] as $c) {
        if (empty($c['list'])) continue;
        if ($c['tipo'] === 'select' && isset($c['lookup'])) {
            $a = 'j' . ($i++); $lk = $c['lookup'];
            $joins[] = "LEFT JOIN [{$lk['tabla']}] AS $a ON M.[{$c['col']}] = $a.[{$lk['pk']}]";
            $sel[] = "$a.[{$lk['den']}] AS [{$c['col']}]";
        } else {
            $sel[] = "M.[{$c['col']}] AS [{$c['col']}]";
            if ($c['tipo'] === 'date') $dateCols[] = $c;
        }
    }
    // ACE exige paréntesis anidados con 2+ LEFT JOIN: ((M LEFT JOIN j0..) LEFT JOIN j1..)
    $from = "[{$def['tabla']}] AS M";
    foreach ($joins as $j) $from = "($from $j)";
    $orden = (isset($def['orden']) ? $def['orden'] : $pk);
    $conds = [];
    $w = fijo_where($def, 'M'); if ($w !== '') $conds[] = $w;
    // 'buscable': maestros grandes (ej. Localidades 19k) → filtra server-side, TOP acotado, NO precarga todo
    $top = '';
    if (!empty($def['buscable'])) {
        $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
        if ($q !== '') {
            $top = 'TOP 200 ';
            $qs = db_esc($q); $oc = [];
            foreach ($def['campos'] as $c) {
                if (empty($c['list']) || $c['tipo'] === 'select' || $c['tipo'] === 'bool' || $c['tipo'] === 'date') continue;
                $oc[] = "M.[{$c['col']}] Like '%$qs%'";
            }
            if (is_numeric($q)) $oc[] = "M.[$pk] = " . intval($q);
            if ($oc) $conds[] = '(' . implode(' OR ', $oc) . ')';
        } else {
            $top = 'TOP 50 ';   // sin término: sólo las primeras 50 (evita bajar 19k)
        }
    }
    $where = count($conds) ? ' WHERE ' . implode(' AND ', $conds) : '';
    $rows = db_query("SELECT $top" . implode(', ', $sel) . " FROM $from$where ORDER BY M.[$orden];");
    if ($dateCols) foreach ($rows as &$r) conv_fechas($r, $dateCols, 'disp');
    ok($rows);
}

/** Literal de PK: entero (auto) o string entre comillas (strpk: código manual ej. Holistor "V01"). */
function pk_lit($def, $raw) { return !empty($def['strpk']) ? "'" . db_esc(trim((string) $raw)) . "'" : (string) intval($raw); }

function obtener($def) {
    $idSql = pk_lit($def, isset($_GET['id']) ? $_GET['id'] : '');
    $w = fijo_where($def, '');
    $scope = ($w !== '') ? " AND $w" : '';
    $row = db_row("SELECT * FROM [{$def['tabla']}] WHERE [{$def['pk']}] = $idSql$scope;");
    if (!$row) { fail('Registro no encontrado'); return; }
    conv_fechas($row, $def['campos'], 'iso');
    // Denominación de los lookups 'big' (para mostrar en el input de autocomplete al cargar).
    foreach ($def['campos'] as $c) {
        if ($c['tipo'] === 'select' && !empty($c['big']) && isset($c['lookup'])
            && isset($row[$c['col']]) && $row[$c['col']] !== null && $row[$c['col']] !== '') {
            $lk = $c['lookup'];
            $fid = !empty($c['strkey']) ? "'" . db_esc($row[$c['col']]) . "'" : intval($row[$c['col']]);
            $d = db_row("SELECT [{$lk['den']}] AS den FROM [{$lk['tabla']}] WHERE [{$lk['pk']}] = $fid;");
            $row[$c['col'] . '__den'] = $d ? $d['den'] : '';
        }
    }
    // Campos read-only: formatear para mostrar (fechas dd/mm/aaaa, decimales es-AR).
    foreach ($def['campos'] as $c) {
        if (empty($c['ro']) || !array_key_exists($c['col'], $row)) continue;
        if ($c['tipo'] === 'date') $row[$c['col']] = to_disp_date($row[$c['col']]);
        elseif ($c['tipo'] === 'decimal') $row[$c['col']] = number_format((float) $row[$c['col']], 2, '.', ',');  // convención app: punto decimal, coma miles
    }
    $row['__hijos'] = [];
    foreach (((isset($def['hijos']) ? $def['hijos'] : [])) as $h) $row['__hijos'][$h['key']] = childRows($h, $idSql);
    ok($row);
}

/** Filas de un hijo: valor crudo por campo + nombre (_den) para los selects. */
function childRows($h, $pid) {
    $fk = $h['fk']; $clave = $h['clave']; $kc = $clave['col'];
    $sel = ["C.[$kc] AS __key"]; $joins = []; $i = 0; $orderBy = "C.[$kc]";
    if ($clave['tipo'] === 'select') {
        $lk = $clave['lookup'];
        $joins[] = "LEFT JOIN [{$lk['tabla']}] AS k ON C.[$kc] = k.[{$lk['pk']}]";
        $sel[] = "k.[{$lk['den']}] AS __keyden";
        $orderBy = "k.[{$lk['den']}]";
    }
    $dateCols = [];
    foreach ($h['campos'] as $c) {
        $sel[] = "C.[{$c['col']}] AS [{$c['col']}]";
        if ($c['tipo'] === 'select' && isset($c['lookup'])) {
            $a = 'h' . ($i++); $lk = $c['lookup'];
            $joins[] = "LEFT JOIN [{$lk['tabla']}] AS $a ON C.[{$c['col']}] = $a.[{$lk['pk']}]";
            $sel[] = "$a.[{$lk['den']}] AS [{$c['col']}__den]";
        } elseif ($c['tipo'] === 'date') { $dateCols[] = $c; }
    }
    // ACE exige paréntesis anidados con 2+ LEFT JOIN
    $from = "[{$h['tabla']}] AS C";
    foreach ($joins as $j) $from = "($from $j)";
    $rows = db_query("SELECT " . implode(', ', $sel) . " FROM $from WHERE C.[$fk] = $pid ORDER BY $orderBy;");
    if ($dateCols) foreach ($rows as &$r) conv_fechas($r, $dateCols, 'iso');
    return $rows;
}

function guardar($def) {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $pk = $def['pk'];
    $id = trim((isset($_POST['__id']) ? $_POST['__id'] : ''));
    $cols = []; $vals = [];
    foreach ($def['campos'] as $c) {
        if (!empty($c['ro'])) continue;   // read-only: no se graba (saldos, fechas calculadas)
        $err = null;
        $lit = val_sql($c, (isset($_POST[$c['col']]) ? $_POST[$c['col']] : ''), $err);
        if ($err) { fail($err); return; }
        $cols[] = $c['col']; $vals[] = $lit;
    }
    $ue = check_unico($def, $id);
    if ($ue) { fail($ue); return; }
    if ($id === '') {
        $te = check_tope($def);
        if ($te) { fail($te); return; }
        if (!empty($def['strpk'])) {   // PK = código manual (ej. Holistor "V01")
            $code = trim((isset($_POST['__newpk']) ? $_POST['__newpk'] : ''));
            if ($code === '') { fail('Falta el código'); return; }
            if (db_row("SELECT [$pk] FROM [{$def['tabla']}] WHERE [$pk]='" . db_esc($code) . "';")) { fail('Ya existe un registro con ese código'); return; }
            $pid = $code; $pkLit = "'" . db_esc($code) . "'";
        } else {
            $pid = next_number($def['ult']); $pkLit = (string) $pid;
        }
        $fcols = []; $fvals = [];
        if (!empty($def['fijo'])) foreach ($def['fijo'] as $fc => $fv) { $fcols[] = $fc; $fvals[] = fijo_lit($fv); }
        if (!empty($def['alta'])) foreach ($def['alta'] as $ac => $as) { $fcols[] = $ac; $fvals[] = alta_lit($as); }
        $allCols = array_merge([$pk], $fcols, $cols);
        $allVals = array_merge([$pkLit], $fvals, $vals);
        db_exec("INSERT INTO [{$def['tabla']}] ([" . implode('],[', $allCols) . "]) VALUES (" . implode(',', $allVals) . ");");
        $nuevo = true;
    } else {
        $pid = !empty($def['strpk']) ? $id : intval($id);
        $sets = [];
        foreach ($cols as $k => $col) $sets[] = "[$col]={$vals[$k]}";
        db_exec("UPDATE [{$def['tabla']}] SET " . implode(',', $sets) . " WHERE [$pk]=" . pk_lit($def, $id) . ";");
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
        // 'entity': sub-entidad con PK propia (ej. Subrubros CODSUB) referenciada por otras tablas →
        // sync por upsert (NO borrar-reinsertar, que rompería las FKs); baja bloqueada por 'uso'.
        if ($h['clave']['tipo'] === 'entity') { guardarHijosEntity($h, $pid, $rows); continue; }
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

/** Hijo 'entity': sub-entidad con PK propia auto-numerada (clave.ult). Upsert por __key + borra los
 *  que ya no están (bloqueando si están en uso, como el Form_Delete del subform legacy). */
function guardarHijosEntity($h, $pid, $rows) {
    $fk = $h['fk']; $kc = $h['clave']['col']; $tabla = $h['tabla']; $ult = $h['clave']['ult'];
    $exist = [];
    foreach (db_query("SELECT [$kc] AS k FROM [$tabla] WHERE [$fk]=$pid;") as $x) $exist[(int) $x['k']] = true;
    $post = [];
    foreach ($rows as $r) {
        $cols = []; $vals = [];
        foreach ($h['campos'] as $c) {
            $err = null; $lit = val_sql($c, (isset($r[$c['col']]) ? $r[$c['col']] : ''), $err);
            if ($err) $lit = 'Null';
            $cols[] = $c['col']; $vals[] = $lit;
        }
        $key = intval(isset($r['__key']) ? $r['__key'] : 0);
        if ($key > 0) {   // existente → UPDATE (preserva el CODSUB que referencian los Productos)
            $post[$key] = true;
            $sets = []; foreach ($cols as $i => $col) $sets[] = "[$col]={$vals[$i]}";
            if ($sets) db_exec("UPDATE [$tabla] SET " . implode(',', $sets) . " WHERE [$kc]=$key;");
        } else {          // nuevo → INSERT con número propio
            $nk = next_number($ult);
            $allCols = array_merge([$fk, $kc], $cols); $allVals = array_merge([(string) $pid, (string) $nk], $vals);
            db_exec("INSERT INTO [$tabla] ([" . implode('],[', $allCols) . "]) VALUES (" . implode(',', $allVals) . ");");
        }
    }
    // borrar los quitados (bloquea si están en uso)
    foreach ($exist as $k => $_) {
        if (isset($post[$k])) continue;
        foreach (((isset($h['uso']) ? $h['uso'] : [])) as $u) {
            if (db_row("SELECT TOP 1 [{$u['col']}] AS x FROM [{$u['tabla']}] WHERE [{$u['col']}]=$k;"))
                throw new Exception(isset($u['msg']) ? $u['msg'] : 'No se puede quitar un sub-registro en uso.');
        }
        db_exec("DELETE FROM [$tabla] WHERE [$kc]=$k;");
    }
}

function borrar($def) {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $idRaw = (isset($_POST['__id']) ? $_POST['__id'] : (isset($_GET['id']) ? $_GET['id'] : ''));
    if (trim((string) $idRaw) === '' || (empty($def['strpk']) && intval($idRaw) <= 0)) { fail('Falta id'); return; }
    $idSql = pk_lit($def, $idRaw);
    // Chequeo de uso (como DelData legacy): bloquear si el registro está referenciado.
    foreach (((isset($def['uso']) ? $def['uso'] : [])) as $u) {
        $r = db_row("SELECT TOP 1 [{$u['col']}] AS k FROM [{$u['tabla']}] WHERE [{$u['col']}] = $idSql;");
        if ($r) { fail(isset($u['msg']) ? $u['msg'] : 'No se puede eliminar: el registro está en uso.', 409); return; }
    }
    // Las sub-tablas son propiedad del padre: borrarlas primero.
    foreach (((isset($def['hijos']) ? $def['hijos'] : [])) as $h) {
        try { db_exec("DELETE FROM [{$h['tabla']}] WHERE [{$h['fk']}] = $idSql;"); } catch (Exception $e) {}
    }
    try {
        db_exec("DELETE FROM [{$def['tabla']}] WHERE [{$def['pk']}] = $idSql;");
        ok(true);
    } catch (Exception $e) {
        fail('No se puede eliminar: el registro está en uso por otros datos.', 409);
    }
}
