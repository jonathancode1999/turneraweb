<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/mercadopago.php';
require_once __DIR__ . '/includes/availability.php';
require_once __DIR__ . '/includes/timeline.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode((string)$raw, true);
if (!is_array($in)) $in = [];

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$pdo = db();

$attemptToken = trim((string)($in['attempt_token'] ?? ''));
$cardToken = trim((string)($in['card_token'] ?? ''));
$paymentMethodId = trim((string)($in['payment_method_id'] ?? ''));
$issuerId = trim((string)($in['issuer_id'] ?? ''));
$installments = (int)($in['installments'] ?? 1);
$payerEmail = trim((string)($in['payer_email'] ?? ''));
$docType = trim((string)($in['doc_type'] ?? 'DNI'));
$docNumber = trim((string)($in['doc_number'] ?? ''));

if ($attemptToken === '' || $cardToken === '' || $paymentMethodId === '' || $payerEmail === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Datos de pago incompletos.']);
    exit;
}

$st = $pdo->prepare("SELECT * FROM payment_attempts WHERE business_id=:bid AND token=:t LIMIT 1");
$st->execute([':bid' => $bid, ':t' => $attemptToken]);
$attempt = $st->fetch(PDO::FETCH_ASSOC);
if (!$attempt || (string)($attempt['status'] ?? '') !== 'pending') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Intento de pago inválido o vencido.']);
    exit;
}

try {
    $tok = mp_refresh_access_token_if_needed($pdo, $bid);
    if (empty($tok['ok']) || empty($tok['access_token'])) {
        throw new RuntimeException('MercadoPago no conectado.');
    }

    $payload = [
        'transaction_amount' => (float)((int)$attempt['payment_amount_ars']),
        'token' => $cardToken,
        'description' => 'Reserva de turno',
        'installments' => max(1, $installments),
        'payment_method_id' => $paymentMethodId,
        'external_reference' => (string)$attempt['token'],
        'binary_mode' => true,
        'payer' => [
            'email' => $payerEmail,
            'identification' => [
                'type' => $docType !== '' ? $docType : 'DNI',
                'number' => $docNumber,
            ],
        ],
    ];
    if ($issuerId !== '') $payload['issuer_id'] = $issuerId;

    $idemKey = 'attempt_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $attemptToken) . '_' . bin2hex(random_bytes(8));
    $ch = curl_init('https://api.mercadopago.com/v1/payments');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . (string)$tok['access_token'],
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Idempotency-Key: ' . $idemKey,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException('MercadoPago error: ' . $err);
    }
    $payment = json_decode((string)$resp, true);
    if (!is_array($payment)) $payment = [];
    if ($code < 200 || $code >= 300) {
        $msg = (string)($payment['message'] ?? $payment['error'] ?? ('HTTP ' . $code));
        throw new RuntimeException('MercadoPago HTTP ' . $code . ': ' . $msg);
    }
    $status = (string)($payment['status'] ?? '');
    $paymentId = (string)($payment['id'] ?? '');

    if ($status === 'approved') {
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

            $stmtIns = $pdo->prepare('INSERT INTO appointments (business_id, branch_id, professional_id, service_id, customer_name, customer_phone, customer_email, notes, start_at, end_at, status, token, price_snapshot_ars, payment_status, payment_mode, payment_amount_ars, payment_expires_at, mp_payment_id, paid_at)
                                      VALUES (:bid, :brid, :bar, :sid, :n, :ph, :em, :notes, :s, :e, :st, :t, :price, :pstat, :pmode, :pamt, :pexp, :pid, CURRENT_TIMESTAMP)');
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
                ':st' => 'ACEPTADO',
                ':t' => (string)$attempt['token'],
                ':price' => (int)($service['price_ars'] ?? 0),
                ':pstat' => 'paid',
                ':pmode' => (string)($attempt['payment_mode'] ?? 'deposit'),
                ':pamt' => (int)($attempt['payment_amount_ars'] ?? 0),
                ':pexp' => null,
                ':pid' => $paymentId,
            ]);
            $newApptId = (int)$pdo->lastInsertId();

            $pdo->prepare("UPDATE payment_attempts SET status='approved', paid_at=CURRENT_TIMESTAMP WHERE business_id=:bid AND id=:id")
                ->execute([':bid' => $bid, ':id' => (int)$attempt['id']]);

            if ($newApptId > 0) {
                appt_log_event($bid, (int)$attempt['branch_id'], $newApptId, 'paid', 'Pago recibido (MercadoPago)', [
                    'mp_payment_id' => $paymentId,
                ], 'system');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'ok' => true,
            'status' => 'approved',
            'manage_url' => 'manage.php?token=' . urlencode((string)$attempt['token']),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newStatus = ($status === 'rejected' || $status === 'cancelled') ? $status : 'pending';
    $pdo->prepare("UPDATE payment_attempts SET status=:st WHERE business_id=:bid AND id=:id")
        ->execute([':st' => $newStatus, ':bid' => $bid, ':id' => (int)$attempt['id']]);

    echo json_encode([
        'ok' => false,
        'status' => $status,
        'error' => (string)($payment['status_detail'] ?? 'No se pudo aprobar el pago.'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
