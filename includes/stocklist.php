<?php
/**
 * Helper compartido para los Listados de Stock (Rpt SI *).
 * Barra de filtros Categoría / Rubro / Subrubro / Línea / Proveedor / Sucursal (+ Producto),
 * igual semántica que el legacy: combo con código exacto; vacío = todos (el `Like "*"`).
 * Los listados que lo usan: Productos x Código/Denominación/Nivel, Stocks Mínimos, Reposiciones,
 * Precios de Venta, Inventario Actual/Periódico, Movimientos x Producto.
 */
require_once __DIR__ . '/db.php';

/** Lookups cacheados (una sola lectura por request). */
function sl_lookups() {
    static $L = null;
    if ($L !== null) return $L;
    $L = array('cat' => array(), 'rub' => array(), 'sub' => array(), 'lin' => array(),
               'suc' => array(), 'prv' => array(), 'mon' => array(), 'udm' => array());
    foreach (db_query("SELECT CODCAT, DENCAT FROM [Tbl Categorias Productos] ORDER BY DENCAT;") as $r)
        $L['cat'][(string) (int) $r['CODCAT']] = trim((string) nz($r['DENCAT'], ''));
    foreach (db_query("SELECT CODRUB, DENRUB FROM [Tbl Rubros] ORDER BY DENRUB;") as $r)
        $L['rub'][(string) (int) $r['CODRUB']] = trim((string) nz($r['DENRUB'], ''));
    foreach (db_query("SELECT S.CODSUB, S.DENSUB, S.CODRUB, R.DENRUB FROM [Tbl SubRubros] AS S LEFT JOIN [Tbl Rubros] AS R ON R.CODRUB=S.CODRUB ORDER BY R.DENRUB, S.DENSUB;") as $r)
        $L['sub'][(string) (int) $r['CODSUB']] = array('den' => trim((string) nz($r['DENSUB'], '')), 'rub' => (string) (int) $r['CODRUB'],
                                                'lbl' => trim((string) nz($r['DENRUB'], '')) . ' › ' . trim((string) nz($r['DENSUB'], '')));
    foreach (db_query("SELECT CODLIN, DENLIN FROM [Tbl Lineas] ORDER BY DENLIN;") as $r)
        $L['lin'][(string) (int) $r['CODLIN']] = trim((string) nz($r['DENLIN'], ''));
    foreach (db_query("SELECT CODSUC, DENSUC FROM [Tbl Sucursales] ORDER BY DENSUC;") as $r)
        $L['suc'][(string) (int) $r['CODSUC']] = trim((string) nz($r['DENSUC'], ''));
    foreach (db_query("SELECT CODMON, SIMMON FROM [Tbl Monedas];") as $r)
        $L['mon'][(string) $r['CODMON']] = trim((string) nz($r['SIMMON'], ''));
    foreach (db_query("SELECT CODUDM, DENUDM, DECUDM FROM [Tbl Unidades de Medida];") as $r)
        $L['udm'][(string) (int) $r['CODUDM']] = array('den' => trim((string) nz($r['DENUDM'], '')), 'dec' => (int) nz($r['DECUDM'], 0));
    // proveedores = acreedores que son proveedores de algún producto (más útil que los 767 acreedores)
    foreach (db_query("SELECT DISTINCT PP.CODCUE, C.DENCUE FROM [Tbl Productos Proveedores] AS PP INNER JOIN [Tbl Cuentas Corrientes] AS C ON C.CODCUE=PP.CODCUE ORDER BY C.DENCUE;") as $r)
        $L['prv'][(string) (int) $r['CODCUE']] = trim((string) nz($r['DENCUE'], ''));
    return $L;
}

/** Código numérico de clasificación → 4 dígitos con ceros para mostrar ("0009"). */
function sl_pad($v) { return str_pad((string) (int) $v, 4, '0', STR_PAD_LEFT); }

/** Parámetros seleccionados desde $_GET (sólo los que estén entre los lookups válidos). */
function sl_params() {
    $L = sl_lookups();
    $p = array();
    foreach (array('cat' => 'cat', 'rub' => 'rub', 'sub' => 'sub', 'lin' => 'lin', 'suc' => 'suc', 'prv' => 'prv') as $k => $set) {
        $v = isset($_GET[$k]) ? (string) $_GET[$k] : '';
        $p[$k] = ($v !== '' && isset($L[$set][$v])) ? $v : '';
    }
    return $p;
}

/** Fragmento WHERE (sin el "WHERE", con AND inicial) para los filtros de producto.
 *  $cols = mapa parte→columna calificada, ej. ['cat'=>'P.CODCAT','rub'=>'P.CODRUB',...]. */
function sl_where($p, $cols) {
    $w = '';
    foreach ($cols as $k => $col) {
        if ($k === 'prv') continue; // proveedor se maneja aparte (subconsulta)
        if (isset($p[$k]) && $p[$k] !== '')
            $w .= " AND $col = " . (int) $p[$k]; // códigos de clasificación son numéricos (Long)
    }
    return $w;
}

/** Sub-condición EXISTS de proveedor (porta el First(CODPRO) del legacy). $palias = alias de Productos. */
function sl_where_prv($p, $palias) {
    if (empty($p['prv'])) return '';
    return " AND EXISTS (SELECT 1 FROM [Tbl Productos Proveedores] AS _PP WHERE _PP.CODPRO=$palias.CODPRO AND _PP.CODCUE=" . (int) $p['prv'] . ")";
}

/** Render de un <select> de filtro. $set = clave de lookups (cat/rub/sub/lin/suc/prv). */
function sl_select($name, $set, $sel, $label, $extra = '') {
    $L = sl_lookups();
    $opts = '<option value="">(Todos)</option>';
    foreach ($L[$set] as $code => $v) {
        $txt = is_array($v) ? $v['lbl'] : $v;
        $opts .= '<option value="' . h($code) . '"' . ($code === (string) $sel ? ' selected' : '') . '>' . h($txt) . '</option>';
    }
    return '<span class="lst-fpair"><label>' . h($label) . '</label><select name="' . h($name) . '" class="form-select form-select-sm"' . $extra . '>' . $opts . '</select></span>';
}

/** Texto del parámetro para el encabezado del reporte (DENOMINACION seleccionada o ''). */
function sl_param_text($p, $key) {
    $L = sl_lookups();
    $set = $key;
    if (empty($p[$key]) || !isset($L[$set][$p[$key]])) return '';
    $v = $L[$set][$p[$key]];
    return is_array($v) ? (isset($v['den']) ? $v['den'] : $v['lbl']) : $v;
}

/** Script para activar los combos buscables (IWK.combo) sobre la barra de filtro. */
function sl_combo_script() {
    return '<script>document.addEventListener("DOMContentLoaded",function(){if(window.IWK&&IWK.combo){IWK.combo.enhanceForm(document.querySelector(".lst-filter"));}});</script>';
}
