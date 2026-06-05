<?php
/** Estadísticas de uso — adopción del sistema nuevo (por módulo / usuario / máquina / día). */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
auth_require_admin();   // solo administradores (config 'admin_users')

module_head('Estadísticas de Uso', 'bi-graph-up-arrow',
    '<button id="btnReload" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-clockwise me-1"></i>Refrescar</button>');
?>
<link href="../abm/assets/css/abm.css" rel="stylesheet">
<style>
  .uso-bars { display:flex; align-items:flex-end; gap:3px; height:90px; }
  .uso-bars .bar { flex:1 1 0; background:var(--fc-primary); border-radius:2px 2px 0 0; min-height:2px; opacity:.85; }
  .uso-bars .bar:hover { opacity:1; }
  .kpi { font-size:1.6rem; font-weight:700; line-height:1; }
</style>

<div class="card fc-card"><div class="card-body">
  <div class="row g-2 align-items-end">
    <div class="col-md-2"><label class="form-label">Sistema</label><select id="fSistema" class="form-select form-select-sm"><option value="">Todos</option></select></div>
    <div class="col-md-2"><label class="form-label">Desde</label><input type="date" id="fDesde" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label">Hasta</label><input type="date" id="fHasta" class="form-control form-control-sm"></div>
    <div class="col-md-3 d-flex gap-2">
      <button id="btnFiltrar" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrar</button>
      <div class="btn-group btn-group-sm">
        <button class="btn btn-outline-secondary preset" data-d="7">7d</button>
        <button class="btn btn-outline-secondary preset" data-d="30">30d</button>
        <button class="btn btn-outline-secondary preset" data-d="90">90d</button>
      </div>
    </div>
  </div>
</div></div>

<div class="row g-2 mb-2">
  <div class="col-md-3"><div class="card fc-card h-100"><div class="card-body text-center"><div class="kpi text-primary" id="kHits">—</div><div class="small text-muted">Accesos (páginas)</div></div></div></div>
  <div class="col-md-3"><div class="card fc-card h-100"><div class="card-body text-center"><div class="kpi" id="kUsr">—</div><div class="small text-muted">Usuarios activos</div></div></div></div>
  <div class="col-md-3"><div class="card fc-card h-100"><div class="card-body text-center"><div class="kpi" id="kMaq">—</div><div class="small text-muted">Máquinas</div></div></div></div>
  <div class="col-md-3"><div class="card fc-card h-100"><div class="card-body text-center"><div class="kpi" id="kDias">—</div><div class="small text-muted">Días con uso</div></div></div></div>
</div>

<div class="card fc-card mb-2"><div class="card-header"><span><i class="bi bi-hdd-stack me-1"></i>Por sistema</span></div>
  <div class="card-body p-0"><table class="table table-sm mb-0"><thead><tr><th>Sistema</th><th class="text-end">Accesos</th><th class="text-end">Usuarios</th><th>Último</th></tr></thead><tbody id="tSis"></tbody></table></div>
</div>

<div class="card fc-card"><div class="card-header"><span><i class="bi bi-bar-chart me-1"></i>Accesos por día</span></div>
  <div class="card-body"><div class="uso-bars" id="bars"></div><div class="small text-muted mt-1" id="barsLbl">—</div></div>
</div>

<div class="row g-2">
  <div class="col-lg-4"><div class="card fc-card h-100">
    <div class="card-header"><span><i class="bi bi-window-stack me-1"></i>Por página / módulo</span></div>
    <div class="card-body p-0"><table class="table table-sm mb-0"><thead><tr><th>Módulo</th><th class="text-end">Accesos</th><th>Último</th></tr></thead><tbody id="tMod"></tbody></table></div>
  </div></div>
  <div class="col-lg-4"><div class="card fc-card h-100">
    <div class="card-header"><span><i class="bi bi-person me-1"></i>Por usuario</span></div>
    <div class="card-body p-0"><table class="table table-sm mb-0"><thead><tr><th>Usuario</th><th class="text-end">Accesos</th><th class="text-end">Máq.</th><th>Último</th></tr></thead><tbody id="tUsr"></tbody></table></div>
  </div></div>
  <div class="col-lg-4"><div class="card fc-card h-100">
    <div class="card-header"><span><i class="bi bi-pc-display me-1"></i>Por máquina</span></div>
    <div class="card-body p-0"><table class="table table-sm mb-0"><thead><tr><th>IP / Host</th><th class="text-end">Accesos</th><th class="text-end">Usu.</th><th>Último</th></tr></thead><tbody id="tMaq"></tbody></table></div>
  </div></div>
</div>

<div class="fc-toast-container"><div id="toastMsg" class="toast align-items-center border-0"><div class="d-flex"><div class="toast-body" id="toastBody"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div></div>

<?php
module_foot('<script src="assets/js/uso.js"></script>');
