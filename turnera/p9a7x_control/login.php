<?php require __DIR__.'/_inc.php';

$securityQuestions = admin_security_questions();
$requestedMode = trim((string)($_GET['mode'] ?? 'login'));
$allowedModes = ['login', 'setup', 'recover'];
if (!in_array($requestedMode, $allowedModes, true)) {
  $requestedMode = 'login';
}
$currentMode = super_admin_needs_setup() ? 'setup' : $requestedMode;

if (is_logged() && $currentMode === 'login') {
  header('Location: dashboard.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $formMode = trim((string)($_POST['mode'] ?? 'login'));
  if (!in_array($formMode, $allowedModes, true)) {
    $formMode = 'login';
  }
  if (super_admin_needs_setup() && $formMode !== 'setup') {
    $formMode = 'setup';
  }

  csrf_check('login.php?mode='.$formMode);

  if ($formMode === 'login') {
    $u = trim($_POST['u'] ?? '');
    $p = $_POST['p'] ?? '';
    if (login_ok($u, $p)) {
      $_SESSION['sa_logged'] = true;
      flash_set('ok', 'Bienvenido.');
      header('Location: dashboard.php'); exit;
    }
    flash_set('err', 'Usuario o contraseña inválidos.');
    header('Location: login.php'); exit;
  }

  if ($formMode === 'setup') {
    $u = trim($_POST['u'] ?? '');
    $e = trim($_POST['e'] ?? '');
    $p = $_POST['p'] ?? '';
    $p2 = $_POST['p2'] ?? '';
    $q = trim($_POST['q'] ?? '');
    $a = trim($_POST['a'] ?? '');
    if ($u === '' || strlen($u) < 3) {
      flash_set('err', 'Ingresá un usuario válido (mínimo 3 caracteres).');
      header('Location: login.php?mode=setup'); exit;
    }
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
      flash_set('err', 'Ingresá un correo válido.');
      header('Location: login.php?mode=setup'); exit;
    }
    if (!isset($securityQuestions[$q])) {
      flash_set('err', 'Elegí una pregunta de seguridad válida.');
      header('Location: login.php?mode=setup'); exit;
    }
    if (mb_strlen($a) < 3) {
      flash_set('err', 'La respuesta de seguridad debe tener al menos 3 caracteres.');
      header('Location: login.php?mode=setup'); exit;
    }
    if ($p !== $p2) {
      flash_set('err', 'Las contraseñas no coinciden.');
      header('Location: login.php?mode=setup'); exit;
    }
    if (!admin_password_is_strong($p)) {
      flash_set('err', admin_password_error_message($p));
      header('Location: login.php?mode=setup'); exit;
    }
    super_admin_update_credentials($u, $e, $p, $q, $a);
    $_SESSION['sa_logged'] = true;
    flash_set('ok', 'Usuario super admin configurado. Ya podés iniciar sesión.');
    header('Location: dashboard.php'); exit;
  }

  $u = trim($_POST['u'] ?? '');
  $e = trim($_POST['e'] ?? '');
  $a = trim($_POST['a'] ?? '');
  $p = $_POST['p'] ?? '';
  $p2 = $_POST['p2'] ?? '';
  if (!hash_equals(strtolower(trim((string)(cfg()['super_user'] ?? ''))), strtolower($u))) {
    flash_set('err', 'El usuario no coincide con el super admin configurado.');
    header('Location: login.php?mode=recover'); exit;
  }
  if (!filter_var($e, FILTER_VALIDATE_EMAIL) || !hash_equals(strtolower(trim((string)(cfg()['super_email'] ?? ''))), strtolower($e))) {
    flash_set('err', 'El usuario o el correo no coinciden con el super admin configurado.');
    header('Location: login.php?mode=recover'); exit;
  }
  if ($a === '') {
    flash_set('err', 'Ingresá la respuesta de seguridad.');
    header('Location: login.php?mode=recover'); exit;
  }
  if (!super_admin_verify_security_answer($a)) {
    flash_set('err', 'La respuesta de seguridad no coincide.');
    header('Location: login.php?mode=recover'); exit;
  }
  if ($p === '' || $p2 === '') {
    flash_set('err', 'Completá la nueva contraseña.');
    header('Location: login.php?mode=recover'); exit;
  }
  if ($p !== $p2) {
    flash_set('err', 'Las contraseñas no coinciden.');
    header('Location: login.php?mode=recover'); exit;
  }
  if (!admin_password_is_strong($p)) {
    flash_set('err', admin_password_error_message($p));
    header('Location: login.php?mode=recover'); exit;
  }
  super_admin_reset_password($p);
  flash_set('ok', 'Contraseña actualizada. Ya podés iniciar sesión.');
  header('Location: login.php'); exit;
}

$pageTitle = match ($currentMode) {
  'setup' => 'Configurar Super Admin',
  'recover' => 'Recuperar contraseña',
  default => 'Login Super Admin',
};

header_html($pageTitle);
?>
<?php if ($currentMode === 'setup'): ?>
  <div class="card" style="max-width:560px;margin:0 auto;">
    <h2 style="margin-top:0">Crear / configurar usuario inicial</h2>
    <form method="post" data-password-pair data-password-input="setup-password" data-confirm-input="setup-password2">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="mode" value="setup">
      <label>Usuario</label>
      <input name="u" value="<?=h((string)($_POST['u'] ?? super_admin_username()))?>" autocomplete="username" required>
      <label>Correo</label>
      <input name="e" type="email" value="<?=h((string)($_POST['e'] ?? (cfg()['super_email'] ?? '')))?>" autocomplete="email" required>
      <label>Pregunta de seguridad</label>
      <select name="q" required>
        <option value="">Elegí una pregunta</option>
        <?php foreach ($securityQuestions as $key => $label): ?>
          <option value="<?=h($key)?>" <?=((string)($_POST['q'] ?? cfg()['super_security_question'] ?? '') === $key ? 'selected' : '')?>><?=h($label)?></option>
        <?php endforeach; ?>
      </select>
      <label>Respuesta de seguridad</label>
      <input name="a" value="<?=h((string)($_POST['a'] ?? ''))?>" autocomplete="off" required minlength="3">
      <label>Contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="setup-password" type="password" name="p" autocomplete="new-password" required style="flex:1">
        <?=render_password_toggle_button('setup-password')?>
      </div>
      <?=render_password_requirements_block()?>
      <label>Repetir contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="setup-password2" type="password" name="p2" autocomplete="new-password" required style="flex:1">
        <?=render_password_toggle_button('setup-password2')?>
      </div>
      <div class="password-match match-bad" data-password-match-message aria-live="polite" hidden style="margin:6px 0 0 0">Repetí la contraseña para confirmar que coincide.</div>
      <div style="margin-top:12px">
        <button class="btn btn-primary" type="submit">Guardar</button>
      </div>
    </form>
    <?php if (!super_admin_needs_setup()): ?>
      <div class="small" style="margin-top:12px"><a href="login.php">Volver al login</a></div>
    <?php endif; ?>
  </div>
<?php elseif ($currentMode === 'recover'): ?>
  <div class="card" style="max-width:560px;margin:0 auto;">
    <h2 style="margin-top:0">Recuperar contraseña del super admin</h2>
    <form method="post" data-password-pair data-password-input="reset-password" data-confirm-input="reset-password2">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="mode" value="recover">
      <label>Usuario</label>
      <input name="u" autocomplete="username" required>
      <label>Correo</label>
      <input name="e" type="email" autocomplete="email" required>
      <label>Pregunta de seguridad</label>
      <div class="small" style="margin-bottom:6px"><?=h(super_admin_question_label())?></div>
      <input name="a" autocomplete="off" required placeholder="Tu respuesta">
      <label>Nueva contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="reset-password" type="password" name="p" autocomplete="new-password" required style="flex:1">
        <?=render_password_toggle_button('reset-password')?>
      </div>
      <?=render_password_requirements_block()?>
      <label>Repetir contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="reset-password2" type="password" name="p2" autocomplete="new-password" required style="flex:1">
        <?=render_password_toggle_button('reset-password2')?>
      </div>
      <div class="password-match match-bad" data-password-match-message aria-live="polite" hidden style="margin:6px 0 0 0">Repetí la contraseña para confirmar que coincide.</div>
      <div style="margin-top:12px">
        <button class="btn btn-primary" type="submit">Actualizar contraseña</button>
      </div>
    </form>
    <div class="small" style="margin-top:12px"><a href="login.php">Volver al login</a></div>
  </div>
<?php else: ?>
  <div class="card" style="max-width:520px;margin:0 auto;">
    <h2 style="margin-top:0">Ingresar</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="mode" value="login">
      <label>Usuario</label>
      <input name="u" autocomplete="username" required>
      <label>Contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="login-password" type="password" name="p" autocomplete="current-password" required style="flex:1">
        <?=render_password_toggle_button('login-password')?>
      </div>
      <div style="margin-top:12px">
        <button class="btn btn-primary" type="submit">Entrar</button>
      </div>
    </form>
    <div class="small" style="margin-top:12px;display:flex;gap:12px;flex-wrap:wrap">
      <a href="login.php?mode=recover">Olvidé mi contraseña</a>
      <a href="login.php?mode=setup">Crear o reconfigurar usuario inicial</a>
    </div>
  </div>
<?php endif; ?>
<script src="assets/password-ui.js"></script>
<?php footer_html(); ?>
