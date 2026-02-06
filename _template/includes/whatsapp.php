<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function wa_normalize_phone(string $raw): string {
    $p = preg_replace('/\D+/', '', $raw);
    if ($p === '') return '';
    // Remove leading 00
    if (strpos($p, '00') === 0) $p = substr($p, 2);
    // Remove leading 0 (common local prefix)
    if (strpos($p, '0') === 0) $p = ltrim($p, '0');
    // Argentina-friendly default: if it's 10/11 digits and doesn't start with country code, prepend 54
    if (strlen($p) >= 10 && strlen($p) <= 11 && strpos($p, '54') !== 0) {
        $p = '54' . $p;
    }
    return $p;
}

function wa_get_template(PDO $pdo, int $businessId, string $eventKey): ?string {
    $st = $pdo->prepare("SELECT body FROM message_templates WHERE business_id=:bid AND event_key=:ek AND channel='whatsapp' LIMIT 1");
    $st->execute([':bid'=>$businessId, ':ek'=>$eventKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['body']) && trim((string)$row['body']) !== '') return (string)$row['body'];
    return null;
}

function wa_default_template(string $eventKey): string {
    switch ($eventKey) {
        case 'approved':
            return "Hola {cliente}!\n\nTu turno fue CONFIRMADO.\n\n {negocio} - {sucursal}\n Servicio: {servicio}\n Profesional: {profesional}\n {fecha} {hora}\n\n Dirección: {direccion}\n {maps}\n\nSi necesitás cambiarlo, usá este link: {manage_url}";
        case 'cancelled':
            return "Hola {cliente}!\n\nTu turno fue CANCELADO.\n\n {negocio} - {sucursal}\n Servicio: {servicio}\n {fecha} {hora}\n\nSi querés sacar otro, podés volver a reservar cuando quieras.";
        case 'rescheduled':
            return "Hola {cliente}!\n\nTu turno fue REPROGRAMADO.\n\n {negocio} - {sucursal}\n Servicio: {servicio}\n Profesional: {profesional}\n Nuevo horario: {fecha} {hora}\n\n Dirección: {direccion}\n {maps}\n\nGestionar turno: {manage_url}";
        case 'reminder':
            return "Hola {cliente}!\n\nRecordatorio: tenés turno en {negocio} ({sucursal})\n {servicio} con {profesional}\n {fecha} {hora}\n\n {direccion}\n {maps}";
        default:
            return "Hola {cliente}! Tu turno en {negocio} es: {fecha} {hora}.";
    }
}


function wa_guess_base_url_for_client(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // Expect /.../<client>/admin/whatsapp_send.php
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    // remove /admin
    if (substr($dir, -6) === '/admin') $dir = substr($dir, 0, -6);
    return $scheme . '://' . $host . $dir;
}

function wa_build_message(PDO $pdo, array $appointment, array $branch, string $eventKey): string {
    $tpl = wa_get_template($pdo, (int)$appointment['business_id'], $eventKey);
    if ($tpl === null) $tpl = wa_default_template($eventKey);

    // Fetch business name
    $b = $pdo->prepare("SELECT name FROM businesses WHERE id=:id");
    $b->execute([':id'=>(int)$appointment['business_id']]);
    $bizName = (string)($b->fetchColumn() ?: '');

    // service and professional names are usually joined, but fallback
    $service = (string)($appointment['service_name'] ?? '');
    $pro = (string)($appointment['barber_name'] ?? '');

    $dt = parse_db_datetime((string)$appointment['start_at']);
    $fecha = $dt->format('d/m/Y');
    $hora = $dt->format('H:i');

    $manageUrl = '';
    if (!empty($appointment['token'])) {
        $cfg = app_config();
        $base = rtrim((string)($cfg['public_base_url'] ?? ''), '/');
        if ($base === '') $base = rtrim(wa_guess_base_url_for_client(), '/');
        if ($base !== '') $manageUrl = $base . '/public/manage.php?token=' . urlencode((string)$appointment['token']);
    }

    $repl = [
        '{cliente}' => (string)$appointment['customer_name'],
        '{negocio}' => $bizName,
        '{sucursal}' => (string)($branch['name'] ?? ''),
        '{servicio}' => $service,
        '{profesional}' => $pro,
        '{fecha}' => $fecha,
        '{hora}' => $hora,
        '{direccion}' => (string)($branch['address'] ?? ''),
        '{maps}' => (string)($branch['maps_url'] ?? ''),
        '{manage_url}' => $manageUrl,
    ];
    return strtr($tpl, $repl);
}

function wa_build_link(string $phoneNormalized, string $message): string {
    $text = rawurlencode($message);
    return "https://wa.me/" . $phoneNormalized . "?text=" . $text;
}
