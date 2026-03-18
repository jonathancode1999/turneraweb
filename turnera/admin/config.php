<?php
// Super Admin config
return [
  'super_user' => 'admin',
  'super_pass' => 'admin', // Solo como fallback legacy; luego del setup se limpia.
  'super_pass_hash' => '',
  'super_email' => '',
  'super_security_question' => '',
  'super_security_answer_hash' => '',
  'root_dir' => realpath(__DIR__ . '/..'),
  // MySQL (shared DB for all clients; business_id selected per client config)
  'mysql_host' => '127.0.0.1',
  'mysql_port' => 3306,
  'mysql_db' => 'turnera_db',
  'mysql_user' => 'jondev_user',
  'mysql_pass' => '-45225755Jo-',
  'mysql_charset' => 'utf8mb4',
];
