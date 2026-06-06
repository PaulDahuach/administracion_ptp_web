<?php
/**
 * Carga el padrón ARBA descargado (por tools/padron_arba.php) en PadronRentas.mdb → tabla PADRONRS,
 * que consultan la percep de la NC (APBPIB) y la retención de la OP (ARBPIB).
 *
 * Streamea los dos .txt del .zip (Per = percepciones, Ret = retenciones), FILTRA por los CUIT que PTP
 * tiene en cta cte (~1.176, en vez de los millones del padrón completo) y MERGEA Per+Ret en una fila por
 * CUIT. Reemplaza el contenido de PADRONRS (es el padrón del período vigente).
 *
 * USO:  php tools/padron_arba_cargar.php <ruta-al-zip>
 * Ej:   php tools/padron_arba_cargar.php storage/padron_arba/PadronRGS_20230201-20230228.zip
 *
 * Formato .txt (semicolon): tipo ; FDP(ddmmyyyy) ; FVD ; FVH ; CUIT(11) ; TCI ; MAB ; MCA ; alícuota(coma) ; grupo ;
 */

error_reporting(E_ALL); ini_set('display_errors', 1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if ($argc < 2) { fwrite(STDERR, "Uso: php padron_arba_cargar.php <ruta-al-zip>\n"); exit(1); }
$zipPath = $argv[1];
if (!file_exists($zipPath)) { $alt = __DIR__ . '/../' . $zipPath; if (file_exists($alt)) $zipPath = $alt; else { fwrite(STDERR, "No existe el zip: $zipPath\n"); exit(1); } }

$padronPath = trim((string) nz(db_row("SELECT DBPIBR FROM [Rec Control];")['DBPIBR'], ''));
if ($padronPath === '' || !file_exists($padronPath)) { fwrite(STDERR, "PadronRentas.mdb no encontrado ($padronPath)\n"); exit(1); }

/** DDMMYYYY → literal de fecha Access #MM/DD/YYYY# (o Null). */
function ddmmyyyy_access($s) {
    $s = preg_replace('/[^0-9]/', '', (string) $s);
    if (strlen($s) != 8) return 'Null';
    return '#' . substr($s, 2, 2) . '/' . substr($s, 0, 2) . '/' . substr($s, 4, 4) . '#';
}

// 1) CUITs de PTP (cta cte), sin guiones, como set para filtrar.
$cuits = array();
foreach (db_query("SELECT CITCUE FROM [Tbl Cuentas Corrientes] WHERE CITCUE Is Not Null AND CITCUE <> '';") as $r) {
    $c = preg_replace('/[^0-9]/', '', (string) $r['CITCUE']);
    if ($c !== '') $cuits[$c] = true;
}
echo count($cuits) . " CUIT de PTP para filtrar.\n";

// 2) Streamear los dos .txt del zip, filtrando + mergeando.
$z = new ZipArchive();
if ($z->open($zipPath) !== true) { fwrite(STDERR, "No se pudo abrir el zip.\n"); exit(2); }
$rows = array();   // cuit => campos mergeados
$periodo = array('fdp' => 'Null', 'fvd' => 'Null', 'fvh' => 'Null');
for ($i = 0; $i < $z->numFiles; $i++) {
    $name = $z->getNameIndex($i);
    $esPer = (stripos($name, 'Per') !== false);
    $esRet = (stripos($name, 'Ret') !== false);
    if (!$esPer && !$esRet) continue;
    echo "Procesando $name (" . ($esPer ? 'percepciones' : 'retenciones') . ")…\n";
    $fp = $z->getStream($name);
    $n = 0; $hit = 0;
    while (($l = fgets($fp)) !== false) {
        $n++;
        $f = explode(';', rtrim($l, "\r\n"));
        if (count($f) < 9) continue;
        $cuit = trim($f[4]);
        if (!isset($cuits[$cuit])) continue;
        $hit++;
        if (!isset($rows[$cuit])) $rows[$cuit] = array('tci' => trim($f[5]), 'mab' => trim($f[6]), 'mca' => trim($f[7]),
            'apb' => 0.0, 'arb' => 0.0, 'ngp' => '', 'ngr' => '', 'fdp' => $f[1], 'fvd' => $f[2], 'fvh' => $f[3]);
        $ali = (float) str_replace(',', '.', trim($f[8]));
        $grp = trim(isset($f[9]) ? $f[9] : '');
        if ($esPer) { $rows[$cuit]['apb'] = $ali; $rows[$cuit]['ngp'] = $grp; }
        else        { $rows[$cuit]['arb'] = $ali; $rows[$cuit]['ngr'] = $grp; }
        if ($periodo['fvd'] === 'Null') { $periodo = array('fdp' => ddmmyyyy_access($f[1]), 'fvd' => ddmmyyyy_access($f[2]), 'fvh' => ddmmyyyy_access($f[3])); }
    }
    fclose($fp);
    echo "  $n líneas, $hit de PTP.\n";
}
$z->close();
echo count($rows) . " CUIT de PTP encontrados en el padrón. Cargando en PADRONRS…\n";

// Guard: si el parse no encontró nada (descargue fallido / zip corrupto), NO tocar PADRONRS para no
// vaciar el padrón vigente por un error.
if (count($rows) == 0) { fwrite(STDERR, "No se encontró ningún CUIT de PTP en el padrón → NO se modifica PADRONRS (evito vaciarla por un descargue fallido).\n"); exit(1); }

// 3) Reemplazo COMPLETO de PADRONRS: borrar TODO el contenido antes de insertar el padrón del período
// vigente. Así los CUIT dados de baja en el padrón nuevo NO sobreviven (no se re-insertan → desaparecen).
$cn = new COM('ADODB.Connection');
$cn->Open('Provider=Microsoft.ACE.OLEDB.12.0;Data Source=' . $padronPath . ';');
$cn->Execute("DELETE FROM [PADRONRS];");   // ← vacía la tabla completa antes de la importación
$ins = 0;
foreach ($rows as $cuit => $r) {
    $tci = ($r['tci'] === '') ? 'Null' : "'" . str_replace("'", "''", $r['tci']) . "'";
    $mab = ($r['mab'] === '') ? 'Null' : "'" . str_replace("'", "''", $r['mab']) . "'";
    $mca = ($r['mca'] === '') ? 'Null' : "'" . str_replace("'", "''", $r['mca']) . "'";
    $ngp = ($r['ngp'] === '') ? 'Null' : "'" . str_replace("'", "''", $r['ngp']) . "'";
    $ngr = ($r['ngr'] === '') ? 'Null' : "'" . str_replace("'", "''", $r['ngr']) . "'";
    $cn->Execute("INSERT INTO [PADRONRS] (CITPIB, FDPPIB, FVDPIB, FVHPIB, TCIPIB, MABPIB, MCAPIB, APBPIB, ARBPIB, NGPPIB, NGRPIB) VALUES ("
        . "'" . $cuit . "', " . ddmmyyyy_access($r['fdp']) . ", " . ddmmyyyy_access($r['fvd']) . ", " . ddmmyyyy_access($r['fvh']) . ", "
        . "$tci, $mab, $mca, " . round($r['apb'], 2) . ", " . round($r['arb'], 2) . ", $ngp, $ngr);");
    $ins++;
}
$cn->Close();
echo "✓ $ins CUIT cargados en PADRONRS de $padronPath.\n";
echo "  Listo: la percep de la NC y la retención de la OP ya consultan estas alícuotas (cuando PIXCDC/RIXCDC lo permitan).\n";
