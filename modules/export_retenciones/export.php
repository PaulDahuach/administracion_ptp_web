<?php
/** Exportación de Retenciones SUFRIDAS · descarga del .txt (sin zip). ?tipo=iibb|iva|ganancias|suss.
 *  Arma el contenido con ret_line() y lo streamea con el nombre del legacy (CM03_R/IVA_R/Gan_R/SUSS_R). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/_ret.php';
auth_require_login();

$tipo = isset($_GET['tipo']) ? strtolower($_GET['tipo']) : 'iibb';
$cf = ret_cfg($tipo);
if (!$cf) { http_response_code(400); echo 'Tipo inválido'; exit; }

$desIso = isset($_GET['desde']) ? $_GET['desde'] : '';
$hasIso = isset($_GET['hasta']) ? $_GET['hasta'] : '';
$rows = ret_rows($tipo, ret_serial($desIso), ret_serial($hasIso));

$lines = array();
foreach ($rows as $r) $lines[] = ret_line($tipo, $r);
$content = $lines ? implode("\r\n", $lines) . "\r\n" : '';

header('Content-Type: text/plain; charset=ISO-8859-1');
header('Content-Disposition: attachment; filename="' . $cf['file'] . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-store');
echo $content;
exit;
