<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/utils.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$pdo = db();

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo "Falta token.";
    exit;
}

// Just return to manage; webhook will update status. We also do a quick check for approved via query params.
redirect('manage.php?token=' . urlencode($token));
