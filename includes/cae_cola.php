<?php
/**
 * Cola de CAE pendientes (B-php) — comprobantes electrónicos (FV/NC/ND) que se grabaron SIN CAE
 * porque AFIP estaba caído al emitir, más el resolver que los autoriza después.
 *
 * Contexto: el web es el ÚNICO emisor electrónico → el contador local ULTCM<letra> (Tbl Puntos de
 * Venta) es la autoridad de numeración (se sincroniza con AFIP en cada éxito). Por eso un pendiente
 * reserva ULTCM+1 y ESE número es el candidato de AFIP, sin carrera con Access.
 *
 * Reglas:
 *  - Correlatividad: AFIP autoriza en orden. Con backlog para un (pdv,tipo), todo nuevo encola.
 *  - Reconciliación: antes de pedir el CAE de N se consulta FECompConsultar(N); si ya está autorizado
 *    (caso "AFIP otorgó pero se perdió la respuesta"), se persiste ese CAE en vez de pedir uno nuevo.
 *  - Un rechazo de datos frena (y bloquea) el grupo: no se puede autorizar el siguiente número.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** Comprobantes en la cola para este (pdv, tipo) — pendiente o rechazado, ambos bloquean la correlatividad. */
function cae_backlog($pdv, $tipo) {
    $r = db_row("SELECT Count(*) AS n FROM [Web CAE Pendientes] WHERE PTOVTA=" . (int) $pdv . " AND CBTETIPO=" . (int) $tipo . ";");
    return (int) nz($r['n'], 0);
}

/** Encola un comprobante recién grabado sin CAE (AFIP caído). $payload = request de AFIP (array). */
function cae_encolar($nummov, $codope, $pdv, $tipo, $numero, $letra, $payload, $err) {
    db_exec("INSERT INTO [Web CAE Pendientes]
        (NUMMOV, CODOPE, PTOVTA, CBTETIPO, NUMERO, LETRA, ESTADO, INTENTOS, ULTERR, PAYLOAD, FALTA, FULT)
        VALUES (" . (int) $nummov . ", " . (int) $codope . ", " . (int) $pdv . ", " . (int) $tipo . ", " . (int) $numero . ",
        '" . db_esc(strtoupper(trim((string) $letra))) . "', 'pendiente', 0, '" . db_esc((string) $err) . "',
        '" . db_esc(json_encode($payload)) . "', Now(), Now());");
}

/** Ymd ('20260616') → serial de fecha de Access (días desde 1899-12-30). */
function cae_ymd_serial($ymd) {
    $ymd = trim((string) $ymd);
    if (strlen($ymd) !== 8) return null;
    $d = DateTime::createFromFormat('Ymd', $ymd);
    if (!$d) return null;
    $base = new DateTime('1899-12-30');
    $d->setTime(0, 0, 0); $base->setTime(0, 0, 0);
    return (int) $base->diff($d)->days;
}

/** Persiste el CAE en el movimiento ya grabado y lo saca de la cola (transacción). NO toca ULTCM (ya se reservó al encolar). */
function cae_persistir($nummov, $cae, $caeVtoYmd) {
    $fvc = cae_ymd_serial($caeVtoYmd);
    db_begin();
    try {
        db_exec("UPDATE [Tbl Movimientos] SET CAEMOV='" . db_esc($cae) . "', FVCMOV=" . ($fvc !== null ? $fvc : 'Null') . " WHERE NUMMOV=" . (int) $nummov . ";");
        db_exec("DELETE FROM [Web CAE Pendientes] WHERE NUMMOV=" . (int) $nummov . ";");
        db_commit();
    } catch (Exception $e) { db_rollback(); throw $e; }
}

/**
 * Procesa la cola en orden correlativo. Devuelve un resumen con conteos + detalle.
 * $solo = NUMMOV opcional para reintentar uno puntual (igual respeta el orden del grupo).
 */
function cae_resolver($solo = null) {
    require_once __DIR__ . '/afip_wsfe.php';
    require_once __DIR__ . '/../config/afip.php';
    $res = array('autorizados' => 0, 'rechazados' => 0, 'pendientes' => 0, 'reconciliados' => 0, 'detalle' => array());

    $where = "ESTADO='pendiente'" . ($solo !== null ? " AND NUMMOV=" . (int) $solo : "");
    $rows = db_query("SELECT NUMMOV, CODOPE, PTOVTA, CBTETIPO, NUMERO, LETRA, INTENTOS, PAYLOAD
        FROM [Web CAE Pendientes] WHERE $where ORDER BY PTOVTA, CBTETIPO, NUMERO;");
    if (!$rows) return $res;

    try { $wsfe = new AfipWsfe(); }
    catch (Exception $e) { $res['error'] = 'AFIP inalcanzable: ' . $e->getMessage(); return $res; }

    // Agrupar por (pdv, tipo); procesar cada grupo en orden de número; frenar ante rechazo o AFIP caído.
    $grupos = array();
    foreach ($rows as $r) { $grupos[$r['PTOVTA'] . '|' . $r['CBTETIPO']][] = $r; }
    foreach ($grupos as $k => $items) {
        list($pdv, $tipo) = explode('|', $k);
        // Un rechazado previo bloquea TODO el grupo (es el número más bajo sin resolver): resolución manual.
        $rech = db_row("SELECT Count(*) AS n FROM [Web CAE Pendientes] WHERE PTOVTA=" . (int) $pdv . " AND CBTETIPO=" . (int) $tipo . " AND ESTADO='rechazado';");
        if ((int) nz($rech['n'], 0) > 0) { $res['detalle'][] = "pdv $pdv tipo $tipo: bloqueado por un rechazado (resolución manual)"; continue; }

        foreach ($items as $r) {
            $num = (int) $r['NUMERO']; $nummov = (int) $r['NUMMOV'];
            try {
                $cae = null; $caeVto = null;
                // 1) Reconciliar: ¿ya está autorizado en AFIP? (respuesta perdida tras un timeout)
                try {
                    $g = $wsfe->consultarComprobante($pdv, $tipo, $num);
                    if ($g && isset($g->CodAutorizacion) && trim((string) $g->CodAutorizacion) !== '') {
                        $cae = (string) $g->CodAutorizacion; $caeVto = (string) $g->FchVto; $res['reconciliados']++;
                    }
                } catch (AfipRejected $e) { /* AFIP dice que N no existe → seguir a solicitar */ }
                // 2) No estaba autorizado: pedir el CAE replayando el payload con su número reservado.
                if ($cae === null) {
                    $req = json_decode($r['PAYLOAD'], true);
                    $req['cbte_desde'] = $num; $req['cbte_hasta'] = $num;
                    $sol = $wsfe->solicitarCAE($req);
                    $cae = $sol['cae']; $caeVto = $sol['cae_vencimiento'];
                }
                cae_persistir($nummov, $cae, $caeVto);
                $res['autorizados']++; $res['detalle'][] = "mov $nummov nº $num → CAE $cae";
            } catch (AfipRejected $e) {
                db_exec("UPDATE [Web CAE Pendientes] SET ESTADO='rechazado', INTENTOS=INTENTOS+1, ULTERR='" . db_esc($e->getMessage()) . "', FULT=Now() WHERE NUMMOV=$nummov;");
                $res['rechazados']++; $res['detalle'][] = "mov $nummov nº $num → RECHAZADO: " . $e->getMessage();
                break;   // correlatividad: frenar el grupo
            } catch (AfipUnreachable $e) {
                db_exec("UPDATE [Web CAE Pendientes] SET INTENTOS=INTENTOS+1, ULTERR='" . db_esc($e->getMessage()) . "', FULT=Now() WHERE NUMMOV=$nummov;");
                $res['pendientes']++; $res['detalle'][] = "mov $nummov nº $num → AFIP caído, sigue pendiente";
                break;   // AFIP caído: frenar el grupo
            }
        }
    }
    return $res;
}
