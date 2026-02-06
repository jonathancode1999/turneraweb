<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/whatsapp.php';

admin_require_login();
$pdo = db();
// Business and branch context are derived from app_config() and admin session.
$cfg = app_config();
$bid = (int)($cfg['business_id'] ?? 0);
$branchId = admin_current_branch_id();

if ($bid <= 0 || $branchId <= 0) {
    flash('Contexto inválido (negocio/sucursal).', 'err');
    header('Location: appointments.php');
    exit;
}

$aid = (int)($_GET['aid'] ?? 0);
$event = (string)($_GET['event'] ?? '');
$return = (string)($_GET['return'] ?? 'appointments.php');

$allowed = ['approved','cancelled','rescheduled','reminder'];
if ($aid <= 0 || !in_array($event, $allowed, true)) {
    flash('Acción WhatsApp inválida.', 'err');
    header('Location: ' . $return);
    exit;
}

// Load branch
$br = $pdo->prepare("SELECT * FROM branches WHERE business_id=:bid AND id=:id");
$br->execute([':bid'=>$bid, ':id'=>$branchId]);
$branch = $br->fetch(PDO::FETCH_ASSOC);
if (!$branch) {
    flash('Sucursal inválida.', 'err');
    header('Location: ' . $return);
    exit;
}

// Load appointment with joined names
$st = $pdo->prepare("SELECT a.*, s.name AS service_name, b.name AS barber_name
    FROM appointments a
    JOIN services s ON s.id=a.service_id
    JOIN barbers b ON b.id=a.barber_id
    WHERE a.business_id=:bid AND a.branch_id=:brid AND a.id=:id
    LIMIT 1");
$st->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$aid]);
$a = $st->fetch(PDO::FETCH_ASSOC);
if (!$a) {
    flash('Turno no encontrado.', 'err');
    header('Location: ' . $return);
    exit;
}

$phone = wa_normalize_phone((string)$a['customer_phone']);
if ($phone === '') {
    flash('El cliente no tiene teléfono válido para WhatsApp.', 'warn');
    header('Location: ' . $return);
    exit;
}

$msg = wa_build_message($pdo, $a, $branch, $event);
$link = wa_build_link($phone, $msg);

// Mark reminder as sent (since user clicked send)
if ($event === 'reminder') {
    $pdo->prepare("UPDATE appointments SET reminder_sent_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
        ->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$aid]);
}

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Enviar WhatsApp...</title>
</head>
<body>
<script>
(function(){
  var url = <?php echo json_encode($link); ?>;
  var back = <?php echo json_encode($return); ?>;
  try {
    window.open(url, '_blank');
  } catch (e) {
    // ignore
  }
  // Always return to admin quickly
  window.location.href = back;
})();
</script>
<p>Abriendo WhatsApp...</p>
<p><a href="<?php echo h($link); ?>" target="_blank" rel="noopener">Si no se abre, tocá acá</a></p>
<p><a href="<?php echo h($return); ?>">Volver</a></p>
</body>
</html>
