<?php
require __DIR__.'/_inc.php'; require_login(); csrf_check();

$slug = trim($_POST['slug'] ?? '');
$business = trim($_POST['business_name'] ?? '');
$adminUser = trim($_POST['admin_user'] ?? 'admin');
$adminPass = (string)($_POST['admin_pass'] ?? '');

if($slug==='' || $business==='' || $adminUser==='' || $adminPass===''){
  flash_set('err','Campos incompletos.');
  header('Location: dashboard.php'); exit;
}
if(!client_slug_valid($slug)){
  flash_set('err','Slug inválido.');
  header('Location: dashboard.php'); exit;
}

$root = cfg()['root_dir'];
$target = $root . DIRECTORY_SEPARATOR . $slug;
if(file_exists($target)){
  flash_set('err','Ya existe un cliente con ese slug.');
  header('Location: dashboard.php'); exit;
}

// Copy template
$tpl = $root . DIRECTORY_SEPARATOR . '_template';
if(!is_dir($tpl)){
  flash_set('err','No existe _template.');
  header('Location: dashboard.php'); exit;
}

function rcopy($src, $dst){
  mkdir($dst, 0777, true);
  $dir = opendir($src);
  while(false !== ($file = readdir($dir))){
    if($file==='.'||$file==='..') continue;
    $s = $src.DIRECTORY_SEPARATOR.$file;
    $d = $dst.DIRECTORY_SEPARATOR.$file;
    if(is_dir($s)) rcopy($s,$d);
    else copy($s,$d);
  }
  closedir($dir);
}
rcopy($tpl, $target);

$dbPath = $target . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'app.sqlite';
// (Re)Create DB from schema.sql so new clients always start with the latest schema
if (file_exists($dbPath)) @unlink($dbPath);
$schemaFile = $target . DIRECTORY_SEPARATOR . 'schema.sql';
if (!file_exists($schemaFile)) {
  flash_set('err','Falta schema.sql en el template.');
  header('Location: dashboard.php'); exit;
}
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');

$sql = file_get_contents($schemaFile);
if ($sql === false) {
  flash_set('err','No se pudo leer schema.sql');
  header('Location: dashboard.php'); exit;
}
$pdo->exec($sql);

// Init DB: set business name + admin credentials, clear demo data if any

$pdo->beginTransaction();
try{
  $pdo->prepare('UPDATE businesses SET name=:n WHERE id=1')->execute([':n'=>$business]);

  // Reset users to only one admin
  $pdo->exec('DELETE FROM users WHERE business_id=1;');
  $hash = password_hash($adminPass, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare('INSERT INTO users (business_id, username, password_hash, role) VALUES (1, :u, :p, :r)');
  $stmt->execute([':u'=>$adminUser, ':p'=>$hash, ':r'=>'admin']);

  // Ensure at least 1 branch
  $branchId = (int)$pdo->query('SELECT id FROM branches WHERE business_id=1 ORDER BY id ASC LIMIT 1')->fetchColumn();
  if($branchId<=0){
    $pdo->prepare('INSERT INTO branches (business_id, name) VALUES (1, :n)')->execute([':n'=>'Sucursal 1']);
    $branchId = (int)$pdo->lastInsertId();
  }

  // Ensure business_hours exists for 7 weekdays
  $count = (int)$pdo->query('SELECT COUNT(*) FROM business_hours WHERE business_id=1 AND branch_id='.(int)$branchId)->fetchColumn();
  if($count<7){
    $pdo->exec('DELETE FROM business_hours WHERE business_id=1 AND branch_id='.(int)$branchId);
    for($wd=0;$wd<=6;$wd++){
      if($wd===0){
        $pdo->prepare('INSERT INTO business_hours (business_id, branch_id, weekday, is_closed) VALUES (1, :b, :w, 1)')
            ->execute([':b'=>$branchId, ':w'=>$wd]);
      } else {
        $pdo->prepare('INSERT INTO business_hours (business_id, branch_id, weekday, open_time, close_time, is_closed) VALUES (1, :b, :w, :o, :c, 0)')
            ->execute([':b'=>$branchId, ':w'=>$wd, ':o'=>'09:00', ':c'=>'19:00']);
      }
    }
  }

  // Clean demo tables
  $pdo->exec('DELETE FROM services WHERE business_id=1;');
  $pdo->exec('DELETE FROM barbers WHERE business_id=1;');
  $pdo->exec('DELETE FROM barber_hours WHERE business_id=1;');
  $pdo->exec('DELETE FROM blocks WHERE business_id=1;');
  $pdo->exec('DELETE FROM appointments WHERE business_id=1;');

  $pdo->commit();
} catch(Throwable $e){
  $pdo->rollBack();
  // remove folder if failed
  // best effort
  flash_set('err','Error creando cliente: '.$e->getMessage());
  header('Location: dashboard.php'); exit;
}

flash_set('ok','Cliente creado: '.$slug);
header('Location: manage.php?c='.urlencode($slug));
