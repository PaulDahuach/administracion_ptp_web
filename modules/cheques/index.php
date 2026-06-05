<?php
/**
 * Cheques — Vista. Solo lectura. Cheques de terceros (cartera, diferidos, histórico).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

module_head('Cheques', 'bi-cash-coin', '');
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
    .stat-card { border-radius: .5rem; padding: .6rem .8rem; display: flex; align-items: center; gap: .6rem; }
    .stat-card .stat-icon { font-size: 1.2rem; width: 2rem; height: 2rem; border-radius: .4rem; display: flex; align-items: center; justify-content: center; }
    .stat-card .stat-value { font-weight: 700; font-size: .95rem; font-variant-numeric: tabular-nums; }
    .stat-card .stat-label { font-size: .68rem; color: var(--bs-secondary-color); text-transform: uppercase; }
    #tblChq td, #tblChq th { font-variant-numeric: tabular-nums; }
</style>

<div class="card card-filtros mb-2">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label mb-1">Estado</label>
                <select id="cboEstado" class="form-select">
                    <option value="depositar" selected>A Depositar</option>
                    <option value="diferidos">Diferidos</option>
                    <option value="cartera">En cartera</option>
                    <option value="todos">Todos</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label mb-1">Buscar</label>
                <input type="text" id="txtQ" class="form-control" placeholder="Librador, CUIT o Nº de cheque" autocomplete="off">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Importe</label>
                <input type="number" step="0.01" id="txtImporte" class="form-control" placeholder="Exacto">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Fecha por</label>
                <select id="cboBase" class="form-select">
                    <option value="emi" selected>Emisión</option>
                    <option value="entrada">Entrada (ingreso)</option>
                    <option value="acred">Acreditación</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label mb-1 d-none d-md-block">&nbsp;</label>
                <button id="btnBuscar" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </div>
        <div class="row g-2 align-items-end mt-1">
            <div class="col-md-2"><label class="form-label mb-1 small text-muted">Desde</label><input type="date" id="txtDesde" class="form-control form-control-sm"></div>
            <div class="col-md-2"><label class="form-label mb-1 small text-muted">Hasta</label><input type="date" id="txtHasta" class="form-control form-control-sm"></div>
        </div>
    </div>
</div>

<div class="row g-2 mb-2 stats-row" id="statsRow" style="display:none;">
    <div class="col-6 col-md-4"><div class="card stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-cash-coin"></i></div><div><div class="stat-value" id="stCant">0</div><div class="stat-label">Cheques</div></div></div></div>
    <div class="col-6 col-md-4"><div class="card stat-card"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-cash-stack"></i></div><div><div class="stat-value" id="stTotal">$0,00</div><div class="stat-label">Total Importe</div></div></div></div>
</div>

<div class="card" id="cardGrid" style="display:none;">
    <div class="card-body">
        <table id="tblChq" class="table table-sm table-striped table-hover w-100">
            <thead>
                <tr>
                    <th>Banco</th>
                    <th style="width:110px">Nº Cheque</th>
                    <th>Librador</th>
                    <th style="width:115px">CUIT</th>
                    <th style="width:95px">Emisión</th>
                    <th style="width:95px">Ingreso</th>
                    <th style="width:95px">Acred.</th>
                    <th class="text-end" style="width:130px">Importe</th>
                    <th style="width:90px">Estado</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/cheques.js?v=3"></script>
'); ?>
