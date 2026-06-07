<?php
/**
 * Saldos Actuales (Deudores) — Vista. Solo lectura.
 * Supervisor/Admin: columnas Blanco / Capacitacion / Total. Operador/Capacitación: un solo "Saldo".
 * Click en una fila → Resumen de Cuenta de ese cliente.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();
$ve = auth_ve_ambos();

module_head('Saldos Actuales — Deudores', 'bi-cash-stack',
    '<button id="btnImprimir" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-printer me-1"></i>Imprimir</button>');
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
    .saldo-pos { color: var(--bs-danger); }
    .saldo-neg { color: var(--bs-success); }
    .stat-card { border-radius: .5rem; padding: .6rem .8rem; display: flex; align-items: center; gap: .6rem; }
    .stat-card .stat-icon { font-size: 1.2rem; width: 2rem; height: 2rem; border-radius: .4rem; display: flex; align-items: center; justify-content: center; }
    .stat-card .stat-value { font-weight: 700; font-size: .95rem; font-variant-numeric: tabular-nums; }
    .stat-card .stat-label { font-size: .68rem; color: var(--bs-secondary-color); text-transform: uppercase; }
    #tblSaldos td, #tblSaldos th { font-variant-numeric: tabular-nums; }
    #tblSaldos tbody tr { cursor: pointer; }
    .print-header { display: none; }
    @media print {
        .fc-topbar, .stats-row, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info, .no-print { display: none !important; }
        .print-header { display: block !important; font-family: monospace; margin-bottom: 10px; }
        .card { border: none !important; box-shadow: none !important; }
        body { background: #fff !important; }
        #tblSaldos { font-size: 10px; }
    }
</style>

<!-- STATS -->
<div class="row g-2 mb-2 stats-row">
    <?php if ($ve): ?>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-light text-dark border"><i class="bi bi-circle"></i></div><div><div class="stat-value" id="stBlanco">$0,00</div><div class="stat-label">Total en Blanco</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-dark text-light"><i class="bi bi-circle-fill"></i></div><div><div class="stat-value" id="stCapacitacion">$0,00</div><div class="stat-label">Total en Capacitación</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-cash-stack"></i></div><div><div class="stat-value" id="stTotal">$0,00</div><div class="stat-label">Total a Cobrar</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people"></i></div><div><div class="stat-value" id="stCant">0</div><div class="stat-label">Deudores</div></div></div></div>
    <?php else: ?>
    <div class="col-6 col-md-4"><div class="card stat-card"><div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-cash-stack"></i></div><div><div class="stat-value" id="stTotal">$0,00</div><div class="stat-label">Total a Cobrar</div></div></div></div>
    <div class="col-6 col-md-4"><div class="card stat-card"><div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people"></i></div><div><div class="stat-value" id="stCant">0</div><div class="stat-label">Deudores</div></div></div></div>
    <?php endif; ?>
</div>

<div class="print-header"><div style="text-align:center;font-weight:bold;">SALDOS ACTUALES — DEUDORES</div><hr></div>

<div class="card">
    <div class="card-body">
        <table id="tblSaldos" class="table table-sm table-striped table-hover w-100">
            <thead>
                <tr>
                    <th style="width:70px">Código</th>
                    <th>Cliente</th>
                    <th style="width:130px">CUIT</th>
                    <?php if ($ve): ?>
                    <th class="text-end" style="width:140px">Blanco</th>
                    <th class="text-end" style="width:140px">Capacitación</th>
                    <th class="text-end" style="width:150px">Total</th>
                    <?php else: ?>
                    <th class="text-end" style="width:160px">Saldo</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot class="fw-bold">
                <tr>
                    <th colspan="3" class="text-end">Totales:</th>
                    <?php if ($ve): ?>
                    <th class="text-end" id="ftBlanco"></th>
                    <th class="text-end" id="ftCapacitacion"></th>
                    <th class="text-end" id="ftTotal"></th>
                    <?php else: ?>
                    <th class="text-end" id="ftTotal"></th>
                    <?php endif; ?>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>window.VE_AMBOS = <?= $ve ? 'true' : 'false' ?>;</script>
<?php module_foot('
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/saldos.js"></script>
'); ?>
