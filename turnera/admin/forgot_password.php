<?php require __DIR__.'/_inc.php';
if (super_admin_needs_setup()) { header('Location: setup.php'); exit; }
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check('forgot_password.php');
  $username = trim((string)($_POST['username'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $answer = trim((string)($_POST['security_answer'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $password2 = (string)($_POST['password2'] ?? '');
  $config = cfg();

  if ($username === '' || $email === '' || $answer === '' || $password === '' || $password2 === '') {
    flash_set('err','Completá todos los campos para recuperar el acceso.');
    header('Location: forgot_password.php'); exit;
  }
  if (!hash_equals((string)($config['super_user'] ?? ''), $username) || !hash_equals((string)($config['super_email'] ?? ''), $email)) {
    flash_set('err','El usuario o el correo no coinciden con el super admin configurado.');
    header('Location: forgot_password.php'); exit;
  }
  if (!super_admin_verify_security_answer($answer)) {
    flash_set('err','La respuesta de seguridad no coincide.');
    header('Location: forgot_password.php'); exit;
  }
  if ($password !== $password2) {
    flash_set('err','Las contraseñas no coinciden.');
    header('Location: forgot_password.php'); exit;
  }
  if (!admin_password_is_strong($password)) {
    flash_set('err', admin_password_error_message($password));
    header('Location: forgot_password.php'); exit;
  }

  super_admin_reset_password($password);
  flash_set('ok','Contraseña actualizada. Ya podés iniciar sesión.');
  header('Location: login.php'); exit;
}
header_html('Recuperar contraseña');
?>
<div class="card" style="max-width:620px;margin:0 auto;">
  <h2 style="margin-top:0">Recuperar contraseña del super admin</h2>
  <p class="small" style="margin-top:0">Ingresá el usuario, el correo de recuperación y respondé la pregunta de seguridad para definir una nueva contraseña.</p>
  <form method="post" data-password-pair data-password-input="forgot-password" data-confirm-input="forgot-password2">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div style="margin-bottom:10px">
      <label>Usuario</label>
      <input name="username" required autocomplete="username" value="<?=h((string)($_POST['username'] ?? ''))?>">
    </div>
    <div style="margin-bottom:10px">
      <label>Correo de recuperación</label>
      <input name="email" type="email" required autocomplete="email" value="<?=h((string)($_POST['email'] ?? ''))?>">
    </div>
    <div style="margin-bottom:10px">
      <label>Pregunta de seguridad</label>
      <input value="<?=h(super_admin_question_label())?>" disabled>
    </div>
    <div style="margin-bottom:10px">
      <label>Respuesta de seguridad</label>
      <input name="security_answer" required autocomplete="off">
    </div>
    <div style="margin-bottom:10px">
      <label>Nueva contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="forgot-password" name="password" type="password" required autocomplete="new-password" style="flex:1">
        <?=render_password_toggle_button('forgot-password')?>
      </div>
      <?=render_password_requirements_block()?>
    </div>
    <div style="margin-bottom:10px">
      <label>Repetir nueva contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="forgot-password2" name="password2" type="password" required autocomplete="new-password" style="flex:1">
        <?=render_password_toggle_button('forgot-password2')?>
      </div>
      <div class="password-match match-bad" data-password-match-message aria-live="polite">Repetí la contraseña para confirmar que coincide.</div>
    </div>
    <button class="btn btn-primary" type="submit">Actualizar contraseña</button>
  </form>
  <div class="small" style="margin-top:12px"><a href="login.php">Volver al login</a></div>
</div>
<script src="assets/password-ui.js"></script>
<?php footer_html(); ?>
