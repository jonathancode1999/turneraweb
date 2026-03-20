<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/availability.php';
require_once __DIR__ . '/includes/branches.php';
require_once __DIR__ . '/includes/mercadopago.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$pdo = db();

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo "Falta token.";
    exit;
}

// Expire any pending payments opportunistically
expire_pending_payments($pdo);

$stmt = $pdo->prepare("SELECT a.*, s.name AS service_name, s.price_ars, s.deposit_percent_override, br.name AS barber_name
                       FROM appointments a
                       JOIN services s ON s.id=a.service_id
                       JOIN profesionales br ON br.id=a.professional_id
                       WHERE a.business_id=:bid AND a.token=:t
                       LIMIT 1");
$stmt->execute([':bid'=>$bid, ':t'=>$token]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$a) {
    http_response_code(404);
    echo "Turno no encontrado.";
    exit;
}

if ((string)$a['status'] !== 'PENDIENTE_PAGO' || (string)$a['payment_status'] !== 'pending') {
    redirect('manage.php?token=' . urlencode($token));
}

$service = get_service($bid, (int)$a['service_id']);
$branch = branch_get((int)$a['branch_id']) ?: [];

$expiresAt = (string)($a['payment_expires_at'] ?? '');
$leftSeconds = 0;
if ($expiresAt !== '') {
    try {
        $dt = new DateTimeImmutable($expiresAt, new DateTimeZone($cfg['timezone']));
        $leftSeconds = max(0, $dt->getTimestamp() - now_tz()->getTimestamp());
    } catch (Throwable $e) { $leftSeconds = 0; }
}

$prefId = (string)($a['mp_preference_id'] ?? '');
$initPoint = '';
try {
    if ($prefId === '') {
        $pref = mp_create_preference($pdo, $bid, $a, $service, $branch);
        $prefId = (string)($pref['id'] ?? '');
        $initPoint = (string)($pref['init_point'] ?? '');
        if ($prefId !== '') {
            $pdo->prepare("UPDATE appointments SET mp_preference_id=:pid WHERE business_id=:bid AND id=:id")
                ->execute([':pid'=>$prefId, ':bid'=>$bid, ':id'=>(int)$a['id']]);
        }
    } else {
        // If preference was already created, just send the user to MP again.
        // MercadoPago preference API doesn't give init_point via GET in a stable way; so we rebuild if needed.
        $pref = mp_create_preference($pdo, $bid, $a, $service, $branch);
        $initPoint = (string)($pref['init_point'] ?? '');
    }
} catch (Throwable $e) {
    $initPoint = '';
    $mpError = $e->getMessage();
}

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pagar turno</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f7f7f8;color:#111}
    .wrap{max-width:520px;margin:32px auto;padding:0 16px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px}
    .btn{display:inline-block;background:#2563eb;color:#fff;padding:12px 14px;border-radius:10px;text-decoration:none;font-weight:600}
    .muted{color:#6b7280}
    .danger{color:#b91c1c}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 10px 0;">Confirmá el pago</h2>
    <p class="muted" style="margin-top:0">Este turno se reserva por 15 minutos. Si no pagás a tiempo, se vence.</p>

    <div style="margin:12px 0;padding:12px;border-radius:12px;background:#f3f4f6">
      <div><b><?php echo h($service['name'] ?? 'Servicio'); ?></b></div>
      <div><?php echo h($a['customer_name'] ?? ''); ?></div>
      <div class="muted">Importe: <b>$<?php echo number_format((int)($a['payment_amount_ars'] ?? 0), 0, ',', '.'); ?></b></div>
      <?php if ($expiresAt): ?>
        <div class="muted">Vence: <?php echo h($expiresAt); ?></div>
      <?php endif; ?>
    </div>

    <?php if (!empty($mpError ?? '')): ?>
      <p class="danger"><b>Error MercadoPago:</b> <?php echo h($mpError); ?></p>
      <p class="muted">Avisale al local: falta conectar MercadoPago o configurar credenciales.</p>
      <a class="btn" href="manage.php?token=<?php echo urlencode($token); ?>">Volver</a>
    <?php else: ?>
      <?php if ($initPoint): ?>
        <a class="btn" href="<?php echo h($initPoint); ?>" target="_blank" rel="noopener">Pagar con MercadoPago</a>
        <p class="muted" style="margin-bottom:0;margin-top:10px">Se abre MercadoPago en otra pestaña.</p>
      <?php else: ?>
        <p class="danger">No se pudo generar el link de pago.</p>
        <a class="btn" href="manage.php?token=<?php echo urlencode($token); ?>">Volver</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
