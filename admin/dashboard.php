<?php require __DIR__.'/_inc.php'; require_login();
header_html('Clientes');
$clients = list_clients();
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
      <form method="post" action="create_client.php">
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
          <input name="admin_user" value="admin" required>
        </div>
        <div style="margin-bottom:12px">
          <label>Contraseña admin</label>
          <input name="admin_pass" type="password" required>
        </div>
        <button class="btn btn-primary" type="submit">Crear</button>
        <div class="small" style="margin-top:10px">El cliente nuevo se crea vacío: <b>solo horarios + 1 sucursal + 1 business + 1 admin</b>.</div>
      </form>
    </div>
  </div>
</div>

<?php footer_html(); ?>
