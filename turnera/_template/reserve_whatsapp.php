<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/availability.php';
require_once __DIR__ . '/includes/branches.php';
require_once __DIR__ . '/includes/timeline.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$attemptToken = trim((string)($in['attempt_token'] ?? ''));
if ($attemptToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta attempt_token.']);
    exit;
}

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$pdo = db();

$st = $pdo->prepare("SELECT * FROM payment_attempts WHERE business_id=:bid AND token=:t LIMIT 1");
$st->execute([':bid' => $bid, ':t' => $attemptToken]);
$attempt = $st->fetch(PDO::FETCH_ASSOC);
if (!$attempt || (string)($attempt['status'] ?? '') !== 'pending') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Intento de pago inválido.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $start = parse_db_datetime((string)$attempt['start_at']);
    [$service, $end] = assert_slot_available(
        $bid,
        (int)$attempt['branch_id'],
        (int)$attempt['professional_id'],
        (int)$attempt['service_id'],
        $start
    );

    $stmtIns = $pdo->prepare('INSERT INTO appointments (business_id, branch_id, professional_id, service_id, customer_name, customer_phone, customer_email, notes, start_at, end_at, status, token, price_snapshot_ars, payment_status, payment_mode, payment_amount_ars, payment_expires_at)
                              VALUES (:bid, :brid, :bar, :sid, :n, :ph, :em, :notes, :s, :e, :st, :t, :price, :pstat, :pmode, :pamt, :pexp)');
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
        ':st' => 'PENDIENTE_APROBACION',
        ':t' => (string)$attempt['token'],
        ':price' => (int)($service['price_ars'] ?? 0),
        ':pstat' => 'pending_transfer',
        ':pmode' => 'transfer',
        ':pamt' => (int)($attempt['payment_amount_ars'] ?? 0),
        ':pexp' => null,
    ]);
    $newApptId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE payment_attempts SET status='cancelled' WHERE business_id=:bid AND id=:id")
        ->execute([':bid' => $bid, ':id' => (int)$attempt['id']]);

    if ($newApptId > 0) {
        appt_log_event($bid, (int)$attempt['branch_id'], $newApptId, 'created', 'Reserva creada para coordinar pago por transferencia/WhatsApp', [
            'channel' => 'whatsapp_transfer',
        ], 'customer');
    }
    $pdo->commit();

    $branch = branch_get((int)$attempt['branch_id']) ?: [];
    $waPhone = preg_replace('/\D+/', '', (string)($branch['whatsapp_phone'] ?? ''));
    if ($waPhone === '') {
        $stBiz = $pdo->prepare("SELECT whatsapp_phone FROM businesses WHERE id=:bid LIMIT 1");
        $stBiz->execute([':bid' => $bid]);
        $bizPhone = (string)($stBiz->fetchColumn() ?: '');
        $waPhone = preg_replace('/\D+/', '', $bizPhone);
    }
    $startTxt = $start->format('d/m/Y H:i');
    $amount = (int)($attempt['payment_amount_ars'] ?? 0);
    $msg = "Hola! Quiero reservar por transferencia.\n" .
           "Cliente: " . (string)$attempt['customer_name'] . "\n" .
           "Fecha: {$startTxt}\n" .
           "Seña/total: $" . number_format($amount, 0, ',', '.');
    $waUrl = $waPhone ? ('https://wa.me/' . $waPhone . '?text=' . rawurlencode($msg)) : '';

    echo json_encode([
        'ok' => true,
        'manage_url' => 'manage.php?token=' . urlencode((string)$attempt['token']),
        'wa_url' => $waUrl,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
