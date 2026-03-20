<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';

session_start_safe();
if (admin_needs_setup()) {
    redirect('setup.php');
}
// If a stale session exists (e.g., user deleted/disabled), clear it to avoid redirect loops.
if (!empty($_SESSION['admin_user'])) {
    try {
        $cfg = app_config();
        $bid = (int)($cfg['business_id'] ?? 1);
        $uid = (int)($_SESSION['admin_user']['id'] ?? 0);
        if ($uid > 0) {
            $pdo = db();
            $st = $pdo->prepare('SELECT id, is_active FROM users WHERE business_id=:bid AND id=:id');
            $st->execute([':bid' => $bid, ':id' => $uid]);
            $row = $st->fetch();
            if ($row && (!isset($row['is_active']) || (int)$row['is_active'] === 1)) {
                redirect('dashboard.php');
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    admin_logout();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = trim($_POST['password'] ?? '');
    if (!admin_login($u, $p)) {
        $error = 'Usuario o contraseña incorrectos.';
    } else {
        redirect('dashboard.php');
    }
}

page_head('Admin - Login', 'admin');
?>
<div class="card" style="max-width:520px; margin:0 auto;">
  <h1>Admin</h1>
  <?php if ($error): ?>
    <div class="notice danger"><?php echo h($error); ?></div>
  <?php endif; ?>
  <form method="post">
    <label>Usuario</label>
    <input name="username" required autocomplete="username">
    <label>Contraseña</label>
    <div style="display:flex;gap:8px;align-items:center">
      <input id="login-password" name="password" type="password" required autocomplete="current-password" style="flex:1">
      <button class="btn" type="button" data-toggle-password="login-password" aria-label="Mostrar contraseña">👁</button>
    </div>
    <button class="btn" type="submit">Entrar</button>
  </form>
  <div class="muted" style="margin-top:12px">
    <a class="link" href="forgot_password.php">Olvidé mi contraseña</a>
  </div>
</div>
<script>
document.querySelectorAll('[data-toggle-password]').forEach(function(button){
  button.addEventListener('click', function(){
    var input = document.getElementById(button.getAttribute('data-toggle-password'));
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
  });
});
</script>
<?php page_foot(); ?>
