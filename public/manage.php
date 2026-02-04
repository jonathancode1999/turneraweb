<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/status.php';
require_once __DIR__ . '/../includes/timeline.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$token = trim($_GET['token'] ?? '');
if (!$token) redirect('index.php');

$pdo = db();
$message = '';
$error = '';

// PRG: prevent browser refresh from resubmitting POST
if (isset($_GET['msg'])) {
    $msg = (string)$_GET['msg'];
    if ($msg === 'cancel_ok') $message = 'Turno cancelado.';
    if ($msg === 'resched_sent') $message = 'Solicitud de reprogramación enviada.';
}

// Helpers
function status_label(string $status): string {
    return appt_status_label($status);
}

function status_badge_class(string $status): string {
    return appt_status_badge_class($status);
}

function minutes_to_label(int $min): string {
    if ($min <= 0) return 'sin límite';
    if ($min % 1440 === 0) {
        $d = (int)($min / 1440);
        return $d === 1 ? '1 día' : ($d . ' días');
    }
    if ($min % 60 === 0) {
        $h = (int)($min / 60);
        return $h === 1 ? '1 hora' : ($h . ' horas');
    }
    return $min . ' minutos';
}

function enforce_customer_change_window(array $biz, array $appt): void {
    $cn = (int)($biz['cancel_notice_minutes'] ?? 0);
    if ($cn <= 0) return;
    $tz = new DateTimeZone(app_config()['timezone']);
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$appt['start_at'], $tz)
        ?: new DateTimeImmutable((string)$appt['start_at'], $tz);
    $now = now_tz();
    $diffMin = (int)floor(($start->getTimestamp() - $now->getTimestamp()) / 60);
    if ($diffMin < $cn) {
        throw new RuntimeException('Solo podés cancelar o reprogramar hasta ' . minutes_to_label($cn) . ' antes del turno.');
    }
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM appointments WHERE business_id=:bid AND token=:t');
            $stmt->execute([':bid' => $bid, ':t' => $token]);
            $a = $stmt->fetch();
            if (!$a) throw new RuntimeException('Turno no encontrado.');

            $branchId = (int)($a['branch_id'] ?? 1);
            if ($branchId <= 0) $branchId = 1;

            $biz = get_business($bid);

            if (in_array($a['status'], ['CANCELADO','VENCIDO'], true)) {
                $message = 'Este turno ya no está activo.';
            } else {
                enforce_customer_change_window($biz, $a);
                $pdo->prepare("UPDATE appointments SET status='CANCELADO', cancelled_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                    ->execute([':id' => (int)$a['id']]);
                $message = 'Turno cancelado.';

                // Notify customer + owner (if configured)
                $stmtN = $pdo->prepare('SELECT a.*, s.name AS service_name, br.name AS barber_name
                    FROM appointments a
                    JOIN services s ON s.id=a.service_id
                    JOIN barbers br ON br.id=a.barber_id
                    WHERE a.id=:id');
                $stmtN->execute(array(':id' => (int)$a['id']));
                $full = $stmtN->fetch();
                if ($full) notify_event('booking_cancelled', $biz, $full);
            }
            $pdo->commit();

            // Redirect to avoid re-submitting on refresh
            redirect('manage.php?token=' . urlencode((string)$token) . '&msg=cancel_ok');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }

    if ($action === 'reschedule') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM appointments WHERE business_id=:bid AND token=:t');
            $stmt->execute([':bid' => $bid, ':t' => $token]);
            $a = $stmt->fetch();
            if (!$a) throw new RuntimeException('Turno no encontrado.');

            if (!in_array($a['status'], ['PENDIENTE_APROBACION','ACEPTADO'], true)) {
                throw new RuntimeException('Este turno no se puede reprogramar.');
            }

            $biz = get_business($bid);
            enforce_customer_change_window($biz, $a);

            $newBarberId = (int)($_POST['new_barber_id'] ?? 0);
            $newServiceId = (int)($_POST['new_service_id'] ?? 0);
            if ($newServiceId <= 0) $newServiceId = (int)$a['service_id'];
            $newDate = trim($_POST['new_date'] ?? '');
            $newTime = trim($_POST['new_time'] ?? '');
            if (!$newDate || !$newTime) throw new RuntimeException('Elegí nueva fecha y hora.');

            $start = parse_local_datetime($newDate, $newTime);
            if ($newBarberId === 0) {
                $newBarberId = pick_barber_for_slot($bid, $newServiceId, $start);
            }

            // Validate (ignore current appointment)
            [$service, $end] = assert_slot_available($bid, $branchId, $newBarberId, $newServiceId, $start, (int)$a['id']);

            $pdo->prepare("UPDATE appointments
                          SET status='REPROGRAMACION_PENDIENTE',
                              requested_start_at=:rs,
                              requested_end_at=:re,
                              requested_at=CURRENT_TIMESTAMP,
                              requested_barber_id=:rb,
                              requested_service_id=:rsvc,
                              updated_at=CURRENT_TIMESTAMP
                          WHERE id=:id")
                ->execute([
                    ':rs' => $start->format('Y-m-d H:i:s'),
                    ':re' => $end->format('Y-m-d H:i:s'),
                    ':rb' => $newBarberId,
                    ':rsvc' => $newServiceId,
                    ':id' => (int)$a['id'],
                ]);

            try {
                appt_log_event($bid, $branchId, (int)$a['id'], 'reschedule_requested', 'El cliente solicitó reprogramación', [
                    'requested_start_at' => $start->format('Y-m-d H:i:s'),
                    'requested_barber_id' => $newBarberId,
                    'requested_service_id' => $newServiceId,
                ], 'customer');
            } catch (Throwable $e) {
                // non fatal
            }

            $message = 'Solicitud de reprogramación enviada.';

            // Notify by email (if configured)
            $stmtN = $pdo->prepare('SELECT a.*, s.name AS service_name, br.name AS barber_name
                FROM appointments a
                JOIN services s ON s.id=a.service_id
                JOIN barbers br ON br.id=a.barber_id
                WHERE a.id=:id');
            $stmtN->execute(array(':id' => (int)$a['id']));
            $full = $stmtN->fetch();
            if ($full) notify_event('reschedule_requested', $biz, $full);

            $pdo->commit();

            // Redirect to avoid re-submitting on refresh
            redirect('manage.php?token=' . urlencode((string)$token) . '&msg=resched_sent');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Load appointment
$stmt = $pdo->prepare('SELECT a.*,
        s.name AS service_name, s.duration_minutes,
        br.name AS barber_name,
        rs.name AS requested_service_name,
        rbr.name AS requested_barber_name,
        b.name AS business_name, b.address AS business_address, b.whatsapp_phone AS business_whatsapp,
        b.customer_choose_barber, b.slot_minutes
    FROM appointments a
    JOIN services s ON s.id=a.service_id
    JOIN barbers br ON br.id=a.barber_id
    LEFT JOIN services rs ON rs.id=a.requested_service_id
    LEFT JOIN barbers rbr ON rbr.id=a.requested_barber_id
    JOIN businesses b ON b.id=a.business_id
    WHERE a.business_id=:bid AND a.token=:t');
$stmt->execute([':bid' => $bid, ':t' => $token]);
$a = $stmt->fetch();
if (!$a) {
    page_head('Turno no encontrado', 'public-light');
    echo '<div class="container"><div class="card"><h1>Turno no encontrado</h1><p class="muted">El link es inválido o ya venció.</p><a class="btn" href="index.php">Volver</a></div></div>';
    page_foot();
    exit;
}

$now = now_tz();
$start = parse_db_datetime((string)$a['start_at']);
$end = parse_db_datetime((string)$a['end_at']);
$durationMin = (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60);
if ($durationMin < 0) $durationMin = 0;
$requestedStart = (!empty($a['requested_start_at'])) ? parse_db_datetime((string)$a['requested_start_at']) : null;
$status = (string)$a['status'];
$statusLabel = status_label($status);
$badge = status_badge_class($status);

// Lists for reschedule UI
$services = $pdo->query("SELECT id, name, description, duration_minutes, price_ars, is_active, image_url FROM services WHERE business_id=" . (int)$bid . " AND is_active=1 ORDER BY id")->fetchAll() ?: [];
$barbers = $pdo->query("SELECT id, name, is_active FROM barbers WHERE business_id=" . (int)$bid . " AND is_active=1 ORDER BY id")->fetchAll() ?: [];

// WhatsApp link
$wa = preg_replace('/\D+/', '', (string)($a['business_whatsapp'] ?? ''));
$msg = "Hola, te escribo por el turno #" . (int)$a['id'] . "\n" .
       "Cliente: " . ($a['customer_name'] ?? '') . " (" . ($a['customer_phone'] ?? '') . ")\n" .
       "Servicio: " . ($a['service_name'] ?? '') . "\n" .
       "Profesional: " . ($a['barber_name'] ?? '') . "\n" .
       "Fecha y hora: " . $start->format('d/m/Y H:i') . "\n" .
       "Estado: " . $statusLabel . "\n" .
       "Link: " . public_url('manage.php?token=' . urlencode((string)$token));
$waHref = $wa ? ('https://wa.me/' . $wa . '?text=' . urlencode($msg)) : '';

page_head('Gestionar turno', 'public-light');
?>

<div class="container">
  <div class="card">
    <div class="header">
      <div>
        <div class="title">Gestionar turno</div>
        <div class="subtitle"><?php echo h($a['business_name']); ?></div>
      </div>
      <a class="link" href="index.php">Volver</a>
    </div>

    <?php if ($message): ?><div class="notice ok"><?php echo h($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>

    <div class="kv">
      <div><span>N°</span><b>#<?php echo (int)$a['id']; ?></b></div>
      <div><span>Estado</span><span class="badge <?php echo h($badge); ?>"><?php echo h($statusLabel); ?></span></div>
      <div><span>Profesional</span><b><?php echo h($a['barber_name']); ?></b></div>
      <?php if ($status === 'REPROGRAMACION_PENDIENTE' && !empty($a['requested_barber_name']) && (string)$a['requested_barber_name'] !== (string)$a['barber_name']): ?>
        <div><span>Profesional solicitado</span><b><?php echo h($a['requested_barber_name']); ?></b></div>
      <?php endif; ?>
      <div><span>Servicio</span><b><?php echo h($a['service_name']); ?></b></div>
      <?php if ($status === 'REPROGRAMACION_PENDIENTE' && !empty($a['requested_service_name']) && (string)$a['requested_service_name'] !== (string)$a['service_name']): ?>
        <div><span>Servicio solicitado</span><b><?php echo h($a['requested_service_name']); ?></b></div>
      <?php endif; ?>
      <div><span>Fecha</span><b><?php echo h($start->format('d/m/Y')); ?></b></div>
      <?php if ($requestedStart && $status === 'REPROGRAMACION_PENDIENTE'): ?>
        <div><span>Solicitado</span><b><?php echo h($requestedStart->format('d/m/Y H:i')); ?></b></div>
      <?php endif; ?>
      <div><span>Hora</span><b><?php echo h($start->format('H:i')); ?></b></div>
      <div><span>Termina</span><b><?php echo h($end->format('H:i')); ?></b></div>
      <div><span>Duración</span><b><?php echo h((string)$durationMin); ?> min</b></div>
      <?php if (trim((string)($a['customer_email'] ?? '')) !== ''): ?>
        <div><span>Email</span><b><?php echo h($a['customer_email']); ?></b></div>
      <?php endif; ?>
    </div>

    <?php if ($status === 'PENDIENTE_APROBACION'): ?>
      <div class="notice warn">
        Tu turno está <b>PENDIENTE</b> de aprobación.
        <div class="muted small" style="margin-top:6px">Para confirmarlo, enviá el mensaje por WhatsApp al negocio y te lo aprueban.</div>
        <?php if ($waHref): ?>
          <div style="margin-top:10px"><a class="btn" href="<?php echo h($waHref); ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($status === 'ACEPTADO'): ?>
      <div class="notice ok">Tu turno está <b>CONFIRMADO</b>. Si necesitás cambiarlo, podés solicitar reprogramación abajo.</div>
    <?php endif; ?>

    <?php if ($status === 'REPROGRAMACION_PENDIENTE' && $requestedStart): ?>
      <div class="notice warn">Reprogramación solicitada para <b><?php echo h($requestedStart->format('d/m/Y H:i')); ?></b> (pendiente de aprobación).</div>
    <?php endif; ?>

    <?php if ($waHref && $status !== 'PENDIENTE_APROBACION'): ?>
      <a class="btn" href="<?php echo h($waHref); ?>" target="_blank" rel="noopener">Enviar al negocio por WhatsApp</a>
    <?php endif; ?>

    <?php if (in_array($status, ['PENDIENTE_APROBACION','ACEPTADO','REPROGRAMACION_PENDIENTE'], true)): ?>
      <form method="post" onsubmit="return confirm('¿Seguro que querés cancelar este turno?');" style="margin-top:10px">
        <input type="hidden" name="action" value="cancel">
        <button class="btn danger" type="submit">Cancelar turno</button>
      </form>
    <?php endif; ?>

    <?php if (in_array($status, ['PENDIENTE_APROBACION','ACEPTADO'], true) && count($services) > 0 && count($barbers) > 0): ?>
      <div class="spacer"></div>
      <div class="section-title">Solicitar reprogramación</div>

      <form method="post" id="rsForm">
        <input type="hidden" name="action" value="reschedule">
        <input type="hidden" name="new_barber_id" id="rs_barber_id" value="0">
        <input type="hidden" name="new_service_id" id="rs_service_id" value="">
        <input type="hidden" name="new_date" id="rs_date" value="">
        <input type="hidden" name="new_time" id="rs_time" value="">

        <div class="grid2">
          <div>
            <h3 class="h3">Elegí servicio</h3>
            <div class="services" id="rsServices">
              <?php foreach ($services as $s): ?>
                <div class="service-card" tabindex="0"
                     data-id="<?php echo (int)$s['id']; ?>">
                  <div class="s-name"><?php echo h($s['name']); ?></div>
                  <div class="s-meta"><?php echo (int)$s['duration_minutes']; ?> min · $<?php echo number_format((int)$s['price_ars'], 0, ',', '.'); ?></div>
                  <?php if (!empty($s['description'])): ?><div class="s-desc"><?php echo h($s['description']); ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div>
            <h3 class="h3">Elegí profesional</h3>
            <div class="pro-pills" id="rsProPills">
              <div class="pro-pill selected" tabindex="0" data-id="0">Primer profesional disponible</div>
              <?php foreach ($barbers as $br): ?>
                <div class="pro-pill" tabindex="0" data-id="<?php echo (int)$br['id']; ?>"><?php echo h($br['name']); ?></div>
              <?php endforeach; ?>
            </div>
            <div class="muted small" style="margin-top:6px">Si elegís “primer profesional disponible”, el sistema asigna automáticamente el primero libre.</div>

            <div class="spacer"></div>
            <h3 class="h3">Nueva fecha</h3>
            <div id="rs_calendar" class="calendar"></div>

            <div class="spacer"></div>
            <h3 class="h3">Nueva hora</h3>
            <div class="muted small" id="rsTimesHelp">Elegí servicio y fecha para ver horarios.</div>
            <div class="times" id="rsTimesWrap"></div>

            <div class="spacer"></div>
            <button class="btn" id="rsSubmit" type="submit" disabled>Enviar solicitud</button>
            <p class="muted small">El local debe aprobar el nuevo horario.</p>
          </div>
        </div>
      </form>
    <?php endif; ?>

  </div>
</div>

<script>
(function(){
  const rsForm = document.getElementById('rsForm');
  if(!rsForm) return;

  const proPills = document.getElementById('rsProPills');
  const servicesRoot = document.getElementById('rsServices');
  const barberInput = document.getElementById('rs_barber_id');
  const serviceInput = document.getElementById('rs_service_id');
  const dateInput = document.getElementById('rs_date');
  const timeInput = document.getElementById('rs_time');
  const timesWrap = document.getElementById('rsTimesWrap');
  const timesHelp = document.getElementById('rsTimesHelp');
  const submit = document.getElementById('rsSubmit');
  const calRoot = document.getElementById('rs_calendar');

  let selectedDate = null;
  let selectedTime = null;

  function toYMD(d){
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    return `${yyyy}-${mm}-${dd}`;
  }

  function setPro(id){
    barberInput.value = String(id);
    selectedTime = null;
    timeInput.value = '';
    [...proPills.querySelectorAll('.pro-pill')].forEach(el=>{
      el.classList.toggle('selected', el.dataset.id === String(id));
    });
    loadTimes();
    validateForm();
  }

  proPills.addEventListener('click', (e)=>{
    const pill = e.target.closest('.pro-pill');
    if(!pill) return;
    setPro(pill.dataset.id || '0');
  });
  proPills.addEventListener('keydown', (e)=>{
    if(e.key !== 'Enter' && e.key !== ' ') return;
    const pill = e.target.closest('.pro-pill');
    if(!pill) return;
    e.preventDefault();
    setPro(pill.dataset.id || '0');
  });

  function selectService(card){
    [...servicesRoot.querySelectorAll('.service-card')].forEach(c=>c.classList.remove('selected'));
    card.classList.add('selected');
    serviceInput.value = card.dataset.id;
    selectedTime = null;
    timeInput.value = '';
    loadTimes();
    validateForm();
  }
  servicesRoot.addEventListener('click', (e)=>{
    const card = e.target.closest('.service-card');
    if(!card) return;
    selectService(card);
  });
  servicesRoot.addEventListener('keydown', (e)=>{
    if(e.key !== 'Enter' && e.key !== ' ') return;
    const card = e.target.closest('.service-card');
    if(!card) return;
    e.preventDefault();
    selectService(card);
  });

  async function loadTimes(){
    submit.disabled = true;
    timesWrap.innerHTML = '';
    timesHelp.textContent = 'Cargando horarios...';

    const sid = serviceInput.value;
    const d = dateInput.value;
    const bid = barberInput.value;
    if(bid === '' || !sid || !d){
      timesHelp.textContent = 'Elegí servicio y fecha para ver horarios.';
      return;
    }

    try{
      const res = await fetch(`api.php?action=times&barber_id=${encodeURIComponent(bid)}&service_id=${encodeURIComponent(sid)}&date=${encodeURIComponent(d)}`);
      const data = await res.json();
      if(!data.ok){
        timesHelp.textContent = data.error || 'Sin horarios.';
        return;
      }
      const times = data.times || [];
      if(times.length===0){
        if (data.message) {
          timesHelp.textContent = data.message;
        } else if (bid === '0') {
          timesHelp.textContent = 'No hay horarios disponibles.';
        } else {
          timesHelp.textContent = `No hay horarios disponibles para ${data.barber_name || 'este profesional'}.`;
        }
        return;
      }
      timesHelp.textContent = 'Elegí un horario.';
      times.forEach(t=>{
        const chip = document.createElement('div');
        chip.className='time-chip';
        chip.textContent=t;
        chip.dataset.time=t;
        chip.addEventListener('click', ()=>{
          [...timesWrap.querySelectorAll('.time-chip')].forEach(x=>x.classList.remove('selected'));
          chip.classList.add('selected');
          selectedTime = t;
          timeInput.value = t;
          validateForm();
        });
        timesWrap.appendChild(chip);
      });
    } catch(e){
      timesHelp.textContent = 'Error al cargar horarios.';
    }
  }

  function validateForm(){
    submit.disabled = !((barberInput.value !== '') && serviceInput.value && dateInput.value && timeInput.value);
  }

  // Calendar (same UX as main)
  const today = new Date();
  let view = new Date(today.getFullYear(), today.getMonth(), 1);

  function renderCalendar(){
    const month = view.getMonth();
    const year = view.getFullYear();
    const first = new Date(year, month, 1);
    const startDow = first.getDay();
    const daysInMonth = new Date(year, month+1, 0).getDate();
    const monthName = first.toLocaleString('es-AR',{month:'long', year:'numeric'});

    calRoot.innerHTML = `
      <div class="cal-head">
        <div class="cal-title">${monthName.charAt(0).toUpperCase()+monthName.slice(1)}</div>
        <div class="cal-nav">
          <button type="button" class="cal-btn" id="rsCalPrev">‹</button>
          <button type="button" class="cal-btn" id="rsCalNext">›</button>
        </div>
      </div>
      <div class="cal-grid" id="rsCalGrid"></div>
    `;

    const grid = calRoot.querySelector('#rsCalGrid');
    ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'].forEach(d=>{
      const el=document.createElement('div');
      el.className='cal-dow';
      el.textContent=d;
      grid.appendChild(el);
    });
    for(let i=0;i<startDow;i++){
      const el=document.createElement('div');
      el.className='cal-day disabled';
      el.textContent='';
      grid.appendChild(el);
    }

    for(let day=1; day<=daysInMonth; day++){
      const d = new Date(year, month, day);
      const ymd = toYMD(d);
      const isPast = d < new Date(today.getFullYear(), today.getMonth(), today.getDate());
      const el=document.createElement('div');
      el.className='cal-day' + (isPast ? ' disabled' : '');
      el.textContent=String(day);
      el.dataset.ymd=ymd;
      if(selectedDate===ymd) el.classList.add('selected');
      el.addEventListener('click', ()=>{
        if(isPast) return;
        selectedDate = ymd;
        dateInput.value = ymd;
        selectedTime = null;
        timeInput.value = '';
        renderCalendar();
        loadTimes();
        validateForm();
      });
      grid.appendChild(el);
    }

    calRoot.querySelector('#rsCalPrev').addEventListener('click', ()=>{
      const prev = new Date(year, month-1, 1);
      const minMonth = new Date(today.getFullYear(), today.getMonth(), 1);
      if(prev < minMonth) return;
      view = prev;
      renderCalendar();
    });
    calRoot.querySelector('#rsCalNext').addEventListener('click', ()=>{
      view = new Date(year, month+1, 1);
      renderCalendar();
    });
  }

  // Defaults
  setPro('0');
  // preselect current service
  const currentSvcId = <?php echo (int)$a['service_id']; ?>;
  const svcCard = servicesRoot.querySelector(`.service-card[data-id="${currentSvcId}"]`) || servicesRoot.querySelector('.service-card');
  if (svcCard) selectService(svcCard);
  renderCalendar();

})();
</script>

<?php page_foot(); ?>
