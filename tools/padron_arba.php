<?php
/**
 * Downloader del padrón ARBA (percep/retención IIBB) — reconstrucción del EXE `PadronARBA.exe` perdido.
 * Baja el padrón de un período y lo guarda (.zip/.txt) para refrescar `PadronRentas.mdb` (PADRONRS:
 * CITPIB + APBPIB percep + ARBPIB retención), que usan la percep de la NC y la retención de la OP.
 *
 * USO (CLI — lo corre el operador con SUS credenciales; el script NO trae credenciales embebidas):
 *   php tools/padron_arba.php <fechaDesde:YYYYMMDD> <fechaHasta:YYYYMMDD> <user/CUIT> <clave> [form|multipart]
 * Ejemplo (igual que el EXE viejo):
 *   php tools/padron_arba.php 20230201 20230228 30708544937 080000
 *
 * Endpoint: https://dfe.arba.gov.ar/DomicilioElectronico/SeguridadCliente/dfeServicioDescargaPadron.do
 * Comportamiento esperado: devuelve el padrón (.zip con .txt) o un <DFEError> indicando qué parámetro
 * falta/está mal. Como el formato exacto del POST no está documentado, el script LOGUEA toda la respuesta
 * y los nombres de los campos están en $FIELDS abajo → se ajusta iterando según el <DFEError> que devuelva.
 * (Por el error de prueba sabemos que requiere el parámetro `user`.)
 */

error_reporting(E_ALL); ini_set('display_errors', 1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }
if (!function_exists('curl_init')) { fwrite(STDERR, "Falta la extensión cURL de PHP.\n"); exit(1); }

$ENDPOINT = 'https://dfe.arba.gov.ar/DomicilioElectronico/SeguridadCliente/dfeServicioDescargaPadron.do';
$OUTDIR   = __DIR__ . '/../storage/padron_arba';

// ───────────────────────── Argumentos ─────────────────────────
if ($argc < 5) {
    fwrite(STDERR, "Uso: php padron_arba.php <desde:YYYYMMDD> <hasta:YYYYMMDD> <user/CUIT> <clave> [form|multipart]\n");
    exit(1);
}
$desde = preg_replace('/[^0-9]/', '', $argv[1]);
$hasta = preg_replace('/[^0-9]/', '', $argv[2]);
$user  = preg_replace('/[^0-9]/', '', $argv[3]);
$clave = $argv[4];
$mode  = isset($argv[5]) ? strtolower($argv[5]) : 'multipart';   // multipart (oficial: xml como archivo 'file') | form
if (!preg_match('/^\d{8}$/', $desde) || !preg_match('/^\d{8}$/', $hasta)) { fwrite(STDERR, "Las fechas deben ser YYYYMMDD.\n"); exit(1); }

// ───────────────────────── XML del request (ISO-8859-1, como el EXE) ─────────────────────────
$xml = '<?xml version="1.0" encoding="ISO-8859-1" ?>' . "\r\n"
     . "<DESCARGA-PADRON>\r\n"
     . "\t<fechaDesde>$desde</fechaDesde>\r\n"
     . "\t<fechaHasta>$hasta</fechaHasta>\r\n"
     . "</DESCARGA-PADRON>";

// ───────────────────────── CONFIG del POST (protocolo oficial confirmado) ─────────────────────────
// Multipart con 3 campos: user (CUIT), password (clave), file (el XML adjunto). El nombre del archivo
// es DFEServicioDescargaPadron_<MD5 del contenido XML>.xml (así lo arman las implementaciones de ref).
$FIELDS = array(
    'user'     => $user,
    'password' => $clave,
);
$XML_FIELD = 'file';                                                  // ← campo correcto (no 'xml')
$XML_FNAME = 'DFEServicioDescargaPadron_' . md5($xml) . '.xml';       // ← nombre con hash MD5

@mkdir($OUTDIR, 0777, true);
$stamp = $desde . '-' . $hasta;

// ───────────────────────── Armado del body según el modo ─────────────────────────
$ch = curl_init($ENDPOINT);
$common = array(
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,   // el servicio es viejo (TLS), como el WSFE de AFIP
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_USERAGENT      => 'PadronARBA-PHP/1.0',
);
if ($mode === 'multipart') {
    // XML como archivo adjunto (el binario del EXE mostraba un '.xml' suelto → posible file upload)
    $tmp = $OUTDIR . '/_req.xml'; file_put_contents($tmp, $xml);
    $post = $FIELDS;
    if (class_exists('CURLFile')) $post[$XML_FIELD] = new CURLFile($tmp, 'text/xml', $XML_FNAME);
    else $post[$XML_FIELD] = '@' . $tmp . ';type=text/xml';
    $common[CURLOPT_POSTFIELDS] = $post;   // array → multipart/form-data
    $common[CURLOPT_HTTPHEADER] = array('Cache-Control: no-cache');
} else {
    // form-urlencoded: XML como un campo de texto más
    $post = $FIELDS; $post[$XML_FIELD] = $xml;
    $common[CURLOPT_POSTFIELDS] = http_build_query($post);
    $common[CURLOPT_HTTPHEADER] = array('Content-Type: application/x-www-form-urlencoded');
}
curl_setopt_array($ch, $common);

echo "POST ($mode) → $ENDPOINT  [período $desde..$hasta, user $user]\n";
$resp = curl_exec($ch);
if ($resp === false) { fwrite(STDERR, "cURL error: " . curl_error($ch) . "\n"); exit(2); }
$hsize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$body   = substr($resp, $hsize);
curl_close($ch);
if ($mode === 'multipart' && isset($tmp)) @unlink($tmp);

echo "HTTP $status | Content-Type: $ctype | " . strlen($body) . " bytes\n";

// ───────────────────────── Interpretar la respuesta ─────────────────────────
if (substr($body, 0, 2) === 'PK') {                       // ZIP (PK\x03\x04) → es el padrón
    $f = "$OUTDIR/PadronRGS_$stamp.zip";
    file_put_contents($f, $body);
    echo "✓ PADRÓN DESCARGADO → $f (" . strlen($body) . " bytes)\n";
    echo "  Próximo paso: descomprimir el .txt y cargarlo en PadronRentas.mdb (PADRONRS).\n";
    exit(0);
}
$f = "$OUTDIR/respuesta_$stamp.txt";
file_put_contents($f, $body);
if (stripos($body, 'DFEError') !== false || stripos($body, '<?xml') !== false || stripos($body, '<DFE') !== false) {
    echo "ARBA devolvió XML (probable error / falta o sobra un parámetro):\n";
    echo "----------------------------------------\n" . trim($body) . "\n----------------------------------------\n";
    echo "→ Ajustá \$FIELDS / \$XML_FIELD / el modo (form|multipart) según el parámetro que pida y volvé a correr.\n";
    echo "  (respuesta guardada en $f)\n";
} else {
    echo "Respuesta no reconocida (¿login HTML? ¿otro flujo?). Primeros 800 chars:\n";
    echo substr($body, 0, 800) . "\n";
    echo "  (completa en $f)\n";
}
