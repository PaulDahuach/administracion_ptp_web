<?php
/**
 * Plan de Cuentas — Vista. Solo lectura. Jerarquía de cuentas contables.
 * Click en una cuenta imputable → su Mayor.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

module_head('Plan de Cuentas', 'bi-diagram-3',
    '<button id="btnImprimir" class="btn btn-outline-light btn-sm" disabled><i class="bi bi-printer me-1"></i>Imprimir</button>');
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
    #tblPlan td, #tblPlan th { font-variant-numeric: tabular-nums; }
    #tblPlan tbody tr.imp { cursor: pointer; }
    #tblPlan tbody tr.parent td { font-weight: 600; background: var(--bs-tertiary-bg); }
    .saldo-pos { color: var(--bs-danger); } .saldo-neg { color: var(--bs-success); }
    .nivel-1 { padding-left: .25rem !important; }
    .nivel-2 { padding-left: 1.25rem !important; }
    .nivel-3 { padding-left: 2.25rem !important; }
    .nivel-4 { padding-left: 3.25rem !important; }
    .nivel-5 { padding-left: 4.25rem !important; }
    @media print {
        .fc-topbar, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info, .no-print { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        body { background: #fff !important; } #tblPlan { font-size: 10px; }
    }
</style>

<div class="card">
    <div class="card-body">
        <table id="tblPlan" class="table table-sm table-hover w-100">
            <thead>
                <tr>
                    <th style="width:120px">Código</th>
                    <th>Cuenta</th>
                    <th style="width:90px" class="text-center">Imputable</th>
                    <th class="text-end" style="width:170px">Saldo Actual</th>
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
<script src="assets/js/plan.js"></script>
'); ?>
