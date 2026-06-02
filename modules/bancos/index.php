<?php
/**
 * Bancos / Conciliación — Vista. Solo lectura.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

$desde_def = date('Y-m-01');
$hasta_def = date('Y-m-t');

// Cuentas bancarias (las que tienen CODCBX e imputables)
$ctas = array();
try {
    foreach (db_query("SELECT CODCUE, DENCUE FROM [Tbl Cuentas Contables] WHERE CODCBX IS NOT NULL AND IMPCUE=True ORDER BY CODCUE") as $r)
        $ctas[] = array('cc' => trim((string) $r['CODCUE']), 'den' => trim((string) nz($r['DENCUE'], '')));
} catch (Throwable $e) {}

module_head('Bancos / Conciliación', 'bi-bank',
    '<button id="btnImprimir" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-printer me-1"></i>Imprimir</button>');
?>
<style>
    .stat-card { border-radius: .5rem; padding: .6rem .8rem; display: flex; align-items: center; gap: .6rem; }
    .stat-card .stat-icon { font-size: 1.2rem; width: 2rem; height: 2rem; border-radius: .4rem; display: flex; align-items: center; justify-content: center; }
    .stat-card .stat-value { font-weight: 700; font-size: .95rem; font-variant-numeric: tabular-nums; }
    .stat-card .stat-label { font-size: .68rem; color: var(--bs-secondary-color); text-transform: uppercase; }
    #grdBco td, #grdBco th { font-variant-numeric: tabular-nums; }
    .saldo-anterior-row { background: var(--bs-tertiary-bg) !important; font-style: italic; }
    .print-header { display: none; }
    @media print {
        .fc-topbar, .card-filtros, .stats-row, .no-print { display: none !important; }
        .print-header { display: block !important; font-family: monospace; margin-bottom: 10px; }
        .card { border: none !important; box-shadow: none !important; } body { background: #fff !important; }
        #grdBco { font-size: 9px; }
    }
</style>

<div class="card card-filtros mb-2">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label mb-1">Cuenta bancaria</label>
                <select id="cboCuenta" class="form-select">
                    <option value="">— Elegí una cuenta —</option>
                    <?php foreach ($ctas as $c): ?>
                    <option value="<?= h($c['cc']) ?>"><?= h($c['cc'] . ' — ' . $c['den']) ?></option>
                    <?php endforeach; ?>
                </select>
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
                <label class="form-label mb-1">Conciliación</label>
                <select id="cboEstado" class="form-select">
                    <option value="todos" selected>Todos</option>
                    <option value="pendientes">Pendientes</option>
                    <option value="conciliados">Conciliados</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label mb-1">Fecha</label>
                <select id="cboBase" class="form-select">
                    <option value="com" selected>Comp.</option>
                    <option value="acred">Acred.</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label mb-1 d-none d-md-block">&nbsp;</label>
                <button id="btnConsultar" class="btn btn-primary w-100" disabled><i class="bi bi-search"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="row g-2 mb-2 stats-row" id="statsRow" style="display:none;">
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-secondary-subtle text-secondary"><i class="bi bi-clock-history"></i></div><div><div class="stat-value" id="stAnt">$0,00</div><div class="stat-label">Saldo anterior</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-arrow-down-left"></i></div><div><div class="stat-value" id="stDeb">$0,00</div><div class="stat-label">Débitos</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-success-subtle text-success"><i class="bi bi-arrow-up-right"></i></div><div><div class="stat-value" id="stCre">$0,00</div><div class="stat-label">Créditos</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-info-subtle text-info"><i class="bi bi-bank"></i></div><div><div class="stat-value" id="stSaldo">$0,00</div><div class="stat-label">Saldo según libros</div></div></div></div>
</div>

<div class="print-header" id="printHeader">
    <div style="text-align:center;font-weight:bold;">BANCO — <span id="phCuenta"></span></div>
    <div><b>Período:</b> <span id="phPeriodo"></span></div><hr>
</div>

<div class="card" id="cardGrid" style="display:none;">
    <div class="card-body p-0">
        <div style="max-height: calc(100vh - 340px); overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" id="grdBco">
                <thead class="table-light" style="position:sticky;top:0;">
                    <tr>
                        <th style="width:85px">Fecha</th>
                        <th style="width:140px">Comprobante</th>
                        <th>Detalle</th>
                        <th style="width:150px">Cheque</th>
                        <th style="width:85px">Acred.</th>
                        <th class="text-end" style="width:115px">Débitos</th>
                        <th class="text-end" style="width:115px">Créditos</th>
                        <th class="text-end" style="width:125px">Saldo</th>
                        <th style="width:50px" class="text-center">Conc.</th>
                    </tr>
                </thead>
                <tbody id="tbodyBco"></tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="5" class="text-end">Totales:</td>
                        <td class="text-end" id="totalDebe"></td>
                        <td class="text-end" id="totalHaber"></td>
                        <td class="text-end" id="totalSaldo"></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php module_foot('<script src="assets/js/bancos.js"></script>'); ?>
