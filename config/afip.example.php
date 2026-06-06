<?php
/**
 * PLANTILLA de configuración AFIP — copiar a config/afip.php (NO versionado) y ajustar rutas por instalación.
 *
 * PTP = Responsable Inscripto → emite Factura/NC/ND clase A (con IVA discriminado).
 * MODO 'testing' usa homologación (no emite documentos fiscales reales); 'produccion' los servidores reales.
 * ⚠️ NO pasar a 'produccion' sin confirmación explícita + el certificado de PTP probado.
 *
 * Certificados (fuera del web root): en dev Windows viven en C:/_Inforemp/_Sistemas/...; en el server
 * Linux van a una carpeta tipo /home/<user>/afip_certs/. Ajustar AFIP_CERT/AFIP_KEY según el entorno.
 */

define('AFIP_MODO', 'testing');   // 'testing' | 'produccion'

if (AFIP_MODO === 'produccion') {
    define('AFIP_CUIT', '30708381132');                                                            // PTP (sin guiones)
    define('AFIP_CERT', 'C:/_Inforemp/_Sistemas/_ProcesadoraTextilParque/_CertificadoDigital/03ptp.crt');
    define('AFIP_KEY',  'C:/_Inforemp/_Sistemas/_ProcesadoraTextilParque/_CertificadoDigital/01ptp.rsa');
    define('AFIP_PTO_VTA', 3);
} else {
    define('AFIP_CUIT', '20239619631');                                                            // cert de homologación (Paul Dahuach)
    define('AFIP_CERT', 'C:/_Inforemp/_Sistemas/_PaulDahuach/_CertificadoDigital/03paul_testing.crt');
    define('AFIP_KEY',  'C:/_Inforemp/_Sistemas/_PaulDahuach/_CertificadoDigital/01paul.rsa');
    define('AFIP_PTO_VTA', 1);
}

define('AFIP_WSAA_WSDL_TEST', 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL');
define('AFIP_WSAA_WSDL_PROD', 'https://wsaa.afip.gov.ar/ws/services/LoginCms?WSDL');
define('AFIP_WSAA_WSDL', AFIP_MODO === 'produccion' ? AFIP_WSAA_WSDL_PROD : AFIP_WSAA_WSDL_TEST);
define('AFIP_WSFE_WSDL_TEST', 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL');
define('AFIP_WSFE_WSDL_PROD', 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL');
define('AFIP_WSFE_WSDL', AFIP_MODO === 'produccion' ? AFIP_WSFE_WSDL_PROD : AFIP_WSFE_WSDL_TEST);

define('AFIP_CBTE_FC_A', 1);  define('AFIP_CBTE_ND_A', 2);  define('AFIP_CBTE_NC_A', 3);
define('AFIP_CBTE_FC_B', 6);  define('AFIP_CBTE_ND_B', 7);  define('AFIP_CBTE_NC_B', 8);

$GLOBALS['AFIP_IVA_ID'] = array('0' => 3, '10.5' => 4, '21' => 5, '27' => 6, '5' => 8, '2.5' => 9);

define('AFIP_CONCEPTO', 1);        // 1=Productos
define('AFIP_MONEDA_ID', 'PES');
define('AFIP_MONEDA_COTIZ', 1);

function afip_cbte_tipo($cic, $letra) {
    $map = array('FV' => array('A' => 1, 'B' => 6), 'ND' => array('A' => 2, 'B' => 7), 'NC' => array('A' => 3, 'B' => 8));
    $cic = strtoupper(trim((string) $cic)); $letra = strtoupper(trim((string) $letra));
    return isset($map[$cic][$letra]) ? $map[$cic][$letra] : null;
}
function afip_iva_id($pct) {
    $k = rtrim(rtrim(number_format((float) $pct, 2, '.', ''), '0'), '.');
    return isset($GLOBALS['AFIP_IVA_ID'][$k]) ? $GLOBALS['AFIP_IVA_ID'][$k] : 5;
}
