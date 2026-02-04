<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';

session_start_safe();
if (!empty($_SESSION['admin_user'])) {
    redirect('dashboard.php');
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
    <input name="username" required>
    <label>Contraseña</label>
    <input name="password" type="password" required>
    <button class="btn" type="submit">Entrar</button>
  </form>
  <p class="muted small">Demo: admin / 1234</p>
</div>
<?php page_foot(); ?>
