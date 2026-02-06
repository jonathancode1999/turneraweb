<?php

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/status.php';

function default_event_template(string $eventKey, string $channel, string $fromName): array {
    // Defaults match the original hardcoded messages.
    $subject = $fromName . ' - Turno';
    $title = 'Estado del turno';
    $intro = 'Actualización del turno:';

    switch ($eventKey) {
        case 'booking_pending':
            $subject = $fromName . ' - Turno pendiente de aprobación';
            $title = 'Turno pendiente de aprobación';
            $intro = 'Recibimos tu solicitud de turno. En breve te confirmamos.';
            break;
        case 'booking_approved':
            $subject = $fromName . ' - Turno aprobado';
            $title = 'Turno aprobado';
            $intro = 'Tu turno fue aprobado. ¡Te esperamos!';
            break;
        case 'booking_cancelled':
            $subject = $fromName . ' - Turno cancelado';
            $title = 'Turno cancelado';
            $intro = 'El turno fue cancelado.';
            break;
        case 'booking_expired':
            $subject = $fromName . ' - Turno vencido';
            $title = 'Turno vencido';
            $intro = 'El turno venció por falta de confirmación a tiempo.';
            break;
        case 'reschedule_requested':
            $subject = $fromName . ' - Reprogramación pendiente';
            $title = 'Reprogramación pendiente';
            $intro = 'Recibimos una solicitud de reprogramación. Te avisamos cuando quede aprobada.';
            break;
        case 'reschedule_approved':
            $subject = $fromName . ' - Reprogramación aprobada';
            $title = 'Reprogramación aprobada';
            $intro = 'Tu reprogramación fue aprobada. Revisá la nueva fecha y hora:';
            break;
        case 'reschedule_rejected':
            $subject = $fromName . ' - Reprogramación rechazada';
            $title = 'Reprogramación rechazada';
            $intro = 'No pudimos aprobar la reprogramación solicitada. Tu turno original sigue vigente.';
            break;
        case 'booking_rescheduled_by_admin':
            $subject = $fromName . ' - Turno reprogramado';
            $title = 'Turno reprogramado';
            $intro = 'El negocio reprogramó tu turno. Revisá la nueva fecha y hora.';
            break;
        case 'booking_reminder':
            $subject = $fromName . ' - Recordatorio: tu turno es pronto';
            $title = 'Recordatorio de turno';
            $intro = 'Te recordamos tu turno. ¡Te esperamos!';
            break;
    }

    // Email default is HTML built with build_base_email()
    return ['subject' => $subject, 'title' => $title, 'intro' => $intro, 'is_html' => 1];
}

/**
 * Notifications are sent by EVENT (not only by status), so we can customize:
 * - booking_pending
 * - booking_approved
 * - booking_cancelled
 * - booking_expired
 * - reschedule_requested
 * - reschedule_approved
 * - reschedule_rejected
 */

function build_appt_details_html(array $biz, array $appt, string $manageUrl = ''): string {
    $tz = new DateTimeZone(app_config()['timezone']);
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$appt['start_at'], $tz)
        ?: new DateTimeImmutable((string)$appt['start_at'], $tz);
    $end = null;
    if (!empty($appt['end_at'])) {
        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$appt['end_at'], $tz)
            ?: new DateTimeImmutable((string)$appt['end_at'], $tz);
    }
    $durationMin = null;
    if ($end) {
        $durationMin = (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60);
        if ($durationMin < 0) $durationMin = null;
    }

    $service = trim((string)($appt['service_name'] ?? ''));
    $barber = trim((string)($appt['barber_name'] ?? ''));
    $customer = trim((string)($appt['customer_name'] ?? ''));
    $phone = trim((string)($appt['customer_phone'] ?? ''));
    $email = trim((string)($appt['customer_email'] ?? ''));

    $maps = trim((string)($biz['maps_url'] ?? ''));

    $rows = [];
    if ($customer !== '') $rows[] = '<tr><td><b>Cliente</b></td><td>' . h($customer) . '</td></tr>';
    if ($phone !== '') $rows[] = '<tr><td><b>Teléfono</b></td><td>' . h($phone) . '</td></tr>';
    if ($email !== '') $rows[] = '<tr><td><b>Email</b></td><td>' . h($email) . '</td></tr>';
    if ($service !== '') $rows[] = '<tr><td><b>Servicio</b></td><td>' . h($service) . '</td></tr>';
    if ($barber !== '') $rows[] = '<tr><td><b>Profesional</b></td><td>' . h($barber) . '</td></tr>';
    
    $statusLabel = appt_status_label((string)($appt['status'] ?? ''));
    if ($statusLabel !== '') $rows[] = '<tr><td><b>Estado</b></td><td>' . h($statusLabel) . '</td></tr>';
$rows[] = '<tr><td><b>Fecha</b></td><td>' . h($start->format('d/m/Y')) . '</td></tr>';
    $rows[] = '<tr><td><b>Hora</b></td><td>' . h($start->format('H:i')) . '</td></tr>';
    if ($durationMin !== null && $durationMin > 0) {
        $rows[] = '<tr><td><b>Duración</b></td><td>' . h((string)$durationMin) . ' min</td></tr>';
    }
    if ($end) {
        $rows[] = '<tr><td><b>Termina</b></td><td>' . h($end->format('H:i')) . '</td></tr>';
    }
    if ($maps !== '') $rows[] = '<tr><td><b>Ubicación</b></td><td><a href="' . h($maps) . '">Abrir mapa</a></td></tr>';
    if ($manageUrl !== '') $rows[] = '<tr><td><b>Link</b></td><td><a href="' . h($manageUrl) . '">Ver / gestionar turno</a></td></tr>';

    return '<table style="border-collapse:collapse;width:100%">'
        . implode('', array_map(function($r){ return str_replace('<td>', '<td style="padding:6px 8px;border-bottom:1px solid #eee;vertical-align:top">', $r); }, $rows))
        . '</table>';
}

function build_base_email(string $title, string $intro, string $detailsHtml, string $ctaHtml = ''): string {
    $style = 'font-family:system-ui,Segoe UI,Arial;line-height:1.4;color:#111';
    return '<div style="' . $style . '">'
        . '<h2 style="margin:0 0 8px">' . h($title) . '</h2>'
        . '<p style="margin:0 0 14px">' . $intro . '</p>'
        . $detailsHtml
        . ($ctaHtml ? '<div style="margin-top:14px">' . $ctaHtml . '</div>' : '')
        . '<p style="margin:16px 0 0;color:#666;font-size:12px">Mensaje automático.</p>'
        . '</div>';
}

function notify_event(string $event, array $business, array $appt, array $extra = []): array {
    $pdo = null;
    try { $pdo = db(); } catch (Throwable $e) { $pdo = null; }
    $ownerEmail = trim((string)($business['owner_email'] ?? ''));
    // IMPORTANT: many SMTP providers reject messages when "From" is not the authenticated user.
    // Prefer smtp_from_email, then smtp_user, then owner_email.
    $fromEmail = trim((string)($business['smtp_from_email'] ?? ''));
    if ($fromEmail === '') $fromEmail = trim((string)($business['smtp_user'] ?? ''));
    if ($fromEmail === '') $fromEmail = ($ownerEmail !== '' ? $ownerEmail : 'no-reply@localhost');
    $fromName = trim((string)($business['smtp_from_name'] ?? ''));
    if ($fromName === '') $fromName = trim((string)($business['name'] ?? 'Turnera'));

    $custEmail = trim((string)($appt['customer_email'] ?? ''));
    $token = trim((string)($appt['token'] ?? ''));
    $manageUrl = '';
    if ($token !== '') {
        $base = rtrim((string)($business['public_base_url'] ?? ''), '/');
        if ($base !== '') {
            $manageUrl = $base . '/public/manage.php?token=' . rawurlencode($token);
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            // Build from current request (works on local XAMPP/admin)
            $manageUrl = public_url('manage.php?token=' . rawurlencode($token));
        } else {
            // Fallback for CLI/cron
            $manageUrl = 'public/manage.php?token=' . rawurlencode($token);
        }
    }

    $details = build_appt_details_html($business, $appt, $manageUrl);
    $subject = $fromName . ' - Turno';
    $body = '';
    $sendToCustomer = $custEmail !== '';
    // Allow callers (e.g. admin actions) to disable sending notifications to the business owner.
    $sendToOwner = $ownerEmail !== '' && (!isset($extra['to_owner']) || (bool)$extra['to_owner'] === true);

    // --- Template-driven emails (editable in admin/) ---
    $eventKey = $event;
    if ($eventKey === 'booking_reminder_1h') $eventKey = 'booking_reminder';

    // Variables available for templates
    $tz = new DateTimeZone(app_config()['timezone']);
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)($appt['start_at'] ?? ''), $tz)
        ?: new DateTimeImmutable((string)($appt['start_at'] ?? ''), $tz);
    $end = (!empty($appt['end_at']))
        ? (DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$appt['end_at'], $tz) ?: new DateTimeImmutable((string)$appt['end_at'], $tz))
        : null;
    $durationMin = null;
    if ($end) {
        $durationMin = (int)round(($end->getTimestamp() - $start->getTimestamp()) / 60);
        if ($durationMin < 0) $durationMin = null;
    }
    $vars = [
        '{business_name}' => (string)($business['name'] ?? 'Turnera'),
        '{branch_name}' => (string)($extra['branch_name'] ?? ''),
        '{address}' => (string)($business['address'] ?? ''),
        '{maps_url}' => (string)($business['maps_url'] ?? ''),
        '{whatsapp}' => (string)($business['whatsapp_phone'] ?? ''),
        '{instagram}' => (string)($business['instagram_url'] ?? ''),
        '{customer_name}' => (string)($appt['customer_name'] ?? ''),
        '{customer_phone}' => (string)($appt['customer_phone'] ?? ''),
        '{customer_email}' => (string)($appt['customer_email'] ?? ''),
        '{service_name}' => (string)($appt['service_name'] ?? ''),
        '{professional_name}' => (string)($appt['barber_name'] ?? ''),
        '{date}' => $start->format('d/m/Y'),
        '{time}' => $start->format('H:i'),
        '{end_time}' => $end ? $end->format('H:i') : '',
        '{duration_minutes}' => $durationMin !== null ? (string)$durationMin : '',
        '{manage_url}' => $manageUrl,
        '{status_label}' => appt_status_label((string)($appt['status'] ?? '')),
    ];

    $tpl = default_event_template($eventKey, 'email', $fromName);

$subject = (string)($tpl['subject'] ?? '');
if ($subject === '') $subject = $fromName . ' - Turno';

    $rawBody = (string)($tpl['body'] ?? '');
    $isHtml = (int)($tpl['is_html'] ?? 1) === 1;
    if ($isHtml) {
        // If body contains our placeholder, replace it; otherwise use our default card layout.
        $bodyTpl = $rawBody;
if (strpos($bodyTpl, '{details_html}') !== false) {
            $bodyTpl = str_replace('{details_html}', $details, $bodyTpl);
            $body = $bodyTpl;
        } else {
            $title = (string)($tpl['title'] ?? ($extra['title'] ?? 'Actualización'));
            $intro = (string)($tpl['intro'] ?? ($extra['intro'] ?? 'Actualización del turno:'));
            if ($rawBody === '') {
                $body = build_base_email($title, $intro, $details, $manageUrl ? '<a href="' . h($manageUrl) . '">Ver / gestionar turno</a>' : '');
            } else {
                $body = $bodyTpl;
                // If template didn't include {details_html}, append it.
                if (strpos($body, '{details_html}') !== false) {
                    $body = str_replace('{details_html}', $details, $body);
                } else {
                    $body .= '<div style="margin-top:12px">' . $details . '</div>';
                }
            }
        }
    } else {
        // Plain text email body
        $b = strtr($rawBody, $vars);
        if ($b === '') $b = "Turno: {date} {time}";
        $body = nl2br(h($b));
    }

    // Recordatorio: solo al cliente
    if ($eventKey === 'booking_reminder') {
        $sendToOwner = false;
    }

    $results = [
        'customer' => ['ok' => null, 'error' => null],
        'owner' => ['ok' => null, 'error' => null],
    ];

    if ($sendToCustomer) {
        $results['customer'] = send_mail_html_result($custEmail, $subject, $body, $fromEmail, $fromName);
    }
    if ($sendToOwner) {
        $results['owner'] = send_mail_html_result($ownerEmail, $subject, $body, $fromEmail, $fromName);
    }

    return $results;
}
