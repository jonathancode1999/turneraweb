<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/utils.php';

admin_require_login();
admin_require_permission('settings');

$pdo = db();
$bid = (int)app_config()['business_id'];

$pdo->prepare("UPDATE businesses
               SET mp_connected=0, mp_user_id='', mp_access_token='', mp_refresh_token='', mp_token_expires_at=''
               WHERE id=:id")
    ->execute([':id'=>$bid]);

redirect('settings.php');
