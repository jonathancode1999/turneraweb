<?php require __DIR__.'/_inc.php';
if(is_logged()){ header('Location: index.php'); exit; }
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
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
<div class="card" style="max-width:420px;margin:0 auto;">
  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div style="margin-bottom:10px">
      <label>Usuario</label>
      <input name="u" autocomplete="username" required>
    </div>
    <div style="margin-bottom:12px">
      <label>Contraseña</label>
      <input name="p" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn-primary" type="submit">Entrar</button>
  </form>
  <div class="small" style="margin-top:10px">Tip: cambiá usuario/clave en <code>admin/config.php</code> al subirlo.</div>
</div>
<?php footer_html(); ?>
