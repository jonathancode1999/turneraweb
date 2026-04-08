<?php
require_once __DIR__ . '/includes/availability.php';
require_once __DIR__ . '/includes/service_profesionales.php';
require_once __DIR__ . '/includes/utils.php';
require_once __DIR__ . '/includes/branches.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = public_current_branch_id();

$action = $_GET['action'] ?? '';

try {
    if ($action === 'days') {
        $barberId = (int)($_GET['professional_id'] ?? 0); // 0 = primer profesional disponible
        $serviceId = (int)($_GET['service_id'] ?? 0);
        $month = trim((string)($_GET['month'] ?? '')); // YYYY-MM
        if ($serviceId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            json_response(['ok' => false, 'error' => 'Faltan datos'], 400);
        }

        $allowedIds = service_allowed_barber_ids($bid, $branchId, $serviceId);
        if ($barberId !== 0 && !in_array($barberId, $allowedIds, true)) {
            json_response(['ok' => false, 'error' => 'Profesional no disponible para este servicio'], 400);
        }
        if ($barberId !== 0 && !service_is_barber_allowed($bid, $branchId, $serviceId, $barberId)) {
            json_response(['ok' => false, 'error' => 'Profesional inválido para este servicio'], 400);
        }

        $tz = new DateTimeZone((string)($cfg['timezone'] ?? 'America/Argentina/Buenos_Aires'));
        $firstDay = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00', $tz);
        if (!$firstDay) {
            json_response(['ok' => false, 'error' => 'Mes inválido'], 400);
        }
        $daysInMonth = (int)$firstDay->format('t');
        $today = new DateTimeImmutable('today', $tz);
        $daysOut = [];

        if ($barberId === 0) {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id FROM profesionales WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id");
            $stmt->execute([':bid' => $bid, ':brid' => $branchId]);
            $pros = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!empty($allowedIds)) {
                $set = array_flip($allowedIds);
                $pros = array_values(array_filter($pros, function($pid) use ($set) { return isset($set[(int)$pid]); }));
            }
            $proIds = array_map('intval', $pros);
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $d = $firstDay->setDate((int)$firstDay->format('Y'), (int)$firstDay->format('m'), $day);
                if ($d < $today) continue;
                $ymd = $d->format('Y-m-d');
                $has = false;
                foreach ($proIds as $pid) {
                    $times = available_times_for_day($bid, $branchId, $pid, $serviceId, $ymd);
                    if (!empty($times)) {
                        $has = true;
                        break;
                    }
                }
                if ($has) $daysOut[] = $day;
            }
        } else {
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $d = $firstDay->setDate((int)$firstDay->format('Y'), (int)$firstDay->format('m'), $day);
                if ($d < $today) continue;
                $ymd = $d->format('Y-m-d');
                $times = available_times_for_day($bid, $branchId, $barberId, $serviceId, $ymd);
                if (!empty($times)) $daysOut[] = $day;
            }
        }

        json_response(['ok' => true, 'days' => $daysOut]);
    }

    if ($action === 'times') {
        $barberId = (int)($_GET['professional_id'] ?? 0); // 0 = primer profesional disponible
        $serviceId = (int)($_GET['service_id'] ?? 0);
        $date = trim($_GET['date'] ?? '');
        if ($serviceId <= 0 || !$date) json_response(['ok' => false, 'error' => 'Faltan datos'], 400);

        $allowedIds = service_allowed_barber_ids($bid, $branchId, $serviceId);
        if ($barberId !== 0 && !in_array($barberId, $allowedIds, true)) {
            json_response(['ok'=>false, 'error'=>'Profesional no disponible para este servicio'], 400);
        }


        // barberId=0 => unificar horarios disponibles entre todos los profesionales activos
        if ($barberId === 0) {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id, name FROM profesionales WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id");
            $stmt->execute([':bid' => $bid, ':brid' => $branchId]);
            $pros = $stmt->fetchAll() ?: [];
            if (!empty($allowedIds)) {
                $set = array_flip($allowedIds);
                $pros = array_values(array_filter($pros, function($p) use ($set) { return isset($set[(int)$p['id']]); }));
            }

            $all = [];
            $earliestNext = null;
            foreach ($pros as $p) {
                $pid = (int)$p['id'];
                try {
                    $times = available_times_for_day($bid, $branchId, $pid, $serviceId, $date);
                    foreach ($times as $t) { $all[$t] = true; }
                    if (empty($times)) {
                        $day = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone($cfg['timezone']));
                        if ($day) {
                            $next = find_next_available_day($bid, $pid, $serviceId, $day);
                            if ($next && ($earliestNext === null || $next < $earliestNext)) $earliestNext = $next;
                        }
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
            $timesOut = array_keys($all);
            sort($timesOut);
            $msg = '';
            if (empty($timesOut) && $earliestNext) {
                $msg = 'No hay horarios disponibles. Próxima disponibilidad: ' . $earliestNext->format('d/m/Y') . '.';
            } elseif (empty($timesOut)) {
                $msg = 'No hay horarios disponibles.';
            }
            json_response(['ok' => true, 'times' => $timesOut, 'message' => $msg]);
        }

        if (!service_is_barber_allowed($bid, $branchId, $serviceId, $barberId)) {
            json_response(['ok'=>false,'error'=>'Profesional inválido para este servicio'], 400);
        }

        $res = available_times_for_day_ex($bid, $branchId, $barberId, $serviceId, $date);
        json_response($res);
    }

    json_response(['ok' => false, 'error' => 'Acción inválida'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
