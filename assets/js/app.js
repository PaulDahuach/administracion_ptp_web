/* inforemp-web-kit — JS compartido: tema + logout. */
(function () {
    // Tema persistente
    var saved = localStorage.getItem('iwk-theme') || document.documentElement.getAttribute('data-bs-theme') || 'dark';
    document.documentElement.setAttribute('data-bs-theme', saved);
    var sw = document.getElementById('themeSwitch');
    if (sw) {
        sw.checked = (saved === 'dark');
        sw.addEventListener('change', function (e) {
            var t = e.target.checked ? 'dark' : 'light';
            document.documentElement.setAttribute('data-bs-theme', t);
            localStorage.setItem('iwk-theme', t);
        });
    }
    // Logout
    var base = (window.IWK_BASE || '');
    var btn = document.getElementById('btnLogout');
    if (btn) {
        btn.addEventListener('click', async function () {
            var fd = new FormData();
            fd.append('action', 'logout');
            try { await fetch(base + '/api/auth.php', { method: 'POST', body: fd }); } catch (e) {}
            window.location.href = base + '/app/login.php';
        });
    }
})();
