<?php require __DIR__.'/_inc.php';
if (is_logged()) { header('Location: dashboard.php'); exit; }
$questions = admin_security_questions();
$defaults = cfg();
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check('setup.php');
  $username = trim((string)($_POST['username'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $password2 = (string)($_POST['password2'] ?? '');
  $securityQuestion = trim((string)($_POST['security_question'] ?? ''));
  $securityAnswer = trim((string)($_POST['security_answer'] ?? ''));

  if ($username === '' || $email === '' || $password === '' || $password2 === '' || $securityQuestion === '' || $securityAnswer === '') {
    flash_set('err','Completá todos los campos.');
    header('Location: setup.php'); exit;
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('err','Ingresá un correo válido.');
    header('Location: setup.php'); exit;
  }
  if ($password !== $password2) {
    flash_set('err','Las contraseñas no coinciden.');
    header('Location: setup.php'); exit;
  }
  if (!admin_password_is_strong($password)) {
    flash_set('err','La contraseña debe tener al menos 10 caracteres e incluir mayúscula, minúscula y número.');
    header('Location: setup.php'); exit;
  }
  if (!isset($questions[$securityQuestion])) {
    flash_set('err','Elegí una pregunta de seguridad válida.');
    header('Location: setup.php'); exit;
  }
  if (mb_strlen($securityAnswer) < 3) {
    flash_set('err','La respuesta de seguridad debe tener al menos 3 caracteres.');
    header('Location: setup.php'); exit;
  }

  super_admin_update_credentials($username, $email, $password, $securityQuestion, $securityAnswer);
  flash_set('ok','Usuario super admin configurado. Ya podés iniciar sesión.');
  header('Location: login.php'); exit;
}
header_html(super_admin_needs_setup() ? 'Primer usuario' : 'Reconfigurar acceso');
?>
<div class="card" style="max-width:620px;margin:0 auto;">
  <h2 style="margin-top:0"><?= super_admin_needs_setup() ? 'Crear usuario inicial' : 'Reconfigurar usuario super admin' ?></h2>
  <p class="small" style="margin-top:0">Definí el usuario principal del panel, su correo de recuperación, la pregunta de seguridad y una contraseña fuerte.</p>
  <form method="post" data-password-pair>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div style="margin-bottom:10px">
      <label>Usuario</label>
      <input name="username" required autocomplete="username" value="<?=h((string)($_POST['username'] ?? ($defaults['super_user'] ?? '')))?>">
    </div>
    <div style="margin-bottom:10px">
      <label>Correo de recuperación</label>
      <input name="email" type="email" required autocomplete="email" value="<?=h((string)($_POST['email'] ?? ($defaults['super_email'] ?? '')))?>">
    </div>
    <div style="margin-bottom:10px">
      <label>Pregunta de seguridad</label>
      <select name="security_question" required>
        <option value="">Elegí una pregunta</option>
        <?php foreach($questions as $key => $label): ?>
          <?php $selected = (string)($_POST['security_question'] ?? ($defaults['super_security_question'] ?? '')) === $key; ?>
          <option value="<?=h($key)?>" <?=$selected ? 'selected' : ''?>><?=h($label)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="margin-bottom:10px">
      <label>Respuesta de seguridad</label>
      <input name="security_answer" required autocomplete="off" minlength="3">
    </div>
    <div style="margin-bottom:10px">
      <label>Contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="setup-password" name="password" type="password" required autocomplete="new-password" style="flex:1">
        <button class="btn toggle-password" type="button" data-toggle-password="setup-password" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
      </div>
      <div class="small" style="margin-top:6px">Usá al menos 10 caracteres, con mayúscula, minúscula y número.</div>
    </div>
    <div style="margin-bottom:10px">
      <label>Repetir contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="setup-password2" name="password2" type="password" required autocomplete="new-password" style="flex:1" data-password-confirm="#setup-password">
        <button class="btn toggle-password" type="button" data-toggle-password="setup-password2" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
      </div>
      <div class="small password-match" data-password-match-message aria-live="polite"></div>
    </div>
    <button class="btn btn-primary" type="submit"><?= super_admin_needs_setup() ? 'Crear usuario' : 'Guardar cambios' ?></button>
  </form>
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
