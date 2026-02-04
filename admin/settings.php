<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/mailer.php';

admin_require_login();
admin_require_permission('settings');
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$notice='';
$error='';
$test_notice='';
$test_error='';
$test_log='';
$adjustedServices = [];

function validate_slot_minutes(int $slot): int {
  // min 10, step 5
  if ($slot < 10) $slot = 10;
  if ($slot > 60) $slot = 60;
  // snap to nearest step of 5
  $slot = (int)(round($slot / 5) * 5);
  if ($slot < 10) $slot = 10;
  return $slot;
}

function validate_positive_int(int $v, int $min, int $max): int {
  if ($v < $min) $v = $min;
  if ($v > $max) $v = $max;
  return $v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate_or_die();

  // Actions: save (default) or test_email (does NOT persist changes)
  $action = trim((string)($_POST['action'] ?? 'save'));
  try {
    $bizBefore = $pdo->query('SELECT * FROM businesses WHERE id=' . $bid)->fetch() ?: [];
    $oldSlot = (int)($bizBefore['slot_minutes'] ?? 15);

    $name = trim($_POST['name'] ?? '');
    if ($name === '') throw new RuntimeException('El nombre del negocio es requerido');

    $address = trim($_POST['address'] ?? '');
    $mapsUrl = trim($_POST['maps_url'] ?? '');
    $wa = trim($_POST['whatsapp_phone'] ?? '');
    $ownerEmail = trim($_POST['owner_email'] ?? '');
    $instagramUrl = trim($_POST['instagram_url'] ?? '');
    $introText = trim($_POST['intro_text'] ?? '');
    $themePrimary = trim($_POST['theme_primary'] ?? '');
    $themeAccent = trim($_POST['theme_accent'] ?? '');
    // Reminder (email) minutes before the appointment: 0=off, 120=2h, 1440=24h
    $reminderMinutes = (int)($_POST['reminder_minutes'] ?? 0);
    if (!in_array($reminderMinutes, [0,120,1440], true)) $reminderMinutes = 0;
    // public_base_url: oculto en UI (se puede reactivar si se necesita)

    $slotMin = validate_slot_minutes((int)($_POST['slot_minutes'] ?? 15));
    $slotCap = validate_positive_int((int)($_POST['slot_capacity'] ?? 1), 1, 10);
	    $cancelNotice = validate_positive_int((int)($_POST['cancel_notice_minutes'] ?? 0), 0, 10080); // up to 7 days
        $choose = isset($_POST['customer_choose_barber']) ? 1 : 0;

	    // Minutes allowed for pending confirmation/approval flows.
	    // (Field name kept for backward compatibility)
	    $deadline = validate_positive_int((int)($_POST['pay_deadline_minutes'] ?? ($biz['pay_deadline_minutes'] ?? 15)), 5, 60);

	    // SMTP (opcional)
	    $smtpHost = trim($_POST['smtp_host'] ?? '');
	    $smtpPort = validate_positive_int((int)($_POST['smtp_port'] ?? 587), 1, 65535);
	    $smtpUser = trim($_POST['smtp_user'] ?? '');
	    $smtpPass = trim($_POST['smtp_pass'] ?? '');
	    $smtpSecure = trim($_POST['smtp_secure'] ?? 'tls');
	    if (!in_array($smtpSecure, ['', 'tls', 'ssl'], true)) $smtpSecure = '';
	    $smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
	    $smtpFromName = trim($_POST['smtp_from_name'] ?? '');
	    $smtpEnabled = ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') ? 1 : 0;
// SMTP enabled if host/user/pass are provided     = ( !== '' &&  !== '' &&  !== '') ? 1 : 0;

	    // SMTP is considered enabled when Host+Usuario+Contraseña are provided
	    $smtpEnabled = ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') ? 1 : 0;

    $smtpEnabled = ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') ? 1 : 0;

    // If user clicked "Probar email", we validate and send a test email WITHOUT persisting changes.
    if ($action === 'test_email') {
      $toTest = trim($_POST['test_to'] ?? '');
      if ($toTest === '') $toTest = $ownerEmail !== '' ? $ownerEmail : $smtpUser;
      if ($toTest === '') throw new RuntimeException('Ingresá un email destino para probar.');
      if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '') throw new RuntimeException('Para probar, completá Host/Usuario/Contraseña.');

      $smtp = [
        'host' => $smtpHost,
        'port' => $smtpPort,
        'user' => $smtpUser,
        'pass' => $smtpPass,
        'secure' => $smtpSecure,
      ];
      $fromEmail2 = $smtpFromEmail !== '' ? $smtpFromEmail : ($ownerEmail !== '' ? $ownerEmail : $toTest);
      $fromName2 = $smtpFromName !== '' ? $smtpFromName : ($name !== '' ? $name : 'Turnera');
      $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;line-height:1.4">'
            . '<h2 style="margin:0 0 10px 0">Prueba de correo</h2>'
            . '<p>Si estás viendo esto, la configuración SMTP funciona ✅</p>'
            . '<p style="color:#6b7280;font-size:12px">Enviado desde la turnera (' . h($name) . ').</p>'
            . '</div>';

      $res = smtp_send_html_debug($smtp, $toTest, 'Prueba de correo - Turnera', $html, $fromEmail2, $fromName2);
      if (!empty($res['ok'])) {
        $test_notice = 'Email de prueba enviado a ' . $toTest . ' ✅';
        $test_log = $res['log'] ?? '';
      } else {
        $err = (string)($res['error'] ?? 'Error desconocido');
        $test_error = 'No se pudo enviar el email de prueba: ' . $err;
        $test_log = $res['log'] ?? '';
      }

      goto POST_END;
    }

    // Logo/Cover upload (optional)
    $logoPath = (string)($bizBefore['logo_path'] ?? '');
    $coverPath = (string)($bizBefore['cover_path'] ?? '');
    if (isset($_FILES['logo']) && is_array($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      if (($_FILES['logo']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir el logo.');
      }
      $tmp = (string)$_FILES['logo']['tmp_name'];
      $orig = (string)$_FILES['logo']['name'];
      $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      $allowed = ['png','jpg','jpeg','webp'];
      if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Formato de logo no permitido. Usá PNG/JPG/WEBP.');
      }
      $dir = __DIR__ . '/../public/uploads/branding';
      if (!is_dir($dir)) { mkdir($dir, 0775, true); }
      $filename = 'logo_' . $bid . '.' . $ext;
      $dest = $dir . '/' . $filename;
      if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('No se pudo guardar el logo.');
      }
      $logoPath = 'uploads/branding/' . $filename;
    }

    if (isset($_FILES['cover']) && is_array($_FILES['cover']) && ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      if (($_FILES['cover']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir la portada.');
      }
      $tmp = (string)$_FILES['cover']['tmp_name'];
      $orig = (string)$_FILES['cover']['name'];
      $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      $allowed = ['png','jpg','jpeg','webp'];
      if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Formato de portada no permitido. Usá PNG/JPG/WEBP.');
      }
      $dir = __DIR__ . '/../public/uploads/branding';
      if (!is_dir($dir)) { mkdir($dir, 0775, true); }
      $filename = 'cover_' . $bid . '.' . $ext;
      $dest = $dir . '/' . $filename;
      if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('No se pudo guardar la portada.');
      }
      $coverPath = 'uploads/branding/' . $filename;
    }

	    // 1) Business (global) settings
	    $pdo->prepare('UPDATE businesses
	                  SET name=:n, owner_email=:oe,
	                      instagram_url=:ig, intro_text=:it,logo_path=:lp, cover_path=:cp, slot_minutes=:sm, slot_capacity=:sc,
	                      cancel_notice_minutes=:cn,
	                      theme_primary=:tp, theme_accent=:ta, reminder_minutes=:rm,
	                      pay_deadline_minutes=:pm, customer_choose_barber=:ccb,
	                      smtp_enabled=:se, smtp_host=:sh, smtp_port=:sp, smtp_user=:su, smtp_pass=:spw,
	                      smtp_secure=:ss, smtp_from_email=:sfe, smtp_from_name=:sfn
	                  WHERE id=:id')
	        ->execute(array(
	          ':n'=>$name,
	          ':oe'=>$ownerEmail,
	          ':ig'=>$instagramUrl,
	          ':it'=>$introText,
	          ':tp'=>$themePrimary,
	          ':ta'=>$themeAccent,
	          ':rm'=>$reminderMinutes,
	          ':lp'=>$logoPath,
	          ':cp'=>$coverPath,
	          ':sm'=>$slotMin,
	          ':sc'=>$slotCap,
	          ':cn'=>$cancelNotice,
	          ':pm'=>$deadline,
	          ':ccb'=>$choose,
	          ':se'=>$smtpEnabled,
	          ':sh'=>$smtpHost,
	          ':sp'=>$smtpPort,
	          ':su'=>$smtpUser,
	          ':spw'=>$smtpPass,
	          ':ss'=>$smtpSecure,
	          ':sfe'=>$smtpFromEmail,
	          ':sfn'=>$smtpFromName,
	          ':id'=>$bid,
	        ));

	    // 2) Branch (per-sucursal) contact/location settings
	    $branchId = admin_current_branch_id();
	    if ($branchId > 0) {
	        $pdo->prepare('UPDATE branches
	                      SET owner_email=:oe, address=:a, maps_url=:m, whatsapp_phone=:w, instagram_url=:ig
	                      WHERE business_id=:bid AND id=:brid')
	            ->execute(array(
	                ':oe'=>$ownerEmail,
	                ':a'=>$address,
	                ':m'=>$mapsUrl,
	                ':w'=>$wa,
	                ':ig'=>$instagramUrl,
	                ':bid'=>$bid,
	                ':brid'=>$branchId,
	            ));
	    }

    // If slot changed, align service durations to the new slot.
    if ($slotMin !== $oldSlot) {
      $stmt = $pdo->prepare('SELECT id, name, duration_minutes FROM services WHERE business_id=:bid');
      $stmt->execute([':bid' => $bid]);
      $services = $stmt->fetchAll() ?: [];
      foreach ($services as $s) {
        $oldDur = (int)$s['duration_minutes'];
        $newDur = round_duration_to_slot($oldDur, $slotMin);
        if ($newDur !== $oldDur) {
          $pdo->prepare('UPDATE services SET duration_minutes=:d, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND id=:id')
              ->execute([':d' => $newDur, ':bid' => $bid, ':id' => (int)$s['id']]);
          $adjustedServices[] = [
            'name' => (string)$s['name'],
            'from' => $oldDur,
            'to' => $newDur,
          ];
        }
      }
    }

POST_END:
    if ($action !== 'test_email') {
      $notice = 'Configuración guardada.' . ($slotMin !== $oldSlot ? ' (Se actualizó el slot base y se ajustaron duraciones si era necesario.)' : '');
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$biz = $pdo->query('SELECT * FROM businesses WHERE id=' . $bid)->fetch() ?: array();

// Per-branch contact/location settings
admin_require_branch_selected();
$branchId = admin_current_branch_id();
$branch = branch_get($branchId);
if ($branch) {
    // Override display values with the current branch values where relevant
    $biz['address'] = $branch['address'] ?? ($biz['address'] ?? '');
    $biz['maps_url'] = $branch['maps_url'] ?? ($biz['maps_url'] ?? '');
    $biz['whatsapp_phone'] = $branch['whatsapp_phone'] ?? ($biz['whatsapp_phone'] ?? '');
    $biz['instagram_url'] = $branch['instagram_url'] ?? ($biz['instagram_url'] ?? '');
    if (isset($branch['owner_email']) && trim((string)$branch['owner_email']) !== '') {
        $biz['owner_email'] = $branch['owner_email'];
    }
}

page_head('Configuración', 'admin');
admin_nav('settings');
?>

<div class="card">
  <h1>Configuración</h1>
  <?php if ($notice): ?><div class="notice ok"><?php echo h($notice); ?></div><?php endif; ?>
  <?php if ($test_notice): ?><div class="notice ok"><?php echo h($test_notice); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>
  <?php if ($test_error): ?><div class="notice danger"><?php echo h($test_error); ?></div><?php endif; ?>
  <?php if ($test_log): ?>
    <details class="notice">
      <summary><b>Debug SMTP</b> (click para ver)</summary>
      <pre style="white-space:pre-wrap;max-height:260px;overflow:auto;margin-top:10px"><?php echo h($test_log); ?></pre>
      <p class="muted small">Tip: si ves <b>535</b> es usuario/clave; <b>530/Must issue STARTTLS</b> activá TLS; <b>Could not connect</b> es host/puerto/firewall.</p>
    </details>
  <?php endif; ?>

  <?php if (!empty($adjustedServices)): ?>
    <div class="notice">
      <b>Servicios ajustados por cambio de slot:</b>
      <ul style="margin:8px 0 0 18px">
        <?php foreach ($adjustedServices as $ch): ?>
          <li><?php echo h($ch['name']); ?>: <?php echo (int)$ch['from']; ?> → <?php echo (int)$ch['to']; ?> min</li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">

    <h2>Negocio</h2>
    <div class="row">
      <div style="flex:2;min-width:260px">
        <label>Nombre</label>
        <input name="name" value="<?php echo h($biz['name'] ?? ''); ?>" required maxlength="80">
      </div>
      <div style="flex:2;min-width:260px">
        <label>Email del negocio (para notificaciones)</label>
        <input type="email" name="owner_email" value="<?php echo h($biz['owner_email'] ?? ''); ?>" maxlength="120" placeholder="Ej: tuemail@dominio.com">
        
      </div>
      <div style="flex:2;min-width:260px">
        <label>WhatsApp (teléfono)</label>
        <input name="whatsapp_phone" value="<?php echo h($biz['whatsapp_phone'] ?? ''); ?>" maxlength="40" placeholder="Ej: 54911...">
      </div>

</div>

    <div class="row">
      <div style="flex:1;min-width:260px">
        <label>Selección de profesional en la web</label>
        <label class="checkline muted" style="margin-top:8px">
          <input type="checkbox" name="customer_choose_barber" <?php echo ((int)($biz['customer_choose_barber'] ?? 1)===1)?'checked':''; ?>>
          <span>Permitir que el cliente elija profesional</span>
        </label>
        <p class="muted small">Si lo desactivás, el cliente reserva con “Primer profesional disponible”.</p>
      </div>
    </div>


    <div class="row" style="align-items:flex-start">
      <div style="flex:2;min-width:260px">
        <label>Logo (opcional)</label>
        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
        <p class="muted small">PNG/JPG/WEBP. Si subís uno nuevo, reemplaza al anterior.</p>
        <?php if (!empty($biz['logo_path'])): ?>
          <div class="logo-preview">
            <img src="../public/<?php echo h($biz['logo_path']); ?>" alt="Logo actual">
          </div>
        <?php endif; ?>
      </div>

      <div style="flex:3;min-width:260px">
        <label>Portada (foto principal)</label>
        <input type="file" name="cover" accept="image/png,image/jpeg,image/webp">
        <p class="muted small">Una sola imagen grande (PNG/JPG/WEBP). Se muestra arriba de la página pública.</p>
        <?php if (!empty($biz['cover_path'])): ?>
          <div class="logo-preview" style="max-width:520px">
            <img src="../public/<?php echo h($biz['cover_path']); ?>" alt="Portada actual" style="width:100%;height:auto">
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="row">
      <div style="flex:2;min-width:260px">
        <label>Instagram (link)</label>
        <input name="instagram_url" value="<?php echo h($biz['instagram_url'] ?? ''); ?>" maxlength="300" placeholder="Ej: https://instagram.com/tuperfil">
      </div>
      <div style="flex:3;min-width:260px">
        <label>Descripción breve (opcional)</label>
	    <input name="intro_text" value="<?php echo h($biz['intro_text'] ?? ''); ?>" maxlength="160" placeholder="Ej: Peluquería / Uñas / Estética · Turnos por orden de llegada y con reserva">
      </div>
    </div>

    <div class="row">
      <div style="flex:1;min-width:220px">
        <label>Color principal</label>
        <input type="color" name="theme_primary" value="<?php echo h($biz['theme_primary'] ?? '#2D7BD1'); ?>" style="height:42px;padding:6px">
        <p class="muted small">Se usa en botones principales y tabs.</p>
      </div>
      <div style="flex:1;min-width:220px">
        <label>Color acento</label>
        <input type="color" name="theme_accent" value="<?php echo h($biz['theme_accent'] ?? '#0EA5E9'); ?>" style="height:42px;padding:6px">
        <p class="muted small">Usado en algunos detalles/acciones.</p>
      </div>
      <div style="flex:2;min-width:260px">
        <label>Recordatorio por email</label>
        <?php $rm = (int)($biz['reminder_minutes'] ?? 0); ?>
        <select name="reminder_minutes">
          <option value="0" <?php echo $rm===0?'selected':''; ?>>Desactivado</option>
          <option value="120" <?php echo $rm===120?'selected':''; ?>>2 horas antes</option>
          <option value="1440" <?php echo $rm===1440?'selected':''; ?>>24 horas antes</option>
        </select>
        <p class="muted small">Se envía automáticamente si el cliente dejó email (requiere cron en hosting).</p>
      </div>
    </div>
    <div class="row">
      <div style="flex:2;min-width:260px">
        <label>Dirección</label>
        <input name="address" value="<?php echo h($biz['address'] ?? ''); ?>" maxlength="200" placeholder="Ej: Av. Siempre Viva 123">
      </div>
      <div style="flex:2;min-width:260px">
        <label>Link Google Maps (Cómo llegar)</label>
        <input name="maps_url" value="<?php echo h($biz['maps_url'] ?? ''); ?>" maxlength="400" placeholder="Pegá el link de Google Maps">
      </div>
    </div>

    

    <div class="hr"></div>
    <h2>Reserva</h2>
    <div class="row">
      <div>
        <label>Slot base (min)</label>
        <select name="slot_minutes">
          <?php
            $cur = (int)($biz['slot_minutes'] ?? 15);
            for ($m=10;$m<=60;$m+=5) {
              $sel = ($m===$cur) ? 'selected' : '';
              echo '<option value="' . $m . '" ' . $sel . '>' . $m . '</option>';
            }
          ?>
        </select>
        <p class="muted small">Mínimo 10, de 5 en 5.</p>
      </div>
      <div>
        <label>Turnos por slot (capacidad)</label>
        <input type="number" name="slot_capacity" min="1" max="10" value="<?php echo (int)($biz['slot_capacity'] ?? 1); ?>">
        <p class="muted small">Si trabajás con más de 1 silla / más de 1 cliente a la vez.</p>
      </div>
	    <div>
	      <label>Cancelación / reprogramación (anticipación mínima)</label>
	      <?php $cn = (int)($biz['cancel_notice_minutes'] ?? 0); ?>
	      <select name="cancel_notice_minutes">
	        <?php
	          $opts = [
	            0 => 'Sin límite',
	            30 => '30 minutos',
	            60 => '1 hora',
	            180 => '3 horas',
	            360 => '6 horas',
	            600 => '10 horas',
	            720 => '12 horas',
	            1440 => '1 día',
	            2880 => '2 días',
	            4320 => '3 días',
	          ];
	          foreach ($opts as $min => $label) {
	            $sel = ($min === $cn) ? 'selected' : '';
	            echo '<option value="' . (int)$min . '" ' . $sel . '>' . h($label) . '</option>';
	          }
	        ?>
	      </select>
	      <p class="muted small">Ej: si elegís “10 horas”, el cliente no podrá cancelar ni reprogramar dentro de las últimas 10 horas antes del turno.</p>
	    </div>
      <div>
        <label>Límite para pagar seña (min)</label>
        <input type="number" name="pay_deadline_minutes" min="5" max="60" value="<?php echo (int)($biz['pay_deadline_minutes'] ?? 15); ?>">
      </div>
    </div>

	  <div class="hr"></div>
	  <h2>Email (SMTP opcional)</h2>	  <div class="row">
	    <div style="flex:2;min-width:260px">
	      <label>SMTP Host</label>
	      <input name="smtp_host" value="<?php echo h($biz['smtp_host'] ?? ''); ?>" placeholder="Ej: smtp.gmail.com">
	    </div>
	    <div>
	      <label>Puerto</label>
	      <input type="number" name="smtp_port" value="<?php echo (int)($biz['smtp_port'] ?? 587); ?>" min="1" max="65535">
	    </div>
	    <div>
	      <label>Seguridad</label>
	      <?php $sec = (string)($biz['smtp_secure'] ?? 'tls'); ?>
	      <select name="smtp_secure">
	        <option value="" <?php echo $sec===''?'selected':''; ?>>Ninguna</option>
	        <option value="tls" <?php echo $sec==='tls'?'selected':''; ?>>TLS (STARTTLS)</option>
	        <option value="ssl" <?php echo $sec==='ssl'?'selected':''; ?>>SSL (465)</option>
	      </select>
	    </div>
	  </div>
	  <div class="row">
	    <div style="flex:2;min-width:260px">
	      <label>Usuario SMTP</label>
	      <input name="smtp_user" value="<?php echo h($biz['smtp_user'] ?? ''); ?>" placeholder="Ej: tuemail@dominio.com">
	    </div>
	    <div style="flex:2;min-width:260px">
	      <label>Contraseña SMTP</label>
	      <input type="password" name="smtp_pass" value="<?php echo h($biz['smtp_pass'] ?? ''); ?>" placeholder="(se guarda en la DB)">
	    </div>
	  </div>
	  <div class="row">
	    <div style="flex:2;min-width:260px">
	      <label>From Email</label>
	      <input type="email" name="smtp_from_email" value="<?php echo h($biz['smtp_from_email'] ?? ''); ?>" placeholder="Ej: no-reply@tudominio.com">
	    </div>
	    <div style="flex:2;min-width:260px">
	      <label>From Nombre</label>
	      <input name="smtp_from_name" value="<?php echo h($biz['smtp_from_name'] ?? ''); ?>" placeholder="Ej: Mi negocio">
	    </div>
	  </div>

	  <div class="row" style="align-items:flex-end">
	    <div style="flex:2;min-width:260px">
	      <label>Probar envío (sin guardar)</label>
	      <input type="email" name="test_to" value="<?php echo h($biz['owner_email'] ?? ''); ?>" placeholder="Email destino para prueba">
	      <p class="muted small" style="margin:6px 0 0 0">
		        Tip Gmail: normalmente necesitas una <b>Contraseña de aplicación</b> (no tu contraseña normal). <a class="link" href="https://support.google.com/accounts/answer/185833?hl=es" target="_blank" rel="noopener">Cómo generarla</a>.
	      </p>
	    </div>
	    <div style="align-self:end">
	      <button class="btn" name="action" value="test_email" type="submit">Probar email</button>
	    </div>
	  </div>
    <button class="btn primary" type="submit">Guardar</button>
  </form>
</div>

<?php page_foot(); ?>