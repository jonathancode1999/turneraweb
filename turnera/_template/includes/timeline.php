<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function appt_log_event(int $businessId, int $branchId, int $appointmentId, string $eventType, string $note = '', array $meta = [], string $actorType = 'system', ?int $actorUserId = null): void {
    $pdo = db();
    ensure_multibranch_schema($pdo);
    $metaJson = '';
    if ($meta) {
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) $metaJson = '';
    }
    $st = $pdo->prepare("INSERT INTO appointment_events (business_id, branch_id, appointment_id, actor_type, actor_user_id, event_type, note, meta_json)
                        VALUES (:bid,:brid,:aid,:at,:au,:et,:n,:m)");
    $st->execute([
        ':bid'=>$businessId,
        ':brid'=>$branchId,
        ':aid'=>$appointmentId,
        ':at'=>$actorType,
        ':au'=>$actorUserId,
        ':et'=>$eventType,
        ':n'=>$note,
        ':m'=>$metaJson,
    ]);
}

function appt_events(int $businessId, int $appointmentId): array {
    $pdo = db();
    if (!db_table_exists($pdo, 'appointment_events')) return [];
    $st = $pdo->prepare("SELECT * FROM appointment_events WHERE business_id=:bid AND appointment_id=:aid ORDER BY created_at ASC, id ASC");
    $st->execute([':bid'=>$businessId, ':aid'=>$appointmentId]);
    return $st->fetchAll() ?: [];
}

function appt_event_label(string $eventType): string {
    switch ($eventType) {
        case 'created': return 'Creado';
        case 'status_change': return 'Cambio de estado';
        case 'reschedule_requested': return 'Reprogramación solicitada';
        case 'reschedule_approved': return 'Reprogramación aprobada';
        case 'reschedule_rejected': return 'Reprogramación rechazada';
        case 'admin_rescheduled': return 'Reprogramado por el negocio';
        case 'cancelled': return 'Cancelado';
        case 'accepted': return 'Aprobado';
        case 'completed': return 'Completado';
        case 'reminder_sent': return 'Recordatorio enviado';
        default: return $eventType;
    }
}
