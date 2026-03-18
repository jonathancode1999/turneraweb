<?php
require_once __DIR__ . '/_inc.php';
admin_require_login();

$root = realpath(__DIR__ . '/..');
$template = $root . DIRECTORY_SEPARATOR . '_template';

$filesToCopy = [
  'includes/branches.php',
  'includes/db.php',
  'includes/service_profesionales.php',
  'includes/timeline.php',
  'public/index.php',
  'public/create_booking.php',
  'public/manage.php',
  'public/api.php',
  'admin/dashboard.php',
  'admin/profesionales.php',
  'admin/barber_edit.php',
  'admin/blocks.php',
  'admin/analytics.php',
  'admin/wa_action.php',
];

function is_client_dir(string $dir): bool {
  return is_dir($dir . '/includes') && is_file($dir . '/includes/config.php') && is_dir($dir . '/public');
}

$clients = [];
$dh = opendir($root);
if ($dh) {
  while (($name = readdir($dh)) !== false) {
    if ($name === '.' || $name === '..') continue;
    if ($name === 'admin' || $name === '_template') continue;
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
