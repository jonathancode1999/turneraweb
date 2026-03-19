<?php
$requireEnvSecrets = in_array(strtolower((string)getenv('TURNERA_REQUIRE_ENV_SECRETS')), ['1', 'true', 'yes', 'on'], true);
$dbHost = getenv('TURNERA_DB_HOST') ?: 'localhost';
$dbPort = (int)(getenv('TURNERA_DB_PORT') ?: 3306);
$dbName = getenv('TURNERA_DB_NAME') ?: 'turnera_db';
$dbUser = getenv('TURNERA_DB_USER') ?: 'turnera_user';
$dbPass = getenv('TURNERA_DB_PASS');
$dbPass = ($dbPass !== false && $dbPass !== '') ? $dbPass : '';
$dbCharset = getenv('TURNERA_DB_CHARSET') ?: 'utf8mb4';

return [
    'app_name' => 'Turnera',
    'base_path' => '',

    // Single-business demo (multi-tenant ready). Keep as 1 for now.
    'business_id' => 1,

    'db_driver' => 'mysql',
    'db_host' => $dbHost,
    'db_port' => $dbPort,
    'db_name' => $dbName,
    'db_user' => $dbUser,
    'db_pass' => $dbPass,
    'db_charset' => $dbCharset,

    // Alias legacy para compatibilidad con archivos que todavía leen mysql_*.
    'mysql_host' => $dbHost,
    'mysql_port' => $dbPort,
    'mysql_db' => $dbName,
    'mysql_user' => $dbUser,
    'mysql_pass' => $dbPass,
    'mysql_charset' => $dbCharset,

    'auth_secret' => getenv('TURNERA_AUTH_SECRET') ?: '',
    'require_env_secrets' => $requireEnvSecrets,
    'session_name' => getenv('TURNERA_CLIENT_ADMIN_SESSION_NAME') ?: 'TURNERA_CLIENT_SESSID',
    'admin_gate_key' => getenv('TURNERA_ADMIN_GATE_KEY') ?: '',

    // Timezone for display and parsing
    'timezone' => 'America/Argentina/Buenos_Aires',

    // Slot base in minutes (recommended 15)
    'slot_minutes' => 15,

    // Pending deadline (minutes). Pending bookings older than this become VENCIDO.
    // Nota: aunque antes se llamaba "pay_deadline", ahora se usa como vencimiento de pendientes.
];
