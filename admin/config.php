<?php
// Super Admin config
return [
  'super_user' => 'admin',
  'super_pass' => 'admin', // CHANGE in production
  'root_dir'   => realpath(__DIR__ . '/..'),
  // MySQL (shared DB for all clients; business_id selected per client config)
  'mysql_host' => '127.0.0.1',
  'mysql_port' => 3306,
  'mysql_db'   => 'turnera_db',
  'mysql_user' => 'jondev_user',
  'mysql_pass' => '-45225755Jo-',
  'mysql_charset' => 'utf8mb4',
];