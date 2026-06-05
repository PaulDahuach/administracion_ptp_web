<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/track.php';
auth_require_login();
track_hit();   // registro de uso (adopción)

$name    = sys('short_name', sys('name'));
$full    = sys('name');
$primary = sys('primary', '#2563eb');
$menu    = sys('menu', []);
$ro      = db_readonly();
$dash    = sys('dashboard', []);
$kpis    = (isset($dash['kpis']) ? $dash['kpis'] : []);
$quick   = (isset($dash['quick']) ? $dash['quick'] : []);

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
    <link href="<?= bu('/assets/css/app.css') ?>?v=5" rel="stylesheet">
    <style>:root{ --fc-primary: <?= h($primary) ?>; }</style>
    <script>window.IWK_BASE = '<?= rtrim(sys('base_url',''),'/') ?>';</script>
</head>
<body class="dash">
    <!-- Topbar -->
    <div class="fc-topbar">
        <div class="d-flex align-items-center gap-2 tb-brand">
            <i class="bi bi-grid-3x3-gap-fill" style="color:var(--fc-primary);font-size:1.2rem;"></i>
            <h1><?= h($name) ?></h1>
            <?php if ($ro): ?><span class="badge bg-warning text-dark"><i class="bi bi-eye me-1"></i>Sólo lectura</span><?php endif; ?>
            <?php if (auth_sector_login() && auth_sector()): ?>
              <span class="badge bg-primary"><i class="bi bi-diagram-3 me-1"></i><?= h(auth_sector_name()) ?></span>
              <a href="<?= bu('/app/sector.php') ?>" class="btn btn-sm btn-outline-light py-0 px-1" title="Cambiar sector"><i class="bi bi-arrow-repeat"></i></a>
            <?php endif; ?>
        </div>
        <div class="tb-search">
            <i class="bi bi-search"></i>
            <input type="text" id="menuSearch" placeholder="Buscar opción…  (Ctrl+K)" autocomplete="off">
            <kbd class="tb-kbd">Ctrl K</kbd>
        </div>
        <div class="d-flex align-items-center gap-3">
            <?php if (sys('portal_url')): ?><a href="<?= h(sys('portal_url')) ?>" class="btn btn-outline-light btn-sm" title="Portal de Sistemas"><i class="bi bi-grid-3x3-gap me-1"></i>Portal</a><?php endif; ?>
            <span class="text-light d-none d-md-inline"><i class="bi bi-person-circle me-1"></i><?= h(auth_user()) ?></span>
            <button id="btnLogout" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Salir</button>
            <div class="theme-toggle">
                <span class="theme-icon"><i class="bi bi-sun-fill"></i></span>
                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="themeSwitch" role="switch"></div>
                <span class="theme-icon"><i class="bi bi-moon-fill"></i></span>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="hero-line">
            <span><?= h($saludo) ?>, <strong><?= h(auth_user()) ?></strong></span>
            <span class="text-muted">· <?= h($full) ?> · <?= h(date('d/m/Y')) ?></span>
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
        <div class="quick-row mb-3">
            <span class="quick-pin"><i class="bi bi-star-fill me-1"></i>Favoritos:</span>
            <?php foreach ($quick as $q): ?>
            <a class="quick" href="<?= h(bu($q['url'])) ?>"><i class="bi <?= h((isset($q['icon']) ? $q['icon'] : 'bi-arrow-right')) ?> me-2"></i><?= h($q['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        // Filtrar tarjetas admin-only (flag 'admin'=>true) si el usuario no es admin;
        // las solapas que quedan vacías no se muestran (ni tab ni grupo).
        $isAdmin = auth_is_admin();
        $vmenu = array();
        foreach ($menu as $section => $cards) {
            $vis = array();
            foreach ($cards as $c) { if (empty($c['admin']) || $isAdmin) $vis[] = $c; }
            if ($vis) $vmenu[$section] = $vis;
        }
        if ($vmenu): ?>
        <div class="menu-tabs" id="menuTabs" role="tablist">
            <?php $ti = 0; foreach ($vmenu as $section => $cards):
                $nEna = 0; $nOpt = 0;
                foreach ($cards as $c) { if (isset($c['head'])) continue; $nOpt++; if (empty($c['disabled'])) $nEna++; }
                $cnt = ($nEna < $nOpt) ? ($nEna . '/' . $nOpt) : (string) $nOpt; ?>
            <button type="button" class="menu-tab<?= $ti === 0 ? ' active' : '' ?>" data-tab="<?= $ti ?>" role="tab"><?= h($section) ?><span class="menu-tab-n"><?= h($cnt) ?></span></button>
            <?php $ti++; endforeach; ?>
        </div>
        <div class="menu-panel" id="menuPanel">
            <?php $gi = 0; foreach ($vmenu as $section => $cards): ?>
            <section class="mpanel-group<?= $gi === 0 ? ' active' : '' ?>" data-group="<?= $gi ?>">
                <div class="mpanel-head"><?= h($section) ?></div>
                <?php foreach ($cards as $c):
                    if (isset($c['head'])): ?>
                <div class="mpanel-subhead"><?= h($c['head']) ?></div>
                    <?php continue; endif;
                    $ds = h(mb_strtolower($c['label'] . ' ' . $section . ' ' . (isset($c['desc']) ? $c['desc'] : '')));
                    $ic = h(isset($c['icon']) ? $c['icon'] : 'bi-dot');
                    if (!empty($c['disabled'])): ?>
                <span class="mpanel-link disabled" data-search="<?= $ds ?>" title="Aún no disponible en la versión web">
                    <i class="bi <?= $ic ?>"></i><span><?= h($c['label']) ?></span><span class="mpanel-soon">pronto</span>
                </span>
                    <?php else: ?>
                <a class="mpanel-link" href="<?= h(bu($c['url'])) ?>" data-search="<?= $ds ?>">
                    <i class="bi <?= $ic ?>"></i><span><?= h($c['label']) ?></span>
                </a>
                    <?php endif;
                endforeach; ?>
            </section>
            <?php $gi++; endforeach; ?>
        </div>
        <div class="menu-noresults" id="menuNoRes" style="display:none;">Sin coincidencias.</div>
        <?php else: ?>
        <div class="alert alert-info mt-3">No hay módulos configurados. Editá <code>config/system.php → 'menu'</code>.</div>
        <?php endif; ?>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= bu('/assets/js/app.js') ?>"></script>
<script>
(function(){
    var inp = document.getElementById('menuSearch');
    var panel = document.getElementById('menuPanel');
    var tabs = document.getElementById('menuTabs');
    var nores = document.getElementById('menuNoRes');
    if (!inp || !panel) return;

    var groups = [].slice.call(panel.querySelectorAll('.mpanel-group'));
    var tabBtns = tabs ? [].slice.call(tabs.querySelectorAll('.menu-tab')) : [];

    function norm(s){ return (s||'').toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g,''); }

    function activeTab(){
        for (var i=0;i<tabBtns.length;i++) if (tabBtns[i].classList.contains('active')) return i;
        return 0;
    }
    function showTab(idx){
        groups.forEach(function(g,i){ g.classList.toggle('active', i===idx); });
        tabBtns.forEach(function(b,i){ b.classList.toggle('active', i===idx); });
    }
    tabBtns.forEach(function(b){
        b.addEventListener('click', function(){
            if (inp.value){ inp.value=''; filter(); }   // salir de búsqueda al clickear solapa
            showTab(parseInt(b.dataset.tab,10));
        });
    });

    function filter(){
        var q = norm(inp.value.trim());
        if (q === ''){                              // sin búsqueda → volver a modo solapa
            panel.classList.remove('searching');
            if (tabs) tabs.classList.remove('searching-hide');
            groups.forEach(function(g){
                g.style.display = '';
                g.querySelectorAll('.mpanel-link').forEach(function(a){ a.style.display=''; });
            });
            showTab(activeTab());
            if (nores) nores.style.display = 'none';
            return;
        }
        panel.classList.add('searching');           // búsqueda → todas las solapas, filtradas
        if (tabs) tabs.classList.add('searching-hide');
        var anyTotal = false;
        groups.forEach(function(g){
            var anyG = false;
            g.querySelectorAll('.mpanel-link').forEach(function(a){
                var match = norm(a.dataset.search).indexOf(q) >= 0;
                a.style.display = match ? '' : 'none';
                if (match) anyG = true;
            });
            g.style.display = anyG ? '' : 'none';
            if (anyG) anyTotal = true;
        });
        if (nores) nores.style.display = anyTotal ? 'none' : '';
    }
    inp.addEventListener('input', filter);

    // Enter → abrir el primer resultado visible
    inp.addEventListener('keydown', function(e){
        if (e.key === 'Enter'){
            var first = panel.querySelector('a.mpanel-link:not([style*="display: none"])');
            if (first && first.offsetParent !== null) window.location.href = first.href;
        } else if (e.key === 'Escape'){ inp.value=''; filter(); inp.blur(); }
    });

    // Ctrl+K / "/" enfocan el buscador
    document.addEventListener('keydown', function(e){
        if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k'){ e.preventDefault(); inp.focus(); inp.select(); }
        else if (e.key==='/' && document.activeElement!==inp && !/input|textarea|select/i.test((document.activeElement.tagName||''))){ e.preventDefault(); inp.focus(); }
    });
})();
</script>
</body>
</html>
