<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability.php';

admin_require_login();
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$notice = '';
$error = '';

$today = now_tz()->format('Y-m-d');
$date = trim($_GET['date'] ?? $today);
if ($date < $today) $date = $today;

$barbersStmt = $pdo->prepare('SELECT id, name FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id');
$barbersStmt->execute([':bid' => $bid, ':brid' => $branchId]);
$barbers = $barbersStmt->fetchAll() ?: [];

$servicesStmt = $pdo->prepare('SELECT id, name, duration_minutes FROM services WHERE business_id=:bid AND is_active=1 ORDER BY id');
// services are shared across branches (branch is chosen via professional schedule)
$servicesStmt->execute([':bid' => $bid]);
$services = $servicesStmt->fetchAll() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate_or_die();
  try {
    $ymd = trim($_POST['date'] ?? '');
    $hm = trim($_POST['time'] ?? '');
    $barberId = (int)($_POST['barber_id'] ?? 0);
    $serviceId = (int)($_POST['service_id'] ?? 0);
    if ($ymd === '' || $hm === '' || $barberId <= 0 || $serviceId <= 0) {
      throw new RuntimeException('Completá fecha, hora, profesional y servicio.');
    }

    $biz = get_business($bid);
    $slotMin = (int)($biz['slot_minutes'] ?? 15);
    // Admin: allow creating at any minute. SlotMin is still used to round the service duration.
    if ($slotMin < 10) $slotMin = 10;

    $service = get_service($bid, $serviceId);
    $durationMin = round_duration_to_slot((int)$service['duration_minutes'], $slotMin);

    $tz = new DateTimeZone($cfg['timezone']);
    $day = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, $tz);
    if (!$day) throw new RuntimeException('Fecha inválida.');
    $day = $day->setTime(0, 0);

    // Validate business/barber working hours (and timeoff) so we can clamp the requested time.
    if (barber_is_on_timeoff($bid, $barberId, $day)) {
      throw new RuntimeException('Ese profesional está de vacaciones ese día.');
    }
    $hours = barber_hours_for_day($bid, $barberId, $day);
    if ((int)($hours['is_closed'] ?? 1) === 1) {
      throw new RuntimeException('El negocio está cerrado ese día.');
    }

    $open = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . ($hours['open_time'] ?? '00:00'), $tz);
    $close = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . ($hours['close_time'] ?? '23:59'), $tz);
    if (!$open || !$close) throw new RuntimeException('Horario inválido.');

    $start = parse_local_datetime($ymd, $hm);
    $now = now_tz();
    $nowMinute = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);

    // No permitir crear turnos en el pasado.
    if ($ymd === $today && $start < $nowMinute) {
      throw new RuntimeException('Elegí un turno desde las ' . $nowMinute->format('H:i') . ' en adelante.');
    }

    // Respetar rango de atención.
    if ($start < $open || $start > $close) {
      throw new RuntimeException('Horario fuera del rango de atención (' . $open->format('H:i') . '–' . $close->format('H:i') . ').');
    }

    // If the requested time is not available, automatically pick the next available time.
    $pickedStart = null;
    $pickedEnd = null;
    $lastErr = '';
    // search up to close time (max same day)
    for ($i = 0; $i < 24*60; $i++) {
      try {
        // Stop if the service would exceed closing time.
        $endCandidate = $start->modify('+' . $durationMin . ' minutes');
        if ($endCandidate > $close) {
          $lastErr = 'No hay más horarios dentro del rango de atención.';
          break;
        }
        [$svcTmp, $endTmp, $durTmp] = assert_slot_available($bid, $barberId, $serviceId, $start);
        $pickedStart = $start;
        $pickedEnd = $endTmp;
        $durationMin = $durTmp;
        break;
      } catch (Throwable $e) {
        $lastErr = $e->getMessage();
        // If it's outside working hours, clamp forward to open time.
        if (stripos($lastErr, 'fuera del rango') !== false) {
          if ($start < $open) {
            $start = $open;
            continue;
          }
          break;
        }
        if (stripos($lastErr, 'cerrada') !== false || stripos($lastErr, 'vacaciones') !== false) {
          break;
        }
        // Otherwise it's likely "ocupado" -> try next minute
        $start = $start->modify('+1 minute');
      }
    }
    if (!$pickedStart || !$pickedEnd) {
      throw new RuntimeException($lastErr ?: 'No hay horarios disponibles para ese día.');
    }

    $token = random_token(16);
    $customerName = trim((string)($_POST['customer_name'] ?? ''));
    if ($customerName === '') $customerName = '---';
    $pdo->prepare('INSERT INTO appointments (business_id, branch_id, barber_id, service_id, customer_name, customer_phone, customer_email, notes, start_at, end_at, status, token, price_snapshot_ars)
        VALUES
        (:bid, :brid, :bar, :srv, :cn, :cp, :ce, :nt, :s, :e, :st, :t, :price)')
      ->execute(array(
        ':bid'  => $bid,
        ':brid' => $branchId,
        ':bar'  => $barberId,
        ':srv'  => $serviceId,
        ':cn'   => $customerName,
        ':cp'   => '',
        ':ce'   => '',
        ':nt'   => 'Creado desde Admin (turno rápido)',
        ':s'    => $pickedStart->format('Y-m-d H:i:s'),
        ':e'    => $pickedEnd->format('Y-m-d H:i:s'),
        ':st'   => 'OCUPADO',
        ':t'    => $token,
        ':price'=> (int)($service['price_ars'] ?? 0),
      ));

    redirect('appointments.php?date=' . urlencode($ymd));
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

page_head('Crear turno rápido', 'admin');
admin_nav('appointments');
?>

<div class="card">
  <h1>Crear turno rápido</h1>
  <p class="muted">Para marcar como <b>OCUPADO</b> (cliente sin reserva, o para bloquear un horario con servicio).</p>

  <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>
</div>


  <form method="post" class="card qa-wrap">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">

    <div class="qa-grid">
      <div>
        <label>Fecha</label>
        <input type="date" name="date" value="<?php echo h($date); ?>" min="<?php echo h($today); ?>" required>
      </div>
      <div>
        <label>Hora</label>
        <input type="time" name="time" value="" step="60" required>
        <div class="help">Desde la hora actual en adelante.</div>
      </div>

      <div>
        <label>Profesional</label>
        <select name="barber_id" required>
          <option value="">Elegir...</option>
          <?php foreach ($barbers as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>"><?php echo h($b['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Servicio</label>
        <select name="service_id" required>
          <option value="">Elegir...</option>
          <?php foreach ($services as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['name']); ?> (<?php echo (int)$s['duration_minutes']; ?> min)</option>
          <?php endforeach; ?>
        </select>
        <div class="help">Si el horario está ocupado, se elige automáticamente el próximo disponible.</div>
      </div>

      <div style="grid-column:1 / -1">
        <label>Descripción / Nombre (opcional)</label>
        <input type="text" name="customer_name" maxlength="80" placeholder="Ej: Juan (walk-in) / Bloqueo por reunión" value="">
        <div class="help">Se guardará en el campo <b>Nombre</b> del turno.</div>
      </div>
    </div>

    <div class="qa-actions">
      <button class="btn primary" type="submit">Crear turno</button>
      <a class="btn" href="appointments.php?date=<?php echo h($date); ?>">Volver</a>
    </div>
  </form>


<script>
  // Client-side helpers: prevent selecting past date/time and give nicer constraints.
  (function(){
    const dateEl = document.querySelector('input[name="date"]');
    const timeEl = document.querySelector('input[name="time"]');
    const barberEl = document.querySelector('select[name="barber_id"]');
    const today = <?php echo json_encode($today); ?>;
    function pad(n){return String(n).padStart(2,'0');}
    async function refreshConstraints(){
      if(!dateEl || !timeEl) return;
      // Reset
      timeEl.removeAttribute('min');
      timeEl.removeAttribute('max');

      // 1) Desde ahora (si es hoy)
      let minTime = '';
      if(dateEl.value === today){
        const now = new Date();
        minTime = pad(now.getHours())+':'+pad(now.getMinutes());
      }

      // 2) Working hours (when barber is selected)
      const barberId = barberEl?.value || '';
      if(barberId){
        try{
          const url = 'api.php?action=hours&barber_id='+encodeURIComponent(barberId)+'&date='+encodeURIComponent(dateEl.value);
          const res = await fetch(url, {headers:{'Accept':'application/json'}});
          const j = await res.json();
          if(j && j.ok){
            if(j.is_closed===1 || j.is_timeoff===1){
              // No constraints to set, server will show the right error.
            } else {
              if(j.open_time) {
                // max of (open_time, minTime)
                if(minTime && minTime > j.open_time) timeEl.min = minTime;
                else timeEl.min = j.open_time;
              } else if(minTime) {
                timeEl.min = minTime;
              }
              if(j.close_time) timeEl.max = j.close_time;
            }
          } else {
            if(minTime) timeEl.min = minTime;
          }
        } catch(e){
          if(minTime) timeEl.min = minTime;
        }
      } else {
        if(minTime) timeEl.min = minTime;
      }
    }
    dateEl?.addEventListener('change', refreshConstraints);
    barberEl?.addEventListener('change', refreshConstraints);
    refreshConstraints();
  })();
</script>

<?php page_foot(); ?>