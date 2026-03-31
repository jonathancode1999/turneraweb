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

// Only allow downloading backups for this slug.
// Puede ser .zip, .tar.gz o .tar según extensiones disponibles.
if ($f==='' || !preg_match('/^[a-z0-9_\-]+_\d{8}_\d{6}\.(zip|tar|tar\.gz)$/', $f)) {
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

// Content-Type según extensión
$ctype = 'application/octet-stream';
if (substr($f, -4) === '.zip') $ctype = 'application/zip';
else if (substr($f, -7) === '.tar.gz') $ctype = 'application/gzip';
else if (substr($f, -4) === '.tar') $ctype = 'application/x-tar';

header('Content-Type: '.$ctype);
header('Content-Disposition: attachment; filename="'.$f.'"');
header('Content-Length: '.filesize($path));
readfile($path);
exit;
