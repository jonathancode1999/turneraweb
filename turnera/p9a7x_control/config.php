<?php
$requireEnvSecrets = in_array(strtolower((string)getenv('TURNERA_REQUIRE_ENV_SECRETS')), ['1', 'true', 'yes', 'on'], true);
$dbHost = getenv('TURNERA_DB_HOST') ?: 'localhost';
$dbPort = (int)(getenv('TURNERA_DB_PORT') ?: 3306);
$dbName = getenv('TURNERA_DB_NAME') ?: 'turnera_db';
$dbUser = getenv('TURNERA_DB_USER') ?: 'turnera_user';
$dbPass = getenv('TURNERA_DB_PASS');
$dbPass = ($dbPass !== false && $dbPass !== '') ? $dbPass : '!2000jo1900lb!';
$dbCharset = getenv('TURNERA_DB_CHARSET') ?: 'utf8mb4';

return [
  'base_path' => '',

  'super_user' => 'admin',
  'super_pass' => 'admin',
  'super_pass_hash' => '',
  'super_email' => '',
  'super_security_question' => '',
  'super_security_answer_hash' => '',

  'root_dir' => realpath(__DIR__ . '/../..'),
  'app_dir' => realpath(__DIR__ . '/..'),

  'db_host' => $dbHost,
  'db_port' => $dbPort,
  'db_name' => $dbName,
  'db_user' => $dbUser,
  'db_pass' => $dbPass,
  'db_charset' => $dbCharset,

  'mysql_host' => $dbHost,
  'mysql_port' => $dbPort,
  'mysql_db' => $dbName,
  'mysql_user' => $dbUser,
  'mysql_pass' => $dbPass,
  'mysql_charset' => $dbCharset,

  'auth_secret' => getenv('TURNERA_AUTH_SECRET') ?: '',
  'require_env_secrets' => $requireEnvSecrets,
  'session_name' => getenv('TURNERA_SESSION_NAME') ?: 'TURNERA_SUPERADMIN_SESSID',
  'admin_gate_key' => getenv('TURNERA_ADMIN_GATE_KEY') ?: '',
];
