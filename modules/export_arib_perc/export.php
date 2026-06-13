<?php
/** Exportación A.R.I.B. — Percepciones · descarga del artefacto ARBA (.zip con MD5 en el nombre).
 *  Arma el .txt SIAP, lo zipea y lo firma con MD5 (igual que el legacy: zip → md5 → nombre_<md5>.zip).
 *  El email del legacy lo reemplaza la descarga. Lógica/formato en _arib_p.php. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/afip.php';
require_once __DIR__ . '/_arib_p.php';
auth_require_login();

$desIso = isset($_GET['desde']) ? $_GET['desde'] : '';
$hasIso = isset($_GET['hasta']) ? $_GET['hasta'] : '';
$rows = aribp_rows(aribp_serial($desIso), aribp_serial($hasIso));

$lines = array();
foreach ($rows as $r) $lines[] = aribp_line($r);
$content = $lines ? implode("\r\n", $lines) . "\r\n" : '';

$base = aribp_filebase($desIso, $hasIso);

// .txt dentro del .zip; nombre final del .zip = base_<md5 del zip>.zip (intercambio ARBA)
$tmp = tempnam(sys_get_temp_dir(), 'aribp');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); echo 'No se pudo crear el .zip'; exit; }
$zip->addFromString($base . '.txt', $content);
$zip->close();
$md5 = strtoupper(md5_file($tmp));   // ARBA usa el hash en MAYÚSCULAS (como GetFileMD5HashCode del legacy)
$dlName = $base . '_' . $md5 . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $dlName . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
unlink($tmp);
exit;
