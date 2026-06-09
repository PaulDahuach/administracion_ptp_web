<?php
/**
 * Cuentas Contables (Plan de Cuentas) — API CRUD. Porta Frm IC Cuentas Contables.
 * Maestro jerárquico de 5 niveles. CODCUE = código string de ancho fijo por nivel
 * (1/2/3/5/7 chars); los ancestros se materializan en CN1CUE..CN5CUE (prefijos).
 * Reglas legacy: código válido (sin dígito 0 por nivel, padre existente y NO imputable),
 * cuentas de sistema (SYSCUE) no se editan/borran, niveladoras (con hijos) o comprometidas
 * (usadas en operaciones/modelos/movimientos) no son imputables ni se borran. Saldos read-only.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
try {
    switch ($action) {
        case 'tree':   arbol();    break;
        case 'get':    obtener();  break;
        case 'save':   guardar();  break;
        case 'delete': borrar();   break;
        default: fail('Acción inválida: ' . $action);
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}

// ───────────────────────── helpers de nivelación ─────────────────────────
/** Largo del código → nivel (1,2,3,5,7 → 1..5). 0 si inválido. */
function niv_de_largo($len) {
    $m = array(1 => 1, 2 => 2, 3 => 3, 5 => 4, 7 => 5);
    return isset($m[$len]) ? $m[$len] : 0;
}
/** Largo del código del padre, según el largo propio (2→1, 3→2, 5→3, 7→5). 0 si es raíz. */
function largo_padre($len) {
    $m = array(2 => 1, 3 => 2, 5 => 3, 7 => 5);
    return isset($m[$len]) ? $m[$len] : 0;
}
/** Código → "1.1.1.01.01" (NivCue del legacy). */
function nivcue($s) {
    $s = (string) $s; $n = strlen($s); $o = substr($s, 0, 1);
    if ($n > 1) $o .= '.' . substr($s, 1, 1);
    if ($n > 2) $o .= '.' . substr($s, 2, 1);
    if ($n > 3) $o .= '.' . substr($s, 3, 2);
    if ($n > 5) $o .= '.' . substr($s, 5, 2);
    return $o;
}
/** Ancestros materializados [CN1..CN5] según el código. */
function ancestros($s) {
    $n = strlen($s);
    return array(
        'CN1CUE' => substr($s, 0, 1),
        'CN2CUE' => $n > 1 ? substr($s, 0, 2) : null,
        'CN3CUE' => $n > 2 ? substr($s, 0, 3) : null,
        'CN4CUE' => $n > 3 ? substr($s, 0, 5) : null,
        'CN5CUE' => $n > 5 ? $s : null,
    );
}
function es_true($v) { return $v === true || $v === -1 || $v === '-1' || $v === 1 || $v === '1'; }
function bool_sql($raw) { return (!empty($raw) && $raw !== '0' && $raw !== 'false') ? 'True' : 'False'; }

/** ¿La cuenta es niveladora (tiene hijos que la referencian como ancestro)? */
function tiene_hijos($cod) {
    $e = db_esc($cod);
    $r = db_row("SELECT TOP 1 CODCUE FROM [Tbl Cuentas Contables]
        WHERE ([CN1CUE]='$e' OR [CN2CUE]='$e' OR [CN3CUE]='$e' OR [CN4CUE]='$e') AND [CODCUE]<>'$e';");
    return $r ? true : false;
}
/** ¿Está comprometida en operaciones/modelos/movimientos? */
function esta_usada($cod) {
    $e = db_esc($cod);
    foreach (array('Tbl Operaciones Auxiliares', 'Tbl Modelos Imputaciones', 'Tbl Movimientos Imputaciones') as $t) {
        if (db_row("SELECT TOP 1 CODCUE FROM [$t] WHERE [CODCUE]='$e';")) return true;
    }
    return false;
}

// ───────────────────────── acciones ─────────────────────────
function arbol() {
    $rows = db_query("SELECT CODCUE, CN1CUE, CN2CUE, CN3CUE, CN4CUE, CN5CUE, DENCUE, IMPCUE
        FROM [Tbl Cuentas Contables] ORDER BY CODCUE;");
    $out = array();
    foreach ($rows as $r) {
        $cod = trim((string) $r['CODCUE']);
        $niv = 0;
        foreach (array('CN1CUE','CN2CUE','CN3CUE','CN4CUE','CN5CUE') as $c)
            if (trim((string) nz($r[$c], '')) !== '') $niv++;
        $out[] = array(
            'cod'   => $cod,
            'niv'   => $niv,
            'den'   => trim((string) nz($r['DENCUE'], '')),
            'nivcue' => nivcue($cod),
            'imp'   => es_true($r['IMPCUE']) ? 1 : 0,
        );
    }
    ok($out);
}

function obtener() {
    $cod = trim((isset($_GET['cod']) ? $_GET['cod'] : ''));
    $e = db_esc($cod);
    $r = db_row("SELECT * FROM [Tbl Cuentas Contables] WHERE [CODCUE]='$e';");
    if (!$r) { fail('Cuenta no encontrada'); return; }
    $sys = es_true($r['SYSCUE']);
    $hijos = tiene_hijos($cod);
    $usada = esta_usada($cod);
    $deb = (float) nz($r['DEBCUE'], 0); $cre = (float) nz($r['CRECUE'], 0);
    ok(array(
        'cod'    => $cod,
        'nivcue' => nivcue($cod),
        'niv'    => niv_de_largo(strlen($cod)),
        'den'    => trim((string) nz($r['DENCUE'], '')),
        'imp'    => es_true($r['IMPCUE']) ? 1 : 0,
        'codcbx' => nz($r['CODCBX'], ''),
        'ccc'    => es_true($r['CCCCUE']) ? 1 : 0,
        'dec'    => es_true($r['DECCUE']) ? 1 : 0,
        'con'    => es_true($r['CONCUE']) ? 1 : 0,
        'gas'    => es_true($r['GASCUE']) ? 1 : 0,
        'gex'    => es_true($r['GEXCUE']) ? 1 : 0,
        'dis'    => es_true($r['DISCUE']) ? 1 : 0,
        'codhol' => trim((string) nz($r['CODHOL'], '')),
        'sys'    => $sys ? 1 : 0,
        'hijos'  => $hijos ? 1 : 0,
        'usada'  => $usada ? 1 : 0,
        // bloqueos para el front
        'lock_imp' => ($sys || $hijos || $usada) ? 1 : 0,   // imputable no editable
        'lock_den' => $sys ? 1 : 0,                          // denominación no editable
        'lock_del' => ($sys || $hijos || $usada) ? 1 : 0,    // no se puede borrar
        // saldos read-only (formato app: punto decimal, coma miles)
        'debitos' => number_format($deb, 2, '.', ','),
        'creditos' => number_format($cre, 2, '.', ','),
        's_actual' => number_format($deb - $cre, 2, '.', ','),
        's_inicial' => number_format((float) nz($r['INICUE'], 0), 2, '.', ','),
        's_concil' => number_format((float) nz($r['SACCUE'], 0), 2, '.', ','),
    ));
}

function guardar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $nuevo = (isset($_POST['__nuevo']) && $_POST['__nuevo'] === '1');
    $den = trim((isset($_POST['den']) ? $_POST['den'] : ''));
    if ($den === '') { fail('Falta: Denominación'); return; }

    $codcbx = trim((isset($_POST['codcbx']) ? $_POST['codcbx'] : ''));
    $codhol = trim((isset($_POST['codhol']) ? $_POST['codhol'] : ''));
    $cbxSql = ($codcbx === '') ? 'Null' : (string) intval($codcbx);
    $holSql = ($codhol === '') ? 'Null' : "'" . db_esc($codhol) . "'";
    $sets = array(
        "[DENCUE]='" . db_esc($den) . "'",
        "[IMPCUE]=" . bool_sql(isset($_POST['imp']) ? $_POST['imp'] : ''),
        "[CODCBX]=$cbxSql",
        "[CCCCUE]=" . bool_sql(isset($_POST['ccc']) ? $_POST['ccc'] : ''),
        "[DECCUE]=" . bool_sql(isset($_POST['dec']) ? $_POST['dec'] : ''),
        "[CONCUE]=" . bool_sql(isset($_POST['con']) ? $_POST['con'] : ''),
        "[GASCUE]=" . bool_sql(isset($_POST['gas']) ? $_POST['gas'] : ''),
        "[GEXCUE]=" . bool_sql(isset($_POST['gex']) ? $_POST['gex'] : ''),
        "[DISCUE]=" . bool_sql(isset($_POST['dis']) ? $_POST['dis'] : ''),
        "[CODHOL]=$holSql",
    );

    if ($nuevo) {
        $cod = trim((isset($_POST['cod']) ? $_POST['cod'] : ''));
        $err = validar_codigo($cod);
        if ($err) { fail($err); return; }
        $a = ancestros($cod);
        $cols = array('CODCUE','CN1CUE','CN2CUE','CN3CUE','CN4CUE','CN5CUE','INICUE','DEBCUE','CRECUE','SACCUE','SYSCUE','NOGCUE');
        $vals = array("'" . db_esc($cod) . "'");
        foreach (array('CN1CUE','CN2CUE','CN3CUE','CN4CUE','CN5CUE') as $c)
            $vals[] = ($a[$c] === null) ? 'Null' : "'" . db_esc($a[$c]) . "'";
        $vals[] = '0'; $vals[] = '0'; $vals[] = '0'; $vals[] = '0'; $vals[] = 'False'; $vals[] = 'False';
        $allCols = array_merge($cols, array('DENCUE','IMPCUE','CODCBX','CCCCUE','DECCUE','CONCUE','GASCUE','GEXCUE','DISCUE','CODHOL'));
        $allVals = array_merge($vals, array(
            "'" . db_esc($den) . "'", bool_sql(isset($_POST['imp']) ? $_POST['imp'] : ''), $cbxSql,
            bool_sql(isset($_POST['ccc']) ? $_POST['ccc'] : ''), bool_sql(isset($_POST['dec']) ? $_POST['dec'] : ''),
            bool_sql(isset($_POST['con']) ? $_POST['con'] : ''), bool_sql(isset($_POST['gas']) ? $_POST['gas'] : ''),
            bool_sql(isset($_POST['gex']) ? $_POST['gex'] : ''), bool_sql(isset($_POST['dis']) ? $_POST['dis'] : ''), $holSql,
        ));
        db_exec("INSERT INTO [Tbl Cuentas Contables] ([" . implode('],[', $allCols) . "]) VALUES (" . implode(',', $allVals) . ");");
        ok(array('cod' => $cod, 'nuevo' => true));
    } else {
        $cod = trim((isset($_POST['cod']) ? $_POST['cod'] : ''));
        $e = db_esc($cod);
        $r = db_row("SELECT SYSCUE FROM [Tbl Cuentas Contables] WHERE [CODCUE]='$e';");
        if (!$r) { fail('Cuenta no encontrada'); return; }
        // Cuenta de sistema: no se cambia la denominación (sí flags operativos? legacy bloquea DENCUE e IMPCUE).
        if (es_true($r['SYSCUE'])) {
            // preservar DENCUE/IMPCUE: quitarlos de los sets
            $sets = array_values(array_filter($sets, function ($s) {
                return strpos($s, '[DENCUE]=') !== 0 && strpos($s, '[IMPCUE]=') !== 0;
            }));
        }
        db_exec("UPDATE [Tbl Cuentas Contables] SET " . implode(',', $sets) . " WHERE [CODCUE]='$e';");
        ok(array('cod' => $cod, 'nuevo' => false));
    }
}

/** Valida el código de una cuenta nueva (porta CODCUE_BeforeUpdate). null = OK. */
function validar_codigo($cod) {
    if ($cod === '') return 'Falta: Código';
    if (!ctype_digit($cod)) return 'El código debe ser numérico';
    $len = strlen($cod);
    if (niv_de_largo($len) === 0) return 'Nivelación inválida (largos válidos: 1, 2, 3, 5, 7 dígitos)';
    if ($cod[0] === '0') return 'Nivel 1 inválido (no puede empezar en 0)';
    if ($len > 1 && $cod[1] === '0') return 'Nivel 2 inválido';
    if ($len > 2 && $cod[2] === '0') return 'Nivel 3 inválido';
    if ($len > 3 && substr($cod, 3, 2) === '00') return 'Nivel 4 inválido';
    if ($len > 5 && substr($cod, 5, 2) === '00') return 'Nivel 5 inválido';
    if (db_row("SELECT CODCUE FROM [Tbl Cuentas Contables] WHERE [CODCUE]='" . db_esc($cod) . "';")) return 'Ya existe una cuenta con ese código';
    if ($len > 1) {
        $lp = largo_padre($len);
        $padre = substr($cod, 0, $lp);
        $p = db_row("SELECT IMPCUE FROM [Tbl Cuentas Contables] WHERE [CODCUE]='" . db_esc($padre) . "';");
        if (!$p) return 'El nivel padre ' . nivcue($padre) . ' no existe';
        if (es_true($p['IMPCUE'])) return 'El nivel padre ' . nivcue($padre) . ' es imputable (no puede tener cuentas hijas)';
    }
    return null;
}

function borrar() {
    if (db_readonly()) { fail('Sistema en modo solo-lectura', 403); return; }
    $cod = trim((isset($_POST['cod']) ? $_POST['cod'] : ''));
    $e = db_esc($cod);
    $r = db_row("SELECT SYSCUE FROM [Tbl Cuentas Contables] WHERE [CODCUE]='$e';");
    if (!$r) { fail('Cuenta no encontrada'); return; }
    if (es_true($r['SYSCUE'])) { fail('No se puede eliminar: es una cuenta de sistema.', 409); return; }
    if (tiene_hijos($cod)) { fail('No se puede eliminar: la cuenta es niveladora (tiene cuentas hijas).', 409); return; }
    if (esta_usada($cod)) { fail('No se puede eliminar: la cuenta está comprometida (usada en operaciones, modelos o imputaciones).', 409); return; }
    db_exec("DELETE FROM [Tbl Cuentas Contables] WHERE [CODCUE]='$e';");
    ok(true);
}
