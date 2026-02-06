<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/layout.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$token = trim($_GET['token'] ?? '');
if (!$token) redirect('index.php');

$pdo = db();
$stmt = $pdo->prepare('SELECT a.*, s.name AS service_name, br.name AS barber_name,
    b.name AS business_name,
    COALESCE(bo.name, "") AS branch_name,
    COALESCE(bo.address, b.address) AS address,
    COALESCE(bo.maps_url, b.maps_url) AS maps_url,
    COALESCE(bo.whatsapp_phone, b.whatsapp_phone) AS whatsapp_phone
  FROM appointments a
  JOIN services s ON s.id=a.service_id
  JOIN barbers br ON br.id=a.barber_id
  JOIN businesses b ON b.id=a.business_id
  LEFT JOIN branches bo ON bo.id=a.branch_id AND bo.business_id=a.business_id
  WHERE a.business_id=:bid AND a.token=:t');
$stmt->execute([':bid' => $bid, ':t' => $token]);
$a = $stmt->fetch();

page_head('Turno registrado');
if (!$a) {
    echo "<div class='card'><p>Turno no encontrado.</p><a class='link' href='index.php'>Volver</a></div>";
    page_foot();
    exit;
}

$start = new DateTimeImmutable($a['start_at']);
$end = new DateTimeImmutable($a['end_at']);
$manageLink = public_url('manage.php?token=' . urlencode($token));
$icsLink = 'ics.php?token=' . urlencode($token);
$waDigits = preg_replace('/\D+/', '', (string)($a['whatsapp_phone'] ?? ''));
?>
<div class="card">
  <h1><?php echo $a['status']==='ACEPTADO' ? '¡Turno aprobado!' : 'Solicitud enviada'; ?></h1>
  <p class="muted">Guardá este link para ver el estado, cancelar o reprogramar:</p>
  <p><a class="link" href="manage.php?token=<?php echo h(urlencode($token)); ?>"><?php echo h($manageLink); ?></a></p>

  <div class="kv">
    <div><span>N°</span><b>#<?php echo (int)$a['id']; ?></b></div>
    <div><span>Profesional</span><b><?php echo h($a['barber_name']); ?></b></div>
    <div><span>Servicio</span><b><?php echo h($a['service_name']); ?></b></div>
    <div><span>Fecha</span><b><?php echo h($start->format('d/m/Y')); ?></b></div>
    <div><span>Hora</span><b><?php echo h($start->format('H:i')); ?> · hasta <?php echo h($end->format('H:i')); ?></b></div>
    <?php if (!empty($a['branch_name'])): ?>
      <div><span>Sucursal</span><b><?php echo h($a['branch_name']); ?></b></div>
    <?php endif; ?>
    <?php if (!empty($a['address'])): ?>
      <div><span>Dirección</span><b><?php echo h($a['address']); ?></b></div>
    <?php endif; ?>
    <?php if (!empty($a['maps_url'])): ?>
      <div><span>Cómo llegar</span><b><a class="link" href="<?php echo h($a['maps_url']); ?>" target="_blank" rel="noopener">Abrir Google Maps</a></b></div>
    <?php endif; ?>
  </div>

  <div class="actions" style="margin-top:12px">
    <a class="btn secondary" href="<?php echo h($icsLink); ?>">Agregar al calendario</a>
    <?php if ($waDigits !== ''): ?>
      <a class="btn" target="_blank" rel="noopener" href="https://wa.me/<?php echo h($waDigits); ?>">WhatsApp del local</a>
    <?php endif; ?>
  </div>

  <div class="actions">
    <a class="btn" href="index.php">Reservar otro turno</a>
    <a class="btn secondary" href="manage.php?token=<?php echo h(urlencode($token)); ?>">Ver / Cancelar</a>
  </div>
</div>
<?php page_foot(); ?>