<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo 'Missing token';
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT a.*, s.name AS service_name, br.name AS barber_name,
    b.name AS business_name,
    COALESCE(bo.name, "") AS branch_name,
    COALESCE(bo.address, b.address) AS address
  FROM appointments a
  JOIN services s ON s.id=a.service_id
  JOIN barbers br ON br.id=a.barber_id
  JOIN businesses b ON b.id=a.business_id
  LEFT JOIN branches bo ON bo.id=a.branch_id AND bo.business_id=a.business_id
  WHERE a.business_id=:bid AND a.token=:t');
$stmt->execute([':bid' => $bid, ':t' => $token]);
$a = $stmt->fetch();
if (!$a) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

try {
    $start = new DateTimeImmutable((string)$a['start_at']);
    $end = new DateTimeImmutable((string)$a['end_at']);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Invalid date';
    exit;
}

// Use UTC in the ICS to avoid timezone surprises on phones.
$dtStartUtc = $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
$dtEndUtc = $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

$uid = 'turnera-' . $bid . '-' . ((int)$a['id']) . '@turnera';
$nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');

$summary = trim((string)$a['service_name']);
if ($summary === '') $summary = 'Turno';
$location = trim((string)($a['address'] ?? ''));
if (!empty($a['branch_name'])) {
    $location = trim((string)$a['branch_name'] . ($location ? ' - ' . $location : ''));
}

$descParts = [];
$descParts[] = 'Profesional: ' . (string)$a['barber_name'];
if (!empty($a['branch_name'])) $descParts[] = 'Sucursal: ' . (string)$a['branch_name'];
if (!empty($a['address'])) $descParts[] = 'Dirección: ' . (string)$a['address'];
$descParts[] = 'Gestionar: ' . public_url('manage.php?token=' . urlencode($token));
$description = implode("\\n", $descParts);

// Basic escaping for ICS text fields
function ics_escape($s) {
    $s = (string)$s;
    $s = str_replace("\\", "\\\\", $s);
    $s = str_replace(";", "\\;", $s);
    $s = str_replace(",", "\\,", $s);
    $s = str_replace(["\r\n", "\n", "\r"], "\\n", $s);
    return $s;
}

$ics = "BEGIN:VCALENDAR\r\n";
$ics .= "VERSION:2.0\r\n";
$ics .= "PRODID:-//Turnera//ES\r\n";
$ics .= "CALSCALE:GREGORIAN\r\n";
$ics .= "METHOD:PUBLISH\r\n";
$ics .= "BEGIN:VEVENT\r\n";
$ics .= "UID:" . ics_escape($uid) . "\r\n";
$ics .= "DTSTAMP:" . $nowUtc . "\r\n";
$ics .= "DTSTART:" . $dtStartUtc . "\r\n";
$ics .= "DTEND:" . $dtEndUtc . "\r\n";
$ics .= "SUMMARY:" . ics_escape($summary) . "\r\n";
if ($location !== '') $ics .= "LOCATION:" . ics_escape($location) . "\r\n";
$ics .= "DESCRIPTION:" . ics_escape($description) . "\r\n";
$ics .= "END:VEVENT\r\n";
$ics .= "END:VCALENDAR\r\n";

$filename = 'turno_' . (int)$a['id'] . '.ics';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $ics;
