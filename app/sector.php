<?php
/**
 * Selección de sector (login sectorizado). Paso entre el login y el dashboard,
 * sólo activo si config 'sector_login' está definido. Requiere sesión iniciada.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
auth_require_user();
if (!auth_sector_login()) { header('Location: ' . bu('/app/index.php')); exit; }

$name    = sys('short_name', sys('name'));
$primary = sys('primary', '#2563eb');
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="<?= h(sys('theme', 'dark')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($name) ?> — Elegir Sector</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { min-height:100vh; display:flex; align-items:center; justify-content:center; background:#0f172a; }
        .sec-card { max-width:520px; width:100%; }
        .sec-card .card { border:1px solid rgba(255,255,255,.1); border-radius:1rem; background:#1e293b; }
        .sec-btn { text-align:left; }
        :root{ --bs-primary: <?= h($primary) ?>; }
    </style>
</head>
<body>
<div class="sec-card">
    <div class="card shadow-lg p-4">
        <h5 class="text-center mb-1"><i class="bi bi-diagram-3 me-2"></i>Elegí tu sector</h5>
        <p class="text-center text-muted small mb-3">Hola <?= h(auth_user()) ?></p>
        <div id="err" class="text-danger small text-center mb-2"></div>
        <div id="lista" class="d-grid gap-2"><div class="text-center text-muted">Cargando...</div></div>
        <button id="btnSalir" class="btn btn-link btn-sm mt-3 text-muted">Salir</button>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API = '<?= bu('/api/auth.php') ?>';
async function post(action, extra) {
    const fd = new FormData(); fd.append('action', action);
    for (const k in extra) fd.append(k, extra[k]);
    return (await fetch(API, { method:'POST', body:fd })).json();
}
async function elegir(cod, name) {
    const j = await post('set_sector', { cod, name });
    if (j.ok) window.location.href = j.data.redirect;
    else document.getElementById('err').textContent = j.error || 'Error';
}
(async function () {
    const r = await fetch(API + '?action=sectores');
    const j = await r.json();
    const cont = document.getElementById('lista');
    if (!j.ok) { cont.innerHTML = ''; document.getElementById('err').textContent = j.error || 'Error'; return; }
    const secs = j.data || [];
    if (!secs.length) { cont.innerHTML = '<div class="text-center text-muted">No tenés sectores asignados.</div>'; return; }
    if (secs.length === 1) { elegir(secs[0].id, secs[0].den); return; }   // autoselección
    cont.innerHTML = secs.map(s =>
        `<button class="btn btn-outline-primary sec-btn" onclick="elegir('${s.id}', this.dataset.n)" data-n="${(s.den||'').replace(/"/g,'&quot;')}">
            <i class="bi bi-arrow-right-circle me-2"></i>${(s.den||'').replace(/</g,'&lt;')}</button>`
    ).join('');
})();
document.getElementById('btnSalir').addEventListener('click', async () => {
    await post('logout', {}); window.location.href = '<?= bu('/app/login.php') ?>';
});
</script>
</body>
</html>
