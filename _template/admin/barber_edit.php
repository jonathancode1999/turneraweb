<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/service_barbers.php';

admin_require_login();
admin_require_permission('barbers');
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$barberId = (int)($_GET['id'] ?? 0);
if ($barberId <= 0) {
    redirect('barbers.php');
}

$barberStmt = $pdo->prepare('SELECT * FROM barbers WHERE business_id=:bid AND branch_id=:brid AND id=:id');
$barberStmt->execute(array(':bid' => $bid, ':brid' => $branchId, ':id' => $barberId));
$barber = $barberStmt->fetch();
if (!$barber) {
    redirect('barbers.php');
}

$notice = '';
$error = '';

// Services list (for assigning which services this professional can do)
$servicesStmt = $pdo->prepare('SELECT id, name, is_active FROM services WHERE business_id=:bid ORDER BY name');
$servicesStmt->execute([':bid'=>$bid]);
$services = $servicesStmt->fetchAll() ?: [];
service_barbers_ensure_schema($pdo);
$barberServiceIds = barber_allowed_service_ids($bid, $branchId, $barberId);
$barberServiceSet = array_flip(array_map('intval',$barberServiceIds));


$weekdayNames = [0=>'Dom',1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save_hours') {
            $capacity = (int)($_POST['capacity'] ?? ($barber['capacity'] ?? 1));
            if ($capacity < 1) $capacity = 1;
            if ($capacity > 10) $capacity = 10;
            // Avatar upload (optional, hardened)
            $uploadsDir = __DIR__ . '/../public/uploads/barbers';
            $newAvatar = upload_image_from_field('avatar_file', $uploadsDir, 'barber_' . $barberId . '_avatar', 4 * 1024 * 1024);

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE barbers SET capacity=:c,
                        avatar_path=COALESCE(:av, avatar_path),
                        updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id')
                    ->execute(array(':c' => $capacity, ':av' => $newAvatar, ':bid' => $bid, ':brid' => $branchId, ':id' => $barberId));
                for ($w = 0; $w <= 6; $w++) {
                    $isClosed = isset($_POST['closed'][$w]) ? 1 : 0;
                    $open = trim($_POST['open'][$w] ?? '10:00');
                    $close = trim($_POST['close'][$w] ?? '20:00');
                    if ($isClosed === 0) {
                        if (!preg_match('/^\d\d:\d\d$/', $open) || !preg_match('/^\d\d:\d\d$/', $close)) {
                            throw new RuntimeException('Horario inválido en ' . $weekdayNames[$w]);
                        }
                    }
                    $pdo->prepare('INSERT INTO barber_hours (business_id, branch_id, barber_id, weekday, open_time, close_time, is_closed)
                                  VALUES (:bid,:brid,:bar,:w,:o,:c,:cl)
                                  ON CONFLICT(business_id, branch_id, barber_id, weekday) DO UPDATE SET
                                    open_time=excluded.open_time,
                                    close_time=excluded.close_time,
                                    is_closed=excluded.is_closed')
                        ->execute(array(
                            ':bid' => $bid,
                            ':brid' => $branchId,
                            ':bar' => $barberId,
                            ':w' => $w,
                            ':o' => $open,
                            ':c' => $close,
                            ':cl' => $isClosed,
                        ));
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $notice = 'Horario guardado.';
        } elseif ($act === 'save_services') {
            $sel = $_POST['service_ids'] ?? [];
            if (!is_array($sel)) $sel = [];
            barber_set_allowed_services($bid, $branchId, $barberId, $sel);
            // Refresh sets
            $barberServiceIds = barber_allowed_service_ids($bid, $branchId, $barberId);
            $barberServiceSet = array_flip(array_map('intval',$barberServiceIds));
            $notice = 'Servicios actualizados.';
        } elseif ($act === 'add_timeoff') {
            $start = trim($_POST['start_date'] ?? '');
            $end = trim($_POST['end_date'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            if (!$start || !$end) throw new RuntimeException('Faltan fechas');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                throw new RuntimeException('Fecha inválida');
            }
            if ($end < $start) throw new RuntimeException('Fin debe ser mayor o igual que inicio');
            $pdo->prepare('INSERT INTO barber_timeoff (business_id, barber_id, start_date, end_date, reason) VALUES (:bid,:bar,:s,:e,:r)')
                ->execute([':bid'=>$bid,':bar'=>$barberId,':s'=>$start,':e'=>$end,':r'=>$reason]);
            $notice = 'Vacaciones agregadas.';
        } elseif ($act === 'delete_timeoff') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('DELETE FROM barber_timeoff WHERE business_id=:bid AND barber_id=:bar AND id=:id')
                    ->execute([':bid'=>$bid,':bar'=>$barberId,':id'=>$id]);
            }
            $notice = 'Vacaciones eliminadas.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$hoursStmt = $pdo->prepare('SELECT * FROM barber_hours WHERE business_id=:bid AND barber_id=:bar ORDER BY weekday');
$hoursStmt->execute([':bid'=>$bid, ':bar'=>$barberId]);
$hoursRows = $hoursStmt->fetchAll() ?: [];
$hoursByW = [];
foreach ($hoursRows as $r) $hoursByW[(int)$r['weekday']] = $r;

$timeoffStmt = $pdo->prepare('SELECT * FROM barber_timeoff WHERE business_id=:bid AND barber_id=:bar ORDER BY start_date DESC, end_date DESC');
$timeoffStmt->execute([':bid'=>$bid, ':bar'=>$barberId]);
$timeoffs = $timeoffStmt->fetchAll() ?: [];

page_head('Profesional', 'admin');
admin_nav('barbers');
?>

<div class="card">
  <div class="row" style="justify-content:space-between">
    <div>
      <h1><?php echo h($barber['name']); ?></h1>
      <p class="muted">Configurá horario por día y vacaciones.</p>
    </div>
    <div>
      <a class="btn" href="barbers.php">Volver</a>
    </div>
  </div>

  <?php if ($notice): ?><div class="notice ok"><?php echo h($notice); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>

  <h2>Servicios que realiza</h2>
  <form method="post" class="row" style="align-items:flex-start;gap:16px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <input type="hidden" name="act" value="save_services">
    <div style="flex:1;min-width:260px">
      <label>Seleccioná al menos 1 servicio</label>
      <?php if (empty($services)): ?>
        <div class="notice">Primero creá al menos 1 servicio para poder asignarlo al profesional.</div>
      <?php else: ?>
        <div class="checklist" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">
          <?php foreach ($services as $s): $sid=(int)$s['id']; ?>
            <label class="chk" style="display:flex;gap:8px;align-items:center">
              <input type="checkbox" name="service_ids[]" value="<?php echo $sid; ?>" <?php echo isset($barberServiceSet[$sid]) ? 'checked' : ''; ?> <?php echo ((int)$s['is_active']===1 ? '' : ''); ?>>
              <span><?php echo h($s['name']); ?><?php echo ((int)$s['is_active']===1 ? '' : ' (inactivo)'); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="muted" style="font-size:12px;margin-top:6px">Si intentás desmarcar un servicio donde sos el único profesional, el sistema no te va a dejar.</div>
      <?php endif; ?>
    </div>
    <div style="align-self:end">
      <button class="btn" type="submit">Guardar servicios</button>
    </div>
  </form>

  <div class="hr"></div>

  <h2>Horario semanal</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <input type="hidden" name="act" value="save_hours">

    <div class="row" style="margin:10px 0 14px 0">
      <div>
        <label>Capacidad (turnos simultáneos)</label>
        <input type="number" name="capacity" min="1" max="10" value="<?php echo (int)($barber['capacity'] ?? 1); ?>">
        <p class="muted small">Si este profesional puede atender más de 1 cliente en el mismo horario.</p>
      </div>
    </div>

    <div class="row" style="margin:0 0 14px 0">
      <div style="flex:1;min-width:240px">
        <label>Avatar (opcional)</label>
        <input type="file" name="avatar_file" accept="image/*">
        <?php if (!empty($barber['avatar_path'])): ?>
          <div class="small muted" style="margin-top:6px">Actual: <a href="../public/<?php echo h($barber['avatar_path']); ?>" target="_blank" rel="noopener">ver</a></div>
        <?php endif; ?>
      </div>
    </div>


    <table class="table">
      <thead><tr><th>Día</th><th>Abre</th><th>Cierra</th><th>Cerrado</th></tr></thead>
      <tbody>
      <?php for ($w=0;$w<=6;$w++):
        $hr = $hoursByW[$w] ?? ['open_time'=>'10:00','close_time'=>'20:00','is_closed'=>($w===0?1:0)];
      ?>
        <tr>
          <td><?php echo h($weekdayNames[$w]); ?></td>
          <td><input type="time" name="open[<?php echo $w; ?>]" value="<?php echo h($hr['open_time']); ?>"></td>
          <td><input type="time" name="close[<?php echo $w; ?>]" value="<?php echo h($hr['close_time']); ?>"></td>
          <td style="text-align:center">
            <input type="checkbox" name="closed[<?php echo $w; ?>]" <?php echo ((int)$hr['is_closed']===1)?'checked':''; ?>>
          </td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>

    <button class="btn primary" type="submit">Guardar horario</button>
  </form>

  <div class="hr"></div>
  <h2>Vacaciones</h2>

  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <input type="hidden" name="act" value="add_timeoff">
    <div>
      <label>Desde</label>
      <input type="date" name="start_date" required>
    </div>
    <div>
      <label>Hasta</label>
      <input type="date" name="end_date" required>
    </div>
    <div style="flex:2">
      <label>Motivo (opcional)</label>
      <input name="reason" placeholder="Ej: vacaciones" maxlength="120">
    </div>
    <div style="align-self:end">
      <button class="btn primary" type="submit">Agregar</button>
    </div>
  </form>

  <div class="spacer"></div>

  <?php if (!$timeoffs): ?>
    <p class="muted">Sin vacaciones cargadas.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Desde</th><th>Hasta</th><th>Motivo</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($timeoffs as $t): ?>
          <tr>
            <td><?php echo h($t['start_date']); ?></td>
            <td><?php echo h($t['end_date']); ?></td>
            <td><?php echo h($t['reason'] ?? ''); ?></td>
            <td>
              <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar vacaciones?');">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="act" value="delete_timeoff">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="btn danger" type="submit">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

</div>

<?php page_foot(); ?>
