<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$token = trim($_POST['token'] ?? '');
if (!$token) redirect('index.php');

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT * FROM appointments WHERE business_id=:bid AND token=:t');
    $stmt->execute([':bid' => $bid, ':t' => $token]);
    $a = $stmt->fetch();
    if (!$a) throw new RuntimeException('Turno no encontrado.');

    if ($a['status'] !== 'PENDIENTE_DE_PAGO') {
        // Already handled
        $pdo->commit();
        redirect('success.php?token=' . urlencode($token));
    }

    // Mark paid
    $pdo->prepare("UPDATE appointments SET updated_at=CURRENT_TIMESTAMP WHERE id=:id")
        ->execute([':id' => (int)$a['id']]);

    $pdo->prepare("UPDATE payments SET status='paid', updated_at=CURRENT_TIMESTAMP WHERE appointment_id=:aid")
        ->execute([':aid' => (int)$a['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo 'Error: ' . h($e->getMessage());
    exit;
}

redirect('success.php?token=' . urlencode($token));
