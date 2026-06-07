<?php
/**
 * Búsqueda de Comprobantes — Vista. Solo lectura.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_login();

// Tipos de comprobante presentes (para el dropdown)
$cic_labels = array(
    'FV' => 'Factura Venta', 'RV' => 'Remito Venta', 'NC' => 'Nota de Crédito', 'ND' => 'Nota de Débito',
    'RC' => 'Recibo', 'CP' => 'Comprobante a Pagar', 'OP' => 'Orden de Pago', 'RA' => 'Remito Compra',
    'AD' => 'Asiento Diario', 'AA' => 'Asiento Ajuste', 'DB' => 'Débito Bancario', 'BD' => 'Depósito Bancario',
    'TB' => 'Transferencia', 'CV' => 'Canje Valores', 'SI' => 'Saldo Inicial', 'RS' => 'Retiro Socios',
    'AS' => 'Aporte/Ajuste', 'DC' => 'Dif. Caja', 'CA' => 'Canc. Anticipos', 'VC' => 'Vale Caja', 'RG' => 'Ret. Ganancias',
);
$tipos = array();
try {
    foreach (db_query("SELECT CICMOV, COUNT(*) AS N FROM [Tbl Movimientos] GROUP BY CICMOV ORDER BY COUNT(*) DESC") as $t) {
        $c = trim((string) nz($t['CICMOV'], ''));
        if ($c !== '') $tipos[] = $c;
    }
} catch (Throwable $e) { /* silencioso */ }

module_head('Búsqueda de Comprobantes', 'bi-search', '');
?>
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
    #tblComp td, #tblComp th { font-variant-numeric: tabular-nums; }
    #tblComp tbody tr.link { cursor: pointer; }
    #tblComp tr.anulado td { text-decoration: line-through; opacity: .6; }
</style>

<div class="card card-filtros mb-2">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label mb-1">Buscar</label>
                <input type="text" id="txtQ" class="form-control" placeholder="Razón social, CUIT, CAE, Nº comp. o Nº mov." autocomplete="off">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Tipo</label>
                <select id="cboTipo" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($tipos as $c): ?>
                    <option value="<?= h($c) ?>"><?= h($c . (isset($cic_labels[$c]) ? ' — ' . $cic_labels[$c] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1">Importe</label>
                <input type="number" step="0.01" id="txtImporte" class="form-control" placeholder="Total exacto">
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
                <label class="form-label mb-1 d-none d-md-block">&nbsp;</label>
                <button id="btnBuscar" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </div>
        <div class="row g-2 align-items-end mt-1">
            <div class="col-md-2">
                <label class="form-label mb-1 small text-muted">Desde</label>
                <input type="date" id="txtDesde" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1 small text-muted">Hasta</label>
                <input type="date" id="txtHasta" class="form-control form-control-sm">
            </div>
            <div class="col-md-8 text-md-end"><span id="infoRes" class="text-muted small"></span></div>
        </div>
    </div>
</div>

<div class="card" id="cardGrid" style="display:none;">
    <div class="card-body">
        <table id="tblComp" class="table table-sm table-striped table-hover w-100">
            <thead>
                <tr>
                    <th style="width:90px">Fecha</th>
                    <th style="width:150px">Comprobante</th>
                    <th>Operación / Cuenta</th>
                    <th style="width:120px">CUIT</th>
                    <th class="text-end" style="width:130px">Total</th>
                    <th style="width:140px">CAE</th>
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
<script src="assets/js/comprobantes.js"></script>
'); ?>
