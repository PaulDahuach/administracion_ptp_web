<?php
/**
 * Mayor x Cuenta — Vista. Solo lectura. Libro mayor de una cuenta contable.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$desde_def = date('Y-m-01');
$hasta_def = date('Y-m-t');

module_head('Mayor x Cuenta', 'bi-book',
    '<button id="btnImprimir" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-printer me-1"></i>Imprimir</button>');
?>
<style>
    .ac-wrap { position: relative; }
    .ac-list { position: absolute; z-index: 1000; top: 100%; left: 0; right: 0; max-height: 320px; overflow-y: auto;
        background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: .375rem; display: none; box-shadow: 0 .5rem 1rem rgba(0,0,0,.15); }
    .ac-list.show { display: block; }
    .ac-item { padding: .35rem .6rem; cursor: pointer; font-size: .85rem; border-bottom: 1px solid var(--bs-border-color-translucent); }
    .ac-item:hover { background: var(--bs-tertiary-bg); }
    .ac-item .ac-code { color: var(--bs-secondary-color); font-size: .78rem; margin-right: .5rem; font-variant-numeric: tabular-nums; }
    .stat-card { border-radius: .5rem; padding: .6rem .8rem; display: flex; align-items: center; gap: .6rem; }
    .stat-card .stat-icon { font-size: 1.2rem; width: 2rem; height: 2rem; border-radius: .4rem; display: flex; align-items: center; justify-content: center; }
    .stat-card .stat-value { font-weight: 700; font-size: .95rem; font-variant-numeric: tabular-nums; }
    .stat-card .stat-label { font-size: .68rem; color: var(--bs-secondary-color); text-transform: uppercase; }
    #grdMayor td, #grdMayor th { font-variant-numeric: tabular-nums; }
    .saldo-anterior-row { background: var(--bs-tertiary-bg) !important; font-style: italic; }
    .print-header { display: none; }
    @media print {
        .fc-topbar, .card-filtros, .stats-row, .no-print { display: none !important; }
        .print-header { display: block !important; font-family: monospace; margin-bottom: 10px; }
        .card { border: none !important; box-shadow: none !important; } body { background: #fff !important; }
        #grdMayor { font-size: 9px; }
    }
</style>

<div class="card card-filtros mb-2">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label mb-1">Cuenta contable</label>
                <div class="ac-wrap">
                    <input type="text" id="txtCuenta" class="form-control" placeholder="Buscar por código o nombre..." autocomplete="off">
                    <input type="hidden" id="hdnCodcue">
                    <div id="acList" class="ac-list"></div>
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Desde</label>
                <input type="date" id="txtDesde" class="form-control" value="<?= $desde_def ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Hasta</label>
                <input type="date" id="txtHasta" class="form-control" value="<?= $hasta_def ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Fecha</label>
                <select id="cboFecha" class="form-select">
                    <option value="com" selected>Comprobante</option>
                    <option value="mov">Movimiento</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label mb-1 d-none d-md-block">&nbsp;</label>
                <button id="btnConsultar" class="btn btn-primary w-100" disabled><i class="bi bi-search"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="card mb-2" id="cardCuenta" style="display:none;">
    <div class="card-body py-2">
        <div class="row align-items-center">
            <div class="col-md-7"><strong id="lblCuenta"></strong></div>
            <div class="col-md-3 text-md-end"><small class="text-muted">Período</small><br><span id="lblPeriodo"></span></div>
            <div class="col-md-2 text-md-end"><small class="text-muted">Asientos</small><br><strong id="lblCant">0</strong></div>
        </div>
    </div>
</div>

<div class="row g-2 mb-2 stats-row" id="statsRow" style="display:none;">
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-secondary-subtle text-secondary"><i class="bi bi-clock-history"></i></div><div><div class="stat-value" id="statSaldoAnt">$0,00</div><div class="stat-label">Saldo anterior</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-arrow-down-left"></i></div><div><div class="stat-value" id="statDebitos">$0,00</div><div class="stat-label">Débitos</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-arrow-up-right"></i></div><div><div class="stat-value" id="statCreditos">$0,00</div><div class="stat-label">Créditos</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-info-subtle text-info"><i class="bi bi-scale"></i></div><div><div class="stat-value" id="statSaldoFinal">$0,00</div><div class="stat-label">Saldo final</div></div></div></div>
</div>

<div class="print-header" id="printHeader">
    <div style="text-align:center;font-weight:bold;">MAYOR — <span id="phCuenta"></span></div>
    <div><b>Período:</b> <span id="phPeriodo"></span></div><hr>
</div>

<div class="card" id="cardGrid" style="display:none;">
    <div class="card-body p-0">
        <div style="max-height: calc(100vh - 340px); overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" id="grdMayor">
                <thead class="table-light" style="position:sticky;top:0;">
                    <tr>
                        <th style="width:90px">Fecha</th>
                        <th style="width:150px">Comprobante</th>
                        <th>Detalle</th>
                        <th style="width:150px">Centro de Costo</th>
                        <th class="text-end" style="width:120px">Debe</th>
                        <th class="text-end" style="width:120px">Haber</th>
                        <th class="text-end" style="width:130px">Saldo</th>
                    </tr>
                </thead>
                <tbody id="tbodyMayor"></tbody>
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

<?php module_foot('<script src="assets/js/mayor.js"></script>'); ?>
