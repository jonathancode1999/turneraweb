<?php
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/service_barbers.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/branches.php';

$cfg = app_config();
$bid = (int)$cfg['business_id'];
$branchId = public_current_branch_id();

$action = $_GET['action'] ?? '';

try {
    if ($action === 'times') {
        $barberId = (int)($_GET['barber_id'] ?? 0); // 0 = primer profesional disponible
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
            $stmt = $pdo->prepare("SELECT id, name FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id");
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
