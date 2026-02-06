<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

session_start_safe();

if (!admin_needs_setup()) {
    // Already configured
    redirect('login.php');
}

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$pdo = db();

$error = '';
$notice = '';

function strong_password_ok(string $p): bool {
    if (strlen($p) < 10) return false;
    if (!preg_match('/[A-Z]/', $p)) return false;
    if (!preg_match('/[a-z]/', $p)) return false;
    if (!preg_match('/\d/', $p)) return false;
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $username = trim((string)($_POST['username'] ?? ''));
    $pass1 = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    try {
        if ($username === '' || strlen($username) < 3) {
            throw new RuntimeException('Ingresá un usuario válido (mínimo 3 caracteres).');
        }
        if ($pass1 !== $pass2) {
            throw new RuntimeException('Las contraseñas no coinciden.');
        }
        if (!strong_password_ok($pass1)) {
            throw new RuntimeException('La contraseña debe tener al menos 10 caracteres e incluir mayúscula, minúscula y número.');
        }

        // Re-check inside transaction to avoid race
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT COUNT(1) FROM users WHERE business_id=:bid');
        $st->execute([':bid'=>$bid]);
        $c = (int)($st->fetchColumn() ?: 0);
        if ($c > 0) {
            $pdo->rollBack();
            redirect('login.php');
        }

        $hash = password_hash($pass1, PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO users (business_id, username, password_hash, role) VALUES (:bid,:u,:h,:r)');
        $ins->execute([':bid'=>$bid, ':u'=>$username, ':h'=>$hash, ':r'=>'admin']);

        // Ensure full permissions for the admin account (idempotent)
        $pdo->exec("UPDATE users SET can_branches=1,can_settings=1,can_appointments=1,can_barbers=1,can_services=1,can_hours=1,can_blocks=1,can_system=1,can_analytics=1,all_branches=1 WHERE business_id=".(int)$bid." AND role='admin'");
        $pdo->commit();

        // Auto-login
        admin_login($username, $pass1);
        redirect('dashboard.php');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

page_head('Configurar Admin', 'admin');
?>
<div class="card" style="max-width:560px; margin:0 auto;">
  <h1>Primer inicio</h1>
  <p class="muted">Creá el usuario administrador para empezar a usar el panel.</p>

  <?php if ($error): ?>
    <div class="notice danger"><?php echo h($error); ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <label>Usuario</label>
    <input name="username" required minlength="3" maxlength="40" autocomplete="username">
    <label>Contraseña</label>
    <input name="password" type="password" required autocomplete="new-password">
    <label>Repetir contraseña</label>
    <input name="password2" type="password" required autocomplete="new-password">
    <p class="muted small" style="margin-top:8px">Mínimo 10 caracteres, con mayúscula, minúscula y número.</p>
    <button class="btn primary" type="submit">Crear administrador</button>
  </form>
</div>
<?php page_foot(); ?>
