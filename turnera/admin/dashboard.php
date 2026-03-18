<?php require __DIR__.'/_inc.php'; require_login();
header_html('Clientes');
$clients = list_clients();
$securityQuestions = admin_security_questions();
?>
<div class="grid">
  <div class="col-8">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Clientes</h3>
      <table>
        <thead><tr><th>Logo</th><th>Slug</th><th>Negocio</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach($clients as $slug):
          try{
            $pdo=client_pdo($slug);
            $bid = client_business_id($slug);
            $name=$pdo->prepare("SELECT name FROM businesses WHERE id=?");
            $name->execute([$bid]);
            $name = $name->fetchColumn() ?: $slug;

            $logoPathStmt=$pdo->prepare("SELECT logo_path FROM businesses WHERE id=?");
            $logoPathStmt->execute([$bid]);
            $logoPath=$logoPathStmt->fetchColumn() ?: '';

            $coverPathStmt=$pdo->prepare("SELECT cover_path FROM businesses WHERE id=?");
            $coverPathStmt->execute([$bid]);
            $coverPath=$coverPathStmt->fetchColumn() ?: '';
          } catch(Throwable $e){ $name='(error DB)'; $logoPath=''; $coverPath=''; }
          $disabled = client_disabled($slug);
        ?>
          <tr>
            <td><code><?=h($slug)?></code></td>
            <td><?=h($name)?></td>
            <td><?= $disabled ? '<span class="badge badge-off">Desactivado</span>' : '<span class="badge badge-on">Activo</span>' ?></td>
            <td>
              <div class="btn-row">
                <a class="btn" href="manage.php?c=<?=urlencode($slug)?>">Gestionar</a>
                <a class="btn" target="_blank" href="../<?=h($slug)?>/public/">Abrir sitio</a>
                <a class="btn" target="_blank" href="../<?=h($slug)?>/admin/">Abrir admin</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-4">
    <div class="card">
      <h3 style="margin:0 0 10px 0;">Integraciones</h3>
      <p class="muted" style="margin:0 0 10px 0;">Configuración técnica global.</p>
      <a class="btn" href="mp_settings.php">MercadoPago (técnico)</a>
    </div>

    <div class="card">
      <h3 style="margin:0 0 10px 0;">Crear cliente</h3>
      <form method="post" action="create_client.php" data-password-pair>
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div style="margin-bottom:10px">
          <label>Slug (carpeta)</label>
          <input name="slug" placeholder="barberia_jony" required pattern="[a-z0-9_\-]+">
          <div class="small">Solo minúsculas, números, _ y -</div>
        </div>
        <div style="margin-bottom:10px">
          <label>Nombre del negocio</label>
          <input name="business_name" placeholder="Profesionalía Jony" required>
        </div>
        <div style="margin-bottom:10px">
          <label>Usuario admin</label>
          <input name="admin_user" value="<?=h((string)($_POST['admin_user'] ?? ''))?>" required autocomplete="username">
        </div>
        <div style="margin-bottom:10px">
          <label>Correo admin</label>
          <input name="admin_email" type="email" value="<?=h((string)($_POST['admin_email'] ?? ''))?>" required autocomplete="email">
        </div>
        <div style="margin-bottom:10px">
          <label>Pregunta de seguridad</label>
          <select name="security_question" required>
            <option value="">Elegí una pregunta</option>
            <?php foreach($securityQuestions as $key => $label): ?>
              <option value="<?=h($key)?>" <?=((string)($_POST['security_question'] ?? '') === $key ? 'selected' : '')?>><?=h($label)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="margin-bottom:10px">
          <label>Respuesta de seguridad</label>
          <input name="security_answer" value="<?=h((string)($_POST['security_answer'] ?? ''))?>" required autocomplete="off">
        </div>
        <div style="margin-bottom:12px">
          <label>Contraseña admin</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input id="admin-pass" name="admin_pass" type="password" required autocomplete="new-password" style="flex:1">
            <button class="btn toggle-password" type="button" data-toggle-password="admin-pass" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
          </div>
        </div>
        <div style="margin-bottom:12px">
          <label>Repetir contraseña</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input id="admin-pass2" name="admin_pass2" type="password" required autocomplete="new-password" style="flex:1">
            <button class="btn toggle-password" type="button" data-toggle-password="admin-pass2" aria-label="Mostrar contraseña" aria-pressed="false">👁</button>
          </div>
        </div>
        <div class="small password-match" data-password-match-message aria-live="polite" style="margin:-2px 0 12px 0"></div>
        <button class="btn btn-primary" type="submit">Crear</button>
        <div class="small" style="margin-top:10px">El cliente nuevo se crea con el negocio, la sucursal principal y el admin que cargues vos manualmente.</div>
      </form>
    </div>
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

  function bindPasswordPair(scope){
    var password = scope.querySelector('input[name="admin_pass"]');
    var confirm = scope.querySelector('input[name="admin_pass2"]');
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
