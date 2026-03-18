<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';

$cfg = app_config();
$driver = $cfg['db_driver'] ?? 'mysql';
$dstDir = __DIR__ . '/../data/backups';
if (!is_dir($dstDir)) mkdir($dstDir, 0777, true);

$ts = date('Ymd_His');
if ($driver === 'sqlite') {
  $src = __DIR__ . '/../data/app.sqlite';
  if (!file_exists($src)) {
    echo "No DB file\n";
    exit(1);
  }
  $dst = $dstDir . '/app_' . $ts . '.sqlite';
  if (!copy($src, $dst)) {
    echo "Copy failed\n";
    exit(1);
  }
  echo "OK: " . basename($dst) . "\n";
  exit(0);
}

// MySQL: dump to .sql (requires mysqldump)
$host = $cfg['mysql_host'] ?? '127.0.0.1';
$port = (int)($cfg['mysql_port'] ?? 3306);
$dbn  = $cfg['mysql_db'] ?? '';
$user = $cfg['mysql_user'] ?? '';
$pass = $cfg['mysql_pass'] ?? '';
$dst = $dstDir . '/db_' . $ts . '.sql';

$bin = trim((string)@shell_exec('command -v mysqldump 2>/dev/null'));
if ($bin === '') {
  echo "mysqldump not found\n";
  exit(1);
}

$cmd = 'MYSQL_PWD=' . escapeshellarg($pass) . ' ' . escapeshellcmd($bin)
  . ' --single-transaction --quick --lock-tables=false'
  . ' -h ' . escapeshellarg($host)
  . ' -P ' . escapeshellarg((string)$port)
  . ' -u ' . escapeshellarg($user)
  . ' ' . escapeshellarg($dbn)
  . ' > ' . escapeshellarg($dst) . ' 2>/dev/null';

@exec($cmd, $out, $code);
if ($code !== 0 || !file_exists($dst) || filesize($dst) <= 0) {
  echo "Dump failed (code $code)\n";
  exit(1);
}

echo "OK: " . basename($dst) . "\n";
