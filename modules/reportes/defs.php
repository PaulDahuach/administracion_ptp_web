<?php
/**
 * ============================================================================
 *  Motor de Reportes / Listados (solo lectura, config-driven) — DEFINICIONES
 * ============================================================================
 *  EJEMPLO / PLANTILLA. Reemplazá las entradas por los listados reales de tu
 *  sistema. El motor (api.php/index.php/reportes.js) NO se toca: renderiza
 *  cualquier consulta como una grilla DataTable con buscador, orden e impresión.
 *
 *  Cada reporte se ve en  /modules/reportes/?r=<clave>.
 *
 *  Def de un reporte:
 *    'titulo' => 'Texto del encabezado'
 *    'icono'  => 'bi-...'                        (Bootstrap Icons)
 *    'sql'    => "SELECT ... ;"                  SQL Access; los alias de columna
 *                  (AS [Etiqueta]) se usan como encabezados de la grilla.
 *    'fechas' => ['Recibido', ...]              alias de columnas que vienen como
 *                  serial Access y hay que convertir a dd/mm/aaaa (helpers del kit).
 *
 *  Sugerencia: si varios reportes comparten el mismo FROM/JOIN, definílo una vez
 *  en una variable y reusalo (ojo con los paréntesis de los JOIN anidados Access:
 *  N tablas = N-1 paréntesis de apertura).
 * ============================================================================
 */

return [

    // ── Ejemplo 1: listado simple ───────────────────────────────────────
    'clientes' => [
        'titulo' => 'Clientes', 'icono' => 'bi-people',
        'sql' => "SELECT C.CODCLI AS [Código], C.DENCLI AS [Cliente], L.DENLOC AS [Localidad]
                  FROM [Tbl Clientes] AS C LEFT JOIN [Tbl Localidades] AS L ON C.CODLOC = L.CODLOC
                  ORDER BY C.DENCLI;",
        'fechas' => [],
    ],

    // ── Ejemplo 2: con fecha serial Access a convertir ──────────────────
    // 'movimientos' => [
    //     'titulo' => 'Movimientos recientes', 'icono' => 'bi-clock-history',
    //     'sql' => "SELECT TOP 200 M.NUMMOV AS [N°], M.FECMOV AS [Fecha], C.DENCLI AS [Cliente], M.IMPMOV AS [Importe]
    //               FROM [Tbl Movimientos] AS M LEFT JOIN [Tbl Clientes] AS C ON M.CODCLI = C.CODCLI
    //               ORDER BY M.NUMMOV DESC;",
    //     'fechas' => ['Fecha'],
    // ],

];
