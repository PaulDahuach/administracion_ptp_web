<?php
/**
 * inforemp-web-kit — Shell compartido para páginas de módulo.
 *
 * Uso en modules/<slug>/index.php:
 *
 *   require_once __DIR__ . '/../../includes/auth.php';
 *   require_once __DIR__ . '/../../includes/layout.php';
 *   auth_require_login();
 *   module_head('Cuentas Corrientes', 'bi-people');
 *   ... contenido ...
 *   module_foot('<script src="assets/js/module.js"></script>');
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function module_head($title, $icon = 'bi-app', $buttons_html = '') {
    $primary = sys('primary', '#2563eb');
    $theme   = sys('theme', 'dark');
    $ro      = db_readonly();
    ?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="<?= h($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= bu('/assets/css/app.css') ?>" rel="stylesheet">
    <style>:root{ --fc-primary: <?= h($primary) ?>; }</style>
    <script>window.IWK_BASE = '<?= rtrim(sys('base_url',''),'/') ?>';</script>
</head>
<body>
<div class="fc-topbar">
    <div class="d-flex align-items-center gap-3">
        <a href="<?= bu('/app/index.php') ?>" class="btn btn-outline-light btn-sm me-2" title="Menú"><i class="bi bi-house-door"></i></a>
        <?php if (sys('portal_url')): ?><a href="<?= h(sys('portal_url')) ?>" class="btn btn-outline-light btn-sm me-2" title="Portal de Sistemas"><i class="bi bi-grid-3x3-gap"></i></a><?php endif; ?>
        <h1><i class="bi <?= h($icon) ?> me-2"></i><?= h($title) ?></h1>
        <?php if ($ro): ?><span class="badge bg-warning text-dark"><i class="bi bi-eye me-1"></i>Sólo lectura</span><?php endif; ?>
        <?php if (function_exists('auth_sector_login') && auth_sector_login() && auth_sector()): ?>
        <span class="badge bg-primary"><i class="bi bi-diagram-3 me-1"></i><?= h(auth_sector_name()) ?></span>
        <a href="<?= bu('/app/sector.php') ?>" class="btn btn-sm btn-outline-light py-0 px-1" title="Cambiar sector"><i class="bi bi-arrow-repeat"></i></a>
        <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?= $buttons_html ?>
        <div class="theme-toggle">
            <span class="theme-icon"><i class="bi bi-sun-fill"></i></span>
            <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="themeSwitch" role="switch"></div>
            <span class="theme-icon"><i class="bi bi-moon-fill"></i></span>
        </div>
    </div>
</div>
<div class="container-fluid py-3">
    <?php
}

function module_foot($extra_html = '') {
    ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= bu('/assets/js/app.js') ?>"></script>
<?= $extra_html ?>
</body>
</html>
    <?php
}
