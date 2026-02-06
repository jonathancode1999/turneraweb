<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/status.php';
require_once __DIR__ . '/../includes/timeline.php';

admin_require_login();
admin_require_branch_selected();

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
  flash_set('error', 'Turno inválido.');
  redirect('appointments.php');
}

// Load appointment
$stmt = $pdo->prepare("SELECT a.*, s.name AS service_name, s.duration_minutes, br.name AS barber_name
  FROM appointments a
  JOIN services s ON s.id=a.service_id
  JOIN barbers br ON br.id=a.barber_id
  WHERE a.business_id=:bid AND a.id=:id");
$stmt->execute(array(':bid' => $bid, ':id' => $id));
$a = $stmt->fetch();
if (!$a) {
  flash_set('error', 'Turno no encontrado.');
  redirect('appointments.php');
}

$allowed = array(APPT_STATUS_PENDING_APPROVAL, APPT_STATUS_ACCEPTED, APPT_STATUS_RESCHEDULE_PENDING);
if (!in_array((string)$a['status'], $allowed, true)) {
  flash_set('error', 'Este turno no se puede reprogramar por su estado actual.');
  redirect('appointments.php');
}

// Lists for UI
$services = $pdo->prepare('SELECT id, name, duration_minutes, is_active FROM services WHERE business_id=:bid AND is_active=1 ORDER BY id');
$services->execute(array(':bid' => $bid));
$services = $services->fetchAll() ?: array();

$barbers = $pdo->prepare('SELECT id, name, is_active FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id');
$barbers->execute(array(':bid' => $bid, ':brid' => $branchId));
$barbers = $barbers->fetchAll() ?: array();

$message = '';
$error = flash_get('error');

$autoWaUrl='';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate_or_die();
  try {
    // Keep old date/time for the customer notification
    $oldWhen = '';
    try {
      $oldDt = parse_db_datetime((string)$a['start_at']);
      $oldWhen = $oldDt->format('d/m/Y H:i');
    } catch (Throwable $e) {}
    $newBarberId = (int)($_POST['new_barber_id'] ?? 0);
    $newServiceId = (int)($_POST['new_service_id'] ?? 0);
    if ($newServiceId <= 0) $newServiceId = (int)$a['service_id'];
    $newDate = trim($_POST['new_date'] ?? '');
    $newTime = trim($_POST['new_time'] ?? '');
    if ($newDate === '' || $newTime === '') throw new RuntimeException('Elegí nueva fecha y hora.');

    $start = parse_local_datetime($newDate, $newTime);
    if ($newBarberId === 0) {
      $newBarberId = pick_barber_for_slot($bid, $branchId, $newServiceId, $start);
    }

    // Validate (ignore this appointment)
    list($svc, $end) = assert_slot_available($bid, $branchId, $newBarberId, $newServiceId, $start, (int)$a['id']);

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE appointments
      SET barber_id=:bar,
          service_id=:sid,
          start_at=:s,
          end_at=:e,
          status='" . APPT_STATUS_ACCEPTED . "',
          requested_start_at=NULL,
          requested_end_at=NULL,
          requested_at=NULL,
          requested_barber_id=NULL,
          requested_service_id=NULL,
          updated_at=CURRENT_TIMESTAMP
      WHERE business_id=:bid AND branch_id=:brid AND id=:id")
      ->execute(array(
        ':bar' => $newBarberId,
        ':sid' => $newServiceId,
        ':s'   => $start->format('Y-m-d H:i:s'),
        ':e'   => $end->format('Y-m-d H:i:s'),
        ':bid' => $bid,
        ':brid'=> $branchId,
        ':id'  => $id,
      ));

    try {
      $uid = (int)($_SESSION['admin_user']['id'] ?? 0);
      appt_log_event($bid, $branchId, (int)$id, 'admin_rescheduled', 'Reprogramado por el negocio', [
        'old_when' => $oldWhen,
        'new_start_at' => $start->format('Y-m-d H:i:s'),
        'new_barber_id' => $newBarberId,
        'new_service_id' => $newServiceId,
      ], 'admin', $uid);
    } catch (Throwable $e) {
      // non fatal
    }
    $pdo->commit();

    
    $autoWaUrl = 'wa_action.php?act=rescheduled&id=' . $id . '&csrf=' . urlencode(csrf_token()) . '&return=' . urlencode('appointments.php');
// Reload and notify
    $stmt->execute(array(':bid' => $bid, ':id' => $id));
    $a = $stmt->fetch() ?: $a;
    $biz = get_business($bid);
    // Admin-initiated reschedule (not a customer request)
    // Admin reschedule: notify the customer only.
    notify_event('booking_rescheduled_by_admin', $biz, $a, array('old_when' => $oldWhen, 'to_owner' => false));

    $message = 'Turno reprogramado y aprobado.';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
  }
}

$curStart = parse_db_datetime((string)$a['start_at']);
$reqStart = !empty($a['requested_start_at']) ? parse_db_datetime((string)$a['requested_start_at']) : null;

if ($autoWaUrl !== '' && !empty($_POST['open_whatsapp'])) {
  // Abrir WhatsApp en la pestaña nueva (evita popup blockers)
  header('Location: ' . $autoWaUrl);
  exit;
}

page_head('Reprogramar turno', 'admin');
admin_nav('appointments');
?>

<div class="container" style="max-width:920px;margin:0 auto;">
  <div class="card">
    <div class="header">
      <div>
        <div class="title">Reprogramar turno #<?php echo (int)$a['id']; ?></div>
        <div class="subtitle">Cliente: <?php echo h((string)$a['customer_name']); ?></div>
      </div>
      <a class="link" href="appointments.php">Volver</a>
    </div>

    <?php if ($message): ?><div class="notice ok"><?php echo h($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>

    <div class="kv" style="margin-top:8px;">
      <div><span>Actual</span><b><?php echo h($curStart->format('d/m/Y H:i')); ?></b></div>
      <div><span>Estado</span><span class="badge <?php echo h(appt_status_badge_class((string)$a['status'])); ?>"><?php echo h(appt_status_label((string)$a['status'])); ?></span></div>
      <?php if ($reqStart): ?>
        <div><span>Solicitado</span><b><?php echo h($reqStart->format('d/m/Y H:i')); ?></b></div>
      <?php endif; ?>
    </div>

    <form method="post" class="grid" style="margin-top:14px;" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">

      <div>
        <label>Servicio</label>
        <select name="new_service_id" id="service_id">
          <?php foreach ($services as $s): $sid=(int)$s['id']; ?>
            <option value="<?php echo $sid; ?>" <?php echo $sid===(int)$a['service_id']?'selected':''; ?>><?php echo h((string)$s['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Profesional</label>
        <select name="new_barber_id" id="barber_id">
          <option value="0">Automático</option>
          <?php foreach ($barbers as $b): $bid2=(int)$b['id']; ?>
            <option value="<?php echo $bid2; ?>" <?php echo $bid2===(int)$a['barber_id']?'selected':''; ?>><?php echo h((string)$b['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Fecha</label>
        <input type="date" name="new_date" id="new_date" value="<?php echo h($curStart->format('Y-m-d')); ?>">
      </div>

      <div>
        <label>Hora</label>
        <select name="new_time" id="new_time">
          <option value="">Elegí hora...</option>
        </select>
        <div class="small muted" id="time_msg" style="margin-top:6px;"></div>
      </div>

      <div style="grid-column:1/-1; display:flex; gap:10px; align-items:center; justify-content:flex-end;">
        <button class="btn primary" type="submit" name="open_whatsapp" value="1" formtarget="_blank" onclick="setTimeout(function(){window.location.href='appointments.php';},50);">Guardar y abrir WhatsApp</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const service = document.getElementById('service_id');
  const barber  = document.getElementById('barber_id');
  const date    = document.getElementById('new_date');
  const timeSel = document.getElementById('new_time');
  const msg     = document.getElementById('time_msg');

  async function loadTimes(){
    const d = date.value;
    const s = service.value;
    const b = barber.value;
    timeSel.innerHTML = '<option value="">Cargando...</option>';
    msg.textContent = '';

    if(!d || !s){
      timeSel.innerHTML = '<option value="">Elegí fecha...</option>';
      return;
    }

    try{
      const url = `../public/api.php?action=times&date=${encodeURIComponent(d)}&service_id=${encodeURIComponent(s)}&barber_id=${encodeURIComponent(b)}`;
      const res = await fetch(url, {cache:'no-store'});
      const json = await res.json();
      if(!json.ok) throw new Error(json.error || 'Error');
      const times = json.times || [];
      timeSel.innerHTML = '<option value="">Elegí hora...</option>';
      times.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t;
        opt.textContent = t;
        timeSel.appendChild(opt);
      });
      msg.textContent = json.message || (times.length ? '' : 'No hay horarios disponibles.');
    }catch(e){
      timeSel.innerHTML = '<option value="">Error</option>';
      msg.textContent = e.message || 'Error al cargar horarios.';
    }
  }

  service.addEventListener('change', loadTimes);
  barber.addEventListener('change', loadTimes);
  date.addEventListener('change', loadTimes);

  loadTimes();
})();
</script>

<?php page_foot(); ?>
