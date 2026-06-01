<?php
/**
 * ============================================================================
 *  ABM genérico (CRUD config-driven) — DEFINICIONES
 * ============================================================================
 *  EJEMPLO / PLANTILLA. Reemplazá las entradas de abajo por los maestros reales
 *  de tu sistema (las tablas de la .mdb). El motor (api.php/index.php) NO se toca:
 *  todo se define acá.
 *
 *  Cada entrada (clave => def) genera una pantalla "form desplegado" con
 *  Nuevo / Guardar / Cancelar / Buscar, accesible por  /modules/abm/?m=<clave>.
 *
 *  Def del maestro:
 *    'tabla'  => 'Tbl X'         tabla Access del maestro
 *    'pk'     => 'CODX'          clave primaria (Long MANUAL, no autonum)
 *    'ult'    => 'ULTX'          contador en [Rec Control] para el próximo número
 *                                (mdlGetNextNumber). Omitir si la PK no se autogenera.
 *    'titulo' => 'X'             rótulo de la pantalla
 *    'icono'  => 'bi-...'        ícono Bootstrap Icons
 *    'orden'  => 'DENX'          columna de ordenamiento de la lista
 *    'campos' => [ ... ]         (ver abajo)
 *    'hijos'  => [ ... ]         sub-tablas / subforms (opcional)
 *
 *  Campo:
 *    ['col'=>'DENX', 'label'=>'Denominación', 'tipo'=>'text',
 *     'req'=>true, 'size'=>50, 'list'=>true, 'lookup'=>$ALGUNA]
 *      tipo  : text | memo | number | decimal | bool | date | select
 *      req   : obligatorio
 *      size  : maxlength (text)
 *      list  : se muestra como columna en la grilla de Buscar
 *      lookup: para 'select' → ['tabla'=>..,'pk'=>..,'den'=>..]
 *
 *  Hijo (sub-tabla editada junto al padre; se borra-reinserta al guardar):
 *    ['key'=>'detalle', 'titulo'=>'Detalle', 'tabla'=>'Tbl X Detalle', 'fk'=>'CODX',
 *     'clave'=>['tipo'=>'auto','col'=>'ORDXXX']                       // línea autonumérica
 *           o  ['tipo'=>'select','col'=>'CODY','label'=>'Y','lookup'=>$Y], // relación M:N
 *     'campos'=>[ ...campos extra de la fila... ]]
 * ============================================================================
 */

// Lookups reutilizables (declarar una vez, usar en varios 'select').
$PROVINCIA = ['tabla' => 'Tbl Provincias', 'pk' => 'CODPRV', 'den' => 'DENPRV'];

return [

    // ── Ejemplo 1: maestro simple ───────────────────────────────────────
    'localidades' => [
        'tabla'  => 'Tbl Localidades', 'pk' => 'CODLOC', 'ult' => 'ULTLOC',
        'titulo' => 'Localidades', 'icono' => 'bi-geo-alt', 'orden' => 'DENLOC',
        'campos' => [
            ['col' => 'DENLOC', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 50, 'list' => true],
            ['col' => 'CPXLOC', 'label' => 'Código Postal', 'tipo' => 'text', 'size' => 10, 'list' => true],
            ['col' => 'CODPRV', 'label' => 'Provincia', 'tipo' => 'select', 'lookup' => $PROVINCIA, 'req' => true, 'list' => true],
        ],
    ],

    // ── Ejemplo 2: maestro con sub-tabla (hijo M:N por 'select') ─────────
    // 'clientes' => [
    //     'tabla' => 'Tbl Clientes', 'pk' => 'CODCLI', 'ult' => 'ULTCLI',
    //     'titulo' => 'Clientes', 'icono' => 'bi-people', 'orden' => 'DENCLI',
    //     'campos' => [
    //         ['col'=>'DENCLI','label'=>'Denominación','tipo'=>'text','req'=>true,'size'=>50,'list'=>true],
    //         ['col'=>'CODLOC','label'=>'Localidad','tipo'=>'select','lookup'=>['tabla'=>'Tbl Localidades','pk'=>'CODLOC','den'=>'DENLOC'],'list'=>true],
    //         ['col'=>'OBSCLI','label'=>'Observaciones','tipo'=>'memo'],
    //     ],
    //     'hijos' => [
    //         ['key'=>'marcas','titulo'=>'Marcas','tabla'=>'Tbl Clientes Marcas','fk'=>'CODCLI',
    //          'clave'=>['tipo'=>'select','col'=>'CODMAR','label'=>'Marca','lookup'=>['tabla'=>'Tbl Marcas','pk'=>'CODMAR','den'=>'DENMAR']],
    //          'campos'=>[['col'=>'OBSCMX','label'=>'Obs','tipo'=>'text','size'=>50,'list'=>true]]],
    //     ],
    // ],

];
