<?php

function app_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function db_is_mysql(?PDO $pdo = null): bool {
    try {
        return !$pdo || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    } catch (Throwable $e) {
        return true;
    }
}

function db_mysql_index_exists(PDO $pdo, string $table, string $indexName): bool {
    $cfg = app_config();
    $dbName = $cfg['mysql_db'] ?? ($cfg['db_name'] ?? '');
    if (!$dbName) return false;
    $stmt = $pdo->prepare("SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND INDEX_NAME = :i");
    $stmt->execute([':db' => $dbName, ':t' => $table, ':i' => $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = app_config();
    $host = $cfg['db_host'] ?? ($cfg['mysql_host'] ?? 'localhost');
    $port = (int)($cfg['db_port'] ?? ($cfg['mysql_port'] ?? 3306));
    $dbn  = $cfg['db_name'] ?? ($cfg['mysql_db'] ?? '');
    $user = $cfg['db_user'] ?? ($cfg['mysql_user'] ?? '');
    $pass = $cfg['db_pass'] ?? ($cfg['mysql_pass'] ?? '');
    $charset = $cfg['db_charset'] ?? ($cfg['mysql_charset'] ?? 'utf8mb4');

    if (!empty($cfg['require_env_secrets']) && trim((string)$pass) === '') {
        throw new RuntimeException('Falta TURNERA_DB_PASS y require_env_secrets está activo.');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$dbn};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    migrate_if_needed($pdo);
    return $pdo;
}

function db_table_exists(PDO $pdo, string $name): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
    );
    $st->execute([':t' => $name]);
    return ((int)$st->fetchColumn()) > 0;
}

function db_column_exists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $st->execute([':t' => $table, ':c' => $column]);
    return ((int)$st->fetchColumn()) > 0;
}

function ensure_multibranch_schema(PDO $pdo): void {
    if (!db_table_exists($pdo, 'branches')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS branches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            business_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            address VARCHAR(255) DEFAULT '',
            maps_url TEXT,
            whatsapp_phone VARCHAR(64) DEFAULT '',
            owner_email VARCHAR(255) DEFAULT '',
            instagram_url TEXT,
            logo_path VARCHAR(255) DEFAULT '',
            cover_path VARCHAR(255) DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            whatsapp_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
            whatsapp_reminder_minutes INT NOT NULL DEFAULT 1440,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    $branchAdds = [
        'is_active' => "ALTER TABLE branches ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        'whatsapp_reminder_enabled' => "ALTER TABLE branches ADD COLUMN whatsapp_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'whatsapp_reminder_minutes' => "ALTER TABLE branches ADD COLUMN whatsapp_reminder_minutes INT NOT NULL DEFAULT 1440",
        'owner_email' => "ALTER TABLE branches ADD COLUMN owner_email VARCHAR(255) DEFAULT ''",
        'logo_path' => "ALTER TABLE branches ADD COLUMN logo_path VARCHAR(255) DEFAULT ''",
        'cover_path' => "ALTER TABLE branches ADD COLUMN cover_path VARCHAR(255) DEFAULT ''",
    ];
    foreach ($branchAdds as $col => $sql) {
        if (!db_column_exists($pdo, 'branches', $col)) {
            $pdo->exec($sql);
        }
    }

    if (db_table_exists($pdo, 'users')) {
        $userAdds = [
            'is_active' => "ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
            'all_branches' => "ALTER TABLE users ADD COLUMN all_branches TINYINT(1) NOT NULL DEFAULT 1",
            'can_branches' => "ALTER TABLE users ADD COLUMN can_branches TINYINT(1) NOT NULL DEFAULT 1",
            'can_settings' => "ALTER TABLE users ADD COLUMN can_settings TINYINT(1) NOT NULL DEFAULT 1",
            'can_appointments' => "ALTER TABLE users ADD COLUMN can_appointments TINYINT(1) NOT NULL DEFAULT 1",
            'can_barbers' => "ALTER TABLE users ADD COLUMN can_barbers TINYINT(1) NOT NULL DEFAULT 1",
            'can_services' => "ALTER TABLE users ADD COLUMN can_services TINYINT(1) NOT NULL DEFAULT 1",
            'can_hours' => "ALTER TABLE users ADD COLUMN can_hours TINYINT(1) NOT NULL DEFAULT 1",
            'can_blocks' => "ALTER TABLE users ADD COLUMN can_blocks TINYINT(1) NOT NULL DEFAULT 1",
            'can_system' => "ALTER TABLE users ADD COLUMN can_system TINYINT(1) NOT NULL DEFAULT 1",
            'can_analytics' => "ALTER TABLE users ADD COLUMN can_analytics TINYINT(1) NOT NULL DEFAULT 1",
        ];
        foreach ($userAdds as $col => $sql) {
            if (!db_column_exists($pdo, 'users', $col)) {
                $pdo->exec($sql);
            }
        }

        if (!db_table_exists($pdo, 'user_branch_access')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_branch_access (
                business_id INT NOT NULL,
                user_id INT NOT NULL,
                branch_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (business_id, user_id, branch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }
    }

    $tables = ['appointments', 'profesionales', 'blocks', 'business_hours', 'barber_hours', 'barber_timeoff', 'expenses'];
    foreach ($tables as $table) {
        if (db_table_exists($pdo, $table) && !db_column_exists($pdo, $table, 'branch_id')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN branch_id INT NOT NULL DEFAULT 1");
        }
    }

    if (db_table_exists($pdo, 'barber_hours') && !db_mysql_index_exists($pdo, 'barber_hours', 'idx_bh_unique')) {
        try {
            $pdo->exec("ALTER TABLE barber_hours ADD UNIQUE KEY idx_bh_unique (business_id, branch_id, professional_id, weekday)");
        } catch (Throwable $e) {
        }
    }

    if (db_table_exists($pdo, 'blocks') && !db_column_exists($pdo, 'blocks', 'reason')) {
        try {
            $pdo->exec("ALTER TABLE blocks ADD COLUMN reason VARCHAR(255) DEFAULT ''");
        } catch (Throwable $e) {
        }
    }
}

function migrate_if_needed(PDO $pdo): void {
    $mysqlColumnExists = function(PDO $pdo, string $table, string $column): bool {
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :t
                   AND COLUMN_NAME = :c"
            );
            $st->execute([':t' => $table, ':c' => $column]);
            return ((int)$st->fetchColumn()) > 0;
        } catch (Throwable $e) {
            return false;
        }
    };

    $sql = "
CREATE TABLE IF NOT EXISTS meta (
  `key` VARCHAR(190) PRIMARY KEY,
  `value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_templates (
  id INT NOT NULL AUTO_INCREMENT,
  business_id INT NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
  event_key VARCHAR(64) NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_msg_tpl (business_id, channel, event_key),
  KEY idx_msg_tpl_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS businesses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  owner_email VARCHAR(255) DEFAULT '',
  address VARCHAR(255) DEFAULT '',
  maps_url TEXT,
  whatsapp_phone VARCHAR(64) DEFAULT '',
  logo_path VARCHAR(255) DEFAULT '',
  cover_path VARCHAR(255) DEFAULT '',
  instagram_url TEXT,
  intro_text TEXT,
  timezone VARCHAR(64) DEFAULT 'America/Argentina/Buenos_Aires',
  slot_minutes INT NOT NULL DEFAULT 15,
  slot_capacity INT NOT NULL DEFAULT 1,
  cancel_notice_minutes INT NOT NULL DEFAULT 0,
  pay_deadline_minutes INT NOT NULL DEFAULT 0,
  payment_mode VARCHAR(16) NOT NULL DEFAULT 'OFF',
  deposit_percent_default INT NOT NULL DEFAULT 30,
  mp_connected TINYINT(1) NOT NULL DEFAULT 0,
  mp_user_id VARCHAR(64) DEFAULT '',
  mp_access_token TEXT,
  mp_refresh_token TEXT,
  mp_token_expires_at DATETIME NULL,
  customer_choose_barber TINYINT(1) NOT NULL DEFAULT 1,
  smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  smtp_host VARCHAR(255) DEFAULT '',
  smtp_port INT DEFAULT 587,
  smtp_user VARCHAR(255) DEFAULT '',
  smtp_pass VARCHAR(255) DEFAULT '',
  smtp_secure VARCHAR(16) DEFAULT '',
  smtp_from_email VARCHAR(255) DEFAULT '',
  smtp_from_name VARCHAR(255) DEFAULT '',
  public_base_url TEXT,
  theme_primary VARCHAR(16) DEFAULT '#2563eb',
  theme_accent VARCHAR(16) DEFAULT '#2563eb',
  reminder_minutes INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  address VARCHAR(255) DEFAULT '',
  maps_url TEXT,
  whatsapp_phone VARCHAR(64) DEFAULT '',
  owner_email VARCHAR(255) DEFAULT '',
  instagram_url TEXT,
  logo_path VARCHAR(255) DEFAULT '',
  cover_path VARCHAR(255) DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  whatsapp_reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
  whatsapp_reminder_minutes INT NOT NULL DEFAULT 1440,
  smtp_host VARCHAR(255) DEFAULT '',
  smtp_port INT NOT NULL DEFAULT 587,
  smtp_user VARCHAR(255) DEFAULT '',
  smtp_pass VARCHAR(255) DEFAULT '',
  smtp_secure VARCHAR(16) DEFAULT '',
  smtp_from_email VARCHAR(255) DEFAULT '',
  smtp_from_name VARCHAR(255) DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_br_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_br_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  username VARCHAR(64) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  security_question VARCHAR(80) NOT NULL DEFAULT '',
  security_answer_hash VARCHAR(255) NOT NULL DEFAULT '',
  role VARCHAR(32) NOT NULL DEFAULT 'admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  all_branches TINYINT(1) NOT NULL DEFAULT 1,
  can_branches TINYINT(1) NOT NULL DEFAULT 1,
  can_settings TINYINT(1) NOT NULL DEFAULT 1,
  can_appointments TINYINT(1) NOT NULL DEFAULT 1,
  can_barbers TINYINT(1) NOT NULL DEFAULT 1,
  can_services TINYINT(1) NOT NULL DEFAULT 1,
  can_hours TINYINT(1) NOT NULL DEFAULT 1,
  can_blocks TINYINT(1) NOT NULL DEFAULT 1,
  can_system TINYINT(1) NOT NULL DEFAULT 1,
  can_analytics TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users (business_id, username),
  CONSTRAINT fk_users_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_branch_access (
  business_id INT NOT NULL,
  user_id INT NOT NULL,
  branch_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (business_id, user_id, branch_id),
  INDEX idx_uba_user (user_id),
  INDEX idx_uba_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  duration_minutes INT NOT NULL,
  price_ars INT NOT NULL DEFAULT 0,
  deposit_percent_override INT NULL,
  image_url TEXT,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  avatar_path VARCHAR(255) DEFAULT '',
  cover_path VARCHAR(255) DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_services_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_services_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profesionales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(60) NULL,
  email VARCHAR(190) NULL,
  capacity INT NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME NULL,
  avatar_path VARCHAR(255) DEFAULT '',
  cover_path VARCHAR(255) DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_barbers_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_barbers_business (business_id),
  INDEX idx_barbers_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_profesionales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  service_id INT NOT NULL,
  professional_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_service_barber (business_id, branch_id, service_id, professional_id),
  INDEX idx_sb_service (service_id),
  INDEX idx_sb_barber (professional_id),
  CONSTRAINT fk_sb_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS barber_timeoff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL,
  professional_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  reason VARCHAR(255) DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_timeoff_range (business_id, branch_id, professional_id, start_date, end_date),
  CONSTRAINT fk_timeoff_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  CONSTRAINT fk_timeoff_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_timeoff_prof FOREIGN KEY (professional_id) REFERENCES profesionales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_hours (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  weekday INT NOT NULL,
  open_time VARCHAR(8) DEFAULT NULL,
  close_time VARCHAR(8) DEFAULT NULL,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_bh (business_id, branch_id, weekday),
  CONSTRAINT fk_bh_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS barber_hours (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  professional_id INT NOT NULL,
  weekday INT NOT NULL,
  open_time VARCHAR(8) DEFAULT NULL,
  close_time VARCHAR(8) DEFAULT NULL,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_barber_hours (business_id, branch_id, professional_id, weekday),
  INDEX idx_barber_hours (business_id, branch_id, professional_id, weekday),
  CONSTRAINT fk_bh2_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  professional_id INT DEFAULT NULL,
  title VARCHAR(255) DEFAULT '',
  reason VARCHAR(255) DEFAULT '',
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_blocks_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_blocks_range (business_id, branch_id, start_at, end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  professional_id INT NOT NULL,
  service_id INT NOT NULL,
  customer_name VARCHAR(255) NOT NULL,
  customer_phone VARCHAR(64) NOT NULL,
  customer_email VARCHAR(255) DEFAULT '',
  notes TEXT,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  status VARCHAR(32) NOT NULL,
  token VARCHAR(64) NOT NULL,
  requested_start_at DATETIME NULL,
  requested_end_at DATETIME NULL,
  requested_at DATETIME NULL,
  requested_professional_id INT NULL,
  requested_service_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  cancelled_at DATETIME NULL,
  reminder_sent_at DATETIME NULL,
  reminder_skipped_at DATETIME NULL,
  payment_status VARCHAR(16) NOT NULL DEFAULT 'none',
  payment_mode VARCHAR(16) NOT NULL DEFAULT 'none',
  payment_amount_ars INT NOT NULL DEFAULT 0,
  payment_expires_at DATETIME NULL,
  mp_preference_id VARCHAR(255) DEFAULT '',
  mp_payment_id VARCHAR(255) DEFAULT '',
  paid_at DATETIME NULL,
  reminder_last_error TEXT,
  UNIQUE KEY uq_token (business_id, token),
  INDEX idx_appt_range (business_id, branch_id, start_at, end_at),
  INDEX idx_appt_status (business_id, status),
  CONSTRAINT fk_appt_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 0,
  expense_date DATE NOT NULL,
  category VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  amount_ars INT NOT NULL DEFAULT 0,
  is_recurring TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_expenses_biz_date (business_id, expense_date),
  INDEX idx_expenses_branch (business_id, branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  appointment_id INT NOT NULL,
  actor_type VARCHAR(32) NOT NULL DEFAULT 'system',
  actor_user_id INT NULL,
  event_type VARCHAR(64) NOT NULL,
  note TEXT,
  meta_json LONGTEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_appt_events_appt (business_id, appointment_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $pdo->exec($stmt);
    }

    if (!$mysqlColumnExists($pdo, 'branches', 'is_active')) {
        try { $pdo->exec("ALTER TABLE branches ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    }
    if (!$mysqlColumnExists($pdo, 'users', 'is_active')) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    }
    if (!$mysqlColumnExists($pdo, 'users', 'all_branches')) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN all_branches TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    }
    foreach ([
        'email' => "ALTER TABLE users ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT ''",
        'security_question' => "ALTER TABLE users ADD COLUMN security_question VARCHAR(80) NOT NULL DEFAULT ''",
        'security_answer_hash' => "ALTER TABLE users ADD COLUMN security_answer_hash VARCHAR(255) NOT NULL DEFAULT ''",
    ] as $col => $sql) {
        if (!$mysqlColumnExists($pdo, 'users', $col)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }
    foreach ([
        'phone' => "ALTER TABLE profesionales ADD COLUMN phone VARCHAR(60) NULL",
        'email' => "ALTER TABLE profesionales ADD COLUMN email VARCHAR(190) NULL",
        'deleted_at' => "ALTER TABLE profesionales ADD COLUMN deleted_at DATETIME NULL",
    ] as $col => $sql) {
        if (!$mysqlColumnExists($pdo, 'profesionales', $col)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }

    $permCols = [
        'can_branches' => 0,
        'can_settings' => 0,
        'can_appointments' => 1,
        'can_barbers' => 0,
        'can_services' => 0,
        'can_hours' => 0,
        'can_blocks' => 0,
        'can_system' => 0,
        'can_analytics' => 0,
    ];
    foreach ($permCols as $col => $def) {
        if (!$mysqlColumnExists($pdo, 'users', $col)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN {$col} TINYINT(1) NOT NULL DEFAULT {$def}"); } catch (Throwable $e) {}
        }
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_branch_access (
            business_id INT NOT NULL,
            user_id INT NOT NULL,
            branch_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (business_id, user_id, branch_id),
            INDEX idx_uba_user (business_id, user_id),
            INDEX idx_uba_branch (business_id, branch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
    }

    $cfg = app_config();
    $bid = (int)($cfg['business_id'] ?? 1);

    $exists = $pdo->prepare("SELECT id FROM businesses WHERE id=?");
    $exists->execute([$bid]);
    if (!$exists->fetch()) {
        $ins = $pdo->prepare("INSERT INTO businesses (id, name, timezone, slot_minutes, slot_capacity, payment_mode, deposit_percent_default) VALUES (?,?,?,?,?,?,?)");
        $ins->execute([$bid, 'Turnera Demo', ($cfg['timezone'] ?? 'America/Argentina/Buenos_Aires'), (int)($cfg['slot_minutes'] ?? 15), 1, 'OFF', 30]);

        $pdo->prepare("INSERT INTO branches (business_id, name) VALUES (?,?)")->execute([$bid, 'Sucursal Principal']);

        for ($wd = 0; $wd <= 6; $wd++) {
            $isClosed = ($wd === 0) ? 1 : 0;
            $open = ($isClosed ? null : '09:00');
            $close = ($isClosed ? null : '19:00');
            $pdo->prepare("INSERT INTO business_hours (business_id, branch_id, weekday, open_time, close_time, is_closed) VALUES (?,?,?,?,?,?)")
                ->execute([$bid, 1, $wd, $open, $close, $isClosed]);
        }
    }
}

/**
 * Asegura (idempotente) que exista la tabla branches y las columnas branch_id.
 * No depende del schema_version y no rompe si ya existe.
 */

// ensure_multibranch_schema() duplicate removed (fix)

function seed_demo_data(PDO $pdo): void {
    $cfg = app_config();

    $has = (int)$pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn();
    if ($has > 0) {
        return;
    }

    $pdo->prepare('INSERT INTO businesses (id, name, address, whatsapp_phone, timezone, slot_minutes)
                   VALUES (1, :name, :addr, :wa, :tz, :slot)')
        ->execute([
            ':name' => 'Turnera Demo',
            ':addr' => 'Av. Siempre Viva 123, Quilmes',
            ':wa' => '54911XXXXXXXX',
            ':tz' => $cfg['timezone'],
            ':slot' => (int)$cfg['slot_minutes'],
        ]);

    $pdo->prepare('INSERT INTO branches (business_id, name, address, maps_url, whatsapp_phone, is_active)
                   VALUES (1, :n, :a, :m, :w, 1)')
        ->execute([
            ':n' => 'Sucursal Principal',
            ':a' => 'Av. Siempre Viva 123, Quilmes',
            ':m' => '',
            ':w' => '54911XXXXXXXX',
        ]);

    for ($w = 0; $w <= 6; $w++) {
        if ($w === 0) {
            $pdo->prepare('INSERT INTO business_hours (business_id, branch_id, weekday, is_closed) VALUES (1, 1, :w, 1)')
                ->execute([':w' => $w]);
        } else {
            $pdo->prepare('INSERT INTO business_hours (business_id, branch_id, weekday, open_time, close_time, is_closed)
                           VALUES (1, 1, :w, :o, :c, 0)')
                ->execute([':w' => $w, ':o' => '10:00', ':c' => '20:00']);
        }
    }

    $pdo->exec("INSERT INTO profesionales (business_id, branch_id, name, is_active) VALUES (1,1,'Profesional 1',1), (1,1,'Profesional 2',1)");
    $bh = $pdo->query("SELECT weekday, open_time, close_time, is_closed FROM business_hours WHERE business_id=1 AND branch_id=1")->fetchAll();
    $profesionales = $pdo->query("SELECT id FROM profesionales WHERE business_id=1 AND branch_id=1 ORDER BY id")->fetchAll();
    $ins = $pdo->prepare("INSERT INTO barber_hours (business_id, branch_id, professional_id, weekday, open_time, close_time, is_closed)
                           VALUES (1,1,:pid,:w,:o,:c,:closed)
                           ON DUPLICATE KEY UPDATE open_time=VALUES(open_time), close_time=VALUES(close_time), is_closed=VALUES(is_closed), updated_at=CURRENT_TIMESTAMP");
    foreach ($profesionales as $b) {
        foreach ($bh as $h) {
            $ins->execute([
                ':pid' => (int)$b['id'],
                ':w' => (int)$h['weekday'],
                ':o' => $h['open_time'],
                ':c' => $h['close_time'],
                ':closed' => (int)$h['is_closed'],
            ]);
        }
    }

    $services = [
        ['Corte', 'Corte clásico o moderno. Incluye lavado y terminación.', 30, 12000, '../assets/services/corte.svg'],
        ['Corte + Barba', 'Combo completo: corte + perfilado/arreglo de barba.', 60, 18000, '../assets/services/corte_barba.svg'],
        ['Barba', 'Perfilado y arreglo de barba. Incluye toalla caliente.', 30, 9000, '../assets/services/barba.svg'],
        ['Tintura', 'Color / decoloración. Consultá por tonos y mantenimiento.', 90, 25000, '../assets/services/tintura.svg'],
    ];
    $stmt = $pdo->prepare('INSERT INTO services (business_id, name, description, duration_minutes, price_ars, image_url, is_active)
                           VALUES (1, :n, :desc, :d, :p, :img, 1)');
    foreach ($services as $s) {
        $stmt->execute([
            ':n' => $s[0],
            ':desc' => $s[1],
            ':d' => (int)$s[2],
            ':p' => (int)$s[3],
            ':img' => $s[4],
        ]);
    }
}

function ensure_payment_schema(PDO $pdo): void {
    if (!db_column_exists($pdo, 'businesses', 'payment_mode')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN payment_mode VARCHAR(16) NOT NULL DEFAULT 'OFF'");
    }
    if (!db_column_exists($pdo, 'businesses', 'deposit_percent_default')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN deposit_percent_default INT NOT NULL DEFAULT 30");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_connected')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_connected TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_user_id')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_user_id VARCHAR(64) DEFAULT ''");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_access_token')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_access_token TEXT");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_refresh_token')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_refresh_token TEXT");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_token_expires_at')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_token_expires_at DATETIME NULL");
    }

    if (db_table_exists($pdo, 'services') && !db_column_exists($pdo, 'services', 'deposit_percent_override')) {
        $pdo->exec("ALTER TABLE services ADD COLUMN deposit_percent_override INT NULL");
    }

    if (db_table_exists($pdo, 'appointments')) {
        if (!db_column_exists($pdo, 'appointments', 'payment_status')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN payment_status VARCHAR(16) NOT NULL DEFAULT 'none'");
        }
        if (!db_column_exists($pdo, 'appointments', 'payment_mode')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN payment_mode VARCHAR(16) NOT NULL DEFAULT 'none'");
        }
        if (!db_column_exists($pdo, 'appointments', 'payment_amount_ars')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN payment_amount_ars INT NOT NULL DEFAULT 0");
        }
        if (!db_column_exists($pdo, 'appointments', 'payment_expires_at')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN payment_expires_at DATETIME NULL");
        }
        if (!db_column_exists($pdo, 'appointments', 'mp_preference_id')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN mp_preference_id VARCHAR(255) DEFAULT ''");
        }
        if (!db_column_exists($pdo, 'appointments', 'mp_payment_id')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN mp_payment_id VARCHAR(255) DEFAULT ''");
        }
        if (!db_column_exists($pdo, 'appointments', 'paid_at')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN paid_at DATETIME NULL");
        }
        if (!db_column_exists($pdo, 'appointments', 'reminder_skipped_at')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN reminder_skipped_at DATETIME NULL");
        }
    }
}

function expire_pending_payments(PDO $pdo): void {
    // Expire pending payments after deadline.
    // Only affects slots that were created as payment-required reservations.
    $pdo->prepare("UPDATE appointments
                   SET status='VENCIDO', payment_status='expired', updated_at=CURRENT_TIMESTAMP
                   WHERE status='PENDIENTE_PAGO'
                     AND payment_status='pending'
                     AND payment_expires_at IS NOT NULL
                     AND payment_expires_at <= NOW()")
        ->execute();
}

function expire_pending_bookings(PDO $pdo): void {
    // v1: invalidamos enlaces de turnos ya pasados.
    // El registro puede seguir existiendo para historial, pero el link deja de ser válido.
        $pdo->prepare("UPDATE appointments
                   SET status='VENCIDO', updated_at=CURRENT_TIMESTAMP
                   WHERE status IN ('PENDIENTE_APROBACION','ACEPTADO','REPROGRAMACION_PENDIENTE')
                     AND end_at <= NOW()")
        ->execute();
}
