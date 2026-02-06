<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/status.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/timeline.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/availability.php';

admin_require_login();
admin_require_permission('appointments');

$cfg = app_config();
$bid = (int)($cfg['business_id'] ?? 1);
$pdo = db();
ensure_multibranch_schema($pdo);

$branchId = admin_current_branch_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('appointments.php');

$stmt = $pdo->prepare("SELECT a.*,
        s.name AS service_name, b.name AS barber_name
    FROM appointments a
    JOIN services s ON s.id=a.service_id
    JOIN barbers b ON b.id=a.barber_id
    WHERE a.business_id=:bid AND a.branch_id=:brid AND a.id=:id LIMIT 1");
$stmt->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$id]);
$a = $stmt->fetch();
if (!$a) redirect('appointments.php');

// Handle actions from this detail screen.
$autoWaUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate_or_die();
  $act = (string)($_POST['act'] ?? '');
  $idPost = (int)($_POST['id'] ?? 0);
  if ($idPost !== $id) redirect('appointment.php?id=' . (int)$id);

  try {
    if ($act === 'accept') {
      $pdo->prepare("UPDATE appointments SET status='ACEPTADO', updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
          ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
                    $waEvent='approved';
} elseif ($act === 'cancel') {
      $pdo->prepare("UPDATE appointments SET status='CANCELADO', cancelled_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
          ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
                    $waEvent='cancelled';
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
        [$svc, $newEnd] = assert_slot_available($bid, $branchId, $newBarber, $newService, $rs, (int)$id);
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
        $waEvent='rescheduled';
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
    redirect('appointment.php?id=' . (int)$id);
  }

  // Reload appointment after action for timeline + notifications.
  $stmt->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$id]);
  $a2 = $stmt->fetch() ?: $a;

  $uid = (int)($_SESSION['admin_user']['id'] ?? 0);
  try {
    if ($act === 'accept') appt_log_event($bid, $branchId, $id, 'accepted', 'Turno aprobado', ['status'=>'ACEPTADO'], 'admin', $uid);
    elseif ($act === 'cancel') appt_log_event($bid, $branchId, $id, 'cancelled', 'Turno cancelado', ['status'=>'CANCELADO'], 'admin', $uid);
    elseif ($act === 'complete') appt_log_event($bid, $branchId, $id, 'completed', 'Turno marcado como completado', ['status'=>'COMPLETADO'], 'admin', $uid);
    elseif ($act === 'no_show') appt_log_event($bid, $branchId, $id, 'no_show', 'Turno marcado como no asistió', ['status'=>'NO_ASISTIO'], 'admin', $uid);
    elseif ($act === 'approve_reschedule') appt_log_event($bid, $branchId, $id, 'reschedule_approved', 'Reprogramación aprobada', ['new_start_at'=>(string)($a2['start_at']??'')], 'admin', $uid);
    elseif ($act === 'reject_reschedule') appt_log_event($bid, $branchId, $id, 'reschedule_rejected', 'Reprogramación rechazada', [], 'admin', $uid);
  } catch (Throwable $e) {}

  // Fire notifications if configured.
  $bizStmt = $pdo->prepare('SELECT * FROM businesses WHERE id=:id');
  $bizStmt->execute([':id' => $bid]);
  $business = $bizStmt->fetch() ?: ['id' => $bid, 'name' => 'Turnera'];

  $event = '';
  if ($act === 'accept') $event = 'booking_approved';
  elseif ($act === 'cancel') $event = 'booking_cancelled';
  elseif ($act === 'approve_reschedule') $event = 'reschedule_approved';
  elseif ($act === 'reject_reschedule') $event = 'reschedule_rejected';
  if ($event !== '') {
    try { notify_event($event, $business ?: [], $a2, ['to_owner' => false]); } catch (Throwable $e) {}
  }

  flash_set('ok', 'Acción aplicada.');
  $ret = 'appointment.php?id=' . (int)$id;
  if (!empty($waEvent)) {
    redirect('wa_action.php?aid=' . (int)$id . '&event=' . urlencode($waEvent) . '&return=' . urlencode($ret));
  }
  redirect($ret);
}

$events = appt_events($bid, $id);

if ($autoWaUrl !== '') { redirect($autoWaUrl); }

page_head('Turno #' . (int)$id, 'admin');
admin_nav('appointments');

$start = parse_db_datetime((string)$a['start_at']);
$end = !empty($a['end_at']) ? parse_db_datetime((string)$a['end_at']) : null;
$phDigits = preg_replace('/\D+/', '', (string)($a['customer_phone'] ?? ''));
$stNorm = appt_status_normalize((string)($a['status'] ?? ''));
?>

<div class="card" style="max-width:980px">
  <?php $ferr = flash_get('error'); $fok = flash_get('ok'); ?>
  <?php if ($fok): ?><div class="notice ok"><?php echo h($fok); ?></div><?php endif; ?>
  <?php if ($ferr): ?><div class="notice danger"><?php echo h($ferr); ?></div><?php endif; ?>
  <?php $ferr = flash_get('error'); $fok = flash_get('ok'); ?>
  <?php if ($fok): ?><div class="notice ok"><?php echo h($fok); ?></div><?php endif; ?>
  <?php if ($ferr): ?><div class="notice danger"><?php echo h($ferr); ?></div><?php endif; ?>

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div>
      <div class="card-title" style="margin-bottom:4px">Turno #<?php echo (int)$id; ?></div>
      <div class="muted small"><?php echo h($start->format('d/m/Y H:i')); ?><?php if ($end): ?> · hasta <?php echo h($end->format('H:i')); ?><?php endif; ?></div>
    </div>
    <div>
      <span class="badge <?php echo h(appt_status_badge_class((string)$a['status'])); ?>"><?php echo h(appt_status_label((string)$a['status'])); ?></span>
    </div>
  </div>

  <div class="hr"></div>

  <div class="grid2">
    <div>
      <div class="label">Cliente</div>
      <div style="font-weight:700"><?php echo h((string)$a['customer_name']); ?></div>
      <div class="muted small"><?php echo h((string)($a['customer_phone'] ?? '')); ?></div>
      <?php if ($phDigits !== ''): ?>
        <div class="small" style="margin-top:6px"><a class="link" href="https://wa.me/<?php echo h($phDigits); ?>" target="_blank" rel="noopener">Abrir WhatsApp</a></div>
      <?php endif; ?>
      <?php if (trim((string)($a['customer_email'] ?? '')) !== ''): ?>
        <div class="small"><a class="link" href="mailto:<?php echo h((string)$a['customer_email']); ?>">Enviar Email</a></div>
      <?php endif; ?>
    </div>
    <div>
      <div class="label">Servicio / Profesional</div>
      <div><b><?php echo h((string)$a['service_name']); ?></b></div>
      <div class="muted small"><?php echo h((string)$a['barber_name']); ?></div>
      <?php if (!empty($a['notes'])): ?>
        <div style="margin-top:8px" class="muted small">Comentario: <?php echo h((string)$a['notes']); ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="hr"></div>

  <h2 style="margin:0 0 10px 0">Historial</h2>
  <?php if (!$events): ?>
    <p class="muted">Sin historial todavía.</p>
  <?php else: ?>
    <div class="card" style="background:#fff;border:1px solid var(--border);box-shadow:none">
      <table class="table" style="margin:0">
        <thead><tr><th>Fecha</th><th>Evento</th><th>Nota</th></tr></thead>
        <tbody>
          <?php foreach ($events as $ev): ?>
            <?php $dt = parse_db_datetime((string)($ev['created_at'] ?? '')); ?>
            <tr>
              <td style="white-space:nowrap"><?php echo h($dt->format('d/m H:i')); ?></td>
              <td><?php echo h(appt_event_label((string)($ev['event_type'] ?? ''))); ?></td>
              <td class="muted"><?php echo h((string)($ev['note'] ?? '')); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap">
    <a class="btn" href="appointments.php">Volver</a>
    <a class="btn" href="reschedule.php?id=<?php echo (int)$id; ?>">Reprogramar</a>

    <form method="post" style="display:inline-flex;gap:8px;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

      <?php if ($stNorm === APPT_STATUS_PENDING_APPROVAL): ?>
        <button class="btn primary" type="submit" style="display:none" name="act" value="accept">Aprobar</button>
        <a class="btn danger" target="_blank" rel="noopener" href="wa_action.php?act=cancel&id=<?= (int)$a['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>&return=appointment.php?id=<?= (int)$a['id'] ?>" onclick="if(!confirm('¿Cancelar turno?')) return false; setTimeout(function(){location.reload();},700)">Cancelar</a>
      <?php elseif ($stNorm === APPT_STATUS_RESCHEDULE_PENDING): ?>
        <button class="btn primary" type="submit" name="act" value="approve_reschedule">Aprobar reprogramación</button>
        <button class="btn danger" type="submit" name="act" value="reject_reschedule">Rechazar reprogramación</button>
        <a class="btn danger" target="_blank" rel="noopener" href="wa_action.php?act=cancel&id=<?= (int)$a['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>&return=appointment.php?id=<?= (int)$a['id'] ?>" onclick="if(!confirm('¿Cancelar turno?')) return false; setTimeout(function(){location.reload();},700)">Cancelar</a>
      <?php elseif ($stNorm === APPT_STATUS_ACCEPTED || $stNorm === APPT_STATUS_BLOCKED): ?>
        <a class="btn danger" target="_blank" rel="noopener" href="wa_action.php?act=cancel&id=<?= (int)$a['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>&return=appointment.php?id=<?= (int)$a['id'] ?>" onclick="if(!confirm('¿Cancelar turno?')) return false; setTimeout(function(){location.reload();},700)">Cancelar</a>
        <button class="btn" type="submit" name="act" value="complete">Marcar como completado</button>
        <button class="btn" type="submit" name="act" value="no_show">Marcar como no asistió</button>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php page_foot(); ?>
