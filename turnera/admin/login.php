<?php require __DIR__.'/_inc.php';
if (super_admin_needs_setup()) { header('Location: setup.php'); exit; }
if(is_logged()){ header('Location: index.php'); exit; }
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check('login.php');
  $u = trim($_POST['u'] ?? '');
  $p = (string)($_POST['p'] ?? '');
  if(login_ok($u,$p)){
    $_SESSION['sa_logged']=1;
    flash_set('ok','Bienvenido.');
    header('Location: index.php'); exit;
  } else {
    flash_set('err','Credenciales inválidas.');
    header('Location: login.php'); exit;
  }
}
header_html('Login');
?>
<div class="card" style="max-width:460px;margin:0 auto;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div style="margin-bottom:10px">
      <label>Usuario</label>
      <input name="u" autocomplete="username" required>
    </div>
    <div style="margin-bottom:12px">
      <label>Contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="login-password" name="p" type="password" autocomplete="current-password" required style="flex:1">
        <button class="btn toggle-password" type="button" data-toggle-password="login-password" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
      </div>
    </div>
    <button class="btn btn-primary" type="submit">Entrar</button>
  </form>
  <div class="small" style="margin-top:12px;display:flex;flex-direction:column;gap:6px">
    <a href="forgot_password.php">Olvidé mi contraseña</a>
    <a href="setup.php">Crear o reconfigurar usuario inicial</a>
  </div>
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
  document.querySelectorAll('[data-toggle-password]').forEach(bindPasswordToggle);
})();
</script>
<?php footer_html(); ?>
