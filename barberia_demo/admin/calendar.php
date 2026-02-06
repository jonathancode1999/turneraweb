<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/status.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/timeline.php';

admin_require_login();
admin_require_permission('appointments');
admin_require_branch_selected();

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$view = trim($_GET['view'] ?? 'day'); // 'day' or 'week'
$date = trim($_GET['date'] ?? now_tz()->format('Y-m-d'));
$barberFilter = (int)($_GET['barber_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');

try {
  $curDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone($cfg['timezone']));
  if (!$curDateObj) throw new RuntimeException('Fecha inválida');
} catch (Throwable $e) {
  $curDateObj = now_tz();
  $date = $curDateObj->format('Y-m-d');
}

$prevDate = $curDateObj->modify('-1 day')->format('Y-m-d');
$nextDate = $curDateObj->modify('+1 day')->format('Y-m-d');

$barbersStmt = $pdo->prepare('SELECT id, name FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id');
$barbersStmt->execute([':bid' => $bid, ':brid' => $branchId]);
$barbers = $barbersStmt->fetchAll() ?: [];

$biz = $pdo->prepare('SELECT * FROM businesses WHERE id=:id');
$biz->execute([':id' => $bid]);
$business = $biz->fetch() ?: ['id' => $bid, 'name' => 'Turnera'];

$now = now_tz();
$dayStart = (string)($cfg['calendar_day_start'] ?? '08:00');
$dayEnd = (string)($cfg['calendar_day_end'] ?? '20:00');
$slot = (int)($cfg['slot_minutes'] ?? 15);
if ($slot <= 0) $slot = 15;

// Handle admin actions (same behavior as appointments.php) but return to calendar.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate_or_die();
  $id = (int)($_POST['id'] ?? 0);
  $act = (string)($_POST['act'] ?? '');
  if ($id > 0 && $act !== '') {
    $stmtA = $pdo->prepare("SELECT a.*, s.name AS service_name, br.name AS barber_name
        FROM appointments a
        JOIN services s ON s.id=a.service_id
        JOIN barbers br ON br.id=a.barber_id
        WHERE a.business_id=:bid AND a.branch_id=:brid AND a.id=:id");
    $stmtA->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
    $a = $stmtA->fetch();

    if ($a) {
      try {
        if ($act === 'accept') {
          $pdo->prepare("UPDATE appointments SET status='ACEPTADO', updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
              ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
        } elseif ($act === 'cancel') {
          $pdo->prepare("UPDATE appointments SET status='CANCELADO', cancelled_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
              ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
        } elseif ($act === 'complete') {
          $pdo->prepare("UPDATE appointments SET status='COMPLETADO', updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
              ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
        } elseif ($act === 'no_show') {
          $pdo->prepare("UPDATE appointments SET status='NO_ASISTIO', updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
              ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
        } elseif ($act === 'approve_reschedule') {
          if (!empty($a['requested_start_at'])) {
            $rs = parse_db_datetime((string)$a['requested_start_at']);
            $newBarber = (int)($a['requested_barber_id'] ?? $a['barber_id']);
            $newService = (int)($a['requested_service_id'] ?? $a['service_id']);
            [$svc, $newEnd] = assert_slot_available($bid, $branchId, $newBarber, $newService, $rs, (int)$a['id']);
            $pdo->prepare("UPDATE appointments
              SET start_at=:s, end_at=:e,
                  barber_id=:bar, service_id=:sid,
                  status='ACEPTADO',
                  requested_start_at=NULL, requested_end_at=NULL, requested_at=NULL,
                  requested_barber_id=NULL, requested_service_id=NULL,
                  updated_at=CURRENT_TIMESTAMP
              WHERE business_id=:bid AND branch_id=:brid AND id=:id")
              ->execute([
                ':s' => $rs->format('Y-m-d H:i:s'),
                ':e' => $newEnd->format('Y-m-d H:i:s'),
                ':bar' => $newBarber,
                ':sid' => $newService,
                ':bid' => $bid,
                ':brid' => $branchId,
                ':id' => $id,
              ]);
          }
        } elseif ($act === 'reject_reschedule') {
          $pdo->prepare("UPDATE appointments
            SET status='ACEPTADO',
                requested_start_at=NULL, requested_end_at=NULL, requested_at=NULL,
                requested_barber_id=NULL, requested_service_id=NULL,
                updated_at=CURRENT_TIMESTAMP
            WHERE business_id=:bid AND branch_id=:brid AND id=:id")
            ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
        }
      } catch (Throwable $e) {
        flash_set('error', 'No se pudo aplicar la acción: ' . $e->getMessage());
      }

      // Reload for notifications / timeline
      $stmtA->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
      $a2 = $stmtA->fetch();
      if ($a2) {
        $uid = (int)($_SESSION['admin_user']['id'] ?? 0);
        try {
          if ($act === 'accept') appt_log_event($bid, $branchId, $id, 'accepted', 'Turno aprobado desde Calendario', ['status'=>'ACEPTADO'], 'admin', $uid);
          elseif ($act === 'cancel') appt_log_event($bid, $branchId, $id, 'cancelled', 'Turno cancelado desde Calendario', ['status'=>'CANCELADO'], 'admin', $uid);
          elseif ($act === 'complete') appt_log_event($bid, $branchId, $id, 'completed', 'Turno marcado como completado', ['status'=>'COMPLETADO'], 'admin', $uid);
          elseif ($act === 'no_show') appt_log_event($bid, $branchId, $id, 'no_show', 'Turno marcado como no asistió', ['status'=>'NO_ASISTIO'], 'admin', $uid);
          elseif ($act === 'approve_reschedule') appt_log_event($bid, $branchId, $id, 'reschedule_approved', 'Reprogramación aprobada', ['new_start_at'=>(string)($a2['start_at']??'')], 'admin', $uid);
          elseif ($act === 'reject_reschedule') appt_log_event($bid, $branchId, $id, 'reschedule_rejected', 'Reprogramación rechazada', [], 'admin', $uid);
        } catch (Throwable $e) {}

        $event = '';
        if ($act === 'accept') $event = 'booking_approved';
        elseif ($act === 'cancel') $event = 'booking_cancelled';
        elseif ($act === 'approve_reschedule') $event = 'reschedule_approved';
        elseif ($act === 'reject_reschedule') $event = 'reschedule_rejected';

        if ($event !== '') {
          try { notify_event($event, $business ?: [], $a2, ['to_owner' => false]); } catch (Throwable $e) {}
        }
      }
    }
  }
  redirect('calendar.php?view=' . urlencode($view) . '&date=' . urlencode($date) . '&status=' . urlencode($statusFilter) . '&barber_id=' . urlencode((string)$barberFilter));
}

// Pull appointments for the day (day view). Week view is optional stub.
$params = [':bid' => $bid, ':brid' => $branchId];
$where = "a.business_id=:bid AND a.branch_id=:brid";

if ($view === 'week') {
  // Week view: show 7 days starting from Monday of current week (simple list for now).
  $start = $curDateObj->modify('monday this week');
  $end = $start->modify('+7 day');
  $where .= " AND a.start_at >= :s AND a.start_at < :e";
  $params[':s'] = $start->format('Y-m-d 00:00:00');
  $params[':e'] = $end->format('Y-m-d 00:00:00');
} else {
  $where .= " AND date(a.start_at)=:d";
  $params[':d'] = $date;
}

if ($barberFilter > 0) {
  $where .= " AND a.barber_id=:bar";
  $params[':bar'] = $barberFilter;
}
if ($statusFilter !== '') {
  $where .= " AND a.status=:st";
  $params[':st'] = $statusFilter;
}

$stmt = $pdo->prepare("SELECT a.*,
    s.name AS service_name, b.name AS barber_name
  FROM appointments a
  JOIN services s ON s.id=a.service_id
  JOIN barbers b ON b.id=a.barber_id
  WHERE $where
  ORDER BY a.start_at ASC, a.created_at ASC");
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

// Helpers for rendering
function minutes_from_hhmm(string $hhmm): int {
  if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) return 0;
  return ((int)$m[1]) * 60 + (int)$m[2];
}

$dayStartMin = minutes_from_hhmm($dayStart);
$dayEndMin = minutes_from_hhmm($dayEnd);
if ($dayEndMin <= $dayStartMin) $dayEndMin = $dayStartMin + 12*60;

$slotHeight = 18; // px per slot (e.g. 15min)
$totalMinutes = $dayEndMin - $dayStartMin;
$totalSlots = (int)ceil($totalMinutes / $slot);
$gridHeight = $totalSlots * $slotHeight;

page_head('Calendario', 'admin');
admin_nav('calendar');
?>
<style>
  .cal-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-end; flex-wrap:wrap; }
  .cal-controls { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
  /*
    IMPORTANT:
    We use CSS Grid instead of Flex here.
    In some browsers/layouts (especially when the lane only contains absolutely positioned children)
    a flex item can end up shrink-to-fit and not take the full available width, which made the
    calendar look like it occupied only a quarter of the area.
  */
  .cal-grid { display:block; width:100%; margin-top:12px; border:1px solid var(--border); border-radius:12px; overflow:hidden; background:var(--card); }
  .cal-body { display:grid; grid-template-columns:74px 1fr; width:100%; }
  .cal-times { width:74px; border-right:1px solid var(--border); background:rgba(0,0,0,0.02); }
  /* one row per slot (e.g. 15min). we show label only on full hours */
  /*
    Time column should be clean: no grid lines here.
    Grid lines are drawn only in the lane so the left gray area doesn't look "striped".
  */
  .cal-time { height:<?php echo (int)$slotHeight; ?>px; padding:2px 8px; font-size:12px; color:var(--muted); display:flex; align-items:flex-start; border-bottom:0; }
  .cal-time.hour { font-weight:700; color:var(--text); }
  .cal-lane { position:relative; width:100%; min-width:0; height:<?php echo (int)$gridHeight; ?>px; }
  .cal-line { position:absolute; left:0; right:0; height:1px; background:var(--border); opacity:.7; }
  /* Appointment blocks: force full lane width (avoid shrink-to-fit quirks). */
  .cal-appt {
    position:absolute;
    /* width/left are controlled via CSS vars to support overlaps */
    left:calc(10px + (var(--x, 0) * (100% - 20px)));
    width:calc((var(--w, 1) * (100% - 20px)) - 6px);
    box-sizing:border-box;
    border:1px solid var(--border);
    border-left:4px solid var(--primary);
    border-radius:10px;
    padding:8px 10px;
    background:rgba(0,0,0,0.01);
    cursor:pointer;
    box-shadow:0 2px 10px rgba(0,0,0,0.04);
    overflow:hidden;
  }
  .cal-appt:hover { filter:brightness(0.99); }

  /* Status-based styling (border + subtle bg) */
  .cal-appt.st-pendiente { border-left-color: rgba(240,170,0,1); background:rgba(240,170,0,0.07); }
  .cal-appt.st-aceptado { border-left-color: rgba(45,123,209,1); background:rgba(45,123,209,0.06); }
  .cal-appt.st-ocupado { border-left-color: rgba(45,123,209,1); background:rgba(45,123,209,0.06); }
  .cal-appt.st-cancelado { border-left-color: rgba(220,20,60,1); background:rgba(220,20,60,0.06); }
  .cal-appt.st-reprog { border-left-color: rgba(140,90,255,1); background:rgba(140,90,255,0.06); }
  .cal-appt.st-completado { border-left-color: rgba(0,160,60,1); background:rgba(0,160,60,0.06); }
  .cal-appt.st-noasistio { border-left-color: rgba(120,120,120,1); background:rgba(120,120,120,0.06); }
  .cal-appt.st-expirado { border-left-color: rgba(120,120,120,1); background:rgba(120,120,120,0.04); opacity:.85; }

  /* (Removed overflow grouping – show all appointments, even if many overlap) */
  /* Compact tweaks: when blocks are narrow/short */
  .cal-appt.compact { padding:6px 8px; }
  .cal-appt.compact .s, .cal-appt.compact .meta { display:none; }
  .cal-appt.compact .t { font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; padding-right:64px; }
  .cal-appt.compact .badge { top:6px; right:6px; font-size:11px; padding:1px 7px; }
  .cal-appt .t { font-weight:800; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; padding-right:120px; }
  .cal-appt .s { font-size:12px; color:var(--muted); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; padding-right:120px; }
  .cal-appt .meta { margin-top:6px; font-size:12px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .cal-appt.compact .s, .cal-appt.compact .meta { display:none; }
  .cal-appt .badge { position:absolute; top:8px; right:8px; }
  .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid var(--border); }
  .badge.ok { background:rgba(0,160,60,0.12); }
  .badge.warn { background:rgba(240,170,0,0.14); }
  .badge.danger { background:rgba(220,20,60,0.12); }
  .cal-empty { padding:14px; color:var(--muted); }
  /* Modal */
  .modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; padding:16px; z-index:9999; }
  .modal.on { display:flex; }
  .modal .back { position:absolute; inset:0; background:rgba(0,0,0,0.4); }
  .modal .panel { position:relative; width:min(560px, 100%); background:var(--card); border:1px solid var(--border); border-radius:16px; padding:14px; box-shadow:0 12px 50px rgba(0,0,0,0.25); }
  .modal .panel h3 { margin:0; }
  .modal .row2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:12px; }
  .modal .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
  .modal .close { position:absolute; top:8px; right:8px; width:36px; height:36px; border-radius:10px; border:1px solid var(--border); background:transparent; font-size:22px; line-height:1; }
  @media (max-width: 520px){ .modal .row2 { grid-template-columns:1fr; } .cal-times { width:62px; } }
</style>

<div class="card">
  <?php $ferr = flash_get('error'); $fok = flash_get('ok'); ?>
  <?php if ($fok): ?><div class="notice ok"><?php echo h($fok); ?></div><?php endif; ?>
  <?php if ($ferr): ?><div class="notice danger"><?php echo h($ferr); ?></div><?php endif; ?>

  <div class="cal-head">
    <div>
      <h1 style="margin:0">Calendario</h1>
      <div class="muted small" style="margin-top:4px">
        <a class="link" href="calendar.php?view=<?php echo h(urlencode($view)); ?>&date=<?php echo h($prevDate); ?>&status=<?php echo h(urlencode($statusFilter)); ?>&barber_id=<?php echo h((string)$barberFilter); ?>">← Día anterior</a>
        <span style="margin:0 8px">·</span>
        <a class="link" href="calendar.php?view=<?php echo h(urlencode($view)); ?>&date=<?php echo h($nextDate); ?>&status=<?php echo h(urlencode($statusFilter)); ?>&barber_id=<?php echo h((string)$barberFilter); ?>">Día siguiente →</a>
      </div>
    </div>

    <form method="get" class="cal-controls">
      <input type="hidden" name="view" value="<?php echo h($view); ?>">
      <div>
        <label>Fecha</label>
        <input type="date" name="date" value="<?php echo h($date); ?>">
      </div>
      <div>
        <label>Profesional</label>
        <select name="barber_id">
          <option value="0">Todos</option>
          <?php foreach ($barbers as $b): ?>
            <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$b['id'] === $barberFilter) ? 'selected' : ''; ?>>
              <?php echo h($b['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Estado</label>
        <select name="status">
          <option value="">Todos</option>
          <?php foreach ([APPT_STATUS_PENDING_APPROVAL,APPT_STATUS_ACCEPTED,APPT_STATUS_RESCHEDULE_PENDING,APPT_STATUS_BLOCKED,APPT_STATUS_CANCELLED,APPT_STATUS_COMPLETED,APPT_STATUS_NO_SHOW,APPT_STATUS_EXPIRED] as $st): ?>
            <option value="<?php echo h($st); ?>" <?php echo ($statusFilter === $st) ? 'selected' : ''; ?>>
              <?php echo h(appt_status_label($st)); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn">Ver</button>
      <a class="btn" href="export_csv.php?range=day&date=<?php echo h($date); ?>&barber_id=<?php echo (int)$barberFilter; ?>&status=<?php echo h($statusFilter); ?>">Exportar día (CSV)</a>
      <a class="btn" href="export_csv.php?range=week&date=<?php echo h($date); ?>&barber_id=<?php echo (int)$barberFilter; ?>&status=<?php echo h($statusFilter); ?>">Exportar semana (CSV)</a>
      <a class="btn primary" href="quick_appointment.php?date=<?php echo h($date); ?>">Crear turno</a>
    </form>
  </div>

  <?php if ($view === 'week'): ?>
    <div class="cal-empty">Vista semana: por ahora se muestra como lista (lo hacemos grilla después). Usá “Día” para la vista calendario.</div>
  <?php endif; ?>

  <?php if ($view !== 'week'): ?>
    <div class="cal-grid">
      <div class="cal-body">
        <div class="cal-times" aria-hidden="true">
          <?php
            // One row per slot (e.g. 15min). We print label only on full hours.
            $t = $dayStartMin;
            while ($t < $dayEndMin) {
              $hh = (int)floor($t/60); $mm = (int)($t % 60);
              $isHour = ($mm === 0);
              $label = $isHour ? sprintf('%02d:%02d', $hh, $mm) : '';
              echo '<div class="cal-time ' . ($isHour ? 'hour' : '') . '">' . h($label) . '</div>';
              $t += $slot;
            }
          ?>
        </div>
        <div class="cal-lane" id="calLane">
          <?php
            // grid lines each slot
            for ($i=0; $i<=$totalSlots; $i++) {
              $y = $i * $slotHeight;
              echo '<div class="cal-line" style="top:' . (int)$y . 'px"></div>';
            }

            // Build a small layout model so overlapping turnos do not render on top of each other.
            $appts = [];
            foreach ($rows as $r) {
              $start = parse_db_datetime((string)$r['start_at']);
              $end = parse_db_datetime((string)$r['end_at']);
              $sMin = ((int)$start->format('H'))*60 + (int)$start->format('i');
              $eMin = ((int)$end->format('H'))*60 + (int)$end->format('i');
              if ($eMin <= $sMin) $eMin = $sMin + $slot;
              $appts[] = [
                'row' => $r,
                'start' => $start,
                'end' => $end,
                'sMin' => $sMin,
                'eMin' => $eMin,
                'col' => 0,
                'group' => 0,
              ];
            }

            // Assign columns using a sweep-line algorithm.
            usort($appts, function($a, $b){
              if ($a['sMin'] === $b['sMin']) return $a['eMin'] <=> $b['eMin'];
              return $a['sMin'] <=> $b['sMin'];
            });

            $active = []; // each: ['eMin'=>int,'col'=>int]
            $groupId = 0;
            $groupMax = []; // groupId => max cols used

            foreach ($appts as $i => $a) {
              // Remove finished
              $newActive = [];
              foreach ($active as $act) {
                if ((int)$act['eMin'] > (int)$a['sMin']) $newActive[] = $act;
              }
              $active = $newActive;

              if (count($active) === 0) {
                $groupId++;
                $groupMax[$groupId] = 0;
              }

              // Find first free column
              $used = [];
              foreach ($active as $act) $used[(int)$act['col']] = true;
              $col = 0;
              while (isset($used[$col])) $col++;

              $appts[$i]['col'] = $col;
              $appts[$i]['group'] = $groupId;
              $active[] = ['eMin' => (int)$a['eMin'], 'col' => $col];

              // Update max columns used in this overlap group.
              $maxCol = 0;
              foreach ($active as $act) $maxCol = max($maxCol, (int)$act['col']);
              $groupMax[$groupId] = max((int)$groupMax[$groupId], $maxCol + 1);
            }

            // Render
            foreach ($appts as $a) {
              $r = $a['row'];
              $st = appt_status_normalize((string)$r['status']);
              $start = $a['start'];
              $end = $a['end'];
              $sMin = (int)$a['sMin'];
              $eMin = (int)$a['eMin'];

              $topMin = max(0, $sMin - $dayStartMin);
              $durMin = max($slot, $eMin - $sMin);
              $top = (int)round(($topMin / $slot) * $slotHeight);
              $height = (int)max($slotHeight, round(($durMin / $slot) * $slotHeight));

              // Always show all overlapping appointments (no grouping).
              $cols = max(1, (int)($groupMax[(int)$a['group']] ?? 1));
              $col = (int)$a['col'];

              $x = $col / $cols;
              $w = 1 / $cols;

              $compact = ($height < (int)($slotHeight * 3)); // short appointments -> single line
              $badgeCls = appt_status_badge_class($st);

              $title = sprintf('%s–%s • %s', $start->format('H:i'), $end->format('H:i'), (string)($r['customer_name'] ?? ''));
              $sub = (string)($r['service_name'] ?? '');
              $meta = (string)($r['barber_name'] ?? '');

              $data = [
                'id' => (int)$r['id'],
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
                'customer' => (string)($r['customer_name'] ?? ''),
                'phone' => (string)($r['customer_phone'] ?? ''),
                'email' => (string)($r['customer_email'] ?? ''),
                'service' => (string)($r['service_name'] ?? ''),
                'barber' => (string)($r['barber_name'] ?? ''),
                'status' => $st,
                'statusLabel' => appt_status_label($st),
                'notes' => (string)($r['notes'] ?? ''),
                'requested_start' => (string)($r['requested_start_at'] ?? ''),
              ];
              $json = htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

              // Status class for styling (colors by state)
              $stClass = '';
              if ($st === APPT_STATUS_PENDING_APPROVAL) $stClass = 'st-pendiente';
              elseif ($st === APPT_STATUS_ACCEPTED) $stClass = 'st-aceptado';
              elseif ($st === APPT_STATUS_BLOCKED) $stClass = 'st-ocupado';
              elseif ($st === APPT_STATUS_RESCHEDULE_PENDING) $stClass = 'st-reprog';
              elseif ($st === APPT_STATUS_CANCELLED) $stClass = 'st-cancelado';
              elseif ($st === APPT_STATUS_COMPLETED) $stClass = 'st-completado';
              elseif ($st === APPT_STATUS_NO_SHOW) $stClass = 'st-noasistio';
              elseif ($st === APPT_STATUS_EXPIRED) $stClass = 'st-expirado';

              echo '<div class="cal-appt ' . ($compact ? 'compact' : '') . ' ' . h($stClass) . '" '
                . 'style="--x:' . number_format($x, 6, '.', '') . ';--w:' . number_format($w, 6, '.', '') . ';top:' . $top . 'px;height:' . $height . 'px" '
                . 'data-appt=\'' . $json . '\'>';
              echo '<span class="badge ' . h($badgeCls) . '">' . h(appt_status_label($st)) . '</span>';
              echo '<div class="t">' . h($title) . '</div>';
              echo '<div class="s">' . h($sub) . '</div>';
              echo '<div class="meta">' . h($meta) . '</div>';
              echo '</div>';
            }
if (count($rows) === 0) {
              echo '<div class="cal-empty">No hay turnos para esta fecha con esos filtros.</div>';
            }
          ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="cal-grid" style="padding:12px">
      <?php if (count($rows) === 0): ?>
        <div class="cal-empty">No hay turnos en esta semana con esos filtros.</div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>Fecha</th><th>Hora</th><th>Cliente</th><th>Servicio</th><th>Profesional</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): $start = parse_db_datetime((string)$r['start_at']); $end = parse_db_datetime((string)$r['end_at']); $st = appt_status_normalize((string)$r['status']); ?>
              <tr>
                <td><?php echo h($start->format('Y-m-d')); ?></td>
                <td><?php echo h($start->format('H:i') . '–' . $end->format('H:i')); ?></td>
                <td><?php echo h((string)$r['customer_name']); ?></td>
                <td><?php echo h((string)$r['service_name']); ?></td>
                <td><?php echo h((string)$r['barber_name']); ?></td>
                <td><span class="badge <?php echo h(appt_status_badge_class($st)); ?>"><?php echo h(appt_status_label($st)); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="modal" id="apptModal" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="back" data-close="1"></div>
  <div class="panel">
    <button class="close" type="button" data-close="1" aria-label="Cerrar">×</button>
    <h3 id="mTitle">Turno</h3>
    <div class="muted small" id="mSub"></div>

    <div class="row2">
      <div>
        <div class="muted small">Cliente</div>
        <div id="mCustomer" style="font-weight:700"></div>
        <div class="muted small" id="mContact"></div>
      </div>
      <div>
        <div class="muted small">Estado</div>
        <div><span class="badge" id="mStatus">—</span></div>
        <div class="muted small" id="mNotes" style="margin-top:6px"></div>
      </div>
    </div>

    <div class="actions" id="mActions">
      <a class="btn" id="mView" href="#">Ver detalle</a>
      <a class="btn" id="mReschedule" href="#">Reprogramar</a>

      <form method="post" style="display:inline-flex;gap:10px;flex-wrap:wrap" id="mForms">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="id" id="fId" value="0">
        <button class="btn primary" type="submit" name="act" value="accept" id="btnAccept">Aprobar</button>
        <button class="btn danger" type="submit" name="act" value="cancel" id="btnCancel">Cancelar</button>
        <button class="btn" type="submit" name="act" value="complete" id="btnComplete">Completado</button>
        <button class="btn" type="submit" name="act" value="no_show" id="btnNoShow">No asistió</button>
        <button class="btn primary" type="submit" name="act" value="approve_reschedule" id="btnApproveRes">Aprobar reprogramación</button>
        <button class="btn danger" type="submit" name="act" value="reject_reschedule" id="btnRejectRes">Rechazar reprogramación</button>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('apptModal');
  const lane  = document.getElementById('calLane');
  if (!lane || !modal) return;

  const q = (id) => document.getElementById(id);

  function close(){
    modal.classList.remove('on');
    modal.setAttribute('aria-hidden','true');
  }

  function openAppt(data){
    q('fId').value = data.id;

    q('mTitle').textContent = (data.start || '') + '–' + (data.end || '') + ' · ' + (data.service || '');
    q('mSub').textContent   = data.barber ? ('Profesional: ' + data.barber) : '';
    q('mCustomer').textContent = data.customer || '—';

    const contact = [];
    if (data.phone) contact.push('📱 ' + data.phone);
    if (data.email) contact.push('✉️ ' + data.email);
    q('mContact').textContent = contact.join(' · ');

    q('mNotes').textContent = data.notes ? ('Notas: ' + data.notes) : '';

    q('mStatus').textContent = data.statusLabel || data.status || '—';
    q('mStatus').className = 'badge';

    q('mView').href = 'appointment.php?id=' + encodeURIComponent(String(data.id));
    q('mReschedule').href = 'reschedule.php?id=' + encodeURIComponent(String(data.id));

    const st = String(data.status || '').toUpperCase();

    q('btnAccept').style.display = (st === 'PENDIENTE_APROBACION') ? '' : 'none';
    q('btnCancel').style.display = (st === 'PENDIENTE_APROBACION' || st === 'ACEPTADO' || st === 'OCUPADO' || st === 'REPROGRAMACION_PENDIENTE') ? '' : 'none';
    q('btnApproveRes').style.display = (st === 'REPROGRAMACION_PENDIENTE') ? '' : 'none';
    q('btnRejectRes').style.display = (st === 'REPROGRAMACION_PENDIENTE') ? '' : 'none';

    const showAfter = (st === 'ACEPTADO' || st === 'OCUPADO');
    q('btnComplete').style.display = showAfter ? '' : 'none';
    q('btnNoShow').style.display = showAfter ? '' : 'none';

    modal.classList.add('on');
    modal.setAttribute('aria-hidden','false');
  }

  lane.addEventListener('click', function(e){
    const el = e.target.closest('.cal-appt');
    if (!el) return;
    const raw = el.getAttribute('data-appt');
    if (!raw) return;
    try { openAppt(JSON.parse(raw)); } catch (err) {}
  });

  modal.addEventListener('click', function(e){
    if (e.target.closest('[data-close="1"]')) close();
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') close();
  });
})();
</script>
<?php page_foot(); ?>
PHP