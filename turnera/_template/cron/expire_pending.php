<?php
// Run this periodically (Render cron / external ping) to expire unpaid bookings.
require_once __DIR__ . '/../includes/db.php';

$pdo = db();
expire_pending_bookings($pdo);
echo "OK\n";
