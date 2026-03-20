<?php
// Centralized appointment statuses (v2).
// Keep all status strings here to avoid typos and to support legacy normalization.

const APPT_STATUS_PENDING_APPROVAL = 'PENDIENTE_APROBACION';
const APPT_STATUS_ACCEPTED = 'ACEPTADO';
const APPT_STATUS_RESCHEDULE_PENDING = 'REPROGRAMACION_PENDIENTE';
const APPT_STATUS_CANCELLED = 'CANCELADO';
const APPT_STATUS_EXPIRED = 'VENCIDO';
const APPT_STATUS_COMPLETED = 'COMPLETADO';
const APPT_STATUS_NO_SHOW = 'NO_ASISTIO';
const APPT_STATUS_BLOCKED = 'OCUPADO';

// Legacy statuses seen in older builds / demo flows.
const APPT_STATUS_LEGACY_CONFIRMED = 'CONFIRMADO';
function appt_status_normalize(string $status): string {
    $s = strtoupper(trim($status));
    if ($s === APPT_STATUS_LEGACY_CONFIRMED) return APPT_STATUS_ACCEPTED;
    return $s;
}

function appt_status_label(string $status): string {
    $s = appt_status_normalize($status);
    $map = [
        APPT_STATUS_PENDING_APPROVAL => 'Pendiente de aprobación',
        APPT_STATUS_ACCEPTED => 'Aprobado',
        APPT_STATUS_RESCHEDULE_PENDING => 'Reprogramación pendiente',
        APPT_STATUS_CANCELLED => 'Cancelado',
        APPT_STATUS_EXPIRED => 'Vencido',
        APPT_STATUS_COMPLETED => 'Completado',
        APPT_STATUS_NO_SHOW => 'No asistió',
        APPT_STATUS_BLOCKED => 'Ocupado',
    ];
    return $map[$s] ?? $s;
}

function appt_status_badge_class(string $status): string {
    $s = appt_status_normalize($status);
    if (in_array($s, [APPT_STATUS_PENDING_APPROVAL, APPT_STATUS_RESCHEDULE_PENDING], true)) return 'warn';
    if (in_array($s, [APPT_STATUS_ACCEPTED, APPT_STATUS_COMPLETED], true)) return 'ok';
    if ($s === APPT_STATUS_NO_SHOW) return 'danger';
    if (in_array($s, [APPT_STATUS_CANCELLED, APPT_STATUS_EXPIRED], true)) return 'danger';
    return '';
}
