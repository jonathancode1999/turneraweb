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
        if ($pdo) return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    } catch (Throwable $e) { /* ignore */ }
    $cfg = app_config();
    $driver = $cfg['db_driver'] ?? 'mysql';
    return $driver === 'mysql';
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

    // Default to mysql in production; sqlite remains supported for local/dev.
    $driver = $cfg['db_driver'] ?? 'mysql';
    if ($driver === 'mysql') {
        $host = $cfg['mysql_host'] ?? '127.0.0.1';
        $port = (int)($cfg['mysql_port'] ?? 3306);
        $dbn  = $cfg['mysql_db'] ?? '';
        $user = $cfg['mysql_user'] ?? '';
        $pass = $cfg['mysql_pass'] ?? '';
        $charset = $cfg['mysql_charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbn};charset={$charset}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        migrate_if_needed($pdo); // MySQL schema bootstrap
        return $pdo;
    }

    // SQLite fallback (dev only)
    $dsn = 'sqlite:' . $cfg['sqlite_path'];

    $dir = dirname($cfg['sqlite_path']);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    migrate_if_needed($pdo);
    return $pdo;
}

// Helpers: table / column existence (works for both MySQL and SQLite)
function db_table_exists(PDO $pdo, string $name): bool {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
        );
        $st->execute([':t' => $name]);
        return ((int)$st->fetchColumn()) > 0;
    }

    // SQLite
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n LIMIT 1");
    $st->execute([':n' => $name]);
    return (bool)$st->fetchColumn();
}

function db_column_exists(PDO $pdo, string $table, string $column): bool {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $st->execute([':t' => $table, ':c' => $column]);
        return ((int)$st->fetchColumn()) > 0;
    }

    // SQLite
    $st = $pdo->prepare("PRAGMA table_info('" . str_replace("'","''",$table) . "')");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        if (isset($r['name']) && $r['name'] === $column) return true;
    }
    return false;
}

function ensure_multibranch_schema(PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // MySQL: use INFORMATION_SCHEMA checks (avoid SQLite-only PRAGMA/sqlite_master that would 500)
    if ($driver === 'mysql') {
        // Branches table
        if (!db_table_exists($pdo, 'branches')) {
            // migrate_if_needed() should have created it; keep a minimal safety net.
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
            if (!db_column_exists($pdo, 'branches', $col)) { $pdo->exec($sql); }
        }

        // Users table / permissions
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
                if (!db_column_exists($pdo, 'users', $col)) { $pdo->exec($sql); }
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

        // Core tables need branch_id
        $tables = ['appointments','profesionales','blocks','business_hours','barber_hours','barber_timeoff','expenses'];
        foreach ($tables as $t) {
            if (db_table_exists($pdo, $t) && !db_column_exists($pdo, $t, 'branch_id')) {
                $pdo->exec("ALTER TABLE {$t} ADD COLUMN branch_id INT NOT NULL DEFAULT 1");
            }
        }

        

// Ensure barber_hours UNIQUE index for upserts (MySQL)
if (db_table_exists($pdo, 'barber_hours') && !db_mysql_index_exists($pdo, 'barber_hours', 'idx_bh_unique')) {
    try {
        $pdo->exec("ALTER TABLE barber_hours ADD UNIQUE KEY idx_bh_unique (business_id, branch_id, professional_id, weekday)");
    } catch (Throwable $e) { /* ignore if already exists */ }
}

// Ensure blocks.reason column (used by quick blocks UI)
if (db_table_exists($pdo, 'blocks') && !db_column_exists($pdo, 'blocks', 'reason')) {
    try {
        $pdo->exec("ALTER TABLE blocks ADD COLUMN reason TEXT");
    } catch (Throwable $e) { /* ignore */ }
}

        return;
    }

    // Defensive "healing" for partially migrated DBs:
    // ensure branches table + branch_id columns exist even if schema_version is already bumped.
    if (!db_table_exists($pdo, 'branches')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS branches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            address TEXT NOT NULL,
            whatsapp_phone TEXT,
            maps_url TEXT,
            instagram_url TEXT,
            owner_email TEXT DEFAULT '',
            logo_path TEXT DEFAULT '',
            cover_path TEXT DEFAULT '',
            smtp_enabled INTEGER NOT NULL DEFAULT 0,
            smtp_host TEXT DEFAULT '',
            smtp_port INTEGER NOT NULL DEFAULT 587,
            smtp_user TEXT DEFAULT '',
            smtp_pass TEXT DEFAULT '',
            smtp_secure TEXT DEFAULT 'tls',
            smtp_from_email TEXT DEFAULT '',
            smtp_from_name TEXT DEFAULT '',
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now'))
        )");

    // WhatsApp reminder settings per branch
    $branchCols = $pdo->query("PRAGMA table_info(branches)")->fetchAll(PDO::FETCH_ASSOC);
    $bHave = [];
    foreach ($branchCols as $c) { $bHave[$c['name']] = true; }
    if (!isset($bHave['whatsapp_reminder_enabled'])) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN whatsapp_reminder_enabled INTEGER NOT NULL DEFAULT 0");
    }
    if (!isset($bHave['whatsapp_reminder_minutes'])) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN whatsapp_reminder_minutes INTEGER NOT NULL DEFAULT 1440");
    }

    }

    // Ensure branches table has the columns used by the admin UI (idempotent)
    $branchCols = $pdo->query("PRAGMA table_info(branches)")->fetchAll();
    $haveBr = array();
    foreach ($branchCols as $c) { $haveBr[$c['name']] = true; }
    $toAdd = array(
        'owner_email' => "ALTER TABLE branches ADD COLUMN owner_email TEXT DEFAULT ''",
        'logo_path' => "ALTER TABLE branches ADD COLUMN logo_path TEXT DEFAULT ''",
        'cover_path' => "ALTER TABLE branches ADD COLUMN cover_path TEXT DEFAULT ''",
        'smtp_enabled' => "ALTER TABLE branches ADD COLUMN smtp_enabled INTEGER NOT NULL DEFAULT 0",
        'smtp_host' => "ALTER TABLE branches ADD COLUMN smtp_host TEXT DEFAULT ''",
        'smtp_port' => "ALTER TABLE branches ADD COLUMN smtp_port INTEGER NOT NULL DEFAULT 587",
        'smtp_user' => "ALTER TABLE branches ADD COLUMN smtp_user TEXT DEFAULT ''",
        'smtp_pass' => "ALTER TABLE branches ADD COLUMN smtp_pass TEXT DEFAULT ''",
        'smtp_secure' => "ALTER TABLE branches ADD COLUMN smtp_secure TEXT DEFAULT 'tls'",
        'smtp_from_email' => "ALTER TABLE branches ADD COLUMN smtp_from_email TEXT DEFAULT ''",
        'smtp_from_name' => "ALTER TABLE branches ADD COLUMN smtp_from_name TEXT DEFAULT ''",
    );
    foreach ($toAdd as $col => $sql) {
        if (!isset($haveBr[$col])) {
            $pdo->exec($sql);
        }
    }

    // Ensure core tables have branch_id
    // NOTE: services are global per business (not per branch), so we DO NOT add branch_id to services.
    $tables = array('appointments','profesionales','blocks','business_hours','barber_hours','barber_timeoff');
    foreach ($tables as $t) {
        if (!db_table_exists($pdo, $t)) continue;
        $cols = $pdo->query("PRAGMA table_info(" . $t . ")")->fetchAll();
        $has = false;
        foreach ($cols as $c) { if ($c['name'] === 'branch_id') { $has = true; break; } }
        if (!$has) {
            $pdo->exec("ALTER TABLE " . $t . " ADD COLUMN branch_id INTEGER NOT NULL DEFAULT 1");
        }
    }

    // --- Users / permissions (MVP) ---
    if (db_table_exists($pdo, 'users')) {
        $uCols = $pdo->query("PRAGMA table_info(users)")->fetchAll();
        $haveU = array();
        foreach ($uCols as $c) { $haveU[$c['name']] = true; }

        // Soft-delete / activation
        if (!isset($haveU['is_active'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
        }

        // Branch scoping: if all_branches=1, user can access every branch.
        if (!isset($haveU['all_branches'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN all_branches INTEGER NOT NULL DEFAULT 1");
        }

        // Permissions (0/1). Admin role still implies everything.
        $permCols = array(
            'can_branches' => 0,
            'can_settings' => 0,
            'can_appointments' => 1,
            'can_barbers' => 0,
            'can_services' => 0,
            'can_hours' => 0,
            'can_blocks' => 0,
            'can_system' => 0,
            'can_analytics' => 0,
        );
        foreach ($permCols as $col => $def) {
            if (!isset($haveU[$col])) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$col} INTEGER NOT NULL DEFAULT {$def}");
            }
        }

        // Many-to-many user ↔ branches (when all_branches=0)
        if (!db_table_exists($pdo, 'user_branch_access')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_branch_access (
                business_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                branch_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (business_id, user_id, branch_id)
            )");
        }

        // Backfill: if legacy users.branch_id existed, convert to mapping
        if (isset($haveU['branch_id'])) {
            $legacy = $pdo->query("SELECT id, branch_id, role FROM users WHERE branch_id IS NOT NULL AND branch_id>0")->fetchAll();
            foreach ($legacy as $r) {
                $uid = (int)$r['id'];
                $brid = (int)$r['branch_id'];
                $pdo->prepare("INSERT OR IGNORE INTO user_branch_access (business_id, user_id, branch_id) VALUES (1, :uid, :brid)")
                    ->execute(array(':uid'=>$uid, ':brid'=>$brid));
            }
            // Keep column for compatibility, but move users to all_branches=0 when branch_id was set.
            $pdo->exec("UPDATE users SET all_branches=0 WHERE branch_id IS NOT NULL AND branch_id>0");
        }

        // Ensure at least the main admin has full permissions
        $pdo->exec("UPDATE users SET can_branches=1,can_settings=1,can_appointments=1,can_barbers=1,can_services=1,can_hours=1,can_blocks=1,can_system=1,can_analytics=1,all_branches=1 WHERE role='admin'");
    }

  // --- Business theming + notification config ---
  if (db_table_exists($pdo, 'businesses')) {
    $bCols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
    $haveB = array();
    foreach ($bCols as $c) { $haveB[$c['name']] = true; }

    $bAdd = array(
      // Theme (CSS variables)
      'theme_primary' => "ALTER TABLE businesses ADD COLUMN theme_primary TEXT DEFAULT '#2D7BD1'",
      'theme_accent'  => "ALTER TABLE businesses ADD COLUMN theme_accent TEXT DEFAULT '#0EA5E9'",
      // Reminder config: 0=off, 120=2h, 1440=24h (minutes before start)
      'reminder_minutes' => "ALTER TABLE businesses ADD COLUMN reminder_minutes INTEGER NOT NULL DEFAULT 0",
    );
    foreach ($bAdd as $col => $sql) {
      if (!isset($haveB[$col])) {
        $pdo->exec($sql);
      }
    }
  }

  // --- Appointment timeline/events ---
  if (!db_table_exists($pdo, 'appointment_events')) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS appointment_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      business_id INTEGER NOT NULL,
      branch_id INTEGER NOT NULL DEFAULT 1,
      appointment_id INTEGER NOT NULL,
      actor_type TEXT NOT NULL DEFAULT 'system', -- system/admin/customer
      actor_user_id INTEGER,
      event_type TEXT NOT NULL,
      note TEXT DEFAULT '',
      meta_json TEXT DEFAULT '',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_appt_events_appt ON appointment_events(business_id, appointment_id, created_at)");
  }

    // Ensure barber_hours has the correct UNIQUE constraint for multi-branch.
    // Old schema used UNIQUE(business_id, professional_id, weekday) which breaks when a profesional exists in multiple branches
    // and also breaks ON CONFLICT targets that include branch_id.
    if (db_table_exists($pdo, 'barber_hours')) {
        $needsRebuild = true;
        try {
            $idx = $pdo->query("PRAGMA index_list(barber_hours)")->fetchAll();
            foreach ($idx as $i) {
                if ((int)($i['unique'] ?? 0) !== 1) continue;
                $iname = (string)($i['name'] ?? '');
                if ($iname === '') continue;
                $cols = $pdo->query("PRAGMA index_info(" . $iname . ")")->fetchAll();
                $names = array();
                foreach ($cols as $c) { $names[] = (string)$c['name']; }
                // Accept either exact match or a superset that includes these 4 columns.
                $want = array('business_id','branch_id','professional_id','weekday');
                $ok = true;
                foreach ($want as $w) { if (!in_array($w, $names, true)) { $ok = false; break; } }
                if ($ok) { $needsRebuild = false; break; }
            }
        } catch (Throwable $e) {
            $needsRebuild = true;
        }

        if ($needsRebuild) {
            $pdo->beginTransaction();
            try {
                // Create new table with the right unique.
                $pdo->exec("CREATE TABLE IF NOT EXISTS barber_hours_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL,
                    branch_id INTEGER NOT NULL DEFAULT 1,
                    professional_id INTEGER NOT NULL,
                    weekday INTEGER NOT NULL,
                    open_time TEXT NOT NULL,
                    close_time TEXT NOT NULL,
                    is_closed INTEGER NOT NULL DEFAULT 0,
                    UNIQUE(business_id, branch_id, professional_id, weekday)
                )");

                // Copy data. If old table lacks branch_id, default to 1.
                $cols = $pdo->query("PRAGMA table_info(barber_hours)")->fetchAll();
                $hasBranch = false;
                foreach ($cols as $c) { if ($c['name'] === 'branch_id') { $hasBranch = true; break; } }
                if ($hasBranch) {
                    $pdo->exec("INSERT OR IGNORE INTO barber_hours_new (id,business_id,branch_id,professional_id,weekday,open_time,close_time,is_closed)
                               SELECT id,business_id,branch_id,professional_id,weekday,open_time,close_time,is_closed FROM barber_hours");
                } else {
                    $pdo->exec("INSERT OR IGNORE INTO barber_hours_new (id,business_id,branch_id,professional_id,weekday,open_time,close_time,is_closed)
                               SELECT id,business_id,1,professional_id,weekday,open_time,close_time,is_closed FROM barber_hours");
                }

                $pdo->exec("DROP TABLE barber_hours");
                $pdo->exec("ALTER TABLE barber_hours_new RENAME TO barber_hours");
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                // Keep running; the app will still work for single-branch.
            }
        }
    }

    // barber_hours: for multi-branch we need the UNIQUE constraint to include branch_id.
    // Older DBs may have UNIQUE(business_id, professional_id, weekday) which prevents using the same
    // professional_id across branches and also breaks inserts in the admin.
    if (db_table_exists($pdo, 'barber_hours')) {
        $needRebuild = false;
        try {
            $idx = $pdo->query("PRAGMA index_list(barber_hours)")->fetchAll();
            $foundUnique = false;
            foreach ($idx as $i) {
                if ((int)($i['unique'] ?? 0) !== 1) continue;
                $iname = (string)($i['name'] ?? '');
                $cols = $pdo->query("PRAGMA index_info(" . $iname . ")")->fetchAll();
                $names = array();
                foreach ($cols as $c) { $names[] = (string)$c['name']; }
                // exact order we expect
                if ($names === array('business_id','branch_id','professional_id','weekday')) {
                    $foundUnique = true;
                    break;
                }
            }
            if (!$foundUnique) $needRebuild = true;
        } catch (Throwable $e) {
            $needRebuild = true;
        }

        if ($needRebuild) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->beginTransaction();
            try {
                // Create new table with correct UNIQUE
                $pdo->exec("CREATE TABLE IF NOT EXISTS barber_hours_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL,
                    branch_id INTEGER NOT NULL DEFAULT 1,
                    professional_id INTEGER NOT NULL,
                    weekday INTEGER NOT NULL,
                    open_time TEXT NOT NULL,
                    close_time TEXT NOT NULL,
                    is_closed INTEGER NOT NULL DEFAULT 0,
                    UNIQUE(business_id, branch_id, professional_id, weekday)
                )");

                // Copy rows (branch_id may not exist in old table; default to 1)
                $cols = $pdo->query("PRAGMA table_info(barber_hours)")->fetchAll();
                $hasBranch = false;
                foreach ($cols as $c) { if ($c['name'] === 'branch_id') { $hasBranch = true; break; } }
                if ($hasBranch) {
                    $pdo->exec("INSERT OR IGNORE INTO barber_hours_new (id,business_id,branch_id,professional_id,weekday,open_time,close_time,is_closed)
                               SELECT id,business_id,branch_id,professional_id,weekday,open_time,close_time,is_closed FROM barber_hours");
                } else {
                    $pdo->exec("INSERT OR IGNORE INTO barber_hours_new (id,business_id,branch_id,professional_id,weekday,open_time,close_time,is_closed)
                               SELECT id,business_id,1,professional_id,weekday,open_time,close_time,is_closed FROM barber_hours");
                }

                $pdo->exec('DROP TABLE barber_hours');
                $pdo->exec('ALTER TABLE barber_hours_new RENAME TO barber_hours');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                // leave DB usable; do not crash the app
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
    // --- Analytics / finance schema (idempotent) ---
    if (db_table_exists($pdo, 'appointments')) {
        $aCols = $pdo->query("PRAGMA table_info(appointments)")->fetchAll();
        $haveA = array();
        foreach ($aCols as $c) { $haveA[$c['name']] = true; }
        if (!isset($haveA['is_paid'])) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN is_paid INTEGER NOT NULL DEFAULT 0");
        }
        if (!isset($haveA['paid_at'])) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN paid_at TEXT DEFAULT ''");
        }
        if (!isset($haveA['price_snapshot_ars'])) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN price_snapshot_ars INTEGER NOT NULL DEFAULT 0");
        }
    }

    if (!db_table_exists($pdo, 'expenses')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_id INTEGER NOT NULL,
            branch_id INTEGER NOT NULL DEFAULT 0,
            expense_date TEXT NOT NULL,
            category TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            amount_ars INTEGER NOT NULL DEFAULT 0,
            is_recurring INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        // Add recurring support if upgrading
        $eCols = $pdo->query("PRAGMA table_info(expenses)")->fetchAll(PDO::FETCH_ASSOC);
        $haveE = array();
        foreach ($eCols as $c) { $haveE[$c['name']] = true; }
        if (!isset($haveE['is_recurring'])) {
            $pdo->exec("ALTER TABLE expenses ADD COLUMN is_recurring INTEGER NOT NULL DEFAULT 0");
        }
    }

}



function migrate_if_needed(PDO $pdo): void {
    // Detect driver
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // Helpers for MySQL "add column if missing" (avoid breaking existing installs)
    $mysql_column_exists = function(PDO $pdo, string $table, string $column): bool {
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

    if ($driver === 'mysql') {
        // Create schema (idempotent)
        $sql = "
CREATE TABLE IF NOT EXISTS meta (
  `key` VARCHAR(190) PRIMARY KEY,
  `value` TEXT
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
  password_hash VARCHAR(255) NOT NULL,
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
  capacity INT NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
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

CREATE TABLE IF NOT EXISTS business_hours (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  weekday INT NOT NULL, -- 0=Sun .. 6=Sat
  open_time VARCHAR(8) DEFAULT NULL,
  close_time VARCHAR(8) DEFAULT NULL,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_bh (business_id, branch_id, weekday),
  CONSTRAINT fk_bh_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  professional_id INT DEFAULT NULL,
  title VARCHAR(255) DEFAULT '',
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

";
        foreach (explode(";", $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }

        // Backward-compatible patches for older MySQL installs
        // (tables created before we added some columns required by the multi-branch UI)
        if (!$mysql_column_exists($pdo, 'branches', 'is_active')) {
            try { $pdo->exec("ALTER TABLE branches ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        }
        if (!$mysql_column_exists($pdo, 'users', 'is_active')) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        }
        if (!$mysql_column_exists($pdo, 'users', 'all_branches')) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN all_branches TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
        }
        // Permissions columns (used when role != admin)
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
            if (!$mysql_column_exists($pdo, 'users', $col)) {
                try { $pdo->exec("ALTER TABLE users ADD COLUMN {$col} TINYINT(1) NOT NULL DEFAULT {$def}"); } catch (Throwable $e) {}
            }
        }
        // Many-to-many user ↔ branches (when all_branches=0)
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
        } catch (Throwable $e) {}

        // Ensure a default business exists for demos (business_id=1)
        $cfg = app_config();
        $bid = (int)($cfg['business_id'] ?? 1);

        $exists = $pdo->prepare("SELECT id FROM businesses WHERE id=?");
        $exists->execute([$bid]);
        if (!$exists->fetch()) {
            $ins = $pdo->prepare("INSERT INTO businesses (id, name, timezone, slot_minutes, slot_capacity, payment_mode, deposit_percent_default) VALUES (?,?,?,?,?,?,?)");
            $ins->execute([$bid, 'Turnera Demo', ($cfg['timezone'] ?? 'America/Argentina/Buenos_Aires'), (int)($cfg['slot_minutes'] ?? 15), 1, 'OFF', 30]);

            // Default branch
            $pdo->prepare("INSERT INTO branches (business_id, name) VALUES (?,?)")->execute([$bid, 'Sucursal Principal']);

            // Default admin user (username: admin, password: 1234) only for demo
            $hash = password_hash('1234', PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (business_id, username, password_hash, role) VALUES (?,?,?,?)")
                ->execute([$bid, 'admin', $hash, 'admin']);

            // Default hours: Mon-Sat 09-19, Sun closed
            for ($wd = 0; $wd <= 6; $wd++) {
                $isClosed = ($wd === 0) ? 1 : 0;
                $open = ($isClosed ? null : '09:00');
                $close = ($isClosed ? null : '19:00');
                $pdo->prepare("INSERT INTO business_hours (business_id, branch_id, weekday, open_time, close_time, is_closed) VALUES (?,?,?,?,?,?)")
                    ->execute([$bid, 1, $wd, $open, $close, $isClosed]);
            }
        }

        return;
    }

    // SQLite original migrator (legacy)
    $pdo->exec('PRAGMA foreign_keys = ON');

    // meta schema compatibility (older installs used columns k/v)
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)");
    $metaCols = $pdo->query("PRAGMA table_info(meta)")->fetchAll(PDO::FETCH_ASSOC);
    $haveMeta = array();
    foreach ($metaCols as $c) { $haveMeta[$c['name']] = true; }
    if (!isset($haveMeta['value']) && isset($haveMeta['v'])) {
        $pdo->beginTransaction();
        try {
            $pdo->exec("ALTER TABLE meta RENAME TO meta_old");
            $pdo->exec("CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)");
            $pdo->exec("INSERT INTO meta(key,value) SELECT k,v FROM meta_old");
            $pdo->exec("DROP TABLE meta_old");
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
        }
    }

    // Run schema.sql (SQLite)
    $schemaPath = __DIR__ . '/../schema.sql';
    if (file_exists($schemaPath)) {
        $sql = file_get_contents($schemaPath);
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || stripos($stmt, 'PRAGMA') === 0) continue;
            $pdo->exec($stmt);
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


    // Prevent reseeding if DB already initialized
    $has = (int)$pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn();
    if ($has > 0) { return; }

    // Business
    $pdo->prepare('INSERT INTO businesses (id, name, address, whatsapp_phone, timezone, slot_minutes)
                   VALUES (1, :name, :addr, :wa, :tz, :slot)')
        ->execute([
            ':name' => 'Turnera Demo',
            ':addr' => 'Av. Siempre Viva 123, Quilmes',
            ':wa' => '54911XXXXXXXX',
            ':tz' => $cfg['timezone'],
            ':slot' => (int)$cfg['slot_minutes'],
]);

    // Default branch (Sucursal Principal)
    $pdo->prepare('INSERT INTO branches (business_id, name, address, maps_url, whatsapp_phone, is_active)
                   VALUES (1, :n, :a, :m, :w, 1)')
        ->execute([
            ':n' => 'Sucursal Principal',
            ':a' => 'Av. Siempre Viva 123, Quilmes',
            ':m' => '',
            ':w' => '54911XXXXXXXX',
        ]);

    // Hours (Mon-Sat 10:00-20:00, Sun closed)
    for ($w = 0; $w <= 6; $w++) {
        if ($w === 0) { // Sunday
            $pdo->prepare('INSERT INTO business_hours (business_id, weekday, is_closed) VALUES (1, :w, 1)')
                ->execute([':w' => $w]);
        } else {
            $pdo->prepare('INSERT INTO business_hours (business_id, weekday, open_time, close_time, is_closed)
                           VALUES (1, :w, :o, :c, 0)')
                ->execute([':w' => $w, ':o' => '10:00', ':c' => '20:00']);
        }
    }

    // Profesionales (2 demo)
    $pdo->exec("INSERT INTO profesionales (business_id, name, is_active) VALUES (1,'Profesional 1',1), (1,'Profesional 2',1)");
    $bh = $pdo->query("SELECT weekday, open_time, close_time, is_closed FROM business_hours WHERE business_id=1")->fetchAll();
    $profesionales = $pdo->query("SELECT id FROM profesionales WHERE business_id=1 ORDER BY id")->fetchAll();
    $ins = $pdo->prepare("INSERT OR REPLACE INTO barber_hours (business_id, professional_id, weekday, open_time, close_time, is_closed)
                           VALUES (1,:bid,:w,:o,:c,:closed)");
    foreach ($profesionales as $b) {
        foreach ($bh as $h) {
            $ins->execute([
                ':bid' => (int)$b['id'],
                ':w' => (int)$h['weekday'],
                ':o' => $h['open_time'],
                ':c' => $h['close_time'],
                ':closed' => (int)$h['is_closed'],
            ]);
        }
    }

    // Services
    $services = [
        ['Corte', 'Corte clásico o moderno. Incluye lavado y terminación.', 30, 12000, 2000, '../assets/services/corte.svg'],
        ['Corte + Barba', 'Combo completo: corte + perfilado/arreglo de barba.', 60, 18000, 3000, '../assets/services/corte_barba.svg'],
        ['Barba', 'Perfilado y arreglo de barba. Incluye toalla caliente.', 30, 9000, 1500, '../assets/services/barba.svg'],
        ['Tintura', 'Color / decoloración. Consultá por tonos y mantenimiento.', 90, 25000, 4000, '../assets/services/tintura.svg'],
    ];
    $stmt = $pdo->prepare('INSERT INTO services (business_id, name, description, duration_minutes, price_ars, image_url, is_active)
                           VALUES (1, :n, :desc, :d, :p, :img, 1)');
    foreach ($services as $s) {
        $stmt->execute([
            ':n' => $s[0],
            ':desc' => $s[1],
            ':d' => (int)$s[2],
            ':p' => (int)$s[3],
            ':img' => $s[5],
        ]);
    }
}


function ensure_payment_schema(PDO $pdo): void {
    // Businesses: payment settings + MercadoPago tokens (per business)
    if (!db_column_exists($pdo, 'businesses', 'payment_mode')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN payment_mode TEXT NOT NULL DEFAULT 'OFF'");
    }
    if (!db_column_exists($pdo, 'businesses', 'deposit_percent_default')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN deposit_percent_default INTEGER NOT NULL DEFAULT 30");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_connected')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_connected INTEGER NOT NULL DEFAULT 0");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_user_id')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_user_id TEXT DEFAULT ''");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_access_token')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_access_token TEXT DEFAULT ''");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_refresh_token')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_refresh_token TEXT DEFAULT ''");
    }
    if (!db_column_exists($pdo, 'businesses', 'mp_token_expires_at')) {
        $pdo->exec("ALTER TABLE businesses ADD COLUMN mp_token_expires_at TEXT DEFAULT ''");
    }

    // Services: optional override deposit percent
    if (db_table_exists($pdo, 'services') && !db_column_exists($pdo, 'services', 'deposit_percent_override')) {
        $pdo->exec("ALTER TABLE services ADD COLUMN deposit_percent_override INTEGER");
    }

    // Appointments: payment tracking
    if (db_table_exists($pdo, 'appointments')) {
        if (!db_column_exists($pdo, 'appointments', 'payment_status')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN payment_status TEXT NOT NULL DEFAULT 'none'");
        }
        if (!db_column_exists($pdo, 'appointments', 'payment_mode')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN payment_mode TEXT NOT NULL DEFAULT 'none'");
        }
        if (!db_column_exists($pdo, 'appointments', 'payment_amount_ars')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN payment_amount_ars INTEGER NOT NULL DEFAULT 0");
        }
        if (!db_column_exists($pdo, 'appointments', 'payment_expires_at')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN payment_expires_at TEXT");
        }
        if (!db_column_exists($pdo, 'appointments', 'mp_preference_id')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN mp_preference_id TEXT DEFAULT ''");
        }
        if (!db_column_exists($pdo, 'appointments', 'mp_payment_id')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN mp_payment_id TEXT DEFAULT ''");
        }
        if (!db_column_exists($pdo, 'appointments', 'paid_at')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN paid_at TEXT");
        }
        // WhatsApp dashboard: skip reminder without sending
        if (!db_column_exists($pdo, 'appointments', 'reminder_skipped_at')) {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN reminder_skipped_at TEXT");
        }
    }
}

function expire_pending_payments(PDO $pdo): void {
    // Expire pending payments after deadline (localtime to match stored times)
    // Only affects slots that were created as payment-required reservations.
    $pdo->prepare("UPDATE appointments
                   SET status='VENCIDO', payment_status='expired', updated_at=CURRENT_TIMESTAMP
                   WHERE status='PENDIENTE_PAGO'
                     AND payment_status='pending'
                     AND payment_expires_at IS NOT NULL
                     AND datetime(payment_expires_at) <= datetime('now','localtime')")
        ->execute();
}

function expire_pending_bookings(PDO $pdo): void {
    // v1: invalidamos enlaces de turnos ya pasados.
    // El registro puede seguir existiendo para historial, pero el link deja de ser válido.
    // Guardamos y mostramos los horarios en hora local (Argentina normalmente). SQLite `datetime('now')`
    // usa UTC; si comparamos contra UTC, los turnos pueden vencer 3hs antes. Por eso usamos `localtime`.
    $pdo->prepare("UPDATE appointments
                   SET status='VENCIDO', updated_at=CURRENT_TIMESTAMP
                   WHERE status IN ('PENDIENTE_APROBACION','ACEPTADO','REPROGRAMACION_PENDIENTE')
                     AND datetime(end_at) <= datetime('now','localtime')")
        ->execute();
}