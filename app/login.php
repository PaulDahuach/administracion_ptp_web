<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
$name    = sys('name');
$tagline = sys('tagline', 'Sistema de Gestión');
$logo    = sys('logo', '/assets/img/logo.png');
$primary = sys('primary', '#2563eb');
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($name) ?> — Iniciar Sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { min-height:100vh; display:flex; align-items:center; justify-content:center; background:#0f172a; }
        .login-card { max-width:380px; width:100%; }
        .login-card .card { border:1px solid rgba(255,255,255,.1); border-radius:1rem; background:#1e293b; }
        .login-logo { max-width:200px; margin:0 auto 1.5rem; display:block; }
        .btn-login { background:<?= h($primary) ?>; border:none; font-weight:600; }
        .form-control:focus { border-color:<?= h($primary) ?>; box-shadow:0 0 0 3px rgba(59,130,246,.25); }
        .error-msg { min-height:1.5rem; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="card shadow-lg p-4">
            <img src="<?= h(bu($logo)) ?>" alt="Logo" class="login-logo" onerror="this.style.display='none'">
            <h5 class="text-center mb-4"><?= h($name) ?></h5>
            <div class="mb-3">
                <label class="form-label"><i class="bi bi-key me-1"></i>Contraseña</label>
                <input type="password" id="txtPass" class="form-control" placeholder="Ingrese su contraseña..." autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label"><i class="bi bi-person me-1"></i>Usuario</label>
                <input type="text" id="txtUser" class="form-control" readonly disabled placeholder="---">
                <input type="hidden" id="txtId" value="">
            </div>
            <div class="error-msg text-danger text-center small mb-2" id="err"></div>
            <button id="btnLogin" class="btn btn-login btn-primary w-100" disabled>
                <i class="bi bi-box-arrow-in-right me-1"></i>Iniciar Sesión
            </button>
        </div>
        <p class="text-center text-muted mt-3" style="font-size:.75rem;"><?= h($tagline) ?></p>
    </div>
<script>
const API = '<?= bu('/api/auth.php') ?>';
const el = id => document.getElementById(id);

async function post(action, extra) {
    const fd = new FormData();
    fd.append('action', action);
    for (const k in extra) fd.append(k, extra[k]);
    const r = await fetch(API, { method:'POST', body:fd });
    return r.json();
}

el('txtPass').addEventListener('change', async () => {
    const pass = el('txtPass').value;
    if (!pass) return;
    el('err').textContent = '';
    try {
        const j = await post('get_usuario', { pass });
        if (j.ok) {
            el('txtId').value = j.data.id;
            el('txtUser').value = j.data.name;
            el('btnLogin').disabled = false;
            el('btnLogin').focus();
        } else {
            el('err').textContent = j.error || 'Usuario no encontrado';
            el('txtId').value = ''; el('txtUser').value = '';
            el('btnLogin').disabled = true;
        }
    } catch (e) { el('err').textContent = 'Error de conexión'; }
});
el('txtPass').addEventListener('keydown', e => {
    if (e.key === 'Enter') el('txtPass').dispatchEvent(new Event('change'));
});
el('btnLogin').addEventListener('click', async () => {
    el('btnLogin').disabled = true; el('err').textContent = '';
    try {
        const j = await post('login', { id: el('txtId').value, name: el('txtUser').value, pass: el('txtPass').value });
        if (j.ok) window.location.href = j.data.redirect;
        else { el('err').textContent = j.error || 'Credenciales inválidas'; el('btnLogin').disabled = false; }
    } catch (e) { el('err').textContent = 'Error de conexión'; el('btnLogin').disabled = false; }
});
</script>
</body>
</html>
