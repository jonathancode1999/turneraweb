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

// Copy template source from the app bundle into a sibling client directory.
$tpl = admin_template_dir();
if(!is_dir($tpl)){
  flash_set('err','No existe el template base.');
  header('Location: dashboard.php'); exit;
}

admin_recursive_copy($tpl, $target);
admin_normalize_client_layout($target);

// Create business in shared MySQL database
$pdo = sa_pdo();

// Shared schema bootstrap happens automatically in sa_pdo() on first successful connection.

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
