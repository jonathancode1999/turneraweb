<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

session_start_safe();

$needsSetup = admin_needs_setup();
$requestedMode = trim((string)($_GET['mode'] ?? ''));
$mode = $needsSetup ? 'setup' : (($requestedMode === 'forgot') ? 'forgot' : 'login');
$step = 'lookup';
$error = '';
$notice = '';
$user = null;
$securityQuestions = admin_security_questions();

if (!$needsSetup && !empty($_SESSION['admin_user'])) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $action = trim((string)($_POST['action'] ?? 'login'));

    try {
        if ($action === 'login') {
            $u = trim((string)($_POST['username'] ?? ''));
            $p = (string)($_POST['password'] ?? '');
            if (!admin_login($u, $p)) {
                throw new RuntimeException('Usuario o contraseña incorrectos.');
            }
            redirect('dashboard.php');
        }

        if ($action === 'setup') {
            $cfg = app_config();
            $bid = (int)$cfg['business_id'];
            $pdo = db();
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $securityQuestion = trim((string)($_POST['security_question'] ?? ''));
            $securityAnswer = trim((string)($_POST['security_answer'] ?? ''));
            $pass1 = (string)($_POST['password'] ?? '');
            $pass2 = (string)($_POST['password2'] ?? '');

            if (!$needsSetup) {
                redirect('login.php');
            }
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

            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT COUNT(1) FROM users WHERE business_id=:bid');
            $st->execute([':bid' => $bid]);
            $c = (int)($st->fetchColumn() ?: 0);
            if ($c > 0) {
                $pdo->rollBack();
                redirect('login.php');
            }

            $hash = password_hash($pass1, PASSWORD_DEFAULT);
            $securityHash = admin_create_security_answer_hash($securityAnswer);
            $ins = $pdo->prepare('INSERT INTO users (business_id, username, email, password_hash, security_question, security_answer_hash, role) VALUES (:bid,:u,:e,:h,:sq,:sa,:r)');
            $ins->execute([
                ':bid' => $bid,
                ':u' => $username,
                ':e' => $email,
                ':h' => $hash,
                ':sq' => $securityQuestion,
                ':sa' => $securityHash,
                ':r' => 'admin',
            ]);
            $pdo->exec("UPDATE users SET can_branches=1,can_settings=1,can_appointments=1,can_barbers=1,can_services=1,can_hours=1,can_blocks=1,can_system=1,can_analytics=1,all_branches=1 WHERE business_id=".(int)$bid." AND role='admin'");
            $pdo->commit();

            admin_login($username, $pass1);
            redirect('dashboard.php');
        }

        if ($action === 'forgot_lookup' || $action === 'forgot_reset') {
            if ($needsSetup) {
                redirect('login.php');
            }
            $mode = 'forgot';
            $step = ($action === 'forgot_reset') ? 'reset' : 'lookup';
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));

            if ($username === '' || $email === '') {
                throw new RuntimeException('Ingresá usuario y correo.');
            }

            $user = admin_find_user_for_password_reset($username, $email);
            if (!$user) {
                throw new RuntimeException('No encontramos un usuario con esos datos.');
            }

            if ($action === 'forgot_lookup') {
                $step = 'reset';
            } else {
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
                $mode = 'login';
                $step = 'lookup';
                $user = null;
            }
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        if ($action === 'setup') {
            $mode = 'setup';
        } elseif ($action === 'forgot_lookup' || $action === 'forgot_reset') {
            $mode = 'forgot';
            $step = ($action === 'forgot_reset') ? 'reset' : 'lookup';
        } else {
            $mode = 'login';
        }
    }
}

$pageTitle = $mode === 'setup' ? 'Configurar Admin' : ($mode === 'forgot' ? 'Recuperar contraseña' : 'Admin - Login');
page_head($pageTitle, 'admin');
?>
<div class="card" style="max-width:560px; margin:0 auto;">
  <?php if ($mode === 'setup'): ?>
    <h1>Primer inicio</h1>
    <p class="muted">Creá el usuario administrador para empezar a usar el panel.</p>
  <?php elseif ($mode === 'forgot'): ?>
    <h1>Recuperar contraseña</h1>
    <p class="muted">Completá tu usuario, correo y respondé tu pregunta de seguridad para definir una nueva contraseña.</p>
  <?php else: ?>
    <h1>Admin</h1>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="notice danger"><?php echo h($error); ?></div>
  <?php endif; ?>
  <?php if ($notice): ?>
    <div class="notice ok"><?php echo h($notice); ?></div>
  <?php endif; ?>

  <?php if ($mode === 'setup'): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="action" value="setup">
      <label>Usuario</label>
      <input name="username" required minlength="3" maxlength="40" autocomplete="username" value="<?php echo h((string)($_POST['username'] ?? '')); ?>">
      <label>Correo</label>
      <input name="email" type="email" required maxlength="190" autocomplete="email" value="<?php echo h((string)($_POST['email'] ?? '')); ?>">
      <label>Pregunta de seguridad</label>
      <select name="security_question" required>
        <option value="">Elegí una pregunta</option>
        <?php foreach ($securityQuestions as $key => $label): ?>
          <option value="<?php echo h($key); ?>" <?php echo ((string)($_POST['security_question'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo h($label); ?></option>
        <?php endforeach; ?>
      </select>
      <label>Respuesta de seguridad</label>
      <input name="security_answer" required minlength="3" maxlength="190" autocomplete="off" value="<?php echo h((string)($_POST['security_answer'] ?? '')); ?>">
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
  <?php elseif ($mode === 'forgot'): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="action" value="<?php echo $step === 'reset' ? 'forgot_reset' : 'forgot_lookup'; ?>">
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
    <div class="muted" style="margin-top:12px"><a class="link" href="login.php">Volver al login</a></div>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="action" value="login">
      <label>Usuario</label>
      <input name="username" required autocomplete="username" value="<?php echo h((string)($_POST['username'] ?? '')); ?>">
      <label>Contraseña</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input id="login-password" name="password" type="password" required autocomplete="current-password" style="flex:1">
        <button class="btn" type="button" data-toggle-password="login-password" aria-label="Mostrar contraseña">👁</button>
      </div>
      <button class="btn" type="submit">Entrar</button>
    </form>
    <div class="muted" style="margin-top:12px"><a class="link" href="login.php?mode=forgot">Olvidé mi contraseña</a></div>
  <?php endif; ?>
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
