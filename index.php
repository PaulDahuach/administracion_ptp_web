<?php
// Entrada del sistema → manda al login o al menú según sesión.
require_once __DIR__ . '/includes/auth.php';
header('Location: ' . bu(empty($_SESSION['uid']) ? '/app/login.php' : '/app/index.php'));
exit;
