<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/csrf.php';

admin_require_login();
admin_require_branch_selected();

session_start_safe();
$token = (string)($_GET['csrf'] ?? '');
if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
  http_response_code(403);
  echo 'CSRF inválido';
  exit;
}

$pdo = db();

// Ensure reminder_skipped_at column exists (SQLite legacy only)
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
  try {
    $cols = $pdo->query("PRAGMA table_info(appointments)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $have = [];
    foreach ($cols as $c) { $have[$c['name']] = true; }
    if (!isset($have['reminder_skipped_at'])) {
      $pdo->exec("ALTER TABLE appointments ADD COLUMN reminder_skipped_at TEXT NULL");
    }
  } catch (Throwable $e) {}
}

$cfg = app_config();
$bid = (int)($cfg['business_id'] ?? 0);
$branchId = admin_current_branch_id();

$act = (string)($_GET['act'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$return = (string)($_GET['return'] ?? 'appointments.php');

$allowed = ['accept','cancel','approve_reschedule','rescheduled','reminder','dismiss_reminder'];
if ($bid<=0 || $branchId<=0 || $id<=0 || !in_array($act, $allowed, true)) {
  header('Location: ' . $return);
  exit;
}

// Load branch
$br = $pdo->prepare("SELECT * FROM branches WHERE business_id=:bid AND id=:id");
$br->execute([':bid'=>$bid, ':id'=>$branchId]);
$branch = $br->fetch(PDO::FETCH_ASSOC);
if (!$branch) { header('Location: ' . $return); exit; }

// Load appointment with joined names
$st = $pdo->prepare("SELECT a.*, s.name AS service_name, b.name AS barber_name
  FROM appointments a
  JOIN services s ON s.id=a.service_id
  JOIN profesionales b ON b.id=a.professional_id
  WHERE a.business_id=:bid AND a.branch_id=:brid AND a.id=:id
  LIMIT 1");
$st->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$id]);
$a = $st->fetch(PDO::FETCH_ASSOC);
if (!$a) { header('Location: ' . $return); exit; }

// Load business for notifications
$bst = $pdo->prepare("SELECT * FROM businesses WHERE id=:bid LIMIT 1");
$bst->execute([':bid'=>$bid]);
$business = $bst->fetch(PDO::FETCH_ASSOC) ?: [];

// Apply action (except reminder/rescheduled which may already be applied by other flows)
$event = '';
if ($act === 'accept') {
  $pdo->prepare("UPDATE appointments SET status='ACEPTADO', updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
    ->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$id]);
  $event = 'approved';
} elseif ($act === 'cancel') {
  $pdo->prepare("UPDATE appointments SET status='CANCELADO', cancelled_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
    ->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$id]);
  $event = 'cancelled';
} elseif ($act === 'approve_reschedule' || $act === 'rescheduled') {
  // status already set by reschedule flow in some cases; keep as accepted
  if (($a['status'] ?? '') !== 'ACEPTADO') {
    $pdo->prepare("UPDATE appointments SET status='ACEPTADO', updated_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
      ->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$id]);
  }
  $event = 'rescheduled';
} elseif ($act === 'reminder') {
  // Marcar como enviado y abrir WhatsApp con el recordatorio
  $pdo->prepare("UPDATE appointments SET reminder_sent_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
    ->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$id]);
  $event = 'reminder';
}

elseif ($act === 'dismiss_reminder') {
  // Marcar como omitido y sacarlo de la lista (NO abre WhatsApp)
  $pdo->prepare("UPDATE appointments SET reminder_skipped_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND branch_id=:brid AND id=:id")
    ->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$id]);
  header('Location: ' . $return);
  exit;
}

// Email notification to customer (only for status-changing actions)
try {
  $eventMail = '';
  if ($event === 'approved') $eventMail = 'booking_approved';
  elseif ($event === 'cancelled') $eventMail = 'booking_cancelled';
  elseif ($event === 'rescheduled') $eventMail = 'reschedule_approved';
  if ($eventMail !== '') {
    // Reload appointment after UPDATE (to include updated status/timestamps)
    $st2 = $pdo->prepare("SELECT a.*, s.name AS service_name, b.name AS barber_name
      FROM appointments a
      JOIN services s ON s.id=a.service_id
      JOIN profesionales b ON b.id=a.professional_id
      WHERE a.business_id=:bid AND a.branch_id=:brid AND a.id=:id
      LIMIT 1");
    $st2->execute([':bid'=>$bid, ':brid'=>$branchId, ':id'=>$id]);
    $a2 = $st2->fetch(PDO::FETCH_ASSOC) ?: $a;
    notify_event($eventMail, $business ?: [], $a2, ['to_owner'=>false]);
  }
} catch (Throwable $e) {
  // non-fatal
}

$phone = wa_normalize_phone((string)($a['customer_phone'] ?? ''));
if ($phone === '') {
  // show message and close tab
  ?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>WhatsApp</title></head><body>
  <p>El cliente no tiene teléfono válido para WhatsApp.</p>
  <p><a href="<?php echo h($return); ?>">Volver</a></p>
  <script>setTimeout(function(){ try{ window.close(); }catch(e){} }, 1200);</script>
  </body></html><?php
  exit;
}

$msg = wa_build_message($pdo, $a, $branch, $event);
$link = wa_build_link($phone, $msg);

// Redirect this tab to WhatsApp. Since the tab was opened by the user click, browsers allow it.
header('Location: ' . $link);
exit;
