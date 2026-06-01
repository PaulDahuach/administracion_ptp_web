<?php
/**
 * ============================================================================
 *  inforemp-web-kit  —  CONFIGURACIÓN POR SISTEMA
 * ============================================================================
 *
 *  Este es el ÚNICO archivo que se edita al clonar el kit para un cliente.
 *  Copialo a  config/system.php  y completá los valores.
 *
 *  config/system.php está en .gitignore (no se versiona, lleva rutas/secretos).
 *
 *  Patrón heredado de RDN: front PHP que abre la MISMA .mdb que usa el sistema
 *  legacy (VB6/Access) vía COM/ADODB. Cero migración de datos: ambos conviven.
 *  Requiere Windows + driver Microsoft.ACE.OLEDB.12.0 (Access Database Engine).
 * ============================================================================
 */

return [

    // ── Ruta base ────────────────────────────────────────────────────────
    // '' si el sistema vive en la raíz del host (producción).
    // '/carpeta' si corre en un subdirectorio (ej. dev: localhost/carpeta/).
    'base_url'    => '',

    // ── Identidad del sistema ────────────────────────────────────────────
    'name'        => 'NOMBRE DEL SISTEMA',      // ej. 'Producción PTP'
    'short_name'  => 'SISTEMA',                 // título corto en topbar
    'tagline'     => 'Sistema de Gestión',      // bajo el logo en login

    // ── Base de datos Access (.mdb / .accdb) ─────────────────────────────
    // Ruta ABSOLUTA al archivo que usa el sistema legacy. En la PC del
    // cliente suele ser algo como C:\_Inforemp\Sistema.mdb
    'mdb_path'    => 'C:\\_Inforemp\\SISTEMA.mdb',
    // Provider: ACE para .accdb y .mdb modernos. Jet sólo para .mdb viejos.
    'mdb_provider'=> 'Microsoft.ACE.OLEDB.12.0',
    'mdb_pass'    => '',                         // password de la .mdb si tiene

    // ── Modo de operación ────────────────────────────────────────────────
    // 'readonly' bloquea TODA escritura a la .mdb (recomendado al arrancar
    // un sistema nuevo: convive sin riesgo con el legacy). 'readwrite' habilita
    // alta/edición/baja pantalla por pantalla.
    'mode'        => 'readonly',                 // 'readonly' | 'readwrite'

    // ── Autenticación ────────────────────────────────────────────────────
    // Tabla de usuarios del legacy y sus columnas. En RDN: [Tbl Usuarios]
    // con CODUSR (id), DENUSR (nombre), ACCUSR (clave en texto plano).
    'auth' => [
        'table'    => 'Tbl Usuarios',
        'col_id'   => 'CODUSR',
        'col_name' => 'DENUSR',
        'col_pass' => 'ACCUSR',
    ],

    // ── Branding ─────────────────────────────────────────────────────────
    'logo'        => '/assets/img/logo.png',    // poné el logo del cliente acá
    'primary'     => '#2563eb',                 // color de acento
    'theme'       => 'dark',                     // tema por defecto: 'dark'|'light'

    // ── Menú del dashboard ───────────────────────────────────────────────
    // Grupos → tarjetas. 'url' apunta a /modules/<slug>/ .
    // Quitá/agregá lo que el sistema tenga. Cada módulo se porta aparte.
    'menu' => [
        'Consultas' => [
            ['label' => 'Ejemplo',  'desc' => 'Módulo de ejemplo', 'icon' => 'bi-table', 'url' => '/modules/_template/'],
        ],
    ],

    // ── AFIP (opcional) ──────────────────────────────────────────────────
    // Si el sistema emite comprobantes electrónicos. Dejar enabled=false si no.
    'afip' => [
        'enabled' => false,
        'modo'    => 'testing',                  // 'testing' | 'produccion'
        'cuit'    => '',
        'cert'    => __DIR__ . '/certs/cert.crt',
        'key'     => __DIR__ . '/certs/key.rsa',
    ],

    // ── Tablero del dashboard (OPCIONAL) ─────────────────────────────────
    // El dashboard (app/index.php) muestra sidebar (desde 'menu') + indicadores
    // (KPIs) + accesos rápidos. Si omitís 'dashboard', muestra solo el saludo.
    //   kpis  : cada uno corre una SQL que devuelve UN número (Count/Sum...).
    //           {SECTOR} se reemplaza por el sector activo (si usás sector_login).
    //   quick : botones de acceso directo a las pantallas más usadas.
    // 'dashboard' => [
    //     'kpis' => [
    //         ['label'=>'Pendientes', 'icon'=>'bi-inbox', 'color'=>'#0ea5e9', 'url'=>'/modules/x/',
    //          'sql'=>"SELECT Count(*) AS N FROM [Tbl X] WHERE ESTADO=1;"],
    //     ],
    //     'quick' => [
    //         ['label'=>'Nuevo', 'icon'=>'bi-plus-lg', 'url'=>'/modules/x/'],
    //     ],
    // ],

    // ── Deploy ───────────────────────────────────────────────────────────
    // Clave del endpoint deploy.php (curl). Cambiala por sistema.
    'deploy_key'  => 'CAMBIAR_ESTA_CLAVE',
];
