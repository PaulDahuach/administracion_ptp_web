<?php
/**
 * Balance de Sumas y Saldos — Vista. Solo lectura.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$desde_def = date('Y-m-01');
$hasta_def = date('Y-m-t');

module_head('Balance de Sumas y Saldos', 'bi-table',
    '<button id="btnImprimir" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-printer me-1"></i>Imprimir</button>');
?>
<style>
    .stat-card { border-radius: .5rem; padding: .6rem .8rem; display: flex; align-items: center; gap: .6rem; }
    .stat-card .stat-icon { font-size: 1.2rem; width: 2rem; height: 2rem; border-radius: .4rem; display: flex; align-items: center; justify-content: center; }
    .stat-card .stat-value { font-weight: 700; font-size: .95rem; font-variant-numeric: tabular-nums; }
    .stat-card .stat-label { font-size: .68rem; color: var(--bs-secondary-color); text-transform: uppercase; }
    #grdBal td, #grdBal th { font-variant-numeric: tabular-nums; }
    #grdBal tbody tr.parent td { font-weight: 600; background: var(--bs-tertiary-bg); }
    .nivel-1 { padding-left: .25rem !important; } .nivel-2 { padding-left: 1.25rem !important; }
    .nivel-3 { padding-left: 2.25rem !important; } .nivel-4 { padding-left: 3.25rem !important; }
    .nivel-5 { padding-left: 4.25rem !important; }
    .print-header { display: none; }
    @media print {
        .fc-topbar, .card-filtros, .stats-row, .no-print { display: none !important; }
        .print-header { display: block !important; font-family: monospace; margin-bottom: 10px; }
        .card { border: none !important; box-shadow: none !important; } body { background: #fff !important; }
        #grdBal { font-size: 9px; }
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
            <div class="col-md-2">
                <label class="form-label mb-1 d-none d-md-block">&nbsp;</label>
                <button id="btnConsultar" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Consultar</button>
            </div>
            <div class="col-md-4 text-md-end">
                <span id="balanceChk" class="badge"></span>
            </div>
        </div>
    </div>
</div>

<div class="row g-2 mb-2 stats-row" id="statsRow" style="display:none;">
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-secondary-subtle text-secondary"><i class="bi bi-clock-history"></i></div><div><div class="stat-value" id="stAnt">$0,00</div><div class="stat-label">Σ Saldo Anterior</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-arrow-down-left"></i></div><div><div class="stat-value" id="stDeb">$0,00</div><div class="stat-label">Σ Debe</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-arrow-up-right"></i></div><div><div class="stat-value" id="stCre">$0,00</div><div class="stat-label">Σ Haber</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-info-subtle text-info"><i class="bi bi-scale"></i></div><div><div class="stat-value" id="stSaldo">$0,00</div><div class="stat-label">Σ Saldo Final</div></div></div></div>
</div>

<div class="print-header" id="printHeader">
    <div style="text-align:center;font-weight:bold;">BALANCE DE SUMAS Y SALDOS</div>
    <div><b>Período:</b> <span id="phPeriodo"></span></div><hr>
</div>

<div class="card" id="cardGrid" style="display:none;">
    <div class="card-body p-0">
        <div style="max-height: calc(100vh - 340px); overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" id="grdBal">
                <thead class="table-light" style="position:sticky;top:0;">
                    <tr>
                        <th style="width:120px">Código</th>
                        <th>Cuenta</th>
                        <th class="text-end" style="width:150px">Saldo Anterior</th>
                        <th class="text-end" style="width:150px">Debe</th>
                        <th class="text-end" style="width:150px">Haber</th>
                        <th class="text-end" style="width:160px">Saldo</th>
                    </tr>
                </thead>
                <tbody id="tbodyBal"></tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="2" class="text-end">TOTALES:</td>
                        <td class="text-end" id="ftAnt"></td>
                        <td class="text-end" id="ftDeb"></td>
                        <td class="text-end" id="ftCre"></td>
                        <td class="text-end" id="ftSaldo"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php module_foot('<script src="assets/js/balance.js"></script>'); ?>
