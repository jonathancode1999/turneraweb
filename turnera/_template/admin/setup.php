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
$securityQuestions = admin_security_questions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $securityQuestion = trim((string)($_POST['security_question'] ?? ''));
    $securityAnswer = trim((string)($_POST['security_answer'] ?? ''));
    $pass1 = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    try {
        if ($username === '' || strlen($username) < 3) {
            throw new RuntimeException('Ingresá un usuario válido (mínimo 3 caracteres).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ingresá un correo válido.');
        }
        if (!isset($securityQuestions[$securityQuestion])) {
            throw new RuntimeException('Elegí una pregunta de seguridad válida.');
        }
        if (mb_strlen($securityAnswer) < 3) {
            throw new RuntimeException('La respuesta de seguridad debe tener al menos 3 caracteres.');
        }
        if ($pass1 !== $pass2) {
            throw new RuntimeException('Las contraseñas no coinciden.');
        }
        if (!admin_password_is_strong($pass1)) {
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
        $securityHash = admin_create_security_answer_hash($securityAnswer);
        $ins = $pdo->prepare('INSERT INTO users (business_id, username, email, password_hash, security_question, security_answer_hash, role) VALUES (:bid,:u,:e,:h,:sq,:sa,:r)');
        $ins->execute([
            ':bid'=>$bid,
            ':u'=>$username,
            ':e'=>$email,
            ':h'=>$hash,
            ':sq'=>$securityQuestion,
            ':sa'=>$securityHash,
            ':r'=>'admin',
        ]);

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
    <input name="username" required minlength="3" maxlength="40" autocomplete="username" value="<?php echo h((string)($_POST['username'] ?? '')); ?>">
    <label>Correo</label>
    <input name="email" type="email" required maxlength="190" autocomplete="email" value="<?php echo h((string)($_POST['email'] ?? '')); ?>">
    <label>Pregunta de seguridad</label>
    <select name="security_question" required>
      <option value="">Elegí una pregunta</option>
      <?php foreach ($securityQuestions as $key => $label): ?>
        <option value="<?php echo h($key); ?>" <?php echo ((string)($_POST['security_question'] ?? '') === $key) ? 'selected' : ''; ?>>
          <?php echo h($label); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label>Respuesta de seguridad</label>
    <input name="security_answer" required maxlength="190" autocomplete="off" value="<?php echo h((string)($_POST['security_answer'] ?? '')); ?>">
    <label>Contraseña</label>
    <div style="display:flex;gap:8px;align-items:center">
      <input id="setup-password" name="password" type="password" required autocomplete="new-password" style="flex:1">
      <button class="btn" type="button" data-toggle-password="setup-password" aria-label="Mostrar contraseña">👁</button>
    </div>
    <label>Repetir contraseña</label>
    <div style="display:flex;gap:8px;align-items:center">
      <input id="setup-password2" name="password2" type="password" required autocomplete="new-password" style="flex:1">
      <button class="btn" type="button" data-toggle-password="setup-password2" aria-label="Mostrar contraseña">👁</button>
    </div>
    <p class="muted small" style="margin-top:8px">Mínimo 10 caracteres, con mayúscula, minúscula y número.</p>
    <button class="btn primary" type="submit">Crear administrador</button>
  </form>
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
