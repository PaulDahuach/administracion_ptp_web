<?php
/**
 * inforemp-web-kit — Capa de acceso a datos (Access vía COM/ADODB).
 *
 * Centraliza la conexión que en RDN estaba hardcodeada en cada archivo.
 * Todo módulo hace:  require_once __DIR__ . '/../../includes/db.php';
 *
 * PHP 7.4 / Windows / driver Microsoft.ACE.OLEDB.12.0.
 */

/** Carga (una vez) la configuración del sistema. */
function sys($key = null, $default = null) {
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/system.php';
        if (!is_file($file)) {
            die('Falta config/system.php. Copiá config/system.example.php y completalo.');
        }
        $cfg = require $file;
    }
    if ($key === null) return $cfg;
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

/** True si el sistema está en modo solo-lectura. */
function db_readonly() {
    return sys('mode', 'readonly') === 'readonly';
}

/**
 * Construye una URL respetando 'base_url' de la config.
 * En prod (sistema en la raíz del host) base_url='' → rutas /app/...
 * En dev (localhost/<sistema>/) base_url='/<sistema>' → /<sistema>/app/...
 */
function bu($path = '') {
    // URLs absolutas (http://, https://, //) se devuelven sin tocar.
    if (preg_match('#^(https?:)?//#i', (string) $path)) return $path;
    $b = rtrim((string) sys('base_url', ''), '/');
    if ($path === '' || $path === '/') return $b !== '' ? $b . '/' : '/';
    return $b . '/' . ltrim($path, '/');
}

/** Conexión ADO singleton a la .mdb del sistema. */
function db_connect() {
    static $conn = null;
    if ($conn === null) {
        if (!class_exists('COM')) {
            throw new Exception('Extensión COM no disponible (¿corriendo en Windows con php_com_dotnet habilitado?).');
        }
        $conn = new COM('ADODB.Connection');
        $dsn = 'Provider=' . sys('mdb_provider', 'Microsoft.ACE.OLEDB.12.0')
             . ';Data Source=' . sys('mdb_path')
             . ';Persist Security Info=False;';
        $pass = sys('mdb_pass', '');
        if ($pass !== '') {
            $dsn .= 'Jet OLEDB:Database Password=' . $pass . ';';
        }
        $conn->Open($dsn);
    }
    return $conn;
}

/**
 * Normaliza un valor que viene de un campo ADO.
 * Access devuelve texto en Windows-1252 → lo pasamos a UTF-8.
 * Los numéricos/currency pueden venir como variant → floatval.
 */
function ado_val($raw) {
    if ($raw === null) return null;
    if (is_bool($raw)) return $raw;
    if (is_int($raw) || is_float($raw)) return $raw;
    if (is_object($raw)) return floatval($raw); // COM variant numérico
    $s = (string) $raw;
    if ($s === '') return '';
    if (mb_detect_encoding($s, 'UTF-8', true)) return $s;
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/**
 * Ejecuta un SELECT y devuelve un array de filas asociativas (ya en UTF-8).
 * @return array<int,array<string,mixed>>
 */
function db_query($sql) {
    $conn = db_connect();
    $rs = $conn->Execute($sql);
    $rows = [];
    if ($rs === null) return $rows;
    $count = $rs->Fields->Count;
    while (!$rs->EOF) {
        $row = [];
        for ($i = 0; $i < $count; $i++) {
            $f = $rs->Fields[$i];
            $row[$f->Name] = ado_val($f->Value);
        }
        $rows[] = $row;
        $rs->MoveNext();
    }
    $rs->Close();
    return $rows;
}

/** Igual que db_query pero devuelve sólo la primera fila (o null). */
function db_row($sql) {
    $rows = db_query($sql);
    return $rows ? $rows[0] : null;
}

/** Equivalente al DLookup de Access: un valor escalar. */
function db_lookup($field, $table, $cond = '') {
    $sql = "SELECT $field AS v FROM [$table]" . ($cond !== '' ? " WHERE $cond" : '') . ';';
    $row = db_row($sql);
    return $row ? reset($row) : null;
}

/**
 * Ejecuta INSERT/UPDATE/DELETE. Bloqueado si el sistema está en modo readonly.
 * @return int filas afectadas
 */
function db_exec($sql) {
    if (db_readonly()) {
        throw new Exception('Sistema en modo solo-lectura: escritura deshabilitada.');
    }
    $conn = db_connect();
    $affected = 0;
    $conn->Execute($sql, $affected);
    return (int) $affected;
}

/** Escapa comillas simples para concatenar en SQL de Access. */
function db_esc($v) {
    return str_replace("'", "''", (string) $v);
}

/** Transacciones (ADO). Usar para operaciones multi-tabla (ej. alta de orden + lote). */
function db_begin()    { db_connect()->BeginTrans(); }
function db_commit()   { db_connect()->CommitTrans(); }
function db_rollback() { try { db_connect()->RollbackTrans(); } catch (Throwable $e) {} }

/**
 * Próximo código de un maestro, replicando mdlGetNextNumber del legacy:
 * incrementa el contador ULTxxx en [Rec Control] (registro único) y lo devuelve.
 * Así los códigos no colisionan con los que asigna el sistema de escritorio.
 * @param string $ultCol nombre de la columna contador, ej. 'ULTMAR'
 */
function next_number($ultCol) {
    $row = db_row("SELECT [$ultCol] AS u FROM [Rec Control];");
    $next = ($row ? (int) $row['u'] : 0) + 1;
    db_exec("UPDATE [Rec Control] SET [$ultCol] = $next;");
    return $next;
}
