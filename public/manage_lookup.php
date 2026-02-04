<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/status.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$pdo = db();
$business = $pdo->query('SELECT * FROM businesses WHERE id=' . $bid)->fetch();

$servicesStmt = $pdo->prepare('SELECT id,name,duration_minutes FROM services WHERE business_id=:bid AND is_active=1 ORDER BY id');
$servicesStmt->execute(array(':bid' => $bid));
$services = $servicesStmt->fetchAll() ?: array();

$found = null;
$foundList = array();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $date = trim((string)($_POST['date'] ?? ''));
    $serviceId = (int)($_POST['service_id'] ?? 0);

  if ($email === '' || $date === '' || $serviceId <= 0) {
    $error = 'Completá email, fecha y servicio.';
  } else {
    $q = $pdo->prepare('SELECT a.*, s.name AS service_name, b.name AS barber_name
                        FROM appointments a
                        JOIN services s ON s.id = a.service_id
                        LEFT JOIN barbers b ON b.id = a.barber_id
                        WHERE a.business_id=:bid
                          AND lower(a.customer_email)=lower(:email)
                          AND a.service_id=:sid
                          AND date(a.start_at)=:d
                        ORDER BY a.start_at DESC, a.id DESC');

    $q->execute(array(
      ':bid' => $bid,
      ':email' => $email,
      ':sid' => $serviceId,
      ':d' => $date,
    ));
    $rows = $q->fetchAll() ?: array();
    $found = null;
    $foundList = $rows;
    if (!$foundList || count($foundList) === 0) {
      $error = 'No encontramos ningún turno con esos datos. Revisá el email, la fecha y el servicio.';
    } elseif (count($foundList) === 1) {
      $found = $foundList[0];
    }
  }
}

$subParts = array();
if (!empty($business['address'])) $subParts[] = h((string)$business['address']);
if (!empty($business['maps_url'])) $subParts[] = '<a class="link" href="' . h((string)$business['maps_url']) . '" target="_blank" rel="noopener">Cómo llegar</a>';
$headerHtml = '<div class="public-brand"><div><div class="public-title">' . h($business['name'] ?? 'Turnera') . '</div><div class="public-sub">' . implode(' · ', $subParts) . '</div></div></div>';

page_head('Buscar turno', 'public-light', $headerHtml);
?>

<div class="card">
  <h1>¿Ya reservaste?</h1>
  <p class="muted">Buscá tu turno con los mismos datos que usaste al reservar.</p>

  <form method="post" style="margin-top:14px">
    <div class="row">
      <div style="flex:1;min-width:240px">
        <label>Email</label>
        <input type="email" name="email" required maxlength="120" value="<?php echo h((string)($_POST['email'] ?? '')); ?>" placeholder="Ej: tuemail@dominio.com">
      </div>
      <div style="flex:1;min-width:200px">
        <label>Servicio</label>
        <select name="service_id" required>
          <option value="">Elegí servicio…</option>
          <?php foreach ($services as $s):
            $sid = (int)$s['id'];
            $sel = ((int)($_POST['service_id'] ?? 0) === $sid) ? 'selected' : '';
          ?>
            <option value="<?php echo $sid; ?>" <?php echo $sel; ?>><?php echo h((string)$s['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row">
      <div style="flex:1;min-width:220px">
        <label>Fecha</label>
        <input type="date" name="date" required value="<?php echo h((string)($_POST['date'] ?? '')); ?>">
      </div></div>

    <?php if ($error !== ''): ?>
      <div class="alert warn" style="margin-top:12px"><?php echo h($error); ?></div>
    <?php endif; ?>

    <div class="form-actions" style="margin-top:12px">
      <button class="btn" type="submit">Buscar turno</button>
      <a class="btn secondary" href="index.php">Volver</a>
    </div>
  </form>

    <?php if (!$found && isset($foundList) && count($foundList) > 1): ?>
    <div class="spacer"></div>
    <div class="card" style="border-color:#e5e7eb;background:#fff">
      <div style="font-weight:800;font-size:18px">Encontramos <?php echo count($foundList); ?> turnos</div>
      <div class="muted" style="margin-top:6px">Elegí el que querés abrir:</div>
      <div class="spacer" style="height:10px"></div>

      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($foundList as $row):
          $st = (string)($row['status'] ?? '');
          $label = appt_status_label($st);
          $badge = appt_status_badge_class($st);
          $when = fmt_datetime((string)$row['start_at']);
          $tok = (string)($row['token'] ?? '');
        ?>
          <div class="card" style="margin:0;border:1px solid #e5e7eb;background:#fff">
            <div class="row" style="align-items:center;justify-content:space-between">
              <div>
                <div style="font-weight:700"><?php echo h($when); ?> · <?php echo h((string)($row['service_name'] ?? '')); ?></div>
                <div class="badge <?php echo h($badge); ?>" style="margin-top:6px;display:inline-block"><?php echo h($label); ?></div>
              </div>
              <div>
                <a class="btn" href="manage.php?token=<?php echo rawurlencode($tok); ?>">Abrir</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

<?php if ($found):
    $st = (string)($found['status'] ?? '');
    $label = appt_status_label($st);
    $badge = appt_status_badge_class($st);
    $when = fmt_datetime((string)$found['start_at']);
  ?>
    <div class="spacer"></div>
    <div class="card" style="border-color:#e5e7eb;background:#fff">
      <div class="row" style="align-items:center;justify-content:space-between">
        <div>
          <div style="font-weight:800;font-size:18px">Turno encontrado</div>
          <div class="muted" style="margin-top:4px"><?php echo h($when); ?> · <?php echo h((string)($found['service_name'] ?? '')); ?></div>
          <?php if (!empty($found['barber_name'])): ?>
            <div class="muted" style="margin-top:2px">Profesional: <?php echo h((string)$found['barber_name']); ?></div>
          <?php endif; ?>
        </div>
        <span class="badge <?php echo h($badge); ?>"><?php echo h($label); ?></span>
      </div>
      <div style="margin-top:12px">
        <a class="btn" href="manage.php?token=<?php echo h((string)$found['token']); ?>">Abrir link del turno</a>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php page_foot(); ?>
