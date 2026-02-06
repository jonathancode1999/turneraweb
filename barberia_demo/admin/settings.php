<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability.php';


function ensure_whatsapp_branch_columns(PDO $pdo): void {
  $cols = $pdo->query("PRAGMA table_info(branches)")->fetchAll(PDO::FETCH_ASSOC);
  $have = [];
  foreach ($cols as $c) { $have[$c['name']] = true; }
  if (!isset($have['whatsapp_reminder_enabled'])) {
    $pdo->exec("ALTER TABLE branches ADD COLUMN whatsapp_reminder_enabled INTEGER NOT NULL DEFAULT 0");
  }
  if (!isset($have['whatsapp_reminder_minutes'])) {
    $pdo->exec("ALTER TABLE branches ADD COLUMN whatsapp_reminder_minutes INTEGER NOT NULL DEFAULT 1440");
  }
}

admin_require_login();
admin_require_permission('settings');
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();

$branch = branch_get($branchId) ?: [];
// Ensure defaults for WhatsApp reminder settings
$branch['whatsapp_reminder_enabled'] = (int)($branch['whatsapp_reminder_enabled'] ?? 0);
$branch['whatsapp_reminder_minutes'] = (int)($branch['whatsapp_reminder_minutes'] ?? 1440);
$pdo = db();


ensure_whatsapp_branch_columns($pdo);
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

  
    $wre = isset($_POST['whatsapp_reminder_enabled']) ? 1 : 0;
    $wrm = (int)($_POST['whatsapp_reminder_minutes'] ?? 1440);
    $allowedWr = [5,15,30,60,300,720,1440,2880];
    if (!in_array($wrm, $allowedWr, true)) $wrm = 1440;
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

    // Logo/Cover upload (optional, hardened)
    $logoPath = (string)($bizBefore['logo_path'] ?? '');
    $coverPath = (string)($bizBefore['cover_path'] ?? '');
    if (isset($_FILES['logo']) && is_array($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $dir = __DIR__ . '/../public/uploads/branding';
      $rel = upload_image_from_field('logo', $dir, 'logo_' . $bid, 4 * 1024 * 1024);
      if ($rel) $logoPath = $rel;
    }

    if (isset($_FILES['cover']) && is_array($_FILES['cover']) && ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      $dir = __DIR__ . '/../public/uploads/branding';
      $rel = upload_image_from_field('cover', $dir, 'cover_' . $bid, 4 * 1024 * 1024);
      if ($rel) $coverPath = $rel;
    }

	    // 1) Business (global) settings
	    	    // 1) Business (global) settings (sin SMTP: eso lo configura el técnico)
	    $pdo->prepare('UPDATE businesses
	                  SET name=:n, owner_email=:oe,
	                      instagram_url=:ig, intro_text=:it,
	                      logo_path=:lp, cover_path=:cp,
	                      slot_minutes=:sm, slot_capacity=:sc,
	                      cancel_notice_minutes=:cn,
	                      theme_primary=:tp, theme_accent=:ta,
	                      reminder_minutes=:rm,
	                      customer_choose_barber=:ccb
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
	          ':ccb'=>$choose,
	          ':id'=>$bid,
	        ));

	    // 2) Branch (per-sucursal) contact/location settings
	    $branchId = admin_current_branch_id();
	    if ($branchId > 0) {
	        $pdo->prepare('UPDATE branches
	                      SET owner_email=:oe, address=:a, maps_url=:m, whatsapp_phone=:w, instagram_url=:ig,
	                          whatsapp_reminder_enabled=:wre, whatsapp_reminder_minutes=:wrm
	                      WHERE business_id=:bid AND id=:brid')
	            ->execute(array(
	                ':oe'=>$ownerEmail,
	                ':a'=>$address,
	                ':m'=>$mapsUrl,
	                ':w'=>$wa,
	                ':ig'=>$instagramUrl,
	                ':wre'=>$wre,
	                ':wrm'=>$wrm,
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
    $notice = 'Configuración guardada.' . ($slotMin !== $oldSlot ? ' (Se actualizó el slot base y se ajustaron duraciones si era necesario.)' : '');
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

<h3 style="margin-top:18px;">WhatsApp</h3>
<div class="row">
  <div style="flex:2;min-width:260px">
    <label style="display:flex;align-items:center;gap:10px;margin-top:8px;">
      <input type="checkbox" name="whatsapp_reminder_enabled" value="1" <?php echo ((int)($branch['whatsapp_reminder_enabled'] ?? 0)===1?'checked':''); ?>>
      Recordatorios de turnos (solo aprobados)
    </label>
  </div>
  <div style="flex:2;min-width:260px">
    <label>Anticipación del recordatorio</label>
    <select name="whatsapp_reminder_minutes">
      <?php
        $opts = [
          5=>'5 minutos',
          15=>'15 minutos',
          30=>'30 minutos',
          60=>'1 hora',
          300=>'5 horas',
          720=>'12 horas',
          1440=>'24 horas',
          2880=>'48 horas'
        ];
        $cur = (int)($branch['whatsapp_reminder_minutes'] ?? 1440);
        if (!isset($opts[$cur])) $cur = 1440;
        foreach ($opts as $k=>$label) {
          $sel = ($k===$cur) ? 'selected' : '';
          echo '<option value="'.(int)$k.'" '.$sel.'>'.h($label).'</option>';
        }
      ?>
    </select>
    <p class="muted small">Elegí con cuánta anticipación querés que aparezca en la lista de recordatorios.</p>
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
        <label></label>
        
      </div>
    </div>

    <button class="btn primary" type="submit">Guardar</button>
  </form>
</div>

<?php page_foot(); ?>