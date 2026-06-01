<?php
/**
 * Endpoint de deploy — sube archivos al servidor con curl.
 * La clave se lee de config/system.php → 'deploy_key'.
 *
 *   curl -X POST https://<host>/deploy.php -F "key=CLAVE" -F "path=modules/x/api.php" -F "file=@api.php"
 *   curl -X POST https://<host>/deploy.php -F "key=CLAVE" -F "zipfile=@deploy.zip"
 *   curl "https://<host>/deploy.php?key=CLAVE&list=.&recursive"
 */
require_once __DIR__ . '/includes/db.php';

define('DEPLOY_KEY', sys('deploy_key', ''));
define('TARGET_DIR', __DIR__);
header('Content-Type: application/json; charset=utf-8');

$key = (isset($_POST['key']) ? $_POST['key'] : (isset($_GET['key']) ? $_GET['key'] : (isset($_SERVER['HTTP_X_DEPLOY_KEY']) ? $_SERVER['HTTP_X_DEPLOY_KEY'] : '')));
if (DEPLOY_KEY === '' || $key !== DEPLOY_KEY) {
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'Clave inválida']));
}

if (isset($_POST['mkdir'])) {
    $dir = TARGET_DIR . '/' . str_replace('..', '', $_POST['mkdir']);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    die(json_encode(['ok' => true, 'message' => "Directorio creado: {$_POST['mkdir']}"]));
}

if (isset($_FILES['file']) && isset($_POST['path'])) {
    $path = str_replace('..', '', $_POST['path']);
    $full = TARGET_DIR . '/' . $path;
    if (!is_dir(dirname($full))) mkdir(dirname($full), 0755, true);
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        die(json_encode(['ok' => false, 'error' => 'Upload error: ' . $_FILES['file']['error']]));
    }
    move_uploaded_file($_FILES['file']['tmp_name'], $full);
    die(json_encode(['ok' => true, 'message' => "Subido: $path", 'size' => filesize($full)]));
}

if (isset($_FILES['zipfile'])) {
    if ($_FILES['zipfile']['error'] !== UPLOAD_ERR_OK) {
        die(json_encode(['ok' => false, 'error' => 'Upload error: ' . $_FILES['zipfile']['error']]));
    }
    $zip = new ZipArchive();
    if ($zip->open($_FILES['zipfile']['tmp_name']) !== true) {
        die(json_encode(['ok' => false, 'error' => 'No se pudo abrir el ZIP']));
    }
    $count = $zip->numFiles; $zip->extractTo(TARGET_DIR); $zip->close();
    die(json_encode(['ok' => true, 'message' => "ZIP extraído: $count archivos"]));
}

if (isset($_GET['list'])) {
    $rel = str_replace('..', '', $_GET['list']);
    $dir = TARGET_DIR . '/' . $rel;
    if (!is_dir($dir)) die(json_encode(['ok' => false, 'error' => 'Directorio no existe']));
    die(json_encode(['ok' => true, 'path' => $rel ?: '.', 'data' => listDir($dir, $rel, isset($_GET['recursive']))]));
}

echo json_encode(['ok' => true, 'usage' => 'POST file+path | mkdir | zipfile | GET ?list=dir']);

function listDir($dir, $rel, $recursive) {
    $files = [];
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = "$dir/$f"; $rp = $rel ? "$rel/$f" : $f;
        $e = ['name' => $f, 'path' => $rp, 'type' => is_dir($full) ? 'dir' : 'file',
              'size' => is_file($full) ? filesize($full) : null,
              'modified' => date('Y-m-d H:i:s', filemtime($full))];
        if (is_dir($full) && $recursive) $e['children'] = listDir($full, $rp, true);
        $files[] = $e;
    }
    return $files;
}
