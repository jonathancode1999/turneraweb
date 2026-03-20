<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/csrf.php';

session_start_safe();
if (admin_needs_setup()) {
    redirect('setup.php');
}

$error = '';
$notice = '';
$step = 'lookup';
$user = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $action = (string)($_POST['action'] ?? 'lookup');
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));

    try {
        if ($username === '' || $email === '') {
            throw new RuntimeException('Ingresá usuario y correo.');
        }

        $user = admin_find_user_for_password_reset($username, $email);
        if (!$user) {
            throw new RuntimeException('No encontramos un usuario con esos datos.');
        }

        if ($action === 'lookup') {
            $step = 'reset';
        } elseif ($action === 'reset') {
            $answer = trim((string)($_POST['security_answer'] ?? ''));
            $pass1 = (string)($_POST['password'] ?? '');
            $pass2 = (string)($_POST['password2'] ?? '');

            if ($answer === '') {
                throw new RuntimeException('Ingresá la respuesta a la pregunta de seguridad.');
            }
            if (!admin_verify_security_answer($answer, (string)($user['security_answer_hash'] ?? ''))) {
                throw new RuntimeException('La respuesta de seguridad no coincide.');
            }
            if ($pass1 !== $pass2) {
                throw new RuntimeException('Las contraseñas no coinciden.');
            }
            if (!admin_password_is_strong($pass1)) {
                throw new RuntimeException('La contraseña debe tener al menos 10 caracteres e incluir mayúscula, minúscula y número.');
            }

            $pdo = db();
            $pdo->prepare('UPDATE users SET password_hash=:hash WHERE id=:id AND business_id=:bid')
                ->execute([
                    ':hash' => password_hash($pass1, PASSWORD_DEFAULT),
                    ':id' => (int)$user['id'],
                    ':bid' => (int)$user['business_id'],
                ]);

            $notice = 'Contraseña actualizada. Ya podés iniciar sesión.';
            $step = 'done';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $step = ($action === 'reset') ? 'reset' : 'lookup';
    }
}

page_head('Recuperar contraseña', 'admin');
?>
<div class="card" style="max-width:560px; margin:0 auto;">
  <h1>Recuperar contraseña</h1>
  <p class="muted">Completá tu usuario, correo y respondé tu pregunta de seguridad para definir una nueva contraseña.</p>

  <?php if ($error): ?>
    <div class="notice danger"><?php echo h($error); ?></div>
  <?php endif; ?>
  <?php if ($notice): ?>
    <div class="notice ok"><?php echo h($notice); ?></div>
  <?php endif; ?>

  <?php if ($step === 'lookup' || $step === 'reset'): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="action" value="<?php echo $step === 'reset' ? 'reset' : 'lookup'; ?>">

      <label>Usuario</label>
      <input name="username" required autocomplete="username" value="<?php echo h((string)($_POST['username'] ?? '')); ?>">

      <label>Correo</label>
      <input name="email" type="email" required autocomplete="email" value="<?php echo h((string)($_POST['email'] ?? '')); ?>">

      <?php if ($step === 'reset' && $user): ?>
        <div class="muted" style="margin:10px 0 6px 0"><strong>Pregunta de seguridad:</strong> <?php echo h(admin_security_question_label((string)($user['security_question'] ?? ''))); ?></div>

        <label>Respuesta</label>
        <input name="security_answer" required autocomplete="off">

        <label>Nueva contraseña</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input id="reset-password" name="password" type="password" required autocomplete="new-password" style="flex:1">
          <button class="btn" type="button" data-toggle-password="reset-password" aria-label="Mostrar contraseña">👁</button>
        </div>

        <label>Repetir nueva contraseña</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input id="reset-password2" name="password2" type="password" required autocomplete="new-password" style="flex:1">
          <button class="btn" type="button" data-toggle-password="reset-password2" aria-label="Mostrar contraseña">👁</button>
        </div>

        <p class="muted small" style="margin-top:8px">La nueva contraseña debe tener al menos 10 caracteres, con mayúscula, minúscula y número.</p>
        <button class="btn primary" type="submit">Actualizar contraseña</button>
      <?php else: ?>
        <button class="btn primary" type="submit">Continuar</button>
      <?php endif; ?>
    </form>
  <?php endif; ?>

  <div class="muted" style="margin-top:12px">
    <a class="link" href="login.php">Volver al login</a>
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
