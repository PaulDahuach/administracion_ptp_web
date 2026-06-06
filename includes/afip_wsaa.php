<?php
/**
 * AFIP WSAA — Web Service de Autenticación y Autorización.
 * Genera el TRA, lo firma con CMS (PKCS#7) usando el certificado, lo envía a WSAA y obtiene
 * Token + Sign (válidos ~12hs, cacheados en storage/afip/ta_<service>.json).
 * Portado de inforemp_inside/RDN; compatible PHP 5.5 (sin ??).
 */
require_once __DIR__ . '/../config/afip.php';

class AfipWsaa {

    private $service;
    private $certFile;
    private $keyFile;
    private $wsdl;
    private $cacheDir;

    public function __construct($service = 'wsfe') {
        $this->service  = $service;
        $this->certFile = AFIP_CERT;
        $this->keyFile  = AFIP_KEY;
        $this->wsdl     = AFIP_WSAA_WSDL;
        $this->cacheDir = __DIR__ . '/../storage/afip';
        if (!is_dir($this->cacheDir)) mkdir($this->cacheDir, 0755, true);
    }

    public function getCredentials() {
        $cached = $this->getCachedCredentials();
        if ($cached) return $cached;
        return $this->authenticate();
    }

    private function getCachedCredentials() {
        $file = $this->cacheDir . '/ta_' . $this->service . '_' . AFIP_MODO . '.json';
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        if (!$data || !isset($data['token'], $data['sign'], $data['expiration'])) return null;
        $exp = strtotime($data['expiration']);
        if ($exp && $exp > (time() + 300)) {
            return array('token' => $data['token'], 'sign' => $data['sign']);
        }
        return null;
    }

    private function authenticate() {
        $tra = $this->createTRA();
        $cms = $this->signTRA($tra);
        $response = $this->callWSAA($cms);

        $xml = new SimpleXMLElement($response);
        $token = (string) $xml->credentials->token;
        $sign  = (string) $xml->credentials->sign;
        $expiration = (string) $xml->header->expirationTime;

        $cacheData = array('token' => $token, 'sign' => $sign, 'expiration' => $expiration, 'generated' => date('c'));
        file_put_contents($this->cacheDir . '/ta_' . $this->service . '_' . AFIP_MODO . '.json', json_encode($cacheData, JSON_PRETTY_PRINT));

        return array('token' => $token, 'sign' => $sign);
    }

    private function createTRA() {
        $uniqueId = date('U');
        $generationTime = date('c', time() - 60);
        $expirationTime = date('c', time() + 43200);
        return '<?xml version="1.0" encoding="UTF-8"?>
<loginTicketRequest version="1.0">
  <header>
    <uniqueId>' . $uniqueId . '</uniqueId>
    <generationTime>' . $generationTime . '</generationTime>
    <expirationTime>' . $expirationTime . '</expirationTime>
  </header>
  <service>' . $this->service . '</service>
</loginTicketRequest>';
    }

    private function signTRA($tra) {
        $traFile = tempnam(sys_get_temp_dir(), 'tra_');
        $cmsFile = tempnam(sys_get_temp_dir(), 'cms_');
        file_put_contents($traFile, $tra);

        if (!file_exists($this->certFile)) throw new Exception('No se encuentra el certificado: ' . $this->certFile);
        if (!file_exists($this->keyFile))  throw new Exception('No se encuentra la clave privada: ' . $this->keyFile);
        $cert = file_get_contents($this->certFile);
        $key  = file_get_contents($this->keyFile);

        $res = openssl_pkcs7_sign($traFile, $cmsFile, $cert, $key, array(), PKCS7_BINARY | PKCS7_NOSIGS);
        if (!$res) { @unlink($traFile); @unlink($cmsFile); throw new Exception('Error firmando TRA: ' . openssl_error_string()); }

        $cms = file_get_contents($cmsFile);
        @unlink($traFile); @unlink($cmsFile);

        $parts = explode("\n\n", $cms, 2);
        $body = isset($parts[1]) ? $parts[1] : $cms;
        $body = preg_replace('/\n----.*$/', '', trim($body));
        return $body;
    }

    private function callWSAA($cms) {
        $client = new SoapClient($this->wsdl, array(
            'soap_version'   => SOAP_1_2,
            'trace'          => true,
            'exceptions'     => true,
            'stream_context' => stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false))),
        ));
        try {
            $result = $client->loginCms(array('in0' => $cms));
            return $result->loginCmsReturn;
        } catch (SoapFault $e) {
            if (strpos($e->getMessage(), 'ya posee un TA') !== false) {
                throw new Exception('WSAA: ya existe un Token vigente para este certificado. Esperá a que expire (~12hs) o usá el TA cacheado.');
            }
            throw $e;
        }
    }
}
