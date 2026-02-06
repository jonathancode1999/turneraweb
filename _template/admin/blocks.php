<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/admin_nav.php';
require_once __DIR__ . '/../includes/csrf.php';

admin_require_login();
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$today = now_tz()->format('Y-m-d');

$barbersStmt = $pdo->prepare('SELECT id, name FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id');
$barbersStmt->execute([':bid' => $bid, ':brid' => $branchId]);
$barbers = $barbersStmt->fetchAll() ?: [];

$servicesStmt = $pdo->prepare('SELECT id, name, duration_minutes FROM services WHERE business_id=:bid AND is_active=1 ORDER BY id');
 $servicesStmt->execute(array(':bid' => $bid));
$services = $servicesStmt->fetchAll() ?: [];

$notice='';$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_validate_or_die();
    $act = $_POST['act'] ?? '';
    try {
        if ($act==='create') {
            $barberId = (int)($_POST['barber_id'] ?? 0);
            $startDate = trim($_POST['start_date']??'');
            $endDate = trim($_POST['end_date']??'');
            $startTime = trim($_POST['start_time']??'');
            $endTime = trim($_POST['end_time']??'');
            $dur = (int)($_POST['duration_minutes'] ?? 0);
            $svc = (int)($_POST['service_id'] ?? 0);
            $reason = trim($_POST['reason']??'');
            if (!$startDate || !$startTime) throw new RuntimeException('Faltan datos');
            if (!$endDate) $endDate = $startDate;
            $s = parse_local_datetime($startDate, $startTime);
            if ($svc > 0) {
                foreach ($services as $sv) {
                    if ((int)$sv['id'] === $svc) { $dur = (int)$sv['duration_minutes']; break; }
                }
            }
            // Si no se indicó fin, lo calculamos por duración (si hay).
            if ($endTime === '' && $dur > 0) {
                $e = $s->modify('+' . $dur . ' minutes');
            } else {
                if (!$endTime) throw new RuntimeException('Definí duración o fin');
                $e = parse_local_datetime($endDate, $endTime);
            }
            if ($e <= $s) throw new RuntimeException('Fin debe ser mayor que inicio');
            $barberVal = $barberId > 0 ? $barberId : null;
			$pdo->prepare('INSERT INTO blocks (business_id, branch_id, barber_id, start_at, end_at, reason) VALUES (:bid, :brid, :bar, :s, :e, :r)')
				->execute([':bid'=>$bid,':brid'=>$branchId,':bar'=>$barberVal,':s'=>$s->format('Y-m-d H:i:s'),':e'=>$e->format('Y-m-d H:i:s'),':r'=>$reason]);
            $notice='Bloqueo creado.';
        } elseif ($act==='delete') {
            $id = (int)($_POST['id']??0);
			$pdo->prepare('DELETE FROM blocks WHERE business_id=:bid AND branch_id=:brid AND id=:id')->execute([':bid'=>$bid,':brid'=>$branchId,':id'=>$id]);
            $notice='Bloqueo eliminado.';
        }
    } catch (Throwable $e) {
        $error=$e->getMessage();
    }
}

$stmt=$pdo->prepare('SELECT bl.*, b.name AS barber_name FROM blocks bl LEFT JOIN barbers b ON b.id=bl.barber_id WHERE bl.business_id=:bid AND bl.branch_id=:brid ORDER BY bl.start_at DESC LIMIT 50');
$stmt->execute([':bid' => $bid, ':brid' => $branchId]);
$rows=$stmt->fetchAll()?:[];

page_head('Bloqueos','admin');
admin_nav('blocks');
?>

<div class="card">
  <h1>Bloqueos</h1>
  <?php if ($notice): ?><div class="notice ok"><?php echo h($notice); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice danger"><?php echo h($error); ?></div><?php endif; ?>

  <h2>Agregar bloqueo</h2>
  <form method="post" class="row">
    <div>
      <label>Profesional</label>
      <select name="barber_id">
        <option value="0">Global</option>
        <?php foreach ($barbers as $b): ?>
          <option value="<?php echo (int)$b['id']; ?>"><?php echo h($b['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <input type="hidden" name="act" value="create">
    <div>
      <label>Desde (fecha)</label>
      <input type="date" name="start_date" value="<?php echo h($today); ?>" required>
    </div>
    <div>
      <label>Hasta (fecha)</label>
      <input type="date" name="end_date" value="<?php echo h($today); ?>" required>
    </div>
    <div>
      <label>Desde</label>
      <input type="time" name="start_time" required>
    </div>
    <div>
      <label>Hasta (hora) (opcional)</label>
      <input type="time" name="end_time" placeholder="Ej: 13:30">
    </div>
    <div>
      <label>Servicio (opcional)</label>
      <select name="service_id">
        <option value="0">(sin servicio)</option>
        <?php foreach ($services as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['name']); ?> (<?php echo (int)$s['duration_minutes']; ?> min)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Duración (min) (opcional)</label>
      <input type="number" name="duration_minutes" min="10" max="480" step="5" placeholder="Ej: 60">
    </div>
    <div class="muted small" style="align-self:end;max-width:320px">
      Si completás <b>Hasta (hora)</b>, no hace falta duración. Si dejás <b>Hasta (hora)</b> vacío, usaremos la duración del servicio (o la duración manual). Para bloquear varios días, elegí <b>Hasta (fecha)</b>.
    </div>
    <div style="flex:2">
      <label>Motivo (opcional)</label>
      <input name="reason" placeholder="Ej: almuerzo / vacaciones">
    </div>
    <div style="align-self:end">
      <button class="btn primary" type="submit">Agregar</button>
    </div>
  </form>

  <div class="hr"></div>
  <h2>Últimos bloqueos</h2>
  <?php if (!$rows): ?>
    <p class="muted">Sin bloqueos.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Inicio</th><th>Fin</th><th>Profesional</th><th>Motivo</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo h(fmt_datetime($r['start_at'])); ?></td>
            <td><?php echo h(fmt_datetime($r['end_at'])); ?></td>
            <td><?php echo h($r['barber_name'] ?: 'Global'); ?></td>
            <td><?php echo h($r['reason']??''); ?></td>
            <td>
              <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar bloqueo?');">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="act" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
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