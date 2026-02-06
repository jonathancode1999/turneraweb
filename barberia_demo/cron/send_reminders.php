<?php
// Cron job: send appointment reminder emails ~1 hour before the appointment.
//
// Recommended schedule (Linux):
//   */5 * * * * php /path/to/turnera/cron/send_reminders.php
//
// Notes:
// - Uses business timezone (config.php).
// - Sends only to the customer (never to the owner).
// - Marks reminder_sent_at to avoid duplicates.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/status.php';
require_once __DIR__ . '/../includes/timeline.php';

$cfg = app_config();
$bid = (int)($cfg['business_id'] ?? 1);
$pdo = db();

// Business (for email templates)
$bizStmt = $pdo->prepare('SELECT * FROM businesses WHERE id=:id');
$bizStmt->execute([':id' => $bid]);
$business = $bizStmt->fetch() ?: ['id' => $bid, 'name' => 'Turnera', 'timezone' => ($cfg['timezone'] ?? 'America/Argentina/Buenos_Aires')];

$now = now_tz();

// Config: reminder_minutes (0=off, 120=2h, 1440=24h)
$rm = (int)($business['reminder_minutes'] ?? 0);
if (!in_array($rm, [0,120,1440], true)) $rm = 0;
if ($rm <= 0) {
    echo "Reminders: disabled\n";
    exit(0);
}

// Window: +/- 5 minutes around the target
$from = $now->modify('+' . ($rm - 5) . ' minutes')->format('Y-m-d H:i:s');
$to   = $now->modify('+' . ($rm + 5) . ' minutes')->format('Y-m-d H:i:s');

// Only accepted, with email, and not already reminded.
$stmt = $pdo->prepare(
    "SELECT a.*, s.name AS service_name, br.name AS barber_name
     FROM appointments a
     JOIN services s ON s.id=a.service_id
     JOIN barbers br ON br.id=a.barber_id
     WHERE a.business_id=:bid
       AND a.status=:st
       AND COALESCE(a.customer_email,'') <> ''
       AND a.start_at >= :from AND a.start_at <= :to
       AND a.reminder_sent_at IS NULL"
);
$stmt->execute([
    ':bid' => $bid,
    ':st' => APPT_STATUS_ACCEPTED,
    ':from' => $from,
    ':to' => $to,
]);
$rows = $stmt->fetchAll() ?: [];

$sent = 0;
$failed = 0;

foreach ($rows as $a) {
    $id = (int)($a['id'] ?? 0);
    if ($id <= 0) continue;

    try {
        $res = notify_event('booking_reminder', $business, $a, ['to_owner' => false]);
        $ok = (bool)($res['customer']['ok'] ?? false);
        $err = (string)($res['customer']['error'] ?? '');

        if ($ok) {
            $pdo->prepare("UPDATE appointments SET reminder_sent_at=CURRENT_TIMESTAMP, reminder_last_error=NULL WHERE id=:id")
                ->execute([':id' => $id]);
            try {
                appt_log_event($bid, (int)($a['branch_id'] ?? 1), $id, 'reminder_sent', 'Recordatorio enviado', ['minutes_before' => $rm], 'system');
            } catch (Throwable $e) {
                // non fatal
            }
            $sent++;
        } else {
            $pdo->prepare("UPDATE appointments SET reminder_last_error=:e WHERE id=:id")
                ->execute([':id' => $id, ':e' => $err]);
            $failed++;
        }
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE appointments SET reminder_last_error=:e WHERE id=:id")
            ->execute([':id' => $id, ':e' => $e->getMessage()]);
        $failed++;
    }
}

echo "Reminders: sent={$sent}, failed={$failed}\n";
