<?php
/**
 * Resumen de Cuenta (Acreedores / Proveedores) — Vista. Solo lectura.
 * Espejo del de deudores. Saldo NEGATIVO = le debemos al proveedor.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

module_head('Resumen de Cuenta — Proveedores', 'bi-journal-text',
    '<button id="btnImprimir" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-printer me-1"></i>Imprimir</button>');
?>
<style>
    .ac-wrap { position: relative; }
    .ac-list { position: absolute; z-index: 1000; top: 100%; left: 0; right: 0; max-height: 300px; overflow-y: auto;
        background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: .375rem; display: none; box-shadow: 0 .5rem 1rem rgba(0,0,0,.15); }
    .ac-list.show { display: block; }
    .ac-item { padding: .35rem .6rem; cursor: pointer; font-size: .85rem; border-bottom: 1px solid var(--bs-border-color-translucent); }
    .ac-item:hover { background: var(--bs-tertiary-bg); }
    .ac-item .ac-code { color: var(--bs-secondary-color); font-size: .75rem; margin-right: .4rem; }
    .saldo-pos { color: var(--bs-danger); }
    .saldo-neg { color: var(--bs-success); }
    .stat-card { border-radius: .5rem; padding: .6rem .8rem; display: flex; align-items: center; gap: .6rem; }
    .stat-card .stat-icon { font-size: 1.2rem; width: 2rem; height: 2rem; border-radius: .4rem; display: flex; align-items: center; justify-content: center; }
    .stat-card .stat-value { font-weight: 700; font-size: .95rem; font-variant-numeric: tabular-nums; }
    .stat-card .stat-label { font-size: .68rem; color: var(--bs-secondary-color); text-transform: uppercase; }
    #grdResumen td, #grdResumen th { font-variant-numeric: tabular-nums; }
    .saldo-anterior-row { background: var(--bs-tertiary-bg) !important; font-style: italic; }
    .row-CP td:first-child { box-shadow: inset 3px 0 0 var(--bs-primary); }
    .row-OP td:first-child { box-shadow: inset 3px 0 0 var(--bs-success); }
    .row-NC td:first-child { box-shadow: inset 3px 0 0 var(--bs-warning); }
    .row-ND td:first-child { box-shadow: inset 3px 0 0 var(--bs-danger); }
    .print-header { display: none; }
    @media print {
        .fc-topbar, .card-filtros, .stats-row, .no-print { display: none !important; }
        .print-header { display: block !important; font-family: monospace; margin-bottom: 10px; }
        .card { border: none !important; box-shadow: none !important; }
        body { background: #fff !important; }
    }
</style>

<div class="card card-filtros mb-2">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label mb-1">Proveedor</label>
                <div class="ac-wrap">
                    <input type="text" id="txtCliente" class="form-control" placeholder="Buscar por nombre o CUIT..." autocomplete="off">
                    <input type="hidden" id="hdnCodcue">
                    <div id="acList" class="ac-list"></div>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Desde</label>
                <input type="date" id="txtDesde" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Hasta</label>
                <input type="date" id="txtHasta" class="form-control">
            </div>
            <?php if (auth_ve_ambos()): ?>
            <div class="col-md-1">
                <label class="form-label mb-1">Libro</label>
                <select id="cboLibro" class="form-select">
                    <option value="todos" selected>Todos</option>
                    <option value="blanco">Blanco</option>
                    <option value="capacitacion">Capacitación</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-1">
                <label class="form-label mb-1" title="Nivel de detalle de la impresión">Nivel</label>
                <select id="cboNivel" class="form-select">
                    <option value="D" selected>Detalle</option>
                    <option value="P">Producto</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label mb-1 d-none d-md-block">&nbsp;</label>
                <button id="btnConsultar" class="btn btn-primary w-100" disabled><i class="bi bi-search"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="card mb-2" id="cardCliente" style="display:none;">
    <div class="card-body py-2">
        <div class="row align-items-center">
            <div class="col-md-6"><strong id="lblCliente"></strong><br><small class="text-muted" id="lblCuit"></small></div>
            <div class="col-md-3 text-md-end"><small class="text-muted">Período</small><br><span id="lblPeriodo"></span></div>
            <div class="col-md-3 text-md-end"><small class="text-muted">Movimientos</small><br><strong id="lblCantMov">0</strong></div>
        </div>
    </div>
</div>

<div class="row g-2 mb-2 stats-row" id="statsRow" style="display:none;">
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-secondary-subtle text-secondary"><i class="bi bi-clock-history"></i></div><div><div class="stat-value" id="statSaldoAnt">$0,00</div><div class="stat-label">Saldo anterior</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-cash-coin"></i></div><div><div class="stat-value" id="statDebitos">$0,00</div><div class="stat-label">Pagos / Débitos</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-file-earmark-text"></i></div><div><div class="stat-value" id="statCreditos">$0,00</div><div class="stat-label">Compras / Créditos</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon" id="statSaldoIcon"><i class="bi bi-scale"></i></div><div><div class="stat-value" id="statSaldoFinal">$0,00</div><div class="stat-label">Saldo actual</div></div></div></div>
</div>

<div class="print-header" id="printHeader">
    <div style="text-align:center;font-weight:bold;">RESUMEN DE CUENTA — PROVEEDOR</div>
    <div><b>Cuenta:</b> <span id="phCliente"></span> &nbsp; <b>CUIT:</b> <span id="phCuit"></span></div>
    <div><b>Período:</b> <span id="phPeriodo"></span></div>
    <hr>
</div>

<div class="card" id="cardGrid" style="display:none;">
    <div class="card-body p-0">
        <div style="max-height: calc(100vh - 320px); overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" id="grdResumen">
                <thead class="table-light" style="position:sticky;top:0;">
                    <tr>
                        <th class="text-end" style="width:75px">Mov.</th>
                        <th style="width:90px">Fecha</th>
                        <th style="min-width:150px">Comprobante</th>
                        <th class="d-none d-md-table-cell">Observaciones</th>
                        <th class="text-end" style="width:120px">Débitos</th>
                        <th class="text-end" style="width:120px">Créditos</th>
                        <th class="text-end" style="width:130px">Saldo</th>
                    </tr>
                </thead>
                <tbody id="tbodyResumen"></tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">Totales del período:</td>
                        <td class="text-end" id="totalDebe"></td>
                        <td class="text-end" id="totalHaber"></td>
                        <td class="text-end" id="totalSaldo"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php module_foot('<script src="assets/js/resumen.js?v=3"></script>'); ?>
