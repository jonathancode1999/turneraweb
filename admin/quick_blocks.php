<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability.php';

admin_require_login();
admin_require_branch_selected();
csrf_validate_or_die();

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'close_today') {
        $today = now_tz()->format('Y-m-d');
        $weekday = (int)now_tz()->format('w'); // 0=Sunday
        $hstmt = $pdo->prepare('SELECT open_time, close_time, is_closed FROM business_hours WHERE business_id=:bid AND weekday=:w');
        $hstmt->execute([':bid' => $bid, ':w' => $weekday]);
        $h = $hstmt->fetch() ?: null;

        $start = now_tz();
        // Default: until 23:59 if no schedule
        $end = $start->setTime(23, 59);
        if ($h && (int)($h['is_closed'] ?? 0) === 0 && !empty($h['close_time'])) {
            [$hh, $mm] = array_map('intval', explode(':', (string)$h['close_time']));
            $end = (new DateTimeImmutable($today, new DateTimeZone($cfg['timezone'])))->setTime($hh, $mm);
            if ($end < $start) {
                $end = $start->setTime(23, 59);
            }
        }

        $pdo->prepare('INSERT INTO blocks (business_id, branch_id, barber_id, start_at, end_at, reason) VALUES (:bid, NULL, :s, :e, :r)')
            ->execute([
                ':bid' => $bid,
                ':s' => $start->format('Y-m-d H:i:s'),
                ':e' => $end->format('Y-m-d H:i:s'),
                ':r' => 'Cerrado hoy (operativo rápido)',
            ]);

        flash_set('ok', 'Listo: hoy quedó bloqueado desde ahora hasta el cierre.');
    } elseif ($action === 'block_range') {
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));
        $st = trim((string)($_POST['start_time'] ?? ''));
        $et = trim((string)($_POST['end_time'] ?? ''));
        $barberId = (int)($_POST['barber_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') $reason = 'Bloqueo manual';

        if ($startDate === '' || $endDate === '' || $st === '' || $et === '') {
            throw new RuntimeException('Completá fecha desde/hasta y rango horario.');
        }

        $start = parse_local_datetime($startDate, $st);
        $end = parse_local_datetime($endDate, $et);
        if ($end <= $start) {
            throw new RuntimeException('El horario "hasta" debe ser mayor al "desde".');
        }

        $pdo->prepare('INSERT INTO blocks (business_id, branch_id, barber_id, start_at, end_at, reason) VALUES (:bid, :bar, :s, :e, :r)')
            ->execute([
                ':bid' => $bid,
                ':bar' => ($barberId > 0 ? $barberId : null),
                ':s' => $start->format('Y-m-d H:i:s'),
                ':e' => $end->format('Y-m-d H:i:s'),
                ':r' => $reason,
            ]);

        flash_set('ok', 'Bloqueo creado.');
    } else {
        throw new RuntimeException('Acción inválida.');
    }
} catch (Throwable $e) {
    flash_set('error', $e->getMessage());
}

redirect('dashboard.php');
