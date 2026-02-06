<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/status.php';
require_once __DIR__ . '/../includes/csrf.php';

admin_require_login();
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

// Ensure appointments.reminder_skipped_at exists (older DBs may not have it)
$cols = [];
$ci = $pdo->query("PRAGMA table_info(appointments)");
foreach ($ci as $r) { $cols[$r['name']] = true; }
if (!isset($cols['reminder_skipped_at'])) {
    $pdo->exec("ALTER TABLE appointments ADD COLUMN reminder_skipped_at TEXT");
}



// Branch settings
$branchStmt = $pdo->prepare("SELECT * FROM branches WHERE business_id=:bid AND id=:id");
$branchStmt->execute([':bid'=>$bid, ':id'=>$branchId]);
$branch = $branchStmt->fetch(PDO::FETCH_ASSOC) ?: [];


$now = now_tz();
$today = $now->format('Y-m-d');
$tomorrow = $now->modify('+1 day')->format('Y-m-d');


// WhatsApp reminders (only approved) - list to send with 1 click
$whReminders = [];
$remEnabled = (int)($branch['whatsapp_reminder_enabled'] ?? 0);
$remMinutes = (int)($branch['whatsapp_reminder_minutes'] ?? 1440);
if ($remEnabled === 1 && $remMinutes > 0) {
    $nowR = now_tz();
    $limit = $nowR->add(new DateInterval('PT' . $remMinutes . 'M'));
    $stR = $pdo->prepare("SELECT a.id, a.customer_name, a.customer_phone, a.start_at, s.name AS service_name, b.name AS barber_name
        FROM appointments a
        JOIN services s ON s.id=a.service_id
        JOIN barbers b ON b.id=a.barber_id
        WHERE a.business_id=:bid AND a.branch_id=:brid
          AND a.status='ACEPTADO'
          AND a.reminder_sent_at IS NULL
          AND a.reminder_skipped_at IS NULL
          AND a.start_at >= :now AND a.start_at <= :lim
        ORDER BY a.start_at ASC
        LIMIT 50");
    $stR->execute([
        ':bid'=>$bid,
        ':brid'=>$branchId,
        ':now'=>format_db_datetime($nowR),
        ':lim'=>format_db_datetime($limit),
    ]);
    $whReminders = $stR->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


$viewDate = isset($_GET['date']) ? preg_replace('/[^0-9\-]/', '', (string)$_GET['date']) : $today;
if (!$viewDate) $viewDate = $today;

$tz = new DateTimeZone($cfg['timezone']);
$refNow = ($viewDate === $today)
  ? $now
  : DateTimeImmutable::createFromFormat('Y-m-d H:i', $viewDate . ' 00:00', $tz);

// Turnos del día seleccionado (todos)
$stmt = $pdo->prepare("SELECT a.id, a.start_at, a.end_at, a.status, a.created_at,
        s.name AS service_name, a.customer_name, a.customer_phone, a.customer_email, a.notes,
        br.name AS barber_name
    FROM appointments a
    JOIN services s ON s.id=a.service_id
    JOIN barbers br ON br.id=a.barber_id
    WHERE a.business_id=:bid AND a.branch_id=:brid AND date(a.start_at)=:d
    ORDER BY a.start_at ASC, a.created_at ASC");
$stmt->execute(array(':bid' => $bid, ':brid' => $branchId, ':d' => $viewDate));
$dayAppts = $stmt->fetchAll() ?: [];

// Próximos turnos: muestra como máximo 1 turno por profesional (el próximo de cada uno),
// ordenado por hora (start_at) y, si empata, por fecha de creación (created_at).
$nextAppts = array();
try {
  $stmtNext = $pdo->prepare("SELECT a.id, a.start_at, a.end_at, a.status, a.created_at,
          s.name AS service_name, a.customer_name, a.customer_phone, a.customer_email, a.notes,
          br.name AS barber_name, br.id AS barber_id
      FROM appointments a
      JOIN services s ON s.id=a.service_id
      JOIN barbers br ON br.id=a.barber_id
      WHERE a.business_id=:bid AND a.branch_id=:brid
        AND date(a.start_at)=:d
        AND a.start_at >= :now
        AND a.status IN ('ACEPTADO','OCUPADO')
      ORDER BY a.start_at ASC, a.created_at ASC");
  $stmtNext->execute(array(
    ':bid'  => $bid,
    ':brid' => $branchId,
    ':d'    => $viewDate,
    ':now'  => $refNow->format('Y-m-d H:i:s'),
  ));
  $rows = $stmtNext->fetchAll() ?: array();

  // Primer turno de cada profesional
  $seen = array();
  foreach ($rows as $r) {
    $barberId = (int)($r['barber_id'] ?? 0);
    if ($barberId <= 0) continue;
    if (isset($seen[$barberId])) continue;
    $seen[$barberId] = true;
    $nextAppts[] = $r;
  }

  // Orden final (por las dudas)
  usort($nextAppts, function($a, $b) {
    if ($a['start_at'] === $b['start_at']) {
      return strcmp((string)$a['created_at'], (string)$b['created_at']);
    }
    return strcmp((string)$a['start_at'], (string)$b['start_at']);
  });

} catch (Throwable $e) {
  $nextAppts = array();
}
// Próximo profesional libre (para la fecha seleccionada)
$barberStmt = $pdo->prepare('SELECT id, name FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id');
$barberStmt->execute(array(':bid' => $bid, ':brid' => $branchId));
$barbers = $barberStmt->fetchAll() ?: [];

$svcMinStmt = $pdo->prepare('SELECT id FROM services WHERE business_id=:bid AND is_active=1 ORDER BY duration_minutes ASC LIMIT 1');
$svcMinStmt->execute(array(':bid' => $bid));
$minServiceId = (int)($svcMinStmt->fetchColumn() ?: 0);

$freeInfo = array();
if ($minServiceId > 0) {
  foreach ($barbers as $b) {
    $id = (int)$b['id'];
    $times = available_times_for_day($bid, $branchId, $id, $minServiceId, $viewDate);
    $first = null;
    foreach ($times as $t) {
      $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $viewDate . ' ' . $t, $tz);
      if ($dt && $dt >= $refNow) { $first = $dt; break; }
    }
    if ($first) {
      $mins = (int)round(($first->getTimestamp() - $refNow->getTimestamp())/60);
      $freeInfo[] = array('name'=>(string)$b['name'], 'at'=>$first->format('H:i'), 'mins'=>$mins);
    } else {
	  // next_working_date_for_barber signature: (businessId, barberId, from, maxDays=120, branchId=1)
	  $nextDate = next_working_date_for_barber($bid, $id, $refNow->modify('+1 day'), 120, $branchId);
      $freeInfo[] = array('name'=>(string)$b['name'], 'at'=>null, 'mins'=>null, 'next'=>$nextDate);
    }
  }
}

page_head('Dashboard', 'admin');
admin_nav('dashboard');

// Dashboard mobile hardening (avoid 2-column squeeze + make controls readable)
echo "<style>\n" .
     "/* Dashboard layout helpers */" .
     ".dash-controls{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;align-items:end}" .
     ".dash-actions{display:flex;gap:8px;flex-wrap:wrap}" .
     "@media (min-width:721px){" .
     "  /* keep Hoy/Mañana + date + Ir in one line */" .
     "  .dash-controls{flex-wrap:nowrap;align-items:flex-end}" .
     "  .dash-controls form{display:flex;gap:8px;align-items:flex-end;margin:0}" .
     "  .dash-controls form label{display:block}" .
     "  .dash-controls input[type=date]{min-width:170px}" .
     "  .dash-controls .btn{white-space:nowrap}" .
     "}" .
     "@media (max-width:720px){" .
     ".dash-grid{grid-template-columns:1fr !important;}" .
     ".dash-h{gap:10px !important;}" .
     ".dash-h > div{width:100% !important;}" .
     "/* keep these rows horizontal on mobile (global .row stacks everything) */" .
     ".dash-row{flex-direction:row !important;align-items:center !important;flex-wrap:wrap !important;}" .
     ".dash-row > *{width:auto !important;}" .
     "/* date tabs + date picker layout */" .
     ".dash-controls{display:grid !important;grid-template-columns:1fr 1fr;gap:8px;width:100%;}" .
     ".dash-controls .btn{width:100% !important;}" .
     ".dash-controls form{grid-column:1 / -1;display:grid !important;grid-template-columns:1fr auto;gap:8px;align-items:end;}" .
     ".dash-controls form > div{width:100% !important;}" .
     "/* quick actions: 2 columns */" .
     ".dash-actions{display:grid !important;grid-template-columns:1fr 1fr;gap:8px;width:100%;}" .
     ".dash-actions .btn{width:100% !important;}" .
     ".dash-actions .btn.span2{grid-column:1 / -1;}" .
     ".dash-grid h1{font-size:28px;}" .
     "/* reduce dashboard table columns on mobile to avoid horizontal scroll */" .
     ".dash-table th:nth-child(n+4),.dash-table td:nth-child(n+4){display:none;}" .
     "}" .
     "</style>\n";

function dash_tab_btn($label, $date, $active) {
  $cls = $active ? 'btn primary' : 'btn';
  echo '<a class="' . h($cls) . '" href="dashboard.php?date=' . h($date) . '">' . h($label) . '</a>';
}
?>

<div class="grid dash-grid">
  <!-- Izquierda: Agenda -->
  <div class="card">
    <div class="row dash-h" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div>
        <h1 style="margin:0 0 4px 0">Agenda</h1>
        <div class="muted small"><?php echo h(fmt_date_es($viewDate)); ?></div>
      </div>
      <div class="dash-controls">
        <?php dash_tab_btn('Hoy', $today, $viewDate === $today); ?>
        <?php dash_tab_btn('Mañana', $tomorrow, $viewDate === $tomorrow); ?>
        <form method="get" action="dashboard.php">
          <div>
            <label style="margin:0" class="small muted">Fecha</label>
            <input type="date" name="date" value="<?php echo h($viewDate); ?>" required>
          </div>
          <button class="btn" type="submit">Ir</button>
        </form>
      </div>
    </div>

    <div class="hr"></div>

    <div class="row dash-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div class="dash-actions">
        <a class="btn" href="appointments.php?date=<?php echo h($viewDate); ?>">Turnos</a>
        <a class="btn" href="quick_appointment.php?date=<?php echo h($viewDate); ?>">Turno rápido</a>
        <a class="btn span2" href="blocks.php">Bloqueos</a>
      </div>
      <div class="muted small">Mostrando: <b><?php echo h($viewDate === $today ? 'hoy' : ($viewDate === $tomorrow ? 'mañana' : 'fecha')); ?></b></div>
    </div>

    <div class="hr"></div>

    <h2 style="margin:0 0 8px 0">Próximos turnos</h2>
    <?php if (!$nextAppts): ?>
      <p class="muted">No hay turnos para esta fecha.</p>
    <?php else: ?>
      <div class="panel" style="padding:10px 12px">
        <?php foreach ($nextAppts as $idx => $n): $dt = parse_db_datetime($n['start_at']); $et = parse_db_datetime($n['end_at']); ?>
          <?php if ($idx > 0): ?><div class="hr" style="margin:10px 0"></div><?php endif; ?>
          <div class="row" style="justify-content:space-between;align-items:center;gap:10px">
            <div><b><?php echo h($dt ? $dt->format('H:i') : ''); ?></b> · <?php echo h($n['service_name']); ?> · <?php echo h($n['barber_name']); ?></div>
            <span class="badge <?php echo h(appt_status_badge_class($n['status'])); ?>"><?php echo h(appt_status_label($n['status'])); ?></span>
          </div>
          <div class="muted small" style="margin-top:6px">
            <?php echo h($n['customer_name']); ?>
            <?php if ($et): ?> · termina <?php echo h($et->format('H:i')); ?><?php endif; ?>
            <?php if (!empty($n['notes'])): ?> · comentario: <?php echo h($n['notes']); ?><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="muted small" style="margin-top:6px">Ordenado por hora y fecha de creación.</div>
    <?php endif; ?>

    <div class="hr"></div>

    <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:6px">
      <h2 style="margin:0">Todos los turnos</h2>
      <a class="link" href="appointments.php?date=<?php echo h($viewDate); ?>">Abrir listado completo</a>
    </div>

    <?php if (!$dayAppts): ?>
      <p class="muted">No hay turnos para esta fecha.</p>
    <?php else: ?>
      <div class="table-wrap" style="max-height:560px; overflow-y:auto; border:1px solid #e6e6e6; border-radius:12px">
        <table class="table compact dash-table" style="margin:0">
          <thead>
            <tr>
              <th>Hora</th>
              <th>Cliente</th>
              <th>Servicio</th>
              <th>Profesional</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dayAppts as $r): $dt2 = parse_db_datetime($r['start_at']); ?>
              <tr>
                <td><?php echo h($dt2 ? $dt2->format('H:i') : ''); ?></td>
                <td>
                  <?php echo h($r['customer_name']); ?>
                  <div class="muted small">
                    <?php if (!empty($r['customer_phone'])): ?>
                      <a class="link" href="https://wa.me/<?php echo h(preg_replace('/\D+/', '', (string)$r['customer_phone'])); ?>" target="_blank" rel="noopener">WhatsApp</a>
                    <?php endif; ?>
                    <?php if (!empty($r['customer_phone']) && !empty($r['customer_email'])): ?> · <?php endif; ?>
                    <?php if (!empty($r['customer_email'])): ?>
                      <a class="link" href="mailto:<?php echo h($r['customer_email']); ?>">Email</a>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($r['notes'])): ?>
                    <div class="muted small">Comentario: <?php echo h($r['notes']); ?></div>
                  <?php endif; ?>
                </td>
                <td><?php echo h($r['service_name']); ?></td>
                <td><?php echo h($r['barber_name']); ?></td>
                <td><span class="badge <?php echo h(appt_status_badge_class($r['status'])); ?>"><?php echo h(appt_status_label($r['status'])); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Derecha: Operativo -->
  <div>
    <div class="card" style="margin-bottom:12px">
      <h1 style="margin:0 0 6px 0">Operativo rápido</h1>
      <p class="muted small" style="margin:0 0 12px 0">Para emergencias y bloqueos.</p>

      <form method="post" action="quick_blocks.php" class="row" style="margin-bottom:10px" onsubmit="return confirm('¿Cerrar hoy (bloquear desde ahora hasta el cierre)?');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="close_today">
        <button class="btn danger" type="submit">Cerrar hoy</button>
      </form>

      <form method="post" action="quick_blocks.php" class="row">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="block_range">
        <div>
          <label>Desde (fecha)</label>
          <input type="date" name="start_date" value="<?php echo h($viewDate); ?>" required>
        </div>
        <div>
          <label>Hasta (fecha)</label>
          <input type="date" name="end_date" value="<?php echo h($viewDate); ?>" required>
        </div>
        <div>
          <label>Desde</label>
          <input type="time" name="start_time" required>
        </div>
        <div>
          <label>Hasta</label>
          <input type="time" name="end_time" required>
        </div>
        <div>
          <label>Profesional</label>
          <select name="barber_id">
            <option value="0">Todos (global)</option>
            <?php foreach ($barbers as $b): ?>
              <option value="<?php echo (int)$b['id']; ?>"><?php echo h($b['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:2">
          <label>Motivo</label>
          <input type="text" name="reason" placeholder="Ej: emergencia" maxlength="120">
        </div>
        <div style="align-self:end">
          <button class="btn" type="submit">Bloquear</button>
        </div>
      </form>
    </div>

    <?php if ($freeInfo): ?>
      <div class="card">
        <h1 style="margin:0 0 6px 0">Próximo profesional libre</h1>
        <p class="muted small" style="margin:0 0 12px 0">Cálculo rápido usando el servicio más corto activo.</p>
        <table class="table compact">
          <thead><tr><th>Profesional</th><th>Libre</th><th>En</th></tr></thead>
          <tbody>
            <?php $i=0; foreach ($freeInfo as $f): if ($i++>=6) break; ?>
              <tr>
                <td><?php echo h($f['name']); ?></td>
                <?php if ($f['at']): ?>
                  <td><?php echo h($f['at']); ?></td>
                  <td><?php echo h(max(0,(int)$f['mins']) . ' min'); ?></td>
                <?php else: ?>
                  <td colspan="2"><?php echo $f['next'] ? h('Próximo: ' . fmt_date_es($f['next'])) : h('Sin lugar'); ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>


<div class="card" style="margin-top:16px;">
  <h3>Recordatorios WhatsApp</h3>
  <?php if ((int)($branch['whatsapp_reminder_enabled'] ?? 0) !== 1): ?>
    <p class="muted">Los recordatorios están desactivados. Podés activarlos en <a href="settings.php">Sucursal</a>.</p>
  <?php else: ?>
    <?php if (empty($whReminders)): ?>
      <p class="muted">No hay recordatorios listos para enviar.</p>
    <?php else: ?>
      <?php
        $lblMap = [5=>'5 minutos',15=>'15 minutos',30=>'30 minutos',60=>'1 hora',300=>'5 horas',720=>'12 horas',1440=>'24 horas',2880=>'48 horas'];
        $lbl = $lblMap[$remMinutes] ?? ((int)$remMinutes . ' min');
      ?>
      <p class="muted">Turnos aprobados dentro de los próximos <?= h($lbl) ?>.</p>
      <table class="table">
        <thead><tr><th>Fecha</th><th>Cliente</th><th>Servicio</th><th>Profesional</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($whReminders as $r): ?>
            <tr>
              <td><?= h(parse_db_datetime((string)$r['start_at'])->format('d/m/Y H:i')) ?></td>
              <td><?= h($r['customer_name']) ?></td>
              <td><?= h($r['service_name']) ?></td>
              <td><?= h($r['barber_name']) ?></td>
              <td style="white-space:nowrap;display:flex;gap:8px;justify-content:flex-end;"><a class="btn" target="_blank" rel="noopener" href="wa_action.php?act=reminder&id=<?= (int)$r['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>&return=dashboard.php" >Enviar</a><a class="btn" style="background:#fff;border:1px solid #ccc;color:#444;" href="wa_action.php?act=dismiss_reminder&id=<?= (int)$r['id'] ?>&csrf=<?= urlencode(csrf_token()) ?>&return=dashboard.php" >×</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>


<?php page_foot(); ?>