<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';

$cfg = app_config();
$src = __DIR__ . '/../data/app.sqlite';
if (!file_exists($src)) {
  echo "No DB file\n";
  exit(1);
}
$dstDir = __DIR__ . '/../data/backups';
if (!is_dir($dstDir)) mkdir($dstDir, 0777, true);

$ts = date('Ymd_His');
$dst = $dstDir . '/app_' . $ts . '.sqlite';
if (!copy($src, $dst)) {
  echo "Copy failed\n";
  exit(1);
}
echo "OK: " . basename($dst) . "\n";
