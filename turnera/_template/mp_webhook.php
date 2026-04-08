<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/mercadopago.php';
require_once __DIR__ . '/includes/timeline.php';
require_once __DIR__ . '/includes/availability.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$pdo = db();

// MP sends JSON body; but also query parameters.
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

$paymentId = '';
// new format: data.id
if (isset($data['data']['id'])) $paymentId = (string)$data['data']['id'];
if ($paymentId === '' && isset($_GET['data.id'])) $paymentId = (string)$_GET['data.id'];
if ($paymentId === '' && isset($_GET['id'])) $paymentId = (string)$_GET['id'];

if ($paymentId === '') {
    http_response_code(200);
    echo "OK";
    exit;
}

try {
    $pay = mp_get_payment($pdo, $bid, $paymentId);
    $status = (string)($pay['status'] ?? '');
    $ext = (string)($pay['external_reference'] ?? '');
    $approved = ($status === 'approved');

    if ($ext !== '') {
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE business_id=:bid AND token=:t LIMIT 1");
        $stmt->execute([':bid'=>$bid, ':t'=>$ext]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($appt) {
            // Only update if it's pending payment
            if ((string)$appt['status'] === 'PENDIENTE_PAGO' && (string)$appt['payment_status'] === 'pending') {
                if ($approved) {
                    $pdo->prepare("UPDATE appointments
                                   SET status='ACEPTADO',
                                       payment_status='paid',
                                       mp_payment_id=:pid,
                                       paid_at=CURRENT_TIMESTAMP,
                                       updated_at=CURRENT_TIMESTAMP
                                   WHERE business_id=:bid AND id=:id")
                        ->execute([':pid'=>$paymentId, ':bid'=>$bid, ':id'=>(int)$appt['id']]);

                    appt_log_event($bid, (int)($appt['branch_id'] ?? 1), (int)$appt['id'], 'paid', 'Pago recibido (MercadoPago)', [
                        'mp_payment_id' => $paymentId,
                    ], 'system');
                } else if ($status === 'rejected' || $status === 'cancelled') {
                    $pdo->prepare("UPDATE appointments
                                   SET payment_status=:ps, mp_payment_id=:pid, updated_at=CURRENT_TIMESTAMP
                                   WHERE business_id=:bid AND id=:id")
                        ->execute([':ps'=>$status, ':pid'=>$paymentId, ':bid'=>$bid, ':id'=>(int)$appt['id']]);
                }
            }
        } else {
            // New flow: payment first (no appointment yet). Confirm payment_attempt and create appointment.
            $stAtt = $pdo->prepare("SELECT * FROM payment_attempts WHERE business_id=:bid AND token=:t LIMIT 1");
            $stAtt->execute([':bid' => $bid, ':t' => $ext]);
            $attempt = $stAtt->fetch(PDO::FETCH_ASSOC);
            if ($attempt && (string)($attempt['status'] ?? '') === 'pending') {
                if ($approved) {
                    $pdo->beginTransaction();
                    try {
                        $start = parse_db_datetime((string)$attempt['start_at']);
                        [$service, $end] = assert_slot_available(
                            $bid,
                            (int)$attempt['branch_id'],
                            (int)$attempt['professional_id'],
                            (int)$attempt['service_id'],
                            $start
                        );

                        $apptStatus = 'ACEPTADO';
                        $stmtIns = $pdo->prepare('INSERT INTO appointments (business_id, branch_id, professional_id, service_id, customer_name, customer_phone, customer_email, notes, start_at, end_at, status, token, price_snapshot_ars, payment_status, payment_mode, payment_amount_ars, payment_expires_at, mp_preference_id, mp_payment_id, paid_at)
                                                  VALUES (:bid, :brid, :bar, :sid, :n, :ph, :em, :notes, :s, :e, :st, :t, :price, :pstat, :pmode, :pamt, :pexp, :pref, :pid, CURRENT_TIMESTAMP)');
                        $stmtIns->execute([
                            ':bid' => $bid,
                            ':brid' => (int)$attempt['branch_id'],
                            ':bar' => (int)$attempt['professional_id'],
                            ':sid' => (int)$attempt['service_id'],
                            ':n' => (string)$attempt['customer_name'],
                            ':ph' => (string)$attempt['customer_phone'],
                            ':em' => (string)($attempt['customer_email'] ?? ''),
                            ':notes' => (string)($attempt['notes'] ?? ''),
                            ':s' => (string)$attempt['start_at'],
                            ':e' => (string)$end->format('Y-m-d H:i:s'),
                            ':st' => $apptStatus,
                            ':t' => (string)$attempt['token'],
                            ':price' => (int)($service['price_ars'] ?? 0),
                            ':pstat' => 'paid',
                            ':pmode' => (string)($attempt['payment_mode'] ?? 'deposit'),
                            ':pamt' => (int)($attempt['payment_amount_ars'] ?? 0),
                            ':pexp' => null,
                            ':pref' => (string)($attempt['mp_preference_id'] ?? ''),
                            ':pid' => $paymentId,
                        ]);
                        $newApptId = (int)$pdo->lastInsertId();

                        $pdo->prepare("UPDATE payment_attempts
                                       SET status='approved', paid_at=CURRENT_TIMESTAMP
                                       WHERE business_id=:bid AND id=:id")
                            ->execute([':bid' => $bid, ':id' => (int)$attempt['id']]);

                        if ($newApptId > 0) {
                            appt_log_event($bid, (int)$attempt['branch_id'], $newApptId, 'paid', 'Pago recibido (MercadoPago)', [
                                'mp_payment_id' => $paymentId,
                            ], 'system');
                        }
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                    }
                } else if ($status === 'rejected' || $status === 'cancelled') {
                    $pdo->prepare("UPDATE payment_attempts SET status=:st WHERE business_id=:bid AND id=:id")
                        ->execute([
                            ':st' => $status === 'cancelled' ? 'cancelled' : 'rejected',
                            ':bid' => $bid,
                            ':id' => (int)$attempt['id'],
                        ]);
                }
            }
        }
    }
} catch (Throwable $e) {
    // swallow to keep webhook 200
}

http_response_code(200);
echo "OK";
