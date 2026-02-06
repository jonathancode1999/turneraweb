<?php
require __DIR__.'/_inc.php';
require_login();

$slug = trim($_GET['c'] ?? '');
$f = trim($_GET['f'] ?? '');

if ($slug==='' || !client_slug_valid($slug)) {
  http_response_code(400);
  echo 'Cliente inválido';
  exit;
}

// Only allow downloading backups for this slug, and only .zip files.
if ($f==='' || !preg_match('/^[a-z0-9_\-]+_\d{8}_\d{6}\.zip$/', $f)) {
  http_response_code(400);
  echo 'Archivo inválido';
  exit;
}
if (strpos($f, $slug.'_') !== 0) {
  http_response_code(403);
  echo 'No permitido';
  exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $f;
if (!file_exists($path)) {
  http_response_code(404);
  echo 'No encontrado';
  exit;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$f.'"');
header('Content-Length: '.filesize($path));
readfile($path);
exit;
