<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_nav.php';
admin_require_login();
admin_require_permission('system');

$dstDir = __DIR__ . '/../data/backups';
if (!is_dir($dstDir)) mkdir($dstDir, 0777, true);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_require();
  // trigger backup
  $src = __DIR__ . '/../data/app.sqlite';
  $ts = date('Ymd_His');
  $dst = $dstDir . '/app_' . $ts . '.sqlite';
  @copy($src, $dst);
  flash('Backup creado: ' . basename($dst), 'ok');
  redirect('backups.php');
}

$files = glob($dstDir . '/app_*.sqlite');
rsort($files);

page_head('Backups', 'admin');
admin_nav('system');

echo '<div class="card">';
echo '<div class="card-title">Copias de seguridad</div>';
echo '<form method="post" style="margin-top:10px">';
csrf_field();
echo '<button class="btn primary" type="submit">Crear backup ahora</button>';
echo '</form>';

if (!$files) {
  echo '<div class="muted" style="margin-top:10px">No hay backups todavía.</div>';
} else {
  echo '<table class="table" style="margin-top:10px"><tr><th>Archivo</th><th>Fecha</th><th></th></tr>';
  foreach ($files as $f) {
    $bn = basename($f);
    $dt = preg_replace('/^app_(\d{8})_(\d{6})\.sqlite$/', '$1 $2', $bn);
    echo '<tr><td>'.h($bn).'</td><td class="muted">'.h($dt).'</td><td><a class="btn" href="../data/backups/'.h($bn).'" download>Descargar</a></td></tr>';
  }
  echo '</table>';
}
echo '</div>';

page_foot();
