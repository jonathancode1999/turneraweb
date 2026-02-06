<?php
// Service <-> Professionals (barbers) mapping helpers.
// Backwards compatible: creates table on demand if missing.

require_once __DIR__ . '/db.php';

function service_barbers_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_barbers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL,
        branch_id INTEGER NOT NULL DEFAULT 1,
        service_id INTEGER NOT NULL,
        barber_id INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(business_id, branch_id, service_id, barber_id),
        FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
        FOREIGN KEY(barber_id) REFERENCES barbers(id) ON DELETE CASCADE
    )");
}

function service_barbers_seed_default_if_empty(int $businessId, int $branchId, int $serviceId): void {
    $pdo = db();
    service_barbers_ensure_schema($pdo);

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM service_barbers WHERE business_id=:bid AND branch_id=:brid AND service_id=:sid");
    $cntStmt->execute([':bid'=>$businessId, ':brid'=>$branchId, ':sid'=>$serviceId]);
    $cnt = (int)($cntStmt->fetchColumn() ?: 0);
    if ($cnt > 0) return;

    // Default behavior for older installs: if not configured, allow all active professionals in the branch.
    $bs = $pdo->prepare("SELECT id FROM barbers WHERE business_id=:bid AND branch_id=:brid AND is_active=1 ORDER BY id");
    $bs->execute([':bid'=>$businessId, ':brid'=>$branchId]);
    $barbers = $bs->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO service_barbers (business_id, branch_id, service_id, barber_id) VALUES (:bid,:brid,:sid,:barber)");
    foreach ($barbers as $b) {
        $ins->execute([':bid'=>$businessId, ':brid'=>$branchId, ':sid'=>$serviceId, ':barber'=>(int)$b['id']]);
    }
}

function service_allowed_barber_ids(int $businessId, int $branchId, int $serviceId): array {
    $pdo = db();
    service_barbers_seed_default_if_empty($businessId, $branchId, $serviceId);

    $st = $pdo->prepare("SELECT barber_id FROM service_barbers WHERE business_id=:bid AND branch_id=:brid AND service_id=:sid");
    $st->execute([':bid'=>$businessId, ':brid'=>$branchId, ':sid'=>$serviceId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) $out[] = (int)$r['barber_id'];
    return $out;
}

function service_is_barber_allowed(int $businessId, int $branchId, int $serviceId, int $barberId): bool {
    if ($barberId <= 0) return true; // 0 = primer disponible
    $ids = service_allowed_barber_ids($businessId, $branchId, $serviceId);
    return in_array($barberId, $ids, true);
}

function service_set_allowed_barbers(int $businessId, int $branchId, int $serviceId, array $barberIds): void {
    $pdo = db();
    service_barbers_ensure_schema($pdo);

    $clean = [];
    foreach ($barberIds as $id) {
        $id = (int)$id;
        if ($id > 0) $clean[$id] = true;
    }
    $ids = array_keys($clean);

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM service_barbers WHERE business_id=:bid AND branch_id=:brid AND service_id=:sid");
        $del->execute([':bid'=>$businessId, ':brid'=>$branchId, ':sid'=>$serviceId]);

        $ins = $pdo->prepare("INSERT OR IGNORE INTO service_barbers (business_id, branch_id, service_id, barber_id) VALUES (:bid,:brid,:sid,:barber)");
        foreach ($ids as $barberId) {
            $ins->execute([':bid'=>$businessId, ':brid'=>$branchId, ':sid'=>$serviceId, ':barber'=>$barberId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function service_barbers_count_for_service(int $businessId, int $branchId, int $serviceId): int {
    $pdo = db();
    service_barbers_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT COUNT(*) FROM service_barbers WHERE business_id=:bid AND branch_id=:brid AND service_id=:sid");
    $st->execute([':bid'=>$businessId, ':brid'=>$branchId, ':sid'=>$serviceId]);
    return (int)($st->fetchColumn() ?: 0);
}

function service_barbers_is_barber_assigned(int $businessId, int $branchId, int $serviceId, int $barberId): bool {
    $pdo = db();
    service_barbers_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT 1 FROM service_barbers WHERE business_id=:bid AND branch_id=:brid AND service_id=:sid AND barber_id=:bar LIMIT 1");
    $st->execute([':bid'=>$businessId, ':brid'=>$branchId, ':sid'=>$serviceId, ':bar'=>$barberId]);
    return (bool)$st->fetchColumn();
}

function service_barbers_is_unique_barber_for_service(int $businessId, int $branchId, int $serviceId, int $barberId): bool {
    if (!service_barbers_is_barber_assigned($businessId, $branchId, $serviceId, $barberId)) return false;
    return service_barbers_count_for_service($businessId, $branchId, $serviceId) <= 1;
}

function barber_allowed_service_ids(int $businessId, int $branchId, int $barberId): array {
    $pdo = db();
    service_barbers_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT service_id FROM service_barbers WHERE business_id=:bid AND branch_id=:brid AND barber_id=:bar ORDER BY service_id");
    $st->execute([':bid'=>$businessId, ':brid'=>$branchId, ':bar'=>$barberId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) $out[] = (int)$r['service_id'];
    return $out;
}

/**
 * Set services for a barber (professional).
 * Rules:
 * - Must have at least 1 service (when there are services configured).
 * - Can't remove the barber from a service if it's the only professional assigned to that service.
 */
function barber_set_allowed_services(int $businessId, int $branchId, int $barberId, array $serviceIds): void {
    $pdo = db();
    service_barbers_ensure_schema($pdo);

    $clean = [];
    foreach ($serviceIds as $id) {
        $id = (int)$id;
        if ($id > 0) $clean[$id] = true;
    }
    $new = array_keys($clean);

    // If there are services at all, enforce at least 1 selection
    $totalServices = (int)($pdo->query("SELECT COUNT(*) FROM services WHERE business_id=" . (int)$businessId)->fetchColumn() ?: 0);
    if ($totalServices > 0 && count($new) < 1) {
        throw new RuntimeException('El profesional debe tener al menos 1 servicio.');
    }

    $current = barber_allowed_service_ids($businessId, $branchId, $barberId);
    $curSet = array_flip(array_map('intval', $current));
    $newSet = array_flip(array_map('intval', $new));

    $toRemove = [];
    foreach ($curSet as $sid => $_) {
        if (!isset($newSet[$sid])) $toRemove[] = (int)$sid;
    }
    $toAdd = [];
    foreach ($newSet as $sid => $_) {
        if (!isset($curSet[$sid])) $toAdd[] = (int)$sid;
    }

    // Validate removals: can't leave a service with zero professionals
    if (!empty($toRemove)) {
        $nameStmt = $pdo->prepare("SELECT name FROM services WHERE business_id=:bid AND id=:sid");
        foreach ($toRemove as $sid) {
            if (service_barbers_is_unique_barber_for_service($businessId, $branchId, $sid, $barberId)) {
                $nameStmt->execute([':bid'=>$businessId, ':sid'=>$sid]);
                $svcName = (string)($nameStmt->fetchColumn() ?: ('Servicio #' . $sid));
                throw new RuntimeException('Único profesional en el servicio: ' . $svcName);
            }
        }
    }

    $pdo->beginTransaction();
    try {
        if (!empty($toRemove)) {
            $del = $pdo->prepare("DELETE FROM service_barbers WHERE business_id=:bid AND branch_id=:brid AND barber_id=:bar AND service_id=:sid");
            foreach ($toRemove as $sid) {
                $del->execute([':bid'=>$businessId, ':brid'=>$branchId, ':bar'=>$barberId, ':sid'=>$sid]);
            }
        }
        if (!empty($toAdd)) {
            $ins = $pdo->prepare("INSERT OR IGNORE INTO service_barbers (business_id, branch_id, service_id, barber_id) VALUES (:bid,:brid,:sid,:bar)");
            foreach ($toAdd as $sid) {
                $ins->execute([':bid'=>$businessId, ':brid'=>$branchId, ':sid'=>$sid, ':bar'=>$barberId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

