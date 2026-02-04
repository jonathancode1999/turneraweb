<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/layout.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$token = trim($_GET['token'] ?? '');
if (!$token) redirect('index.php');

$pdo = db();
$stmt = $pdo->prepare('SELECT a.*, s.name AS service_name, b.name AS business_name, b.address AS business_address
  FROM appointments a
  JOIN services s ON s.id=a.service_id
  JOIN businesses b ON b.id=a.business_id
  WHERE a.business_id=:bid AND a.token=:t');
$stmt->execute([':bid' => $bid, ':t' => $token]);
$a = $stmt->fetch();
if (!$a) {
    page_head('Pago', 'public-light');
    echo "<div class='card'><p>Turno no encontrado.</p><a class='link' href='index.php'>Volver</a></div>";
    page_foot();
    exit;
}

// Expired?
if ($a['status'] === 'VENCIDO') {
    page_head('Pago', 'public-light');
    echo "<div class='card'><h1>Este turno venció</h1><p class='muted'>No se pagó la seña a tiempo y el horario se liberó.</p><a class='btn' href='index.php'>Reservar otro turno</a></div>";
    page_foot();
    exit;
}

if ($a['status'] === 'CONFIRMADO') {
    redirect('success.php?token=' . urlencode($token));
}

page_head('Pagar seña', 'public-light');
?>
<div class="card">
  <h1>Pagar seña (demo)</h1>
  <p class="muted">Este es un flujo de prueba. En producción se integra Mercado Pago.</p>

  <div class="kv">
    <div><span>Servicio</span><b><?php echo h($a['service_name']); ?></b></div>
    <div><span>Fecha</span><b><?php echo h((new DateTimeImmutable($a['start_at']))->format('d/m/Y')); ?></b></div>
    <div><span>Hora</span><b><?php echo h((new DateTimeImmutable($a['start_at']))->format('H:i')); ?></b></div>
    <div><span>Seña</span><b><?php echo h(fmt_money_ars((int)$a['deposit_ars'])); ?></b></div>
  </div>

  <form method="post" action="pay_action.php">
    <input type="hidden" name="token" value="<?php echo h($token); ?>">
    <button class="btn" type="submit">Pagar seña</button>
  </form>

  <p class="muted small">Si no pagás dentro de <?php echo (int)$cfg['pay_deadline_minutes']; ?> minutos, el turno se libera.</p>
</div>
<?php page_foot(); ?>