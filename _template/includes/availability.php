<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function get_business(int $businessId): array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM businesses WHERE id=:id');
    $stmt->execute([':id' => $businessId]);
    $b = $stmt->fetch();
    if (!$b) throw new RuntimeException('Negocio no encontrado');
    return $b;
}

function get_service(int $businessId, int $serviceId): array {
    $pdo = db();
    // Servicios son por negocio (no por sucursal)
    $stmt = $pdo->prepare('SELECT * FROM services WHERE id=:id AND business_id=:bid AND is_active=1');
    $stmt->execute([':id' => $serviceId, ':bid' => $businessId]);
    $s = $stmt->fetch();
    if (!$s) throw new RuntimeException('Servicio no encontrado');
    return $s;
}

function get_barber(int $businessId, int $barberId, int $branchId = 1): array {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM barbers WHERE id=:id AND business_id=:bid AND branch_id=:brid AND is_active=1');
    $stmt->execute([':id' => $barberId, ':bid' => $businessId,
            ':brid' => $branchId]);
    $b = $stmt->fetch();
    if (!$b) throw new RuntimeException('Profesional no encontrado');
    return $b;
}

function round_duration_to_slot(int $durationMin, int $slotMin): int {
    if ($slotMin <= 0) $slotMin = 15;
    if ($durationMin <= 0) return $slotMin;
    $m = (int)ceil($durationMin / $slotMin) * $slotMin;
    return max($slotMin, $m);
}

function business_hours_for_day(int $businessId, DateTimeImmutable $day, int $branchId = 1): array {
    $pdo = db();
    $weekday = (int)$day->format('w'); // 0=Sun
    $stmt = $pdo->prepare('SELECT * FROM business_hours WHERE business_id=:bid AND branch_id=:brid AND weekday=:w');
    $stmt->execute(array(':bid' => $businessId, ':brid' => $branchId, ':w' => $weekday));
    $h = $stmt->fetch();
    if (!$h) {
        // default closed
        return ['is_closed' => 1];
    }
    return $h;
}

function barber_hours_for_day(int $businessId, int $barberId, DateTimeImmutable $day, int $branchId = 1): array {
    $pdo = db();
    $weekday = (int)$day->format('w');
    $stmt = $pdo->prepare('SELECT * FROM barber_hours WHERE business_id=:bid AND branch_id=:brid AND barber_id=:bar AND weekday=:w');
    $stmt->execute([':bid' => $businessId,
            ':brid' => $branchId, ':bar' => $barberId, ':w' => $weekday]);
    $h = $stmt->fetch();
    if ($h) return $h;
    // fallback to business hours if not configured
    return business_hours_for_day($businessId, $day, $branchId);
}

function barber_is_on_timeoff(int $businessId, int $barberId, DateTimeImmutable $day, int $branchId = 1): bool {
    $pdo = db();
    $ymd = $day->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT 1 FROM barber_timeoff WHERE business_id=:bid AND branch_id=:brid AND barber_id=:bar AND start_date <= :d AND end_date >= :d LIMIT 1');
    $stmt->execute([':bid' => $businessId,
            ':brid' => $branchId, ':bar' => $barberId, ':d' => $ymd]);
    return (bool)$stmt->fetchColumn();
}

function day_blocks(int $businessId, DateTimeImmutable $day, int $branchId = 1, ?int $barberId = null): array {
    $pdo = db();
    $start = $day->setTime(0,0);
    $end = $day->setTime(23,59,59);
    if ($barberId === null) {
        $stmt = $pdo->prepare('SELECT start_at, end_at FROM blocks WHERE business_id=:bid AND branch_id=:brid AND NOT (end_at <= :s OR start_at >= :e)');
        $stmt->execute([
            ':bid' => $businessId,
            ':brid' => $branchId,
            ':s' => $start->format('Y-m-d H:i:s'),
            ':e' => $end->format('Y-m-d H:i:s'),
        ]);
    } else {
        $stmt = $pdo->prepare("SELECT start_at, end_at FROM blocks
                               WHERE business_id=:bid AND branch_id=:brid
                                 AND (barber_id IS NULL OR barber_id=:bar)
                                 AND NOT (end_at <= :s OR start_at >= :e)");
        $stmt->execute([
            ':bid' => $businessId,
            ':brid' => $branchId,
            ':bar' => $barberId,
            ':s' => $start->format('Y-m-d H:i:s'),
            ':e' => $end->format('Y-m-d H:i:s'),
        ]);
    }
    return $stmt->fetchAll() ?: [];
}

function day_appointments_busy(int $businessId, DateTimeImmutable $day, int $branchId, int $barberId, int $ignoreAppointmentId = 0): array {
    $pdo = db();
    $start = $day->setTime(0,0);
    $end = $day->setTime(23,59,59);

    // Busy: pending approval / accepted / reschedule pending / occupied / completed
    $sql = "SELECT id, start_at, end_at, status FROM appointments
        WHERE business_id=:bid AND branch_id=:brid
          AND barber_id=:bar
          AND status IN ('PENDIENTE_APROBACION','ACEPTADO','REPROGRAMACION_PENDIENTE','OCUPADO','COMPLETADO')
          AND NOT (end_at <= :s OR start_at >= :e)";
    $params = [':bid' => $businessId, ':brid' => $branchId,
        ':bar' => $barberId,
        ':s' => $start->format('Y-m-d H:i:s'),
        ':e' => $end->format('Y-m-d H:i:s'),
    ];
    if ($ignoreAppointmentId > 0) {
        $sql .= " AND id <> :ignore";
        $params[':ignore'] = $ignoreAppointmentId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function interval_overlap_count(DateTimeImmutable $start, DateTimeImmutable $end, array $busyRows, DateTimeZone $tz): int {
    $c = 0;
    foreach ($busyRows as $r) {
        $bs = new DateTimeImmutable($r['start_at'], $tz);
        $be = new DateTimeImmutable($r['end_at'], $tz);
        if (overlaps($start, $end, $bs, $be)) $c++;
    }
    return $c;
}

function overlaps(DateTimeImmutable $aStart, DateTimeImmutable $aEnd, DateTimeImmutable $bStart, DateTimeImmutable $bEnd): bool {
    return !($aEnd <= $bStart || $aStart >= $bEnd);
}

function available_times_for_day(int $businessId, int $branchId, int $barberId, int $serviceId, string $ymd): array {
    $cfg = app_config();
    $biz = get_business($businessId);
    $slotMin = (int)($biz['slot_minutes'] ?? $cfg['slot_minutes']);
    if ($slotMin < 10) $slotMin = 10;

    $day = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, new DateTimeZone($cfg['timezone']));
    if (!$day) throw new InvalidArgumentException('Fecha inválida');

    $barber = get_barber($businessId, $barberId, $branchId);
    if (barber_is_on_timeoff($businessId, $barberId, $day, $branchId)) return [];

    $hours = barber_hours_for_day($businessId, $barberId, $day, $branchId);
    if ((int)($hours['is_closed'] ?? 1) === 1) return [];

    $service = get_service($businessId, $serviceId);
    $durationMin = round_duration_to_slot((int)$service['duration_minutes'], $slotMin);

    $open = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . $hours['open_time'], new DateTimeZone($cfg['timezone']));
    $close = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . $hours['close_time'], new DateTimeZone($cfg['timezone']));
    if (!$open || !$close) return [];

    $blocks = day_blocks($businessId, $day, $branchId, $barberId);
    $busy = day_appointments_busy($businessId, $day, $branchId, $barberId);
    $tz = new DateTimeZone($cfg['timezone']);
    $capacity = max(1, (int)($barber['capacity'] ?? 1));

    $now = now_tz();

    $times = [];
    for ($t = $open; $t <= $close; $t = $t->modify('+' . $slotMin . ' minutes')) {
        $end = $t->modify('+' . $durationMin . ' minutes');
        if ($end > $close) break;

        // no past
        if ($t < $now) continue;

        $ok = true;
        foreach ($blocks as $b) {
            $bs = new DateTimeImmutable($b['start_at'], new DateTimeZone($cfg['timezone']));
            $be = new DateTimeImmutable($b['end_at'], new DateTimeZone($cfg['timezone']));
            if (overlaps($t, $end, $bs, $be)) { $ok = false; break; }
        }
        if (!$ok) continue;
        if ($ok) {
            $count = interval_overlap_count($t, $end, $busy, $tz);
            if ($count >= $capacity) $ok = false;
        }
        if ($ok) {
            $times[] = $t->format('H:i');
        }
    }

    return $times;
}

// Find the next date (Y-m-d) on/after $from (inclusive) where the professional is not on timeoff and is not closed.
// NOTE: branchId is optional for backwards compatibility (defaults to 1).
function next_working_date_for_barber(
    int $businessId,
    int $barberId,
    DateTimeImmutable $from,
    int $maxDays = 120,
    int $branchId = 1
): ?string {
    for ($i = 0; $i < $maxDays; $i++) {
        $d = $from->modify('+' . $i . ' days');
        if (barber_is_on_timeoff($businessId, $barberId, $d, $branchId)) continue;
        $hours = barber_hours_for_day($businessId, $barberId, $d, $branchId);
        if ((int)($hours['is_closed'] ?? 1) === 1) continue;
        return $d->format('Y-m-d');
    }
    return null;
}

function timeoff_range_for_barber_on_day(int $businessId, int $barberId, DateTimeImmutable $day, int $branchId = 1): ?array {
    $pdo = db();
    $ymd = $day->format('Y-m-d');
    $stmt = $pdo->prepare('SELECT start_date, end_date FROM barber_timeoff WHERE business_id=:bid AND branch_id=:brid AND barber_id=:bar AND start_date <= :d AND end_date >= :d ORDER BY end_date DESC LIMIT 1');
    $stmt->execute([':bid' => $businessId,
            ':brid' => $branchId, ':bar' => $barberId, ':d' => $ymd]);
    $row = $stmt->fetch();
    return $row ? ['start_date' => (string)$row['start_date'], 'end_date' => (string)$row['end_date']] : null;
}

// Extended response for the public API.
// barberId: 0 = any available barber ("Primer profesional disponible").
function available_times_for_day_ex(int $businessId, int $branchId, int $barberId, int $serviceId, string $ymd): array {
    $cfg = app_config();
    $day = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, new DateTimeZone($cfg['timezone']));
    if (!$day) return ['ok' => false, 'error' => 'Fecha inválida'];

    // Any barber: union of available slots across active barbers
    if ($barberId === 0) {
        $pdo = db();
	    	$rows = $pdo->prepare('SELECT id FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id');
	    	$rows->execute([':bid' => $businessId, ':brid' => $branchId]);
        $ids = array_map(fn($r) => (int)$r['id'], $rows->fetchAll() ?: []);
        $set = [];
        foreach ($ids as $id) {
            foreach (available_times_for_day($businessId, $branchId, $id, $serviceId, $ymd) as $t) {
                $set[$t] = true;
            }
        }
        $times = array_keys($set);
        sort($times);
        return ['ok' => true, 'times' => $times];
    }

    // Specific barber
    $barber = get_barber($businessId, $barberId, $branchId);
    $times = available_times_for_day($businessId, $branchId, $barberId, $serviceId, $ymd);

    if (count($times) > 0) {
        return ['ok' => true, 'times' => $times, 'barber_name' => (string)$barber['name']];
    }

    // No times: explain why (vacation vs fully booked/closed)
    $vac = timeoff_range_for_barber_on_day($businessId, $barberId, $day);
    if ($vac) {
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $vac['end_date'], new DateTimeZone($cfg['timezone'])) ?: $day;
        $next = next_working_date_for_barber($businessId, $barberId, $end->modify('+1 day'));
        $msg = $next
            ? "No hay horarios disponibles para " . (string)$barber['name'] . ". Está de vacaciones. Vuelve el " . fmt_date_es($next) . "."
            : "No hay horarios disponibles para " . (string)$barber['name'] . ". Está de vacaciones.";
        return ['ok' => true, 'times' => [], 'barber_name' => (string)$barber['name'], 'message' => $msg];
    }

    return ['ok' => true, 'times' => [], 'barber_name' => (string)$barber['name']];
}

// If the user chose "Primer profesional disponible" (barber_id=0), pick a barber who can take the requested start time.
function pick_barber_for_slot(int $businessId, int $branchId, int $serviceId, DateTimeImmutable $start): int {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id');
    $stmt->execute([':bid' => $businessId, ':brid' => $branchId]);
    $barbers = $stmt->fetchAll() ?: [];
    foreach ($barbers as $b) {
        $id = (int)$b['id'];
        try {
            // Will throw if not available
            assert_slot_available($businessId, $branchId, $id, $serviceId, $start);
            return $id;
        } catch (Throwable $e) {
            continue;
        }
    }
    throw new RuntimeException('No hay profesionales disponibles para ese horario.');
}

function assert_slot_available(int $businessId, int $branchId, int $barberId, int $serviceId, DateTimeImmutable $start, int $ignoreAppointmentId = 0): array {
    $cfg = app_config();
    $biz = get_business($businessId);
    $slotMin = (int)($biz['slot_minutes'] ?? $cfg['slot_minutes']);
    if ($slotMin < 10) $slotMin = 10;

    $barber = get_barber($businessId, $barberId, $branchId);
    $capacity = max(1, (int)($barber['capacity'] ?? 1));

    $service = get_service($businessId, $serviceId);
    $durationMin = round_duration_to_slot((int)$service['duration_minutes'], $slotMin);
    $end = $start->modify('+' . $durationMin . ' minutes');

    $day = $start->setTime(0,0);
    $ymd = $start->format('Y-m-d');
    if (barber_is_on_timeoff($businessId, $barberId, $day, $branchId)) {
        throw new RuntimeException('Ese profesional está de vacaciones ese día.');
    }
    $hours = barber_hours_for_day($businessId, $barberId, $day, $branchId);
    if ((int)($hours['is_closed'] ?? 1) === 1) {
        throw new RuntimeException('El local está cerrado ese día.');
    }

    $open = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . $hours['open_time'], new DateTimeZone($cfg['timezone']));
    $close = DateTimeImmutable::createFromFormat('Y-m-d H:i', $ymd . ' ' . $hours['close_time'], new DateTimeZone($cfg['timezone']));
    if (!$open || !$close) throw new RuntimeException('Horario inválido.');

    if ($start < now_tz()) throw new RuntimeException('No podés elegir un horario pasado.');
    if ($start < $open || $end > $close) throw new RuntimeException('Horario fuera del rango de atención.');

    $blocks = day_blocks($businessId, $day, $branchId, $barberId);
    foreach ($blocks as $b) {
        $bs = new DateTimeImmutable($b['start_at'], new DateTimeZone($cfg['timezone']));
        $be = new DateTimeImmutable($b['end_at'], new DateTimeZone($cfg['timezone']));
        if (overlaps($start, $end, $bs, $be)) {
            throw new RuntimeException('Ese horario no está disponible.');
        }
    }

    $busy = day_appointments_busy($businessId, $day, $branchId, $barberId, $ignoreAppointmentId);
    $tz = new DateTimeZone($cfg['timezone']);
    $count = interval_overlap_count($start, $end, $busy, $tz);
    if ($count >= $capacity) {
        throw new RuntimeException('Ese horario ya está ocupado.');
    }

    return [$service, $end, $durationMin];
}
