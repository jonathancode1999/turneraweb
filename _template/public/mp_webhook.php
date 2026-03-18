<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/mercadopago.php';
require_once __DIR__ . '/../includes/timeline.php';

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
        }
    }
} catch (Throwable $e) {
    // swallow to keep webhook 200
}

http_response_code(200);
echo "OK";
