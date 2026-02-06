<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/availability.php';

admin_require_login();
admin_require_branch_selected();
$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();

$action = $_GET['action'] ?? '';

try {
  if ($action === 'hours') {
    $barberId = (int)($_GET['barber_id'] ?? 0);
    $date = trim($_GET['date'] ?? '');
    if ($barberId <= 0 || $date === '') {
      json_response(['ok'=>false,'error'=>'Faltan datos'], 400);
    }
    $tz = new DateTimeZone($cfg['timezone']);
    $day = DateTimeImmutable::createFromFormat('Y-m-d', $date, $tz);
    if (!$day) json_response(['ok'=>false,'error'=>'Fecha inválida'], 400);
    $day = $day->setTime(0,0);

    $isTimeoff = barber_is_on_timeoff($bid, $barberId, $day);
    $hours = barber_hours_for_day($bid, $barberId, $day);

    json_response([
      'ok' => true,
      'date' => $date,
      'barber_id' => $barberId,
      'is_timeoff' => $isTimeoff ? 1 : 0,
      'is_closed' => (int)($hours['is_closed'] ?? 1),
      'open_time' => (string)($hours['open_time'] ?? ''),
      'close_time' => (string)($hours['close_time'] ?? ''),
    ]);
  }

  json_response(['ok'=>false,'error'=>'Acción inválida'], 400);
} catch (Throwable $e) {
  json_response(['ok'=>false,'error'=>$e->getMessage()], 500);
}
