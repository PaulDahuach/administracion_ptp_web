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
 *      suffix: sufijo al lado del campo (ej. '%', 'Días')
 *      ancho : 'narrow' | 'mid' | 'wide' — fuerza el ancho (default según tipo)
 *      req   : obligatorio · default: valor si viene vacío (ej. 0, dummy CUIT)
 *      ro    : true → read-only (se muestra, no se graba; ej. saldos/fechas calculadas)
 *      cuit  : true → valida/normaliza C.U.I.T. (XX-XXXXXXXX-X)
 *      big   : true (en 'select') → autocomplete server-side (lookups grandes)
 *      strkey: true (en 'select') → la clave foránea es texto, no entero (no intval; ej. cuenta contable)
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
$CAT_RESP_IVA = ['tabla' => 'Tbl Categorias Responsabilidad IVA', 'pk' => 'CODCRI', 'den' => 'DENCRI'];
// Localidad: 19.502 filas → lookup 'big' (autocomplete). 'cod'=CPXLOC (cód. postal) se muestra al costado.
$LOCALIDAD = ['tabla' => 'Tbl Localidades', 'pk' => 'CODLOC', 'den' => 'DENLOC', 'cod' => 'CPXLOC'];
$COND_VENTA = ['tabla' => 'Tbl Condiciones de Venta', 'pk' => 'CODCDV', 'den' => 'DENCDV'];
$ZONA = ['tabla' => 'Tbl Zonas', 'pk' => 'CODZON', 'den' => 'DENZON'];
$VENDEDOR = ['tabla' => 'Tbl Vendedores', 'pk' => 'CODVEN', 'den' => 'DENVEN'];
$TRANSPORTE = ['tabla' => 'Tbl Transportes', 'pk' => 'CODTRA', 'den' => 'DENTRA'];
// Categoría de cliente: misma tabla que Categorías, scopeada a deudores.
$CAT_CLIENTE = ['tabla' => 'Tbl Categorias Cuentas Corrientes', 'pk' => 'CODCAT', 'den' => 'DENCAT', 'where' => "CODORI='D'"];
$BANCO = ['tabla' => 'Tbl Bancos', 'pk' => 'CODBAN', 'den' => 'DENBAN'];   // 143 → select buscable
$CAT_PROV = ['tabla' => 'Tbl Categorias Cuentas Corrientes', 'pk' => 'CODCAT', 'den' => 'DENCAT', 'where' => "CODORI='A'"];
$REGIMEN_IIBB = ['tabla' => 'Tbl Regimenes Retencion Ingresos Brutos', 'pk' => 'CODRRI', 'den' => 'DENRRI'];   // 2 regímenes
// Cuenta contable de imputación de compras: 441 imputables, clave STRING (CODCUE, ej. "11101") → big + strkey.
$CTA_CONTABLE_IMP = ['tabla' => 'Tbl Cuentas Contables', 'pk' => 'CODCUE', 'den' => 'DENCUE', 'cod' => 'CODCUE', 'where' => 'IMPCUE=True'];
$RUBRO = ['tabla' => 'Tbl Rubros', 'pk' => 'CODRUB', 'den' => 'DENRUB'];

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

    // ── Transportes (Frm CD Transportes) ────────────────────────────────
    //  CUIT validado (dígito verificador); el dummy "00-00000000-0" puede repetirse.
    //  Localidad = lookup 'big' (autocomplete). Cat. Resp. IVA = select normal (6 opc).
    'transportes' => [
        'tabla'  => 'Tbl Transportes', 'pk' => 'CODTRA', 'ult' => 'ULTTRA',
        'titulo' => 'Transportes', 'icono' => 'bi-truck', 'orden' => 'DENTRA',
        'unico'  => ['DENTRA', ['col' => 'CITTRA', 'except' => '00-00000000-0']],
        'uso'    => [['tabla' => 'Tbl Movimientos', 'col' => 'CODTRA', 'msg' => 'No se puede eliminar: el transporte tiene movimientos asociados.']],
        'campos' => [
            ['col' => 'CITTRA', 'label' => 'C.U.I.T.', 'tipo' => 'text', 'cuit' => true, 'size' => 13, 'ancho' => 'narrow', 'list' => true],
            ['col' => 'CODCRI', 'label' => 'Cat. Resp. I.V.A.', 'tipo' => 'select', 'lookup' => $CAT_RESP_IVA, 'req' => true],
            ['col' => 'DENTRA', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
            ['col' => 'DOMTRA', 'label' => 'Domicilio', 'tipo' => 'text', 'size' => 30],
            ['col' => 'CODLOC', 'label' => 'Localidad', 'tipo' => 'select', 'big' => true, 'lookup' => $LOCALIDAD, 'search' => ['DENLOC', 'CPXLOC'], 'list' => true],
            ['col' => 'TELTRA', 'label' => 'Teléfono', 'tipo' => 'text', 'size' => 30],
            ['col' => 'FAXTRA', 'label' => 'Fax', 'tipo' => 'text', 'size' => 30],
            ['col' => 'DEMTRA', 'label' => 'e-mail', 'tipo' => 'text', 'size' => 30],
            ['col' => 'CONTRA', 'label' => 'Contacto Comercial', 'tipo' => 'text', 'size' => 30],
            ['col' => 'OBSTRA', 'label' => 'Observaciones', 'tipo' => 'memo'],
        ],
    ],

    // ── Cuentas Corrientes Deudoras (Frm CD Cuentas) ────────────────────
    //  El maestro central de clientes. Scope CODORI='D'. Al alta: saldos en 0 y
    //  FDACUE = fecha de apertura del ejercicio (Rec Control.FECAPE), como el legacy.
    //  Saldos y fechas de operación son read-only (los mueve el ledger, no se editan).
    //  Subform EXCLUSIONES: diferido (la tabla está vacía en este backend).
    'cc_deudores' => [
        'tabla'  => 'Tbl Cuentas Corrientes', 'pk' => 'CODCUE', 'ult' => 'ULTCUE',
        'titulo' => 'Cuentas Corrientes (Deudores)', 'icono' => 'bi-person-vcard', 'orden' => 'DENCUE',
        'fijo'   => ['CODORI' => 'D'],
        'alta'   => ['SOPCUE' => 0, 'SANCUE' => 0, 'SACCUE' => 0, 'FDACUE' => ['rec' => 'FECAPE', 'tipo' => 'date']],
        'unico'  => ['DENCUE'],   // único entre deudores (scope CODORI='D'). El CUIT puede repetirse (el legacy lo permite).
        'uso'    => [
            ['tabla' => 'Tbl Movimientos', 'col' => 'CODCUE', 'msg' => 'No se puede eliminar: la cuenta tiene movimientos asociados.'],
            ['tabla' => 'Tbl Cuentas Corrientes Exclusiones', 'col' => 'CODCUE', 'msg' => 'No se puede eliminar: la cuenta tiene exclusiones de productos.'],
        ],
        'campos' => [
            ['col' => 'CITCUE', 'label' => 'C.U.I.T.', 'tipo' => 'text', 'cuit' => true, 'size' => 13, 'ancho' => 'narrow', 'default' => '00-00000000-0', 'list' => true],
            ['col' => 'RCCCUE', 'label' => 'Constancia', 'tipo' => 'bool'],
            ['col' => 'CODCRI', 'label' => 'Cat. Resp. I.V.A.', 'tipo' => 'select', 'lookup' => $CAT_RESP_IVA, 'req' => true],
            ['col' => 'DENCUE', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 50, 'list' => true],
            ['col' => 'CODCAT', 'label' => 'Categoría Cliente', 'tipo' => 'select', 'lookup' => $CAT_CLIENTE, 'req' => true, 'list' => true],
            ['col' => 'DCXCUE', 'label' => 'Calle', 'tipo' => 'text', 'req' => true, 'size' => 30],
            ['col' => 'DNXCUE', 'label' => 'Número', 'tipo' => 'text', 'size' => 10, 'ancho' => 'narrow'],
            ['col' => 'DPXCUE', 'label' => 'Piso', 'tipo' => 'text', 'size' => 5, 'ancho' => 'narrow'],
            ['col' => 'DDXCUE', 'label' => 'Depto.', 'tipo' => 'text', 'size' => 5, 'ancho' => 'narrow'],
            ['col' => 'CODLOC', 'label' => 'Localidad', 'tipo' => 'select', 'big' => true, 'lookup' => $LOCALIDAD, 'search' => ['DENLOC', 'CPXLOC'], 'req' => true, 'list' => true],
            ['col' => 'TELCUE', 'label' => 'Teléfonos', 'tipo' => 'text', 'size' => 30],
            ['col' => 'FAXCUE', 'label' => 'Fax', 'tipo' => 'text', 'size' => 30],
            ['col' => 'DEMCUE', 'label' => 'e-mail', 'tipo' => 'text', 'size' => 255],
            ['col' => 'CONCUE', 'label' => 'Contacto Comercial', 'tipo' => 'text', 'size' => 30],
            ['col' => 'CODCDV', 'label' => 'Condición de Venta', 'tipo' => 'select', 'lookup' => $COND_VENTA, 'req' => true],
            ['col' => 'DVFCUE', 'label' => 'Plazo de Pago', 'tipo' => 'number', 'min' => 0, 'max' => 365, 'suffix' => 'Días', 'default' => 0],
            ['col' => 'HPXCUE', 'label' => 'Historial Productos', 'tipo' => 'bool'],
            ['col' => 'CODZON', 'label' => 'Zona', 'tipo' => 'select', 'lookup' => $ZONA],
            ['col' => 'CODVEN', 'label' => 'Vendedor', 'tipo' => 'select', 'lookup' => $VENDEDOR],
            ['col' => 'CODTRA', 'label' => 'Transporte', 'tipo' => 'select', 'lookup' => $TRANSPORTE],
            ['col' => 'DHECUE', 'label' => 'Días y Horarios de Entrega', 'tipo' => 'memo', 'size' => 50],
            ['col' => 'SPICUE', 'label' => 'Percepción Ingresos Brutos', 'tipo' => 'bool'],
            ['col' => 'OBSCUE', 'label' => 'Observaciones', 'tipo' => 'memo'],
            ['col' => 'FDACUE', 'label' => 'Fecha de Alta', 'tipo' => 'date', 'ro' => true],
            ['col' => 'FUOCUE', 'label' => 'Fecha Última Operación', 'tipo' => 'date', 'ro' => true],
            ['col' => 'SOPCUE', 'label' => 'Saldo Operativo', 'tipo' => 'decimal', 'ro' => true],
            ['col' => 'SANCUE', 'label' => 'Saldo Anticipos', 'tipo' => 'decimal', 'ro' => true],
        ],
    ],

    // ── Cuentas Corrientes Acreedoras (Frm CA Cuentas) ──────────────────
    //  Espejo del de deudores (CODORI='A'). Particular: CPACUE (cuenta contable de
    //  imputación de compras, clave string → autocomplete imputables); CITCUE único
    //  entre acreedores (acá sí bloquea, salvo dummy); retención IIBB (VEICUE venc.
    //  exención / SRICUE retención / CODRRI régimen). Sin Condición de Venta/Zona/
    //  Vendedor/Transporte/Historial. Al alta: SOPCUE/SANCUE=0 + FDACUE=FECAPE (sin SACCUE).
    //  OJO legacy (UX no portada): si CODCAT=1 (PRODUCTOS) deshabilita CPACUE; y el venc.
    //  de exención (VEICUE) auto-togglea SRICUE/CODRRI. Acá van como campos planos.
    'cc_acreedores' => [
        'tabla'  => 'Tbl Cuentas Corrientes', 'pk' => 'CODCUE', 'ult' => 'ULTCUE',
        'titulo' => 'Cuentas Corrientes (Acreedores)', 'icono' => 'bi-person-vcard', 'orden' => 'DENCUE',
        'fijo'   => ['CODORI' => 'A'],
        'alta'   => ['SOPCUE' => 0, 'SANCUE' => 0, 'FDACUE' => ['rec' => 'FECAPE', 'tipo' => 'date']],
        'unico'  => ['DENCUE', ['col' => 'CITCUE', 'except' => '00-00000000-0']],
        'uso'    => [
            // (el legacy chequea Tbl Productos.CODPRV y Rec Control.CODPRV, columnas que no existen
            //  en este backend; el vínculo proveedor↔producto vive en Tbl Productos Proveedores.CODCUE)
            ['tabla' => 'Tbl Productos Proveedores', 'col' => 'CODCUE', 'msg' => 'No se puede eliminar: el proveedor está asignado a productos.'],
            ['tabla' => 'Tbl Movimientos', 'col' => 'CODCUE', 'msg' => 'No se puede eliminar: la cuenta tiene movimientos asociados.'],
        ],
        'campos' => [
            ['col' => 'CITCUE', 'label' => 'C.U.I.T.', 'tipo' => 'text', 'cuit' => true, 'size' => 13, 'ancho' => 'narrow', 'default' => '00-00000000-0', 'list' => true],
            ['col' => 'CODCRI', 'label' => 'Cat. Resp. I.V.A.', 'tipo' => 'select', 'lookup' => $CAT_RESP_IVA, 'req' => true],
            ['col' => 'DENCUE', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 50, 'list' => true],
            ['col' => 'CODCAT', 'label' => 'Categoría Proveedor', 'tipo' => 'select', 'lookup' => $CAT_PROV, 'req' => true, 'list' => true],
            ['col' => 'CPACUE', 'label' => 'Cta. Contable Imput. Compras', 'tipo' => 'select', 'big' => true, 'strkey' => true, 'lookup' => $CTA_CONTABLE_IMP, 'search' => ['CODCUE', 'DENCUE']],
            ['col' => 'DCXCUE', 'label' => 'Calle', 'tipo' => 'text', 'req' => true, 'size' => 30],
            ['col' => 'DNXCUE', 'label' => 'Número', 'tipo' => 'text', 'size' => 10, 'ancho' => 'narrow'],
            ['col' => 'DPXCUE', 'label' => 'Piso', 'tipo' => 'text', 'size' => 5, 'ancho' => 'narrow'],
            ['col' => 'DDXCUE', 'label' => 'Depto.', 'tipo' => 'text', 'size' => 5, 'ancho' => 'narrow'],
            ['col' => 'CODLOC', 'label' => 'Localidad', 'tipo' => 'select', 'big' => true, 'lookup' => $LOCALIDAD, 'search' => ['DENLOC', 'CPXLOC'], 'req' => true, 'list' => true],
            ['col' => 'TELCUE', 'label' => 'Teléfonos', 'tipo' => 'text', 'size' => 30],
            ['col' => 'FAXCUE', 'label' => 'Fax', 'tipo' => 'text', 'size' => 30],
            ['col' => 'DEMCUE', 'label' => 'e-mail', 'tipo' => 'text', 'size' => 30],
            ['col' => 'CONCUE', 'label' => 'Contacto Comercial', 'tipo' => 'text', 'size' => 30],
            ['col' => 'DVFCUE', 'label' => 'Plazo de Pago', 'tipo' => 'number', 'min' => 0, 'max' => 365, 'suffix' => 'Días', 'default' => 0],
            ['col' => 'APICUE', 'label' => 'Agente de Percepción I.V.A.', 'tipo' => 'bool'],
            ['col' => 'APBCUE', 'label' => 'Agente de Percepción I.B.', 'tipo' => 'bool'],
            ['col' => 'VEICUE', 'label' => 'Vencimiento Exención (Ret. IIBB)', 'tipo' => 'date'],
            ['col' => 'SRICUE', 'label' => 'Retención IIBB', 'tipo' => 'bool'],
            ['col' => 'CODRRI', 'label' => 'Régimen Retención IIBB', 'tipo' => 'select', 'lookup' => $REGIMEN_IIBB],
            ['col' => 'EEHCUE', 'label' => 'Excluir de Export. I.V.A. a Holistor', 'tipo' => 'bool'],
            ['col' => 'OBSCUE', 'label' => 'Observaciones', 'tipo' => 'memo'],
            ['col' => 'FDACUE', 'label' => 'Fecha de Alta', 'tipo' => 'date', 'ro' => true],
            ['col' => 'FUOCUE', 'label' => 'Fecha Última Operación', 'tipo' => 'date', 'ro' => true],
            ['col' => 'SOPCUE', 'label' => 'Saldo Operativo', 'tipo' => 'decimal', 'ro' => true],
            ['col' => 'SANCUE', 'label' => 'Saldo Anticipos', 'tipo' => 'decimal', 'ro' => true],
        ],
    ],

    // ── Centros de Costo (Frm IC Centros de Costo) ──────────────────────
    'centros_costo' => [
        'tabla'  => 'Tbl Centros de Costo', 'pk' => 'CODCDC', 'ult' => 'ULTCDC',
        'titulo' => 'Centros de Costo', 'icono' => 'bi-diagram-3', 'orden' => 'DENCDC',
        'unico'  => ['DENCDC'],
        'uso'    => [
            ['tabla' => 'Tbl Modelos Imputaciones', 'col' => 'CODCDC', 'msg' => 'No se puede eliminar: el centro de costo se usa en modelos de imputación.'],
            ['tabla' => 'Tbl Movimientos Imputaciones', 'col' => 'CODCDC', 'msg' => 'No se puede eliminar: el centro de costo tiene imputaciones asociadas.'],
        ],
        'campos' => [
            ['col' => 'DENCDC', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
        ],
    ],

    // ── Bancos (Frm IC Bancos) ───────────────────────────────────────────
    'bancos' => [
        'tabla'  => 'Tbl Bancos', 'pk' => 'CODBAN', 'ult' => 'ULTBAN',
        'titulo' => 'Bancos', 'icono' => 'bi-bank', 'orden' => 'DENBAN',
        'unico'  => ['DENBAN'],
        'uso'    => [
            ['tabla' => 'Tbl Cuentas Bancarias', 'col' => 'CODBAN', 'msg' => 'No se puede eliminar: el banco se usa en cuentas bancarias.'],
            ['tabla' => 'Tbl Cheques', 'col' => 'CODBAN', 'msg' => 'No se puede eliminar: el banco tiene cheques asociados.'],
        ],
        'campos' => [
            ['col' => 'DENBAN', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
            ['col' => 'CITBAN', 'label' => 'C.U.I.T.', 'tipo' => 'text', 'cuit' => true, 'size' => 13, 'ancho' => 'narrow', 'list' => true],
        ],
    ],

    // ── Cuentas Bancarias (Frm IC Cuentas Bancarias) ────────────────────
    'cuentas_bancarias' => [
        'tabla'  => 'Tbl Cuentas Bancarias', 'pk' => 'CODCBX', 'ult' => 'ULTCBX',
        'titulo' => 'Cuentas Bancarias', 'icono' => 'bi-piggy-bank', 'orden' => 'DENCBX',
        'unico'  => ['DENCBX'],
        'uso'    => [
            ['tabla' => 'Tbl Cuentas Contables', 'col' => 'CODCBX', 'msg' => 'No se puede eliminar: la cuenta bancaria está vinculada a una cuenta contable.'],
            ['tabla' => 'Tbl Movimientos', 'col' => 'CODCBX', 'msg' => 'No se puede eliminar: la cuenta bancaria tiene movimientos asociados.'],
        ],
        'campos' => [
            ['col' => 'DENCBX', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
            ['col' => 'CCDCBX', 'label' => 'Copias Constancia Depósito', 'tipo' => 'number', 'req' => true, 'min' => 0, 'max' => 3],
            ['col' => 'CODBAN', 'label' => 'Banco', 'tipo' => 'select', 'lookup' => $BANCO, 'req' => true, 'list' => true],
            ['col' => 'DISCBX', 'label' => 'Discontinuada', 'tipo' => 'bool'],
        ],
    ],

    // ── Stock: Líneas (Frm SI Lineas) ───────────────────────────────────
    'lineas' => [
        'tabla'  => 'Tbl Lineas', 'pk' => 'CODLIN', 'ult' => 'ULTLIN',
        'titulo' => 'Líneas', 'icono' => 'bi-bookmark', 'orden' => 'DENLIN',
        'unico'  => ['DENLIN'],
        'uso'    => [['tabla' => 'Tbl Productos', 'col' => 'CODLIN', 'msg' => 'No se puede eliminar: la línea está asignada a productos.']],
        'campos' => [['col' => 'DENLIN', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true]],
    ],

    // ── Stock: Unidades de Medida (Frm SI Unidades de Medida) ───────────
    'unidades' => [
        'tabla'  => 'Tbl Unidades de Medida', 'pk' => 'CODUDM', 'ult' => 'ULTUDM',
        'titulo' => 'Unidades de Medida', 'icono' => 'bi-rulers', 'orden' => 'DENUDM',
        'unico'  => ['DENUDM'],
        'uso'    => [
            ['tabla' => 'Tbl Productos', 'col' => 'CODUDM', 'msg' => 'No se puede eliminar: la unidad está asignada a productos.'],
            ['tabla' => 'Tbl Productos Unidades', 'col' => 'CODUDM', 'msg' => 'No se puede eliminar: la unidad se usa en equivalencias de productos.'],
        ],
        'campos' => [
            ['col' => 'DENUDM', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
            ['col' => 'DECUDM', 'label' => 'Decimales', 'tipo' => 'number', 'req' => true, 'min' => 0, 'max' => 4, 'list' => true],
        ],
    ],

    // ── Stock: Rubros (Frm SI Rubros) ───────────────────────────────────
    //  Cuentas contables de imputación compras/ventas = clave string → autocomplete imputables.
    //  Los Subrubros se editan como maestro aparte ('subrubros') porque tienen PK propia
    //  referenciada por Productos (no sirve el patrón hijo borrar-reinsertar).
    'rubros' => [
        'tabla'  => 'Tbl Rubros', 'pk' => 'CODRUB', 'ult' => 'ULTRUB',
        'titulo' => 'Rubros', 'icono' => 'bi-tag', 'orden' => 'DENRUB',
        'unico'  => ['DENRUB'],
        'uso'    => [
            ['tabla' => 'Tbl Productos', 'col' => 'CODRUB', 'msg' => 'No se puede eliminar: el rubro está asignado a productos.'],
            ['tabla' => 'Tbl SubRubros', 'col' => 'CODRUB', 'msg' => 'No se puede eliminar: el rubro tiene subrubros.'],
        ],
        'campos' => [
            ['col' => 'DENRUB', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
            ['col' => 'CPARUB', 'label' => 'Cta. Contable Imput. Compras', 'tipo' => 'select', 'big' => true, 'strkey' => true, 'lookup' => $CTA_CONTABLE_IMP, 'search' => ['CODCUE', 'DENCUE']],
            ['col' => 'VTARUB', 'label' => 'Cta. Contable Imput. Ventas', 'tipo' => 'select', 'big' => true, 'strkey' => true, 'lookup' => $CTA_CONTABLE_IMP, 'search' => ['CODCUE', 'DENCUE']],
            ['col' => 'PUNRUB', 'label' => 'Utilidad Neta x Alta de Productos', 'tipo' => 'decimal', 'min' => 0, 'max' => 100, 'suffix' => '%'],
            ['col' => 'DISRUB', 'label' => 'Discontinuado', 'tipo' => 'bool'],
        ],
    ],

    // ── Stock: Subrubros (porta el subform de Frm SI Rubros como maestro propio) ──
    'subrubros' => [
        'tabla'  => 'Tbl SubRubros', 'pk' => 'CODSUB', 'ult' => 'ULTSUB',
        'titulo' => 'Subrubros', 'icono' => 'bi-tags', 'orden' => 'DENSUB',
        'uso'    => [['tabla' => 'Tbl Productos', 'col' => 'CODSUB', 'msg' => 'No se puede eliminar: el subrubro está asignado a productos.']],
        'campos' => [
            ['col' => 'CODRUB', 'label' => 'Rubro', 'tipo' => 'select', 'lookup' => $RUBRO, 'req' => true, 'list' => true],
            ['col' => 'DENSUB', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 30, 'list' => true],
            ['col' => 'CPASUB', 'label' => 'Cta. Contable Imput. Compras', 'tipo' => 'select', 'big' => true, 'strkey' => true, 'lookup' => $CTA_CONTABLE_IMP, 'search' => ['CODCUE', 'DENCUE']],
            ['col' => 'VTASUB', 'label' => 'Cta. Contable Imput. Ventas', 'tipo' => 'select', 'big' => true, 'strkey' => true, 'lookup' => $CTA_CONTABLE_IMP, 'search' => ['CODCUE', 'DENCUE']],
            ['col' => 'PUNSUB', 'label' => 'Utilidad %', 'tipo' => 'decimal', 'min' => 0, 'max' => 100, 'suffix' => '%'],
            ['col' => 'DISSUB', 'label' => 'Discontinuado', 'tipo' => 'bool'],
        ],
    ],

    // ── Ejemplo 1: maestro simple ───────────────────────────────────────
    'localidades' => [
        'tabla'  => 'Tbl Localidades', 'pk' => 'CODLOC', 'ult' => 'ULTLOC',
        'titulo' => 'Localidades', 'icono' => 'bi-geo-alt', 'orden' => 'DENLOC',
        'buscable' => true,   // 19.502 filas → Buscar server-side (TOP 50 sin término, TOP 200 filtrado)
        'campos' => [
            ['col' => 'CPXLOC', 'label' => 'Código Postal', 'tipo' => 'text', 'size' => 10, 'list' => true],
            ['col' => 'DENLOC', 'label' => 'Denominación', 'tipo' => 'text', 'req' => true, 'size' => 50, 'list' => true],
            ['col' => 'PIULOC', 'label' => 'Prefijo InterUrbano', 'tipo' => 'text', 'size' => 8],
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
