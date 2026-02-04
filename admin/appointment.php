<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/status.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/timeline.php';

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

$events = appt_events($bid, $id);

page_head('Turno #' . (int)$id, 'admin');
admin_nav('appointments');

$start = parse_db_datetime((string)$a['start_at']);
$end = !empty($a['end_at']) ? parse_db_datetime((string)$a['end_at']) : null;
$phDigits = preg_replace('/\D+/', '', (string)($a['customer_phone'] ?? ''));
?>

<div class="card" style="max-width:980px">
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

  <div style="display:flex;gap:8px;align-items:center;margin-top:12px">
    <a class="btn" href="appointments.php">Volver</a>
    <a class="btn" href="reschedule.php?id=<?php echo (int)$id; ?>">Reprogramar</a>
  </div>
</div>

<?php page_foot(); ?>
