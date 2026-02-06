<?php
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/service_barbers.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/timeline.php';
require_once __DIR__ . '/../includes/anti_spam.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

// Anti-spam (rate-limit + captcha + honeypot)
try {
    spam_throttle_ip('booking', 30, 300);
    spam_honeypot_check('website');
    spam_captcha_require_post('captcha_answer');
} catch (Throwable $e) {
    // UX: return to the booking form with a friendly inline error (no white screen).
    flash_set('booking_error', $e->getMessage());
    // Keep user-entered fields so they don't have to type again.
    // Keep BOTH user-entered fields and their selected booking options so we
    // can restore the UI (service/pro/date/time) when captcha fails.
    flash_set('booking_old', json_encode([
        'branch_id' => (string)($_POST['branch_id'] ?? ''),
        'barber_id' => (string)($_POST['barber_id'] ?? '0'),
        'service_id' => (string)($_POST['service_id'] ?? ''),
        'date' => (string)($_POST['date'] ?? ''),
        'time' => (string)($_POST['time'] ?? ''),
        'customer_name' => (string)($_POST['customer_name'] ?? ''),
        'customer_phone' => (string)($_POST['customer_phone'] ?? ''),
        'customer_email' => (string)($_POST['customer_email'] ?? ''),
        'notes' => (string)($_POST['notes'] ?? ''),
    ], JSON_UNESCAPED_UNICODE));
    redirect('index.php#bookingForm');
}
// Resolve current branch (public) BEFORE availability checks
$branchId = 0;
if (isset($_POST['branch_id'])) {
    $branchId = (int)($_POST['branch_id'] ?? 0);
    if ($branchId > 0 && branch_get($branchId)) {
        @setcookie('branch_id', (string)$branchId, time() + 86400 * 30, '/');
    } else {
        $branchId = 0;
    }
}
if ($branchId <= 0) {
    $branchId = public_current_branch_id();
}

$branch = branch_get($branchId);

$barberId = (int)($_POST['barber_id'] ?? 0); // 0 = primer profesional disponible
$serviceId = (int)($_POST['service_id'] ?? 0);
$date = trim($_POST['date'] ?? '');
$time = trim($_POST['time'] ?? '');
$name = trim($_POST['customer_name'] ?? '');
$phone = trim($_POST['customer_phone'] ?? '');

try {
    spam_cooldown_phone('booking', $phone, 3, 120);
} catch (Throwable $e) {
    // Same UX as captcha: keep the user on the form.
    flash_set('booking_error', $e->getMessage());
    flash_set('booking_old', json_encode([
        'branch_id' => (string)($_POST['branch_id'] ?? ''),
        'barber_id' => (string)($_POST['barber_id'] ?? '0'),
        'service_id' => (string)($_POST['service_id'] ?? ''),
        'date' => (string)($_POST['date'] ?? ''),
        'time' => (string)($_POST['time'] ?? ''),
        'customer_name' => (string)($_POST['customer_name'] ?? ''),
        'customer_phone' => (string)($_POST['customer_phone'] ?? ''),
        'customer_email' => (string)($_POST['customer_email'] ?? ''),
        'notes' => (string)($_POST['notes'] ?? ''),
    ], JSON_UNESCAPED_UNICODE));
    redirect('index.php#bookingForm');
}
$email = trim($_POST['customer_email'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($serviceId <= 0 || !$date || !$time || $name === '' || $phone === '') {
    http_response_code(400);
    echo "Datos incompletos. <a href='index.php'>Volver</a>";
    exit;
}

try {
    $start = parse_local_datetime($date, $time);

    // Enforce: professional must be allowed for the selected service
    $allowedIds = service_allowed_barber_ids($bid, $branchId, $serviceId);
    if ($barberId !== 0 && !in_array($barberId, $allowedIds, true)) {
        throw new RuntimeException('Profesional no disponible para este servicio');
    }

    $service = null;
    $end = null;
    $durationMin = 0;

    if ($barberId === 0) {
        // Pick the first available professional among those allowed for the service
        foreach ($allowedIds as $candId) {
            try {
                [$service, $end, $durationMin] = assert_slot_available($bid, $branchId, (int)$candId, $serviceId, $start);
                $barberId = (int)$candId;
                break;
            } catch (Throwable $e) {
                // try next
            }
        }
        if ($barberId === 0) {
            // Fallback to legacy picker (should rarely be needed)
            $barberId = pick_barber_for_slot($bid, $branchId, $serviceId, $start);
            [$service, $end, $durationMin] = assert_slot_available($bid, $branchId, $barberId, $serviceId, $start);
        }
    } else {
        [$service, $end, $durationMin] = assert_slot_available($bid, $branchId, $barberId, $serviceId, $start);
    }

    $pdo = db();
    $token = random_token(16);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO appointments (business_id, branch_id, barber_id, service_id, customer_name, customer_phone, customer_email, notes, start_at, end_at, status, token, price_snapshot_ars)
                               VALUES (:bid, :brid, :bar, :sid, :n, :ph, :em, :notes, :s, :e, :st, :t, :price)');
        $stmt->execute(array(
            ':bid' => $bid,
            ':brid' => $branchId,
            ':bar' => $barberId,
            ':sid' => $serviceId,
            ':n' => $name,
            ':ph' => $phone,
            ':em' => $email,
            ':notes' => $notes,
            ':s' => $start->format('Y-m-d H:i:s'),
            ':e' => $end->format('Y-m-d H:i:s'),
            ':st' => 'PENDIENTE_APROBACION',
            ':t' => $token,
            ':price' => (int)($service['price_ars'] ?? 0),
        ));

        $apptId = (int)$pdo->lastInsertId();
        if ($apptId > 0) {
            appt_log_event($bid, $branchId, $apptId, 'created', 'Turno creado por el cliente', [
                'status' => 'PENDIENTE_APROBACION',
                'start_at' => $start->format('Y-m-d H:i:s'),
            ], 'customer');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Email notifications (optional, if SMTP is configured)
    try {
        $business = get_business($bid);
        $stmtN = $pdo->prepare('SELECT a.*, s.name AS service_name, br.name AS barber_name
            FROM appointments a
            JOIN services s ON s.id=a.service_id
            JOIN barbers br ON br.id=a.barber_id
            WHERE a.business_id=:bid AND a.token=:t');
        $stmtN->execute(array(':bid' => $bid, ':t' => $token));
        $full = $stmtN->fetch();
        if ($full) {
            $br = branch_get($branchId);
            $extra = [];
            if ($br) $extra['branch_name'] = (string)($br['name'] ?? '');
            notify_event('booking_pending', $business, $full, $extra);
        }
    } catch (Throwable $e) {
        // Non-fatal
    }

    redirect('manage.php?token=' . urlencode($token));

} catch (Throwable $e) {
    http_response_code(400);
    echo "Error: " . h($e->getMessage()) . "<br><a href='index.php'>Volver</a>";
}
