<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';


function mp_meta_get(string $key): string {
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT `value` FROM meta WHERE `key`=:k LIMIT 1");
        $st->execute([':k'=>$key]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string)$v;
    } catch (Throwable $e) {
        return '';
    }
}


function mp_cfg(): array {
    $cfg = app_config();
    $clientId = getenv('MP_CLIENT_ID') ?: (string)($cfg['mp_client_id'] ?? '');
    $clientSecret = getenv('MP_CLIENT_SECRET') ?: (string)($cfg['mp_client_secret'] ?? '');
    $redirectUri = getenv('MP_REDIRECT_URI') ?: (string)($cfg['mp_redirect_uri'] ?? '');

    // If not set in env/config, fallback to global meta (configured by Super Admin)
    if ($clientId === '') $clientId = mp_meta_get('mp_client_id');
    if ($clientSecret === '') $clientSecret = mp_meta_get('mp_client_secret');
    if ($redirectUri === '') $redirectUri = mp_meta_get('mp_redirect_uri');

    // Final fallback: guess redirect URI for this client install (recommended)
    if ($redirectUri === '') {
        $base = mp_guess_base_url_for_client();
        if ($base !== '') $redirectUri = rtrim($base, '/') . '/admin/mp_callback.php';
    }

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
    ];
}

function mp_guess_base_url_for_client(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // Expect /.../<client>/public/pay.php or /.../<client>/admin/...
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    // remove /public or /admin
    if (substr($dir, -7) === '/public') $dir = substr($dir, 0, -7);
    if (substr($dir, -6) === '/admin') $dir = substr($dir, 0, -6);
    return $scheme . '://' . $host . $dir;
}

function mp_api_request(string $method, string $url, string $accessToken, ?array $jsonBody = null): array {
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_UNESCAPED_UNICODE));
    }
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('MercadoPago error: ' . $err);
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) $data = ['raw' => $resp];

    if ($code < 200 || $code >= 300) {
        $msg = $data['message'] ?? ($data['error'] ?? 'HTTP ' . $code);
        throw new RuntimeException('MercadoPago HTTP ' . $code . ': ' . $msg);
    }
    return $data;
}

function mp_refresh_access_token_if_needed(PDO $pdo, int $businessId): array {
    $biz = $pdo->prepare('SELECT mp_access_token, mp_refresh_token, mp_token_expires_at, mp_connected FROM businesses WHERE id=:id');
    $biz->execute([':id'=>$businessId]);
    $b = $biz->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($b['mp_connected'] ?? 0) !== 1) return ['ok'=>false, 'access_token'=>''];

    $access = (string)($b['mp_access_token'] ?? '');
    $refresh = (string)($b['mp_refresh_token'] ?? '');
    $exp = (string)($b['mp_token_expires_at'] ?? '');

    $needsRefresh = false;
    if ($access === '' || $refresh === '') $needsRefresh = true;
    if ($exp !== '') {
        try {
            $dt = new DateTimeImmutable($exp, new DateTimeZone(app_config()['timezone']));
            if ($dt <= now_tz()->modify('+2 minutes')) $needsRefresh = true;
        } catch (Throwable $e) {
            $needsRefresh = true;
        }
    } else {
        $needsRefresh = true;
    }

    if (!$needsRefresh) return ['ok'=>true, 'access_token'=>$access];

    $cfg = mp_cfg();
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
        throw new RuntimeException('MercadoPago: faltan MP_CLIENT_ID / MP_CLIENT_SECRET (configuración técnica).');
    }
    $url = 'https://api.mercadopago.com/oauth/token';
    $payload = [
        'grant_type' => 'refresh_token',
        'client_id' => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'refresh_token' => $refresh,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException('MercadoPago refresh error: ' . $err);
    $data = json_decode($resp, true);
    if (!is_array($data)) $data = [];
    if ($code < 200 || $code >= 300) {
        $msg = $data['message'] ?? ($data['error_description'] ?? 'HTTP ' . $code);
        throw new RuntimeException('MercadoPago refresh HTTP ' . $code . ': ' . $msg);
    }

    $newAccess = (string)($data['access_token'] ?? '');
    $newRefresh = (string)($data['refresh_token'] ?? $refresh);
    $expiresIn = (int)($data['expires_in'] ?? 0);
    $expAt = ($expiresIn > 0) ? now_tz()->modify('+' . $expiresIn . ' seconds')->format('Y-m-d H:i:s') : '';

    $pdo->prepare('UPDATE businesses SET mp_access_token=:a, mp_refresh_token=:r, mp_token_expires_at=:e WHERE id=:id')
        ->execute([':a'=>$newAccess, ':r'=>$newRefresh, ':e'=>$expAt, ':id'=>$businessId]);

    return ['ok'=>true, 'access_token'=>$newAccess];
}

function mp_create_preference(PDO $pdo, int $businessId, array $appointment, array $service, array $branch): array {
    $tok = mp_refresh_access_token_if_needed($pdo, $businessId);
    if (!$tok['ok'] || $tok['access_token'] === '') throw new RuntimeException('MercadoPago no conectado.');

    $access = $tok['access_token'];
    $biz = $pdo->query('SELECT * FROM businesses WHERE id=' . (int)$businessId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $base = rtrim((string)($biz['public_base_url'] ?? ''), '/');
    if ($base === '') $base = rtrim(mp_guess_base_url_for_client(), '/');

    $token = (string)($appointment['token'] ?? '');
    $back = $base . '/public/mp_return.php?token=' . urlencode($token);
    $notify = $base . '/public/mp_webhook.php';

    $amount = (int)($appointment['payment_amount_ars'] ?? 0);
    $title = 'Turno: ' . (string)($service['name'] ?? 'Servicio');
    $payload = [
        'items' => [[
            'title' => $title,
            'quantity' => 1,
            'unit_price' => (float)$amount,
            'currency_id' => 'ARS',
        ]],
        'external_reference' => $token,
        'metadata' => [
            'business_id' => (int)$businessId,
            'appointment_id' => (int)($appointment['id'] ?? 0),
            'appointment_token' => $token,
        ],
        'back_urls' => [
            'success' => $back,
            'pending' => $back,
            'failure' => $back,
        ],
        'auto_return' => 'approved',
        'notification_url' => $notify,
    ];

    return mp_api_request('POST', 'https://api.mercadopago.com/checkout/preferences', $access, $payload);
}

function mp_get_payment(PDO $pdo, int $businessId, string $paymentId): array {
    $tok = mp_refresh_access_token_if_needed($pdo, $businessId);
    if (!$tok['ok'] || $tok['access_token'] === '') throw new RuntimeException('MercadoPago no conectado.');
    $access = $tok['access_token'];
    $url = 'https://api.mercadopago.com/v1/payments/' . rawurlencode($paymentId);
    return mp_api_request('GET', $url, $access, null);
}
