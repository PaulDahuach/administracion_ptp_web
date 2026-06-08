<?php
/**
 * Reintento de CAE pendientes (B-php) — drena la cola [Web CAE Pendientes] llamando al resolver.
 * Pensado para el Task Scheduler de Windows (cada N minutos). Uso: php cli/cae_retry.php
 *
 * Imprime un resumen por stdout y lo loguea (append) en logs/cae_retry.log.
 * Programar (ejemplo, cada 5 min): schtasks /Create /SC MINUTE /MO 5 /TN "PTP CAE Retry"
 *   /TR "\"C:\wamp64\bin\php\phpX.Y.Z\php.exe\" \"C:\wamp64\www\administracion_ptp\cli\cae_retry.php\""
 */
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/cae_cola.php';

$r = cae_resolver();

$ts = date('Y-m-d H:i:s');
$line = "[$ts] autorizados={$r['autorizados']} reconciliados={$r['reconciliados']} rechazados={$r['rechazados']} pendientes={$r['pendientes']}";
if (isset($r['error'])) $line .= " error={$r['error']}";

echo $line . "\n";
foreach ($r['detalle'] as $d) echo "  $d\n";

// Log (no rompe si no se puede escribir).
$txt = $line . "\n";
foreach ($r['detalle'] as $d) $txt .= "  $d\n";
@file_put_contents(__DIR__ . '/../logs/cae_retry.log', $txt, FILE_APPEND);
