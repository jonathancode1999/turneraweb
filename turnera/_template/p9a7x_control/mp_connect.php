<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';
require_once __DIR__ . '/../includes/mercadopago.php';

admin_require_login();
admin_require_permission('settings');

$cfg = mp_cfg();
if ($cfg['client_id'] === '' || $cfg['redirect_uri'] === '') {
    echo "MercadoPago todavía no está habilitado para este servidor. Pedile al soporte que lo active (configuración técnica).";
    exit;
}

session_start();
$state = bin2hex(random_bytes(16));
$_SESSION['mp_oauth_state'] = $state;

$authUrl = 'https://auth.mercadopago.com.ar/authorization?response_type=code'
    . '&client_id=' . urlencode($cfg['client_id'])
    . '&redirect_uri=' . urlencode($cfg['redirect_uri'])
    . '&state=' . urlencode($state);

redirect($authUrl);
