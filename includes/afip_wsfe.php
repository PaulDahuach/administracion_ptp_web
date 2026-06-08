<?php
/**
 * AFIP WSFE — Web Service de Factura Electrónica v1.
 * FECAESolicitar (pide el CAE), FECompUltimoAutorizado (numeración), FECompConsultar, FEParam*.
 * Soporta Factura A (IVA discriminado) vía el array Iva/AlicIva. Portado de RDN; compatible PHP 5.5.
 */
require_once __DIR__ . '/afip_wsaa.php';

/** AFIP inalcanzable (red/timeout/servicio caído) — transitorio y reintentable; NO se grabó nada. */
class AfipUnreachable extends Exception {}
/** AFIP procesó el pedido y lo RECHAZÓ (observaciones/errores de datos o negocio) — hay que corregir, no reintentar a ciegas. */
class AfipRejected extends Exception {}

class AfipWsfe {

    private $client;
    private $auth;
    private $cuit;

    public function __construct() {
        try {
            $wsaa = new AfipWsaa('wsfe');
            $cred = $wsaa->getCredentials();
            $this->cuit = AFIP_CUIT;
            $this->auth = array('Token' => $cred['token'], 'Sign' => $cred['sign'], 'Cuit' => $this->cuit);
            $this->client = new SoapClient(AFIP_WSFE_WSDL, array(
                'soap_version'   => SOAP_1_2,
                'trace'          => true,
                'exceptions'     => true,
                'stream_context' => stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false))),
            ));
        } catch (SoapFault $e) {
            throw new AfipUnreachable('No se pudo conectar con AFIP: ' . $e->getMessage());
        }
    }

    /** Ejecuta un método SOAP de AFIP; un SoapFault (red/timeout/servicio caído) se traduce a AfipUnreachable (reintentable). */
    private function call($method, $args) {
        try {
            return $this->client->$method($args);
        } catch (SoapFault $e) {
            throw new AfipUnreachable('AFIP no respondió (' . $method . '): ' . $e->getMessage());
        }
    }

    /** Último comprobante autorizado para un punto de venta + tipo. */
    public function ultimoAutorizado($ptoVta, $cbteTipo) {
        $result = $this->call('FECompUltimoAutorizado', array('Auth' => $this->auth, 'PtoVta' => (int) $ptoVta, 'CbteTipo' => (int) $cbteTipo));
        $this->checkErrors($result->FECompUltimoAutorizadoResult);
        return (int) $result->FECompUltimoAutorizadoResult->CbteNro;
    }

    /**
     * Solicita el CAE de un comprobante. $data:
     *   pto_vta, cbte_tipo, cbte_desde, cbte_hasta, cbte_fch (Ymd),
     *   imp_total, imp_neto, imp_iva, imp_trib, imp_op_ex, imp_tot_conc,
     *   doc_tipo (80=CUIT/96=DNI/99=CF), doc_nro, concepto (1/2/3),
     *   iva_array = [ ['Id'=>5,'BaseImp'=>..,'Importe'=>..], ... ]  (Factura A),
     *   cond_iva_receptor (RG 5616, opcional), fch_serv_* (concepto>=2).
     */
    public function solicitarCAE($data) {
        $g = function ($k, $def = 0) use ($data) { return isset($data[$k]) ? $data[$k] : $def; };
        $concepto = $g('concepto', AFIP_CONCEPTO);

        $feDetReq = array(
            'Concepto'   => $concepto,
            'DocTipo'    => $data['doc_tipo'],
            'DocNro'     => $data['doc_nro'],
            'CbteDesde'  => $data['cbte_desde'],
            'CbteHasta'  => $data['cbte_hasta'],
            'CbteFch'    => $data['cbte_fch'],
            'ImpTotal'   => $data['imp_total'],
            'ImpTotConc' => $g('imp_tot_conc', 0),
            'ImpNeto'    => $data['imp_neto'],
            'ImpOpEx'    => $g('imp_op_ex', 0),
            'ImpIVA'     => $g('imp_iva', 0),
            'ImpTrib'    => $g('imp_trib', 0),
            'MonId'      => AFIP_MONEDA_ID,
            'MonCotiz'   => AFIP_MONEDA_COTIZ,
        );

        // Factura A: IVA discriminado por alícuota
        if (isset($data['iva_array']) && is_array($data['iva_array']) && count($data['iva_array']) > 0) {
            $feDetReq['Iva'] = array('AlicIva' => $data['iva_array']);
        }
        // Tributos (percepciones IIBB) — opcional
        if (isset($data['trib_array']) && is_array($data['trib_array']) && count($data['trib_array']) > 0) {
            $feDetReq['Tributos'] = array('Tributo' => $data['trib_array']);
        }
        // Comprobantes asociados (CbtesAsoc) — NC/ND deben enviar CbtesAsoc O PeriodoAsoc (AFIP err 10197).
        // Cada uno: ['Tipo'=>1, 'PtoVta'=>3, 'Nro'=>6079, 'Cuit'=>'30708381132'(opc), 'CbteFch'=>Ymd(opc)].
        if (isset($data['cbtes_asoc']) && is_array($data['cbtes_asoc']) && count($data['cbtes_asoc']) > 0) {
            $feDetReq['CbtesAsoc'] = array('CbteAsoc' => $data['cbtes_asoc']);
        } elseif (isset($data['periodo_asoc']) && is_array($data['periodo_asoc'])) {
            // ND/NC sin factura asociada: período (FchDesde/FchHasta en Ymd) que cubre el comprobante.
            $feDetReq['PeriodoAsoc'] = array('FchDesde' => $data['periodo_asoc']['FchDesde'], 'FchHasta' => $data['periodo_asoc']['FchHasta']);
        }
        // CondicionIVAReceptorId (RG 5616): 1=RI, 4=Exento, 5=CF, 6=Monotributo…
        if (isset($data['cond_iva_receptor']) && $data['cond_iva_receptor'] !== null && $data['cond_iva_receptor'] !== '') {
            $feDetReq['CondicionIVAReceptorId'] = (int) $data['cond_iva_receptor'];
        }
        // Servicios (concepto 2/3): fechas obligatorias
        if ($concepto >= 2) {
            $feDetReq['FchServDesde'] = $g('fch_serv_desde', $data['cbte_fch']);
            $feDetReq['FchServHasta'] = $g('fch_serv_hasta', $data['cbte_fch']);
            $feDetReq['FchVtoPago']   = $g('fch_vto_pago', $data['cbte_fch']);
        }

        $request = array(
            'Auth' => $this->auth,
            'FeCAEReq' => array(
                'FeCabReq' => array('CantReg' => 1, 'PtoVta' => (int) $data['pto_vta'], 'CbteTipo' => (int) $data['cbte_tipo']),
                'FeDetReq' => array('FECAEDetRequest' => $feDetReq),
            ),
        );

        $result = $this->call('FECAESolicitar', $request);
        $fecae = $result->FECAESolicitarResult;
        $this->checkErrors($fecae);

        $det = $fecae->FeDetResp->FECAEDetResponse;
        if (is_array($det)) $det = $det[0];

        if ($det->Resultado !== 'A') {
            $obs = '';
            if (isset($det->Observaciones)) {
                $arr = is_array($det->Observaciones->Obs) ? $det->Observaciones->Obs : array($det->Observaciones->Obs);
                foreach ($arr as $o) $obs .= "({$o->Code}) {$o->Msg} ";
            }
            throw new AfipRejected('Comprobante rechazado por AFIP: ' . $obs);
        }

        return array(
            'cae'            => (string) $det->CAE,
            'cae_vencimiento' => (string) $det->CAEFchVto,   // Ymd
            'cbte_desde'     => (int) $det->CbteDesde,
            'cbte_hasta'     => (int) $det->CbteHasta,
            'resultado'      => (string) $det->Resultado,
        );
    }

    /** Consulta un comprobante ya emitido. */
    public function consultarComprobante($ptoVta, $cbteTipo, $cbteNro) {
        $result = $this->call('FECompConsultar', array(
            'Auth' => $this->auth,
            'FeCompConsReq' => array('CbteTipo' => (int) $cbteTipo, 'CbteNro' => (int) $cbteNro, 'PtoVta' => (int) $ptoVta),
        ));
        $this->checkErrors($result->FECompConsultarResult);
        return $result->FECompConsultarResult->ResultGet;
    }

    private function checkErrors($result) {
        if (isset($result->Errors)) {
            $errs = is_array($result->Errors->Err) ? $result->Errors->Err : array($result->Errors->Err);
            $msg = '';
            foreach ($errs as $e) $msg .= "({$e->Code}) {$e->Msg} ";
            throw new AfipRejected('Error AFIP: ' . $msg);
        }
    }

    public function getLastRequest()  { return $this->client->__getLastRequest(); }
    public function getLastResponse() { return $this->client->__getLastResponse(); }
}

/**
 * Traduce una excepción de emisión electrónica a la respuesta JSON de error adecuada (uniforme FV/NC/ND):
 *  - AfipUnreachable → 503 'unreachable' (transitorio; reintentar; no se grabó nada).
 *  - AfipRejected    → 422 'rejected'    (AFIP rechazó por datos; corregir).
 *  - cualquier otra  → 500 genérico (error local: DB, etc.).
 */
function afip_fail(Exception $e, $verbo) {
    if ($e instanceof AfipUnreachable) {
        fail('AFIP no está respondiendo, así que el comprobante NO se emitió ni se grabó. Reintentá en unos minutos — los datos quedan cargados.', 503, 'unreachable');
    } elseif ($e instanceof AfipRejected) {
        fail($e->getMessage() . ' — corregí los datos y reintentá.', 422, 'rejected');
    } else {
        fail('No se pudo ' . $verbo . ' el comprobante: ' . $e->getMessage(), 500);
    }
}
