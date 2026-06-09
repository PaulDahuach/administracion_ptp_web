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
 *    'fijo'   => ['CODORI'=>'D'] columnas constantes: se setean al alta y SCOPEAN
 *                                list/get/tope (cuando una misma tabla guarda varios
 *                                orígenes, ej. categorías deudores 'D' vs acreedores 'A')
 *    'unico'  => ['DENX']        columna(s) que no pueden repetirse (chequeo global al guardar)
 *    'tope'   => 10              cantidad máxima de registros (dentro del scope 'fijo')
 *    'uso'    => [['tabla'=>'Tbl Y','col'=>'CODX','msg'=>'...']]
 *                                bloquea el borrado si el registro está referenciado (DelData)
 *    'campos' => [ ... ]         (ver abajo)
 *    'hijos'  => [ ... ]         sub-tablas / subforms (opcional)
 *
 *  Campo:
 *    ['col'=>'DENX', 'label'=>'Denominación', 'tipo'=>'text',
 *     'req'=>true, 'size'=>50, 'list'=>true, 'lookup'=>$ALGUNA]
 *      tipo  : text | memo | number | decimal | bool | date | select
 *      req   : obligatorio
 *      size  : maxlength (text)
 *      min/max: rango permitido (number/decimal)
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
$PROVINCIA = ['tabla' => 'Tbl Provincias', 'pk' => 'CODPRO', 'den' => 'DENPRO'];

return [

    // ── Categorías de Cuentas Corrientes — Deudores (Frm CD Categorias) ──
    //  La tabla guarda deudores ('D') y acreedores ('A') → scope por CODORI.
    //  Reglas legacy: DENCAT único (global), máx 10 (deudores), no borrar si está
    //  asignada a una cuenta corriente. ALICAT solo aplica a acreedores (no se edita acá).
    'cat_deudores' => [
        'tabla'  => 'Tbl Categorias Cuentas Corrientes', 'pk' => 'CODCAT', 'ult' => 'ULTCAT',
        'titulo' => 'Categorías (Deudores)', 'icono' => 'bi-tags', 'orden' => 'DENCAT',
        'fijo'   => ['CODORI' => 'D'],
        'unico'  => ['DENCAT'],
        'tope'   => 10,
        'uso'    => [['tabla' => 'Tbl Cuentas Corrientes', 'col' => 'CODCAT',
                      'msg' => 'No se puede eliminar: la categoría está asignada a una o más cuentas corrientes.']],
        'campos' => [
            ['col' => 'DENCAT', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
            ['col' => 'LDPCAT', 'label' => 'Descuento', 'tipo' => 'decimal', 'req' => true, 'min' => 0, 'max' => 100, 'suffix' => '%', 'list' => true],
        ],
    ],

    // ── Categorías de Cuentas Corrientes — Acreedores ───────────────────
    //  No existía en el legacy (los acreedores nunca se cargaron a mano) → pantalla
    //  nueva, por si alguna vez hace falta. Misma tabla, scope CODORI='A'. Acá lo
    //  relevante es la alícuota de IVA (ALICAT, ej. 21/27); el "descuento" (LDPCAT)
    //  es un concepto de deudores, no se edita (queda Null). Sin tope (el máx 10 del
    //  legacy era solo para deudores).
    'cat_acreedores' => [
        'tabla'  => 'Tbl Categorias Cuentas Corrientes', 'pk' => 'CODCAT', 'ult' => 'ULTCAT',
        'titulo' => 'Categorías (Acreedores)', 'icono' => 'bi-tags', 'orden' => 'DENCAT',
        'fijo'   => ['CODORI' => 'A'],
        'unico'  => ['DENCAT'],
        'uso'    => [['tabla' => 'Tbl Cuentas Corrientes', 'col' => 'CODCAT',
                      'msg' => 'No se puede eliminar: la categoría está asignada a una o más cuentas de proveedor.']],
        'campos' => [
            ['col' => 'DENCAT', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
            ['col' => 'ALICAT', 'label' => 'Alícuota IVA', 'tipo' => 'decimal', 'req' => true, 'min' => 0, 'max' => 100, 'suffix' => '%', 'list' => true],
        ],
    ],

    // ── Vendedores (Frm CD Vendedores) ──────────────────────────────────
    'vendedores' => [
        'tabla'  => 'Tbl Vendedores', 'pk' => 'CODVEN', 'ult' => 'ULTVEN',
        'titulo' => 'Vendedores', 'icono' => 'bi-person-badge', 'orden' => 'DENVEN',
        'unico'  => ['DENVEN'],
        'uso'    => [
            ['tabla' => 'Tbl Cuentas Corrientes', 'col' => 'CODVEN', 'msg' => 'No se puede eliminar: el vendedor está asignado a una o más cuentas corrientes.'],
            ['tabla' => 'Tbl Movimientos', 'col' => 'CODVEN', 'msg' => 'No se puede eliminar: el vendedor tiene movimientos asociados.'],
        ],
        'campos' => [
            ['col' => 'DENVEN', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
        ],
    ],

    // ── Zonas (Frm CD Zonas) ─────────────────────────────────────────────
    'zonas' => [
        'tabla'  => 'Tbl Zonas', 'pk' => 'CODZON', 'ult' => 'ULTZON',
        'titulo' => 'Zonas', 'icono' => 'bi-geo', 'orden' => 'DENZON',
        'unico'  => ['DENZON'],
        'uso'    => [
            ['tabla' => 'Tbl Cuentas Corrientes', 'col' => 'CODZON', 'msg' => 'No se puede eliminar: la zona está asignada a una o más cuentas corrientes.'],
            ['tabla' => 'Tbl Movimientos', 'col' => 'CODZON', 'msg' => 'No se puede eliminar: la zona tiene movimientos asociados.'],
        ],
        'campos' => [
            ['col' => 'DENZON', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
        ],
    ],

    // ── Formas de Pago Cuenta Corriente (Frm CD Formas de Pago) ──────────
    //  Comparte la Tbl Formas de Pago con otras condiciones; esta pantalla es solo
    //  la de cuenta corriente → scope CODCDV=2 (como el WHERE del Form_Open legacy).
    'formas_pago_ctacte' => [
        'tabla'  => 'Tbl Formas de Pago', 'pk' => 'CODFDP', 'ult' => 'ULTFDP',
        'titulo' => 'Formas de Pago Cta.Cte.', 'icono' => 'bi-wallet2', 'orden' => 'DENFDP',
        'fijo'   => ['CODCDV' => 2],
        'unico'  => ['DENFDP'],
        'uso'    => [['tabla' => 'Tbl Movimientos', 'col' => 'CODFDP', 'msg' => 'No se puede eliminar: la forma de pago tiene movimientos asociados.']],
        'campos' => [
            ['col' => 'DENFDP', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
            ['col' => 'DVFFDP', 'label' => 'Plazo de Pago', 'tipo' => 'number', 'req' => true, 'min' => 0, 'max' => 365, 'suffix' => 'Días', 'list' => true],
        ],
    ],

    // ── Ejemplo 1: maestro simple ───────────────────────────────────────
    'localidades' => [
        'tabla'  => 'Tbl Localidades', 'pk' => 'CODLOC', 'ult' => 'ULTLOC',
        'titulo' => 'Localidades', 'icono' => 'bi-geo-alt', 'orden' => 'DENLOC',
        'campos' => [
            ['col' => 'DENLOC', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 50, 'list' => true],
            ['col' => 'CPXLOC', 'label' => 'Código Postal', 'tipo' => 'text', 'size' => 10, 'list' => true],
            ['col' => 'CODPRO', 'label' => 'Provincia', 'tipo' => 'select', 'lookup' => $PROVINCIA, 'req' => true, 'list' => true],
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
