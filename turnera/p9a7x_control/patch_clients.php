<?php
require_once __DIR__ . '/_inc.php';
admin_require_login();

$root = realpath(__DIR__ . '/..');
$template = $root . DIRECTORY_SEPARATOR . '_template';

$filesToCopy = admin_client_runtime_files();

function is_client_dir(string $dir): bool {
  admin_normalize_client_layout($dir);
  return is_dir($dir . '/includes') && is_file($dir . '/includes/config.php') && is_file($dir . '/index.php') && is_dir($dir . '/' . admin_client_control_dir());
}

$clients = [];
$dh = opendir($root);
if ($dh) {
  while (($name = readdir($dh)) !== false) {
    if ($name === '.' || $name === '..') continue;
    if ($name === admin_client_control_dir() || $name === '_template') continue;
    $full = $root . DIRECTORY_SEPARATOR . $name;
    if (is_dir($full) && is_client_dir($full)) $clients[] = $name;
  }
  closedir($dh);
}
sort($clients);

$report = [];
foreach ($clients as $slug) {
  $dstBase = $root . DIRECTORY_SEPARATOR . $slug;
  $item = ['slug'=>$slug, 'copied'=>[], 'missing'=>[]];
  foreach ($filesToCopy as $rel) {
    $src = $template . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dst = $dstBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($src)) { $item['missing'][] = 'SRC:' . $rel; continue; }
    if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
    if (@copy($src, $dst)) {
      $item['copied'][] = $rel;
    } else {
      $item['missing'][] = 'COPY_FAIL:' . $rel;
    }
  }
  $report[] = $item;
}

header('Content-Type: text/plain; charset=utf-8');
echo "Patch aplicado. Archivos copiados desde _template a cada cliente.\n\n";
foreach ($report as $r) {
  echo "- {$r['slug']}: ";
  echo "copiados=" . count($r['copied']) . ", errores=" . count($r['missing']) . "\n";
  if (!empty($r['missing'])) {
    foreach ($r['missing'] as $m) echo "    * {$m}\n";
  }
}
echo "\nListo. Podés cerrar esta página.\n";
