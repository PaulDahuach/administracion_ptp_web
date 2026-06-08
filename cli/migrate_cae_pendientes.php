<?php
/**
 * Migración: tabla [Web CAE Pendientes] — cola de comprobantes electrónicos (FV/NC/ND) que se
 * grabaron sin CAE porque AFIP estaba caído al emitir. El resolver (cli/cae_retry.php) la procesa
 * en orden correlativo hasta obtener el CAE (o marcarla 'rechazado' para resolución manual).
 *
 * Tabla web-only (no es del legacy). Uso: php cli/migrate_cae_pendientes.php  (idempotente).
 *
 *  NUMMOV    Long  PK   — el movimiento (Tbl Movimientos) que quedó pendiente
 *  CODOPE    Long       — 420 FV / 440 ND / 460 NC
 *  PTOVTA    Long       — punto de venta AFIP (CIPMOV)
 *  CBTETIPO  Long       — tipo de comprobante AFIP (1/2/3/6/7/8)
 *  NUMERO    Long       — número de comprobante reservado (CINMOV) = ULTCM+1 al encolar
 *  LETRA     Text(1)    — A/B (CIIMOV), para el contador ULTCM<letra>
 *  ESTADO    Text(20)   — 'pendiente' (reintentable) | 'rechazado' (AFIP rechazó: resolución manual)
 *  INTENTOS  Long       — cantidad de reintentos hechos
 *  ULTERR    Memo       — último error de AFIP (diagnóstico)
 *  PAYLOAD   Memo       — request de AFIP (JSON) tal como se armó al emitir, para reenviarlo idéntico
 *  FALTA     DateTime   — alta en la cola
 *  FULT      DateTime   — último intento
 */
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/helpers.php';

$existe = false;
try { db_query("SELECT TOP 1 NUMMOV FROM [Web CAE Pendientes];"); $existe = true; }
catch (Exception $e) { $existe = false; }

if ($existe) {
    echo "La tabla [Web CAE Pendientes] ya existe — nada que hacer.\n";
    exit(0);
}

db_exec("CREATE TABLE [Web CAE Pendientes] (
    NUMMOV   LONG       CONSTRAINT PK_WebCaePend PRIMARY KEY,
    CODOPE   LONG,
    PTOVTA   LONG,
    CBTETIPO LONG,
    NUMERO   LONG,
    LETRA    TEXT(1),
    ESTADO   TEXT(20),
    INTENTOS LONG,
    ULTERR   MEMO,
    PAYLOAD  MEMO,
    FALTA    DATETIME,
    FULT     DATETIME
);");

echo "Tabla [Web CAE Pendientes] creada OK.\n";
