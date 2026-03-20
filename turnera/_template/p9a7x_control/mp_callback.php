<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/mercadopago.php';

admin_require_login();
admin_require_permission('settings');

session_start();
$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');

if ($code === '') {
    echo "MercadoPago: falta el code.";
    exit;
}
if (!isset($_SESSION['mp_oauth_state']) || $state === '' || $state !== $_SESSION['mp_oauth_state']) {
    echo "MercadoPago: state inválido.";
    exit;
}
unset($_SESSION['mp_oauth_state']);

$cfg = mp_cfg();
if ($cfg['client_id'] === '' || $cfg['client_secret'] === '' || $cfg['redirect_uri'] === '') {
    echo "Falta configurar MercadoPago (técnico): MP_CLIENT_ID / MP_CLIENT_SECRET / MP_REDIRECT_URI.";
    exit;
}

$url = 'https://api.mercadopago.com/oauth/token';
$payload = [
    'grant_type' => 'authorization_code',
    'client_id' => $cfg['client_id'],
    'client_secret' => $cfg['client_secret'],
    'code' => $code,
    'redirect_uri' => $cfg['redirect_uri'],
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$resp = curl_exec($ch);
$err = curl_error($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false) {
    echo "MercadoPago: error: " . h($err);
    exit;
}

$data = json_decode($resp, true);
if (!is_array($data)) $data = [];
if ($http < 200 || $http >= 300) {
    $msg = $data['message'] ?? ($data['error_description'] ?? 'HTTP ' . $http);
    echo "MercadoPago: " . h($msg);
    exit;
}

$access = (string)($data['access_token'] ?? '');
$refresh = (string)($data['refresh_token'] ?? '');
$userId = (string)($data['user_id'] ?? '');
$expiresIn = (int)($data['expires_in'] ?? 0);
$expAt = ($expiresIn > 0) ? now_tz()->modify('+' . $expiresIn . ' seconds')->format('Y-m-d H:i:s') : '';

$pdo = db();
$bid = (int)app_config()['business_id'];

$pdo->prepare("UPDATE businesses
               SET mp_connected=1, mp_user_id=:uid, mp_access_token=:a, mp_refresh_token=:r, mp_token_expires_at=:e
               WHERE id=:id")
    ->execute([':uid'=>$userId, ':a'=>$access, ':r'=>$refresh, ':e'=>$expAt, ':id'=>$bid]);

redirect('settings.php');
