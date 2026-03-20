<?php
// Service <-> Professionals mapping helpers
// (Used by admin/profesional_edit.php)

require_once __DIR__ . '/db.php';

/**
 * Ensure schema exists (idempotent).
 * In MySQL we use CREATE TABLE IF NOT EXISTS.
 */
function service_profesionales_ensure_schema(PDO $pdo): void
{
    // The canonical schema is in schema_mysql.sql, but this keeps older installs safe.
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_profesionales (
        business_id INT NOT NULL,
        branch_id INT NOT NULL,
        service_id INT NOT NULL,
        professional_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (business_id, branch_id, service_id, professional_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Return an array of service IDs assigned to a professional (within a branch). */
function profesional_allowed_service_ids(int $businessId, int $branchId, int $professionalId): array
{
    $pdo = db();
    service_profesionales_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT service_id FROM service_profesionales WHERE business_id=:bid AND branch_id=:brid AND professional_id=:pid');
    $st->execute([':bid' => $businessId, ':brid' => $branchId, ':pid' => $professionalId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_map('intval', $ids ?: []));
}

/**
 * Set allowed services for a professional.
 * Safety rule: you can't leave a service with 0 active professionals.
 */
function profesional_set_allowed_services(int $businessId, int $branchId, int $professionalId, array $serviceIds): void
{
    $pdo = db();
    service_profesionales_ensure_schema($pdo);

    // Normalize input
    $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), function ($x) {
        return $x > 0;
    })));
    if (empty($serviceIds)) {
        throw new RuntimeException('Seleccioná al menos 1 servicio.');
    }

    // Existing set
    $existing = profesional_allowed_service_ids($businessId, $branchId, $professionalId);
    $existingSet = array_fill_keys($existing, true);
    $newSet = array_fill_keys($serviceIds, true);

    // Determine which services are being removed for this professional
    $removed = [];
    foreach ($existingSet as $sid => $_) {
        if (!isset($newSet[$sid])) $removed[] = (int)$sid;
    }

    // Validate removal doesn't leave service without professionals
    if (!empty($removed)) {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM service_profesionales WHERE business_id=:bid AND branch_id=:brid AND service_id=:sid');
        foreach ($removed as $sid) {
            $cnt->execute([':bid' => $businessId, ':brid' => $branchId, ':sid' => $sid]);
            $total = (int)$cnt->fetchColumn();
            // If this professional is currently assigned and total==1, removing would leave 0
            if ($total <= 1) {
                throw new RuntimeException('No podés desasignar este servicio porque quedarías sin profesionales para atenderlo.');
            }
        }
    }

	$startedTx = false;
	if (!$pdo->inTransaction()) {
	    $startedTx = (bool)$pdo->beginTransaction();
	    if (!$startedTx) throw new RuntimeException('No se pudo iniciar la transacción');
	}
    try {
        $pdo->prepare('DELETE FROM service_profesionales WHERE business_id=:bid AND branch_id=:brid AND professional_id=:pid')
            ->execute([':bid' => $businessId, ':brid' => $branchId, ':pid' => $professionalId]);

        $ins = $pdo->prepare('INSERT IGNORE INTO service_profesionales (business_id, branch_id, service_id, professional_id) VALUES (:bid,:brid,:sid,:pid)');
        foreach ($serviceIds as $sid) {
            $ins->execute([':bid' => $businessId, ':brid' => $branchId, ':sid' => (int)$sid, ':pid' => $professionalId]);
        }
		if (!empty($startedTx) && $pdo->inTransaction()) {
		    $pdo->commit();
		}
    } catch (Throwable $e) {
		if (!empty($startedTx) && $pdo->inTransaction()) {
		    $pdo->rollBack();
		}
        throw $e;
    }
}

// -----------------------------------------------------------------------------
// Compatibility helpers
// -----------------------------------------------------------------------------
// Some admin pages historically used "barbers" naming. Keep these aliases to
// avoid fatals and to keep the system consistent.

/**
 * Set which professionals can perform a given service.
 * @param int[] $professionalIds
 */
function service_set_allowed_profesionales(int $businessId, int $branchId, int $serviceId, array $professionalIds): void
{
    $pdo = db();
    service_profesionales_ensure_schema($pdo);

    $professionalIds = array_values(array_unique(array_filter(array_map('intval', $professionalIds), function ($x) {
        return $x > 0;
    })));
    if (empty($professionalIds)) {
        throw new RuntimeException('Seleccioná al menos 1 profesional.');
    }

	$startedTx = false;
	if (!$pdo->inTransaction()) {
	    $startedTx = (bool)$pdo->beginTransaction();
	    if (!$startedTx) throw new RuntimeException('No se pudo iniciar la transacción');
	}
    try {
        $pdo->prepare('DELETE FROM service_profesionales WHERE business_id=:bid AND branch_id=:brid AND service_id=:sid')
            ->execute([':bid' => $businessId, ':brid' => $branchId, ':sid' => $serviceId]);

        $ins = $pdo->prepare('INSERT IGNORE INTO service_profesionales (business_id, branch_id, service_id, professional_id) VALUES (:bid,:brid,:sid,:pid)');
        foreach ($professionalIds as $pid) {
            $ins->execute([':bid' => $businessId, ':brid' => $branchId, ':sid' => $serviceId, ':pid' => (int)$pid]);
        }
		if (!empty($startedTx) && $pdo->inTransaction()) {
		    $pdo->commit();
		}
    } catch (Throwable $e) {
		if (!empty($startedTx) && $pdo->inTransaction()) {
		    $pdo->rollBack();
		}
        throw $e;
    }
}

/** Return an array of professional IDs assigned to a service. */
function service_allowed_profesional_ids(int $businessId, int $branchId, int $serviceId): array
{
    $pdo = db();
    service_profesionales_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT professional_id FROM service_profesionales WHERE business_id=:bid AND branch_id=:brid AND service_id=:sid ORDER BY professional_id');
    $st->execute([':bid' => $businessId, ':brid' => $branchId, ':sid' => $serviceId]);
    return array_values(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

// Old names used in some pages (services.php)
function service_set_allowed_barbers(int $businessId, int $branchId, int $serviceId, array $barberIds): void
{
    service_set_allowed_profesionales($businessId, $branchId, $serviceId, $barberIds);
}

function service_allowed_barber_ids(int $businessId, int $branchId, int $serviceId): array
{
    return service_allowed_profesional_ids($businessId, $branchId, $serviceId);
}
