<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
auth_require_login();

$name    = sys('short_name', sys('name'));
$full    = sys('name');
$primary = sys('primary', '#2563eb');
$menu    = sys('menu', []);
$ro      = db_readonly();
$dash    = sys('dashboard', []);
$kpis    = (isset($dash['kpis']) ? $dash['kpis'] : []);
$quick   = (isset($dash['quick']) ? $dash['quick'] : []);

// Indicadores (opcionales). Cada KPI: ['label','sql','icon','color','url'].
// En la SQL, {SECTOR} se reemplaza por el sector activo (sector_login).
$sector = (auth_sector_login() && auth_sector()) ? (int) auth_sector() : 0;
$kpiVals = [];
foreach ($kpis as $i => $k) {
    try {
        $sql = str_replace('{SECTOR}', (string) $sector, $k['sql']);
        $r = db_row($sql);
        $kpiVals[$i] = $r ? (int) array_values($r)[0] : 0;
    } catch (Exception $e) { $kpiVals[$i] = null; }
}
$hh = (int) date('H');
$saludo = $hh < 13 ? 'Buen día' : ($hh < 20 ? 'Buenas tardes' : 'Buenas noches');
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="<?= h(sys('theme','dark')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($name) ?> — Inicio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= bu('/assets/css/app.css') ?>?v=2" rel="stylesheet">
    <style>:root{ --fc-primary: <?= h($primary) ?>; }</style>
    <script>window.IWK_BASE = '<?= rtrim(sys('base_url',''),'/') ?>';</script>
</head>
<body class="dash">
<div class="app-shell">
  <!-- Sidebar -->
  <aside class="app-sidebar" id="sidebar">
    <div class="side-brand"><i class="bi bi-grid-3x3-gap-fill"></i><span><?= h($name) ?></span></div>
    <nav class="side-nav">
      <?php foreach ($menu as $section => $cards): ?>
        <div class="side-section"><?= h($section) ?></div>
        <?php foreach ($cards as $c): ?>
          <a class="side-link" href="<?= h(bu($c['url'])) ?>">
            <i class="bi <?= h((isset($c['icon']) ? $c['icon'] : 'bi-dot')) ?>"></i><span><?= h($c['label']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
  </aside>

  <!-- Main -->
  <div class="app-main">
    <div class="fc-topbar">
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-light btn-sm d-lg-none" id="btnSide"><i class="bi bi-list"></i></button>
        <h1><?= h($full) ?></h1>
        <?php if ($ro): ?><span class="badge bg-warning text-dark"><i class="bi bi-eye me-1"></i>Sólo lectura</span><?php endif; ?>
        <?php if (auth_sector_login() && auth_sector()): ?>
          <span class="badge bg-primary"><i class="bi bi-diagram-3 me-1"></i><?= h(auth_sector_name()) ?></span>
          <a href="<?= bu('/app/sector.php') ?>" class="btn btn-sm btn-outline-light py-0 px-1" title="Cambiar sector"><i class="bi bi-arrow-repeat"></i></a>
        <?php endif; ?>
      </div>
      <div class="d-flex align-items-center gap-3">
        <span class="text-light"><i class="bi bi-person-circle me-1"></i><?= h(auth_user()) ?></span>
        <button id="btnLogout" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Salir</button>
        <div class="theme-toggle">
          <span class="theme-icon"><i class="bi bi-sun-fill"></i></span>
          <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="themeSwitch" role="switch"></div>
          <span class="theme-icon"><i class="bi bi-moon-fill"></i></span>
        </div>
      </div>
    </div>

    <div class="app-content">
      <div class="hero">
        <h2><?= h($saludo) ?>, <?= h(auth_user()) ?> 👋</h2>
        <p><?= h($full) ?> · <?= h(date('d/m/Y')) ?></p>
      </div>

      <?php if ($kpis): ?>
      <div class="kpi-row">
        <?php foreach ($kpis as $i => $k): $v = $kpiVals[$i];
          $url = !empty($k['url']) ? bu($k['url']) : null; $tag = $url ? 'a' : 'div'; ?>
        <<?= $tag ?> class="kpi" style="--c:<?= h((isset($k['color']) ? $k['color'] : $primary)) ?>"<?= $url ? ' href="'.h($url).'"' : '' ?>>
          <div class="kpi-ic"><i class="bi <?= h((isset($k['icon']) ? $k['icon'] : 'bi-bar-chart')) ?>"></i></div>
          <div class="kpi-body">
            <div class="kpi-num"><?= $v === null ? '—' : number_format($v, 0, ',', '.') ?></div>
            <div class="kpi-lbl"><?= h($k['label']) ?></div>
          </div>
        </<?= $tag ?>>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($quick): ?>
      <div class="quick-title">Accesos rápidos</div>
      <div class="quick-row">
        <?php foreach ($quick as $q): ?>
        <a class="quick" href="<?= h(bu($q['url'])) ?>"><i class="bi <?= h((isset($q['icon']) ? $q['icon'] : 'bi-arrow-right')) ?> me-2"></i><?= h($q['label']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!$menu): ?>
        <div class="alert alert-info mt-3">No hay módulos configurados. Editá <code>config/system.php → 'menu'</code>.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= bu('/assets/js/app.js') ?>"></script>
<script>
  (function(){ var b=document.getElementById('btnSide'), s=document.getElementById('sidebar');
    if(b&&s) b.addEventListener('click', function(){ s.classList.toggle('open'); }); })();
</script>
</body>
</html>
