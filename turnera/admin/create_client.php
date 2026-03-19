<?php
require __DIR__.'/_inc.php'; require_login(); csrf_check();

$slug = trim($_POST['slug'] ?? '');
$business = trim($_POST['business_name'] ?? '');
$adminUser = trim($_POST['admin_user'] ?? '');
$adminEmail = trim($_POST['admin_email'] ?? '');
$adminPass = (string)($_POST['admin_pass'] ?? '');
$adminPass2 = (string)($_POST['admin_pass2'] ?? '');
$securityQuestion = trim((string)($_POST['security_question'] ?? ''));
$securityAnswer = trim((string)($_POST['security_answer'] ?? ''));
$securityQuestions = admin_security_questions();

if($slug==='' || $business==='' || $adminUser==='' || $adminEmail==='' || $adminPass==='' || $adminPass2==='' || $securityQuestion==='' || $securityAnswer===''){
  flash_set('err','Campos incompletos.');
  header('Location: dashboard.php'); exit;
}
if(!client_slug_valid($slug)){
  flash_set('err','Slug inválido.');
  header('Location: dashboard.php'); exit;
}
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
  flash_set('err','El correo del admin no es válido.');
  header('Location: dashboard.php'); exit;
}
if ($adminPass !== $adminPass2) {
  flash_set('err','Las contraseñas del admin no coinciden.');
  header('Location: dashboard.php'); exit;
}
if (!admin_password_is_strong($adminPass)) {
  flash_set('err', admin_password_error_message($adminPass));
  header('Location: dashboard.php'); exit;
}
if (!isset($securityQuestions[$securityQuestion])) {
  flash_set('err','Elegí una pregunta de seguridad válida.');
  header('Location: dashboard.php'); exit;
}
if (mb_strlen($securityAnswer) < 3) {
  flash_set('err','La respuesta de seguridad debe tener al menos 3 caracteres.');
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


// Create business in shared MySQL database
$pdo = sa_pdo();

// Ensure MySQL schema exists
$schemaFile = cfg()['root_dir'] . DIRECTORY_SEPARATOR . 'schema_mysql.sql';
if (!file_exists($schemaFile)) {
  flash_set('err','Falta schema_mysql.sql.');
  header('Location: dashboard.php'); exit;
}
// Apply schema to the shared DB (idempotent).
// IMPORTANT: avoid executing comment-only chunks which can cause random MySQL 1064 errors.
$raw = file_get_contents($schemaFile);
if ($raw === false) {
  flash_set('err','No se pudo leer schema_mysql.sql.');
  header('Location: dashboard.php');
  exit;
}

// Strip BOM (if any)
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

// Remove /* ... */ blocks
$rawNoBlockComments = preg_replace('#/\*.*?\*/#s', '', $raw);

// Split by ';' and remove single-line comments / empty chunks
try {
  foreach (explode(';', $rawNoBlockComments) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    // Ignore comment-only chunks
    if (preg_match('/^(--|#)/', $stmt)) continue;
    $pdo->exec($stmt);
  }
} catch (Exception $e) {
  flash_set('err', 'Error al aplicar schema_mysql.sql: ' . $e->getMessage());
  header('Location: dashboard.php');
  exit;
}

$pdo->beginTransaction();
try {
  // Create business
  $bst = $pdo->prepare("INSERT INTO businesses (name, timezone, slot_minutes, slot_capacity, payment_mode, deposit_percent_default) VALUES (?,?,?,?,?,?)");
  $bst->execute([$business, 'America/Argentina/Buenos_Aires', 15, 1, 'OFF', 30]);
  $businessId = (int)$pdo->lastInsertId();

  // Default branch
  $pdo->prepare("INSERT INTO branches (business_id, name) VALUES (?,?)")->execute([$businessId, 'Sucursal Principal']);
  $branchId = (int)$pdo->lastInsertId();

  // Admin user
  $hash = password_hash($adminPass, PASSWORD_DEFAULT);
  $securityHash = admin_security_answer_hash($securityAnswer);
  $pdo->prepare("INSERT INTO users (business_id, username, email, password_hash, security_question, security_answer_hash, role) VALUES (?,?,?,?,?,?,?)")
      ->execute([$businessId, $adminUser, $adminEmail, $hash, $securityQuestion, $securityHash, 'admin']);

  // Default hours
  for($wd=0;$wd<=6;$wd++){
    if($wd===0){
      $pdo->prepare("INSERT INTO business_hours (business_id, branch_id, weekday, is_closed) VALUES (?,?,?,1)")
          ->execute([$businessId, $branchId, $wd]);
    } else {
      $pdo->prepare("INSERT INTO business_hours (business_id, branch_id, weekday, open_time, close_time, is_closed) VALUES (?,?,?,?,?,0)")
          ->execute([$businessId, $branchId, $wd, '09:00', '19:00']);
    }
  }

  $pdo->commit();
} catch(Throwable $e){
  $pdo->rollBack();
  flash_set('err','Error creando cliente: '.$e->getMessage());
  header('Location: dashboard.php'); exit;
}

// Patch client config with new business_id
$cfgFile = $target . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
$cfgTxt = file_get_contents($cfgFile);
$cfgTxt = preg_replace("/'business_id'\s*=>\s*\d+\s*,/", "'business_id' => ".$businessId.",", $cfgTxt);
file_put_contents($cfgFile, $cfgTxt);

flash_set('ok','Cliente creado: '.$slug);
header('Location: manage.php?c='.urlencode($slug));
