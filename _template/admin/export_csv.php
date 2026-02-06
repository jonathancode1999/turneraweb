<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';
require_once __DIR__ . '/../includes/status.php';

admin_require_login();
admin_require_permission('appointments');
admin_require_branch_selected();

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = admin_current_branch_id();
$pdo = db();

$range = strtolower(trim($_GET['range'] ?? 'day')); // day | week
$date = trim($_GET['date'] ?? now_tz()->format('Y-m-d'));
$barberFilter = (int)($_GET['barber_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');

try {
    $dObj = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone($cfg['timezone']));
    if (!$dObj) throw new RuntimeException('Fecha inválida');
} catch (Throwable $e) {
    $dObj = now_tz();
    $date = $dObj->format('Y-m-d');
}

// Build date window
if ($range === 'week') {
    // Monday 00:00 -> next Monday 00:00
    $start = $dObj->modify('monday this week');
    $end = $start->modify('+7 day');
} else {
    $range = 'day';
    $start = $dObj->setTime(0, 0, 0);
    $end = $start->modify('+1 day');
}

$params = [
    ':bid' => $bid,
    ':brid' => $branchId,
    ':s' => $start->format('Y-m-d H:i:s'),
    ':e' => $end->format('Y-m-d H:i:s'),
];

$where = "a.business_id=:bid AND a.branch_id=:brid AND a.start_at >= :s AND a.start_at < :e";
if ($barberFilter > 0) {
    $where .= " AND a.barber_id=:bar";
    $params[':bar'] = $barberFilter;
}
if ($statusFilter !== '') {
    $where .= " AND a.status=:st";
    $params[':st'] = $statusFilter;
}

$st = $pdo->prepare("SELECT a.*, 
        s.name AS service_name,
        b.name AS barber_name,
        br.name AS branch_name
    FROM appointments a
    JOIN services s ON s.id=a.service_id
    JOIN barbers b ON b.id=a.barber_id
    JOIN branches br ON br.id=a.branch_id
    WHERE $where
    ORDER BY a.start_at ASC, a.created_at ASC");
$st->execute($params);
$rows = $st->fetchAll() ?: [];

// CSV output
$branchSafe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($rows[0]['branch_name'] ?? 'sucursal'));
$fileName = 'agenda_' . $range . '_' . $start->format('Ymd') . '_' . $branchSafe . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Fecha',
    'Hora inicio',
    'Hora fin',
    'Cliente',
    'Teléfono',
    'Email',
    'Servicio',
    'Profesional',
    'Sucursal',
    'Estado',
    'Notas',
]);

foreach ($rows as $r) {
    $startAt = parse_db_datetime((string)$r['start_at']);
    $endAt = parse_db_datetime((string)$r['end_at']);
    $status = appt_status_label((string)($r['status'] ?? ''));
    fputcsv($out, [
        $startAt ? $startAt->format('d/m/Y') : '',
        $startAt ? $startAt->format('H:i') : '',
        $endAt ? $endAt->format('H:i') : '',
        (string)($r['customer_name'] ?? ''),
        (string)($r['customer_phone'] ?? ''),
        (string)($r['customer_email'] ?? ''),
        (string)($r['service_name'] ?? ''),
        (string)($r['barber_name'] ?? ''),
        (string)($r['branch_name'] ?? ''),
        $status,
        (string)($r['notes'] ?? ''),
    ]);
}

fclose($out);
exit;
