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
admin_require_permission('appointments');
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$biz = $pdo->prepare('SELECT * FROM businesses WHERE id=:id');
$biz->execute([':id' => $bid]);
$business = $biz->fetch() ?: ['id' => $bid, 'name' => 'Turnera'];

$biz = $pdo->query("SELECT * FROM businesses WHERE id=" . (int)$bid)->fetch();

$view = trim($_GET['view'] ?? 'day'); // 'day' or 'all'
$date = trim($_GET['date'] ?? now_tz()->format('Y-m-d'));
$status = trim($_GET['status'] ?? '');
$barberFilter = (int)($_GET['barber_id'] ?? 0);

$barbersStmt = $pdo->prepare('SELECT id, name FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id');
$barbersStmt->execute([':bid' => $bid, ':brid' => $branchId]);
$barbers = $barbersStmt->fetchAll() ?: [];

$autoWaUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $id = (int)($_POST['id'] ?? 0);
    $act = $_POST['act'] ?? '';
    
    $waEvent = '';
if ($id > 0) {
        // Load full appointment for validations + notifications
        $stmtA = $pdo->prepare("SELECT a.*, s.name AS service_name, br.name AS barber_name
            FROM appointments a
            JOIN services s ON s.id=a.service_id
            JOIN barbers br ON br.id=a.barber_id
            WHERE a.business_id=:bid AND a.branch_id=:brid AND a.id=:id");
        $stmtA->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
        $a = $stmtA->fetch();

        if ($a) {
            if ($act === 'accept') {
                $pdo->prepare("UPDATE appointments SET status='ACEPTADO', updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
                            $waEvent = 'approved';
} elseif ($act === 'cancel') {
                $pdo->prepare("UPDATE appointments SET status='CANCELADO', cancelled_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
                            $waEvent = 'cancelled';
} elseif ($act === 'complete') {
                $pdo->prepare("UPDATE appointments SET status='COMPLETADO', updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
            } elseif ($act === 'no_show') {
                $pdo->prepare("UPDATE appointments SET status='NO_ASISTIO', updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
                    ->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
	            } elseif ($act === 'approve_reschedule') {
	                if (!empty($a['requested_start_at'])) {
	                    try {
	                        // Parse using business timezone to avoid "past" false positives when server TZ != business TZ.
	                        $rs = parse_db_datetime((string)$a['requested_start_at']);
	                        $newBarber = (int)($a['requested_barber_id'] ?? $a['barber_id']);
	                        $newService = (int)($a['requested_service_id'] ?? $a['service_id']);
	                        // Validate again at approval time (ignore this same appointment)
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
	                    
						$waEvent = 'rescheduled';
} catch (Throwable $e) {
	                        // If business hours/slot changed after the client requested, approval may fail.
	                        flash_set('error', 'No se pudo aprobar la reprogramación: ' . $e->getMessage());
	                    }
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

            // Reload and notify on important changes
            $stmtA->execute([':bid' => $bid, ':brid' => $branchId, ':id' => $id]);
            $a2 = $stmtA->fetch();
            if ($a2 && in_array($act, ['accept','cancel','complete','no_show','approve_reschedule','reject_reschedule'], true)) {
                // Timeline
                $uid = (int)($_SESSION['admin_user']['id'] ?? 0);
                try {
                    if ($act === 'accept') {
                        appt_log_event($bid, $branchId, (int)$id, 'accepted', 'Turno aprobado desde Admin', ['status'=>'ACEPTADO'], 'admin', $uid);
                    } elseif ($act === 'cancel') {
                        appt_log_event($bid, $branchId, (int)$id, 'cancelled', 'Turno cancelado desde Admin', ['status'=>'CANCELADO'], 'admin', $uid);
                    } elseif ($act === 'complete') {
                        appt_log_event($bid, $branchId, (int)$id, 'completed', 'Turno marcado como completado', ['status'=>'COMPLETADO'], 'admin', $uid);
                    } elseif ($act === 'no_show') {
                        appt_log_event($bid, $branchId, (int)$id, 'no_show', 'Turno marcado como no asistió', ['status'=>'NO_ASISTIO'], 'admin', $uid);
                    } elseif ($act === 'approve_reschedule') {
                        appt_log_event($bid, $branchId, (int)$id, 'reschedule_approved', 'Reprogramación aprobada', [
                            'new_start_at' => (string)($a2['start_at'] ?? ''),
                            'new_barber_id' => (int)($a2['barber_id'] ?? 0),
                            'new_service_id' => (int)($a2['service_id'] ?? 0),
                        ], 'admin', $uid);
                    } elseif ($act === 'reject_reschedule') {
                        appt_log_event($bid, $branchId, (int)$id, 'reschedule_rejected', 'Reprogramación rechazada', [], 'admin', $uid);
                    }
                } catch (Throwable $e) {
                    // non fatal
                }

                $event = '';
                if ($act === 'accept') $event = 'booking_approved';
                elseif ($act === 'cancel') $event = 'booking_cancelled';
                elseif ($act === 'complete') $event = ''; // por ahora sin mail de completado
                elseif ($act === 'no_show') $event = ''; // sin mail de no asistió
                elseif ($act === 'approve_reschedule') $event = 'reschedule_approved';
                elseif ($act === 'reject_reschedule') $event = 'reschedule_rejected';

                if ($event !== '') {
                    // Admin actions: notify the customer only (owner already knows).
                    notify_event($event, $biz ?: [], $a2, ['to_owner' => false]);
                }
            }
        }
    }
	      $ret = 'appointments.php?view=' . urlencode($view) . '&date=' . urlencode($date) . '&status=' . urlencode($status) . '&barber_id=' . urlencode((string)$barberFilter);
  redirect($ret);
}

// Prev/Next day navigation helpers
try {
    $curDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone($cfg['timezone']));
    if (!$curDateObj) throw new RuntimeException('Fecha inválida');
} catch (Throwable $e) {
    $curDateObj = now_tz();
    $date = $curDateObj->format('Y-m-d');
}
$prevDate = $curDateObj->modify('-1 day')->format('Y-m-d');
$nextDate = $curDateObj->modify('+1 day')->format('Y-m-d');

$params = [':bid' => $bid, ':brid' => $branchId];
$where = "a.business_id=:bid AND a.branch_id=:brid";
if ($view !== 'all') {
    $where .= " AND date(a.start_at)=:d";
    $params[':d'] = $date;
}
if ($status) {
    $where .= " AND a.status=:st";
    $params[':st'] = $status;
}

if ($barberFilter > 0) {
    $where .= " AND a.barber_id=:bar";
    $params[':bar'] = $barberFilter;
}

$stmt = $pdo->prepare("SELECT a.*,
        s.name AS service_name, b.name AS barber_name,
        rs.name AS requested_service_name,
        rb.name AS requested_barber_name
    FROM appointments a
    JOIN services s ON s.id=a.service_id
    JOIN barbers b ON b.id=a.barber_id
    LEFT JOIN services rs ON rs.id=a.requested_service_id
    LEFT JOIN barbers rb ON rb.id=a.requested_barber_id
    WHERE $where
    ORDER BY a.start_at ASC");
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

if ($autoWaUrl !== '') {
    redirect($autoWaUrl);
}

page_head('Turnos', 'admin');
admin_nav('appointments');
?>

<div class="card">
  <?php $ferr = flash_get('error'); $fok = flash_get('ok'); ?>
  <?php if ($fok): ?><div class="notice ok"><?php echo h($fok); ?></div><?php endif; ?>
  <?php if ($ferr): ?><div class="notice danger"><?php echo h($ferr); ?></div><?php endif; ?>
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div>
      <h1 style="margin:0">Turnos</h1>
      <div class="muted small" style="margin-top:4px">
        <a class="link" href="appointments.php?view=<?php echo h(urlencode($view)); ?>&date=<?php echo h($prevDate); ?>&status=<?php echo h(urlencode($status)); ?>&barber_id=<?php echo h((string)$barberFilter); ?>">← Día anterior</a>
        <span style="margin:0 8px">·</span>
        <a class="link" href="appointments.php?view=<?php echo h(urlencode($view)); ?>&date=<?php echo h($nextDate); ?>&status=<?php echo h(urlencode($status)); ?>&barber_id=<?php echo h((string)$barberFilter); ?>">Día siguiente →</a>
      </div>
    </div>
    <a class="btn primary" href="quick_appointment.php?date=<?php echo h($date); ?>">Crear turno rápido</a>
  </div>
  <form method="get" class="row">
    <div>
      <label>Vista</label>
      <select name="view">
        <option value="day" <?php echo $view==='day'?'selected':''; ?>>Día</option>
        <option value="all" <?php echo $view==='all'?'selected':''; ?>>Todos</option>
      </select>
    </div>
    <div>
      <label>Fecha</label>
      <input type="date" name="date" value="<?php echo h($date); ?>">
    </div>
    <div>
	      <label>Profesional</label>
	      <select name="barber_id">
	        <option value="0">Todos</option>
	        <?php foreach ($barbers as $b): $id=(int)$b['id']; ?>
	          <option value="<?php echo $id; ?>" <?php echo $barberFilter===$id?'selected':''; ?>><?php echo h($b['name']); ?></option>
	        <?php endforeach; ?>
	      </select>
	    </div>
	    <div>
      <label>Estado</label>
      <select name="status">
        <option value="">Todos</option>
        <?php foreach ([APPT_STATUS_PENDING_APPROVAL, APPT_STATUS_ACCEPTED, APPT_STATUS_RESCHEDULE_PENDING, APPT_STATUS_BLOCKED, APPT_STATUS_CANCELLED, APPT_STATUS_EXPIRED, APPT_STATUS_COMPLETED] as $st): ?>
          <option value="<?php echo h($st); ?>" <?php echo $status===$st?'selected':''; ?>><?php echo h($st); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="align-self:end">
      <button class="btn primary" type="submit">Filtrar</button>
    </div>
  </form>

  <div class="hr"></div>

  <?php if (!$rows): ?>
    <p class="muted">No hay turnos para ese filtro.</p>
  <?php else: ?>
	    <table class="table table-stack">
      <thead><tr><th>Hora actual</th><th>Hora solicitada</th><th>Cliente</th><th>Profesional</th><th>Servicio</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
        $dtCurrent = parse_db_datetime((string)$r['start_at']);
        $dtEnd = parse_db_datetime((string)$r['end_at']);
        $isPast = $dtEnd < now_tz();
        $dtRequested = (!empty($r['requested_start_at'])) ? parse_db_datetime((string)$r['requested_start_at']) : null;
      ?>
		<tr>
		  <td data-label="Hora actual">
            <?php echo h($dtCurrent->format('H:i')); ?>
            <div class="small muted"><?php echo h($dtCurrent->format('d/m')); ?></div>
          </td>
		  <td data-label="Hora solicitada">
            <?php if ($r['status'] === 'REPROGRAMACION_PENDIENTE' && $dtRequested): ?>
              <?php echo h($dtRequested->format('H:i')); ?>
              <div class="small muted"><?php echo h($dtRequested->format('d/m')); ?></div>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
		  <td data-label="Cliente">
            <?php echo h($r['customer_name']); ?>
            <div class="small muted"><?php echo h((string)$r['customer_phone']); ?></div>
            <?php if (trim((string)($r['notes'] ?? '')) !== ''): ?>
              <div class="small muted" style="margin-top:2px">Comentario: <?php echo h((string)$r['notes']); ?></div>
            <?php endif; ?>
            <?php $phDigits = preg_replace('/\D+/', '', (string)$r['customer_phone']); ?>
            <?php if ($phDigits !== ''): ?>
              <div class="small"><a href="https://wa.me/<?php echo h($phDigits); ?>" target="_blank" rel="noopener">WhatsApp</a></div>
            <?php endif; ?>
            <?php if (trim((string)($r['customer_email'] ?? '')) !== ''): ?>
              <div class="small"><a href="mailto:<?php echo h($r['customer_email']); ?>">Email</a></div>
            <?php endif; ?>
          </td>
	          <td data-label="Profesional">
	            <?php echo h($r['barber_name']); ?>
	            <?php if ($r['status'] === 'REPROGRAMACION_PENDIENTE' && !empty($r['requested_barber_name']) && (string)$r['requested_barber_name'] !== (string)$r['barber_name']): ?>
	              <div class="small muted">Solicitado: <?php echo h($r['requested_barber_name']); ?></div>
	            <?php endif; ?>
	          </td>
	          <td data-label="Servicio">
	            <?php echo h($r['service_name']); ?>
	            <?php if ($r['status'] === 'REPROGRAMACION_PENDIENTE' && !empty($r['requested_service_name']) && (string)$r['requested_service_name'] !== (string)$r['service_name']): ?>
	              <div class="small muted">Solicitado: <?php echo h($r['requested_service_name']); ?></div>
	            <?php endif; ?>
	          </td>
		  <td data-label="Estado">
            <span class="badge <?php echo h(appt_status_badge_class((string)$r['status'])); ?>"><?php echo h(appt_status_label((string)$r['status'])); ?></span>
            
            <?php if ($r['status'] === 'REPROGRAMACION_PENDIENTE' && $dtRequested): ?>
              <div class="small muted">Hora: <?php echo h($dtCurrent->format('H:i')); ?> → <?php echo h($dtRequested->format('H:i')); ?></div>
            <?php endif; ?>
          </td>
		  <td data-label="Acciones">
            <form method="post" style="display:flex;gap:8px;align-items:center;">
              <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">

              <a class="btn" href="appointment.php?id=<?php echo (int)$r['id']; ?>">Ver</a>
              
              <?php if ($r['status'] === 'PENDIENTE_APROBACION'): ?>
                <a class="btn primary" target="_blank" rel="noopener" href="wa_action.php?act=accept&id=<?php echo (int)$r['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>&return=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" onclick="setTimeout(function(){location.reload();},700)">Aceptar</a>
                <a class="btn danger" target="_blank" rel="noopener" href="wa_action.php?act=cancel&id=<?php echo (int)$r['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>&return=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" onclick="if(!confirm('¿Cancelar turno?')) return false; setTimeout(function(){location.reload();},700)">Cancelar</a>
              <?php endif; ?>

              <?php if ($r['status'] === 'REPROGRAMACION_PENDIENTE'): ?>
                <button class="btn primary" name="act" value="approve_reschedule" type="submit">Aprobar reprogramación</button>
                <button class="btn" name="act" value="reject_reschedule" type="submit">Rechazar</button>
              <?php endif; ?>

              <?php if (in_array($r['status'], ['ACEPTADO','OCUPADO'], true)): ?>
                <?php if ($isPast): ?>
                  <button class="btn" name="act" value="complete" type="submit">Completado</button>
                  <button class="btn" name="act" value="no_show" type="submit">No asistió</button>
                <?php endif; ?>
                <a class="btn danger" target="_blank" rel="noopener" href="wa_action.php?act=cancel&id=<?php echo (int)$r['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>&return=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" onclick="if(!confirm('¿Cancelar turno?')) return false; setTimeout(function(){location.reload();},700)">Cancelar</a>
              <?php endif; ?>

              <?php if (in_array($r['status'], ['PENDIENTE_APROBACION','ACEPTADO','REPROGRAMACION_PENDIENTE'], true)): ?>
                <a class="btn" href="reschedule.php?id=<?php echo (int)$r['id']; ?>">Reprogramar</a>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php page_foot(); ?>