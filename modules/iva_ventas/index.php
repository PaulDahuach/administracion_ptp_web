<?php
/**
 * I.V.A. Ventas — Vista. Solo lectura. Porta el "Rpt CD IVA" del legacy.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$desde_def = date('Y-m-01');
$hasta_def = date('Y-m-t');

module_head('I.V.A. Ventas', 'bi-percent',
    '<button id="btnImprimir" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-printer me-1"></i>Imprimir</button>');
?>
<style>
    .stat-card { border-radius: .5rem; padding: .6rem .8rem; display: flex; align-items: center; gap: .6rem; }
    .stat-card .stat-icon { font-size: 1.2rem; width: 2rem; height: 2rem; border-radius: .4rem; display: flex; align-items: center; justify-content: center; }
    .stat-card .stat-value { font-weight: 700; font-size: .95rem; font-variant-numeric: tabular-nums; }
    .stat-card .stat-label { font-size: .68rem; color: var(--bs-secondary-color); text-transform: uppercase; }
    #grdIva td, #grdIva th, #grdResumen td, #grdResumen th { font-variant-numeric: tabular-nums; }
    #grdIva tbody tr.row-NC { color: var(--bs-danger); }
    .print-header { display: none; }
    @media print {
        .fc-topbar, .card-filtros, .stats-row, .no-print { display: none !important; }
        .print-header { display: block !important; font-family: monospace; margin-bottom: 10px; }
        .card { border: none !important; box-shadow: none !important; }
        body { background: #fff !important; }
        #grdIva { font-size: 9px; }
    }
</style>

<div class="card card-filtros mb-2">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1">Desde</label>
                <input type="date" id="txtDesde" class="form-control" value="<?= $desde_def ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1">Hasta</label>
                <input type="date" id="txtHasta" class="form-control" value="<?= $hasta_def ?>">
            </div>
            <?php if (auth_ve_ambos()): ?>
            <div class="col-md-2">
                <label class="form-label mb-1">Libro</label>
                <select id="cboLibro" class="form-select">
                    <option value="blanco" selected>Blanco</option>
                    <option value="negro">Negro</option>
                    <option value="todos">Todos</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label mb-1 d-none d-md-block">&nbsp;</label>
                <button id="btnConsultar" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Consultar</button>
            </div>
        </div>
    </div>
</div>

<div class="row g-2 mb-2 stats-row" id="statsRow" style="display:none;">
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-cash"></i></div><div><div class="stat-value" id="stNeto">$0,00</div><div class="stat-label">Neto Gravado</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-percent"></i></div><div><div class="stat-value" id="stIva">$0,00</div><div class="stat-label">I.V.A.</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-receipt-cutoff"></i></div><div><div class="stat-value" id="stPercep">$0,00</div><div class="stat-label">Percep. IIBB</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-cash-stack"></i></div><div><div class="stat-value" id="stTotal">$0,00</div><div class="stat-label">Total</div></div></div></div>
</div>

<div class="print-header" id="printHeader">
    <div style="text-align:center;font-weight:bold;">I.V.A. VENTAS</div>
    <div><b>Período:</b> <span id="phPeriodo"></span></div><hr>
</div>

<div class="card mb-2" id="cardGrid" style="display:none;">
    <div class="card-body p-0">
        <div style="max-height: calc(100vh - 420px); min-height: 200px; overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" id="grdIva">
                <thead class="table-light" style="position:sticky;top:0;">
                    <tr>
                        <th style="width:80px">Fecha</th>
                        <th style="width:150px">Comprobante</th>
                        <th>Cuenta</th>
                        <th style="width:55px">Cat.</th>
                        <th style="width:115px">CUIT</th>
                        <th class="text-end" style="width:120px">Neto Grav.</th>
                        <th class="text-end" style="width:110px">I.V.A.</th>
                        <th class="text-end" style="width:95px">No Grav.</th>
                        <th class="text-end" style="width:95px">Percep.</th>
                        <th class="text-end" style="width:120px">Total</th>
                    </tr>
                </thead>
                <tbody id="tbodyIva"></tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="5" class="text-end">TOTAL (<span id="ftCant">0</span>):</td>
                        <td class="text-end" id="ftNeto"></td>
                        <td class="text-end" id="ftIva"></td>
                        <td class="text-end" id="ftNoGrav"></td>
                        <td class="text-end" id="ftPercep"></td>
                        <td class="text-end" id="ftTotal"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div class="card" id="cardResumen" style="display:none;">
    <div class="card-header py-2"><strong>Resumen por comprobante y alícuota</strong>
        <span class="text-muted small ms-2">(neto/IVA exactos por alícuota — base F2002)</span></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" id="grdResumen">
            <thead class="table-light">
                <tr>
                    <th>Comprobante</th><th class="text-end" style="width:120px">Alícuota</th><th class="text-end" style="width:90px">Cant.</th>
                    <th class="text-end" style="width:180px">Neto Gravado</th><th class="text-end" style="width:180px">I.V.A.</th>
                </tr>
            </thead>
            <tbody id="tbodyResumen"></tbody>
        </table>
    </div>
</div>

<?php module_foot('<script src="assets/js/iva_ventas.js"></script>'); ?>
