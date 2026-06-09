<?php
/** Productos y Servicios — maestro central de stock. Porta Frm SI Productos. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$ro = db_readonly();
$lk = array(
    'cat' => db_query("SELECT CODCAT AS id, DENCAT AS den, STKCAT AS stk FROM [Tbl Categorias Productos] ORDER BY CODCAT;"),
    'rub' => db_query("SELECT CODRUB AS id, DENRUB AS den, PUNRUB AS pun FROM [Tbl Rubros] ORDER BY DENRUB;"),
    'sub' => db_query("SELECT CODSUB AS id, CODRUB AS rub, DENSUB AS den, PUNSUB AS pun FROM [Tbl SubRubros] ORDER BY DENSUB;"),
    'lin' => db_query("SELECT CODLIN AS id, DENLIN AS den FROM [Tbl Lineas] ORDER BY DENLIN;"),
    'udm' => db_query("SELECT CODUDM AS id, DENUDM AS den FROM [Tbl Unidades de Medida] ORDER BY DENUDM;"),
    'mon' => db_query("SELECT CODMON AS id, DENMON AS den, COTMON AS cot FROM [Tbl Monedas] ORDER BY DENMON;"),
    // categorías de cliente (descuentos) para recalcular precios de venta en vivo
    'catcli' => db_query("SELECT DENCAT AS den, LDPCAT AS ldp FROM [Tbl Categorias Cuentas Corrientes] WHERE CODORI='D' ORDER BY DENCAT;"),
);

$toolbar = '<div class="btn-group me-2">';
if (!$ro) $toolbar .=
    '<button id="btnNuevo" class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Nuevo</button>' .
    '<button id="btnGuardar" class="btn btn-primary btn-sm" disabled><i class="bi bi-check-lg me-1"></i>Guardar</button>' .
    '<button id="btnCancelar" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-x-lg me-1"></i>Cancelar</button>';
$toolbar .= '</div><div class="btn-group me-2"><button id="btnBuscar" class="btn btn-outline-light btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>';
if (!$ro) $toolbar .=
    '<button id="btnEditar" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-pencil me-1"></i>Editar</button>' .
    '<button id="btnEliminar" class="btn btn-outline-danger btn-sm" disabled><i class="bi bi-trash me-1"></i>Eliminar</button>';
$toolbar .= '</div>';
module_head('Productos y Servicios', 'bi-box-seam', $toolbar);
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="assets/css/prod.css?v=3" rel="stylesheet">
<script>window.PR_RO=<?= $ro ? 'true' : 'false' ?>; window.PR_LK=<?= json_encode($lk) ?>;</script>

<div class="prod-form mode-view" id="mainForm">
  <!-- FILA 1: Datos · Última Compra · Precios de Venta (layout legacy) -->
  <div class="row g-3">
    <!-- DATOS DEL PRODUCTO -->
    <div class="col-lg-5"><div class="card fc-card h-100">
      <div class="card-header collapse-hd" data-target="#cMain"><span><i class="bi bi-box-seam me-1"></i>Producto <span class="text-muted ms-2" id="fCod">—</span></span><i class="bi bi-chevron-down collapse-icon"></i></div>
      <div id="cMain" class="collapse show"><div class="card-body">
        <div class="pf"><label>Categoría <span class="text-danger">*</span></label><select id="f_codcat" class="form-select"></select></div>
        <div class="pf"><label>Rubro</label><select id="f_codrub" class="form-select"></select></div>
        <div class="pf"><label>Subrubro</label><select id="f_codsub" class="form-select"></select></div>
        <div class="pf"><label>Línea</label><select id="f_codlin" class="form-select"></select></div>
        <div class="pf"><label>Denominación <span class="text-danger">*</span></label><input id="f_den" class="form-control" maxlength="60"></div>
        <div class="pf"><label>Unidad</label><select id="f_codudm" class="form-select"></select></div>
        <div class="pf"><label>Decimales</label><input id="f_dec" type="number" min="0" max="4" class="form-control pf-narrow"></div>
        <div class="pf"><label>Ubicación</label><input id="f_ubi" class="form-control" maxlength="30"></div>
        <div class="pf"><label>Precio de Lista (venta)</label><input id="f_plv" type="number" step="0.01" class="form-control fc-num pf-mid"></div>
        <div class="pf"><label>Discontinuado</label><input id="f_dis" type="checkbox" class="form-check-input"></div>
        <div class="pf pf-mem"><label>Observaciones</label><textarea id="f_obs" class="form-control" rows="2"></textarea></div>
      </div></div></div></div>
    <!-- ÚLTIMA COMPRA (editable en alta; PLC/PLV también en edición) -->
    <div class="col-lg-3"><div class="card fc-card h-100">
      <div class="card-header collapse-hd" data-target="#cUC"><span>Última Compra</span><i class="bi bi-chevron-down collapse-icon"></i></div>
      <div id="cUC" class="collapse show"><div class="card-body">
        <div class="pf"><label>Fecha</label><input id="f_fuc" type="date" class="form-control pf-mid"></div>
        <div class="pf"><label>Moneda</label><select id="f_codmon" class="form-select pf-mid"></select></div>
        <div class="pf"><label>Cotización</label><input id="f_cot" type="number" step="any" class="form-control fc-num pf-mid"></div>
        <div class="pf"><label>Costo</label><input id="f_cos" type="number" step="0.01" class="form-control fc-num pf-mid"></div>
        <div class="pf"><label>Flete</label><input id="f_flt" type="number" step="0.01" class="form-control fc-num pf-mid"></div>
        <div class="pf"><label>Precio de Lista (compra)</label><input id="f_plc" type="number" step="0.01" class="form-control fc-num pf-mid"></div>
      </div></div></div></div>
    <!-- PRECIOS DE VENTA por categoría (derivado) -->
    <div class="col-lg-4"><div class="card fc-card h-100">
      <div class="card-header collapse-hd" data-target="#cPre"><span>Precios de Venta</span><i class="bi bi-chevron-down collapse-icon"></i></div>
      <div id="cPre" class="collapse show"><div class="card-body p-0"><table class="table table-sm prod-tbl mb-0"><thead><tr><th>Categoría</th><th class="text-end">% Dto.</th><th class="text-end">Neto</th><th class="text-end">% Util.</th></tr></thead><tbody id="tbPrecios"></tbody></table></div></div></div></div>
  </div>

  <!-- FILA 2: Equivalencias · Proveedores -->
  <div class="row g-3">
    <div class="col-md-4"><div class="card fc-card h-100">
      <div class="card-header collapse-hd" data-target="#cEq"><span><i class="bi bi-rulers me-1"></i>Equivalencias</span>
        <span class="hd-right"><button type="button" class="btn btn-outline-primary btn-sm prod-add" data-grid="equiv" disabled><i class="bi bi-plus-lg"></i></button><i class="bi bi-chevron-down collapse-icon"></i></span></div>
      <div id="cEq" class="collapse show"><div class="card-body p-0"><table class="table table-sm prod-tbl mb-0"><thead><tr><th>Unidad</th><th class="text-end">Factor</th><th style="width:2rem"></th></tr></thead><tbody id="tbEquiv"></tbody></table></div></div></div></div>
    <div class="col-md-8"><div class="card fc-card h-100">
      <div class="card-header collapse-hd" data-target="#cPr"><span><i class="bi bi-truck me-1"></i>Proveedores</span>
        <span class="hd-right"><button type="button" class="btn btn-outline-primary btn-sm prod-add" data-grid="prov" disabled><i class="bi bi-plus-lg"></i></button><i class="bi bi-chevron-down collapse-icon"></i></span></div>
      <div id="cPr" class="collapse show"><div class="card-body p-0"><table class="table table-sm prod-tbl mb-0"><thead><tr><th>Proveedor</th><th>Cód. Externo</th><th>Últ. Compra</th><th class="text-end">Costo</th><th style="width:2rem"></th></tr></thead><tbody id="tbProv"></tbody></table></div></div></div></div>
  </div>

  <!-- FILA 3: Stock (a todo el ancho, abajo) -->
  <div class="card fc-card">
    <div class="card-header collapse-hd" data-target="#cStk"><span><i class="bi bi-boxes me-1"></i>Stock</span><i class="bi bi-chevron-down collapse-icon"></i></div>
    <div id="cStk" class="collapse show"><div class="card-body p-0"><table class="table table-sm prod-tbl mb-0"><thead><tr>
      <th>Sucursal</th><th class="text-end">Mínimo</th><th class="text-end">Máximo</th><th class="text-end">Inicial</th><th class="text-end">Existente</th><th class="text-end">Comp.Compras</th><th class="text-end">Comp.Ventas</th><th class="text-end">Disponible</th>
    </tr></thead><tbody id="tbStock"></tbody></table></div></div></div>

  <div class="text-danger small mt-2" id="formErr"></div>
</div>

<!-- MODAL BUSCAR -->
<div class="modal fade" id="modalBuscar" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-search me-2"></i>Buscar — Productos</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><table class="table table-sm table-hover w-100" id="grdBuscar"><thead><tr><th>Código</th><th>Denominación</th><th>Categoría</th><th>Rubro</th></tr></thead></table></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>

<!-- MODAL CONFIRMAR -->
<div class="modal fade" id="modalConfirm" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title">Confirmar</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" id="confirmBody"></div>
  <div class="modal-footer py-1"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-sm btn-danger" id="btnConfirmOk">Eliminar</button></div>
</div></div></div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0" role="alert"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php
module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/productos.js?v=4"></script>
');
