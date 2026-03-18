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
    flash_set('err','La contraseña debe tener al menos 10 caracteres e incluir mayúscula, minúscula y número.');
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
  <form method="post" data-password-pair>
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
        <button class="btn toggle-password" type="button" data-toggle-password="forgot-password" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
      </div>
      <div class="small" style="margin-top:6px">Usá al menos 10 caracteres, con mayúscula, minúscula y número.</div>
    </div>
    <div style="margin-bottom:10px">
      <label>Repetir nueva contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="forgot-password2" name="password2" type="password" required autocomplete="new-password" style="flex:1" data-password-confirm="#forgot-password">
        <button class="btn toggle-password" type="button" data-toggle-password="forgot-password2" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
      </div>
      <div class="small password-match" data-password-match-message aria-live="polite"></div>
    </div>
    <button class="btn btn-primary" type="submit">Actualizar contraseña</button>
  </form>
  <div class="small" style="margin-top:12px"><a href="login.php">Volver al login</a></div>
</div>
<script>
(function(){
  function bindPasswordToggle(button){
    button.addEventListener('click', function(){
      var input = document.getElementById(button.getAttribute('data-toggle-password'));
      if (!input) return;
      var visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      button.textContent = visible ? '🙈' : '👁';
      button.setAttribute('aria-pressed', visible ? 'true' : 'false');
      button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
      button.classList.toggle('is-visible', visible);
    });
  }

  function bindPasswordPair(scope){
    var password = scope.querySelector('input[name="password"]');
    var confirm = scope.querySelector('[data-password-confirm]');
    var message = scope.querySelector('[data-password-match-message]');
    if (!password || !confirm || !message) return;
    function refresh(){
      if (!confirm.value) {
        message.textContent = 'Repetí la contraseña para verificar que coincida.';
        message.className = 'small password-match';
        confirm.setCustomValidity('');
        return;
      }
      if (password.value === confirm.value) {
        message.textContent = 'Las contraseñas coinciden.';
        message.className = 'small password-match match-ok';
        confirm.setCustomValidity('');
      } else {
        message.textContent = 'Las contraseñas no coinciden todavía.';
        message.className = 'small password-match match-bad';
        confirm.setCustomValidity('Las contraseñas no coinciden.');
      }
    }
    password.addEventListener('input', refresh);
    confirm.addEventListener('input', refresh);
    refresh();
  }

  document.querySelectorAll('[data-toggle-password]').forEach(bindPasswordToggle);
  document.querySelectorAll('[data-password-pair]').forEach(bindPasswordPair);
})();
</script>
<?php footer_html(); ?>
