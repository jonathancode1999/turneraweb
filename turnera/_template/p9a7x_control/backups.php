<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_nav.php';
admin_require_login();
admin_require_permission('system');

$cfg = function_exists('app_config') ? app_config() : [];

$dstDir = __DIR__ . '/../data/backups';
if (!is_dir($dstDir)) mkdir($dstDir, 0777, true);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_require();
  $ts = date('Ymd_His');
  $host = $cfg['db_host'] ?? ($cfg['mysql_host'] ?? 'localhost');
  $port = (int)($cfg['db_port'] ?? ($cfg['mysql_port'] ?? 3306));
  $dbn  = $cfg['db_name'] ?? ($cfg['mysql_db'] ?? '');
  $user = $cfg['db_user'] ?? ($cfg['mysql_user'] ?? '');
  $pass = $cfg['db_pass'] ?? ($cfg['mysql_pass'] ?? '');
  $dst = $dstDir . '/db_' . $ts . '.sql';

  $bin = trim((string)@shell_exec('command -v mysqldump 2>/dev/null'));
  if ($bin === '') {
    flash('No se encontró mysqldump en el servidor. Instalalo o hacé el dump desde consola.', 'err');
    redirect('backups.php');
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
    flash('No se pudo generar el dump MySQL (código '.$code.'). Probá desde consola con mysqldump.', 'err');
    redirect('backups.php');
  }
  flash('Backup (dump) creado: ' . basename($dst), 'ok');
  redirect('backups.php');
}

$files = glob($dstDir . '/db_*.sql') ?: [];
rsort($files);

page_head('Backups', 'admin');
admin_nav('system');

echo '<div class="card">';
echo '<div class="card-title">Copias de seguridad</div>';
echo '<form method="post" style="margin-top:10px">';
csrf_field();
echo '<button class="btn primary" type="submit">Crear backup ahora</button>';
echo '<div class="muted" style="margin-top:8px">Se genera un <b>dump .sql</b> de MySQL (requiere mysqldump).</div>';
echo '</form>';

if (!$files) {
  echo '<div class="muted" style="margin-top:10px">No hay backups todavía.</div>';
} else {
  echo '<table class="table" style="margin-top:10px"><tr><th>Archivo</th><th>Fecha</th><th></th></tr>';
  foreach ($files as $f) {
    $bn = basename($f);
    $dt = $bn;
    if (preg_match('/^db_(\d{8})_(\d{6})\.sql$/', $bn)) $dt = preg_replace('/^db_(\d{8})_(\d{6})\.sql$/', '$1 $2', $bn);
    echo '<tr><td>'.h($bn).'</td><td class="muted">'.h($dt).'</td><td><a class="btn" href="../data/backups/'.h($bn).'" download>Descargar</a></td></tr>';
  }
  echo '</table>';
}
echo '</div>';

page_foot();
