<?php

function app_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = app_config();
    $dsn = 'sqlite:' . $cfg['sqlite_path'];

    // Ensure folder exists
    $dir = dirname($cfg['sqlite_path']);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    migrate_if_needed($pdo);
    ensure_multibranch_schema($pdo);

    // Opportunistic cleanup: expire old pending bookings
    expire_pending_bookings($pdo);

    return $pdo;
}

// Helper: check table existence in SQLite
function db_table_exists(PDO $pdo, string $name): bool {
    $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n LIMIT 1");
    $st->execute([':n' => $name]);
    return (bool)$st->fetchColumn();
}

function ensure_multibranch_schema(PDO $pdo): void {
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
    $tables = array('appointments','barbers','blocks','business_hours','barber_hours','barber_timeoff');
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
    // Old schema used UNIQUE(business_id, barber_id, weekday) which breaks when a barber exists in multiple branches
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
                $want = array('business_id','branch_id','barber_id','weekday');
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
                    barber_id INTEGER NOT NULL,
                    weekday INTEGER NOT NULL,
                    open_time TEXT NOT NULL,
                    close_time TEXT NOT NULL,
                    is_closed INTEGER NOT NULL DEFAULT 0,
                    UNIQUE(business_id, branch_id, barber_id, weekday)
                )");

                // Copy data. If old table lacks branch_id, default to 1.
                $cols = $pdo->query("PRAGMA table_info(barber_hours)")->fetchAll();
                $hasBranch = false;
                foreach ($cols as $c) { if ($c['name'] === 'branch_id') { $hasBranch = true; break; } }
                if ($hasBranch) {
                    $pdo->exec("INSERT OR IGNORE INTO barber_hours_new (id,business_id,branch_id,barber_id,weekday,open_time,close_time,is_closed)
                               SELECT id,business_id,branch_id,barber_id,weekday,open_time,close_time,is_closed FROM barber_hours");
                } else {
                    $pdo->exec("INSERT OR IGNORE INTO barber_hours_new (id,business_id,branch_id,barber_id,weekday,open_time,close_time,is_closed)
                               SELECT id,business_id,1,barber_id,weekday,open_time,close_time,is_closed FROM barber_hours");
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
    // Older DBs may have UNIQUE(business_id, barber_id, weekday) which prevents using the same
    // barber_id across branches and also breaks inserts in the admin.
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
                if ($names === array('business_id','branch_id','barber_id','weekday')) {
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
                    barber_id INTEGER NOT NULL,
                    weekday INTEGER NOT NULL,
                    open_time TEXT NOT NULL,
                    close_time TEXT NOT NULL,
                    is_closed INTEGER NOT NULL DEFAULT 0,
                    UNIQUE(business_id, branch_id, barber_id, weekday)
                )");

                // Copy rows (branch_id may not exist in old table; default to 1)
                $cols = $pdo->query("PRAGMA table_info(barber_hours)")->fetchAll();
                $hasBranch = false;
                foreach ($cols as $c) { if ($c['name'] === 'branch_id') { $hasBranch = true; break; } }
                if ($hasBranch) {
                    $pdo->exec("INSERT OR IGNORE INTO barber_hours_new (id,business_id,branch_id,barber_id,weekday,open_time,close_time,is_closed)
                               SELECT id,business_id,branch_id,barber_id,weekday,open_time,close_time,is_closed FROM barber_hours");
                } else {
                    $pdo->exec("INSERT OR IGNORE INTO barber_hours_new (id,business_id,branch_id,barber_id,weekday,open_time,close_time,is_closed)
                               SELECT id,business_id,1,barber_id,weekday,open_time,close_time,is_closed FROM barber_hours");
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
    $pdo->exec('PRAGMA foreign_keys = ON');

    // meta schema compatibility (older installs used columns k/v)
    $pdo->exec("CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)");
    $metaCols = $pdo->query("PRAGMA table_info(meta)")->fetchAll(PDO::FETCH_ASSOC);
    $haveMeta = array();
    foreach ($metaCols as $c) { $haveMeta[$c['name']] = true; }
    if (!isset($haveMeta['value']) && isset($haveMeta['v'])) {
        // Migrate k/v -> key/value
        $pdo->beginTransaction();
        try {
            $pdo->exec("ALTER TABLE meta RENAME TO meta_old");
            $pdo->exec("CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)");
            $pdo->exec("INSERT INTO meta(key,value) SELECT k,v FROM meta_old");
            $pdo->exec("DROP TABLE meta_old");
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    $version = (int)($pdo->query("SELECT value FROM meta WHERE key='schema_version'")->fetchColumn() ?: 0);
    if ($version >= 12) return;

    // Fresh install
    if ($version <= 0) {
        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Missing schema.sql');
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec($schema);
            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','12')")->execute();
            // Seed demo data only when marker file exists (demo site)
            if (file_exists(__DIR__ . '/../.demo_seed')) {
                seed_demo_data($pdo);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Upgrade v1 -> v2 (service description + image)
    if ($version === 1) {
        $pdo->beginTransaction();
        try {
            // SQLite supports ADD COLUMN
            $pdo->exec("ALTER TABLE services ADD COLUMN description TEXT DEFAULT ''");
            $pdo->exec("ALTER TABLE services ADD COLUMN image_url TEXT DEFAULT ''");
            $pdo->prepare("UPDATE services SET description = COALESCE(description,'')")->execute();
            $pdo->prepare("UPDATE services SET image_url = COALESCE(image_url,'')")->execute();
            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','2')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Upgrade v2 -> v3 (barbers + gallery + per-barber blocks/appointments)
    if ($version === 2) {
        $pdo->beginTransaction();
        try {
            // Staff
            $pdo->exec("CREATE TABLE IF NOT EXISTS barbers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                business_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS barber_hours (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                business_id INTEGER NOT NULL,
                barber_id INTEGER NOT NULL,
                weekday INTEGER NOT NULL,
                open_time TEXT,
                close_time TEXT,
                is_closed INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(business_id, barber_id, weekday),
                FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
                FOREIGN KEY(barber_id) REFERENCES barbers(id) ON DELETE CASCADE
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS barber_timeoff (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                business_id INTEGER NOT NULL,
                barber_id INTEGER NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                reason TEXT DEFAULT '',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
                FOREIGN KEY(barber_id) REFERENCES barbers(id) ON DELETE CASCADE
            )");

            // Gallery
            $pdo->exec("CREATE TABLE IF NOT EXISTS gallery_images (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                business_id INTEGER NOT NULL,
                file_path TEXT NOT NULL,
                caption TEXT DEFAULT '',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE
            )");

            // Add barber_id to blocks if missing
            $cols = $pdo->query("PRAGMA table_info(blocks)")->fetchAll();
            $hasBarber = false;
            foreach ($cols as $c) if (($c['name'] ?? '') === 'barber_id') { $hasBarber = true; break; }
            if (!$hasBarber) {
                $pdo->exec("ALTER TABLE blocks ADD COLUMN barber_id INTEGER");
            }

            // Add barber_id to appointments if missing
            $cols = $pdo->query("PRAGMA table_info(appointments)")->fetchAll();
            $hasBarber = false;
            foreach ($cols as $c) if (($c['name'] ?? '') === 'barber_id') { $hasBarber = true; break; }
            if (!$hasBarber) {
                $pdo->exec("ALTER TABLE appointments ADD COLUMN barber_id INTEGER NOT NULL DEFAULT 1");
            }

            // Ensure index exists
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_appt_business_barber_start ON appointments(business_id, barber_id, start_at)");

            // Seed demo barbers if none
            $cnt = (int)($pdo->query("SELECT COUNT(*) FROM barbers WHERE business_id=1")->fetchColumn() ?: 0);
            if ($cnt === 0) {
                $pdo->exec("INSERT INTO barbers (business_id, name, is_active) VALUES (1,'Profesional 1',1), (1,'Profesional 2',1)");
                // Copy business hours into each barber
                $bh = $pdo->query("SELECT weekday, open_time, close_time, is_closed FROM business_hours WHERE business_id=1")->fetchAll();
                $barbers = $pdo->query("SELECT id FROM barbers WHERE business_id=1 ORDER BY id")->fetchAll();
                $ins = $pdo->prepare("INSERT OR REPLACE INTO barber_hours (business_id, barber_id, weekday, open_time, close_time, is_closed)
                                      VALUES (1,:bid,:w,:o,:c,:closed)");
                foreach ($barbers as $b) {
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
            }

            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','3')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Upgrade v3 -> v4 (business settings + barber capacity)
    if ($version === 3) {
        $pdo->beginTransaction();
        try {
            // businesses: gallery title, maps url, slot capacity
            $cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            $have = [];
            foreach ($cols as $c) { $have[$c['name']] = true; }
            if (!isset($have['gallery_title'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN gallery_title TEXT DEFAULT 'Nuestros trabajos'");
            }
            if (!isset($have['maps_url'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN maps_url TEXT DEFAULT ''");
            }
            if (!isset($have['slot_capacity'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN slot_capacity INTEGER NOT NULL DEFAULT 1");
            }

            // barbers capacity
            $cols = $pdo->query("PRAGMA table_info(barbers)")->fetchAll();
            $have = [];
            foreach ($cols as $c) { $have[$c['name']] = true; }
            if (!isset($have['capacity'])) {
                $pdo->exec("ALTER TABLE barbers ADD COLUMN capacity INTEGER NOT NULL DEFAULT 1");
            }

            // Ensure sane defaults
            $pdo->exec("UPDATE businesses SET gallery_title = COALESCE(gallery_title,'Nuestros trabajos') WHERE id=1");
            $pdo->exec("UPDATE businesses SET slot_capacity = CASE WHEN slot_capacity IS NULL OR slot_capacity < 1 THEN 1 ELSE slot_capacity END");
            $pdo->exec("UPDATE barbers SET capacity = CASE WHEN capacity IS NULL OR capacity < 1 THEN 1 ELSE capacity END");

    $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','5')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Upgrade v4 -> v5 (customer_choose_barber)
    if ($version === 4) {
        $pdo->beginTransaction();
        try {
            $cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            $have = [];
            foreach ($cols as $c) { $have[$c['name']] = true; }
            if (!isset($have['customer_choose_barber'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN customer_choose_barber INTEGER NOT NULL DEFAULT 1");
            }
            $pdo->exec("UPDATE businesses SET customer_choose_barber = CASE WHEN customer_choose_barber IS NULL THEN 1 ELSE customer_choose_barber END");
            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','5')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }


    // Upgrade v5 -> v6 (branding + email + reprogramación + customer_email)
    if ($version === 5) {
        $pdo->beginTransaction();
        try {
            $cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            $have = [];
            foreach ($cols as $c) { $have[$c['name']] = true; }
            if (!isset($have['logo_path'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN logo_path TEXT DEFAULT ''");
            }
            if (!isset($have['owner_email'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN owner_email TEXT DEFAULT ''");
            }
            $pdo->exec("UPDATE businesses SET logo_path = COALESCE(logo_path,'') WHERE id=1");

            // appointments: customer_email + requested_* fields
            $colsA = $pdo->query("PRAGMA table_info(appointments)")->fetchAll();
            $haveA = [];
            foreach ($colsA as $c) { $haveA[$c['name']] = true; }
            if (!isset($haveA['customer_email'])) {
                $pdo->exec("ALTER TABLE appointments ADD COLUMN customer_email TEXT DEFAULT ''");
            }
            if (!isset($haveA['requested_start_at'])) {
                $pdo->exec("ALTER TABLE appointments ADD COLUMN requested_start_at TEXT");
            }
            if (!isset($haveA['requested_end_at'])) {
                $pdo->exec("ALTER TABLE appointments ADD COLUMN requested_end_at TEXT");
            }
            if (!isset($haveA['requested_at'])) {
                $pdo->exec("ALTER TABLE appointments ADD COLUMN requested_at TEXT");
            }

            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','6')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }


    // Upgrade v6 -> v7 (reprogramación con cambio de profesional/servicio)
    if ($version === 6) {
        $pdo->beginTransaction();
        try {
            $colsA = $pdo->query("PRAGMA table_info(appointments)")->fetchAll();
            $haveA = [];
            foreach ($colsA as $c) { $haveA[$c['name']] = true; }

            if (!isset($haveA['requested_barber_id'])) {
                $pdo->exec("ALTER TABLE appointments ADD COLUMN requested_barber_id INTEGER");
            }
            if (!isset($haveA['requested_service_id'])) {
                $pdo->exec("ALTER TABLE appointments ADD COLUMN requested_service_id INTEGER");
            }

            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','7')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Upgrade v7 -> v8 (cancel window + SMTP settings)
    if ($version === 7) {
        $pdo->beginTransaction();
        try {
            $cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            $have = [];
            foreach ($cols as $c) { $have[$c['name']] = true; }
            if (!isset($have['cancel_notice_minutes'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN cancel_notice_minutes INTEGER NOT NULL DEFAULT 0");
            }
            if (!isset($have['smtp_enabled'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN smtp_enabled INTEGER NOT NULL DEFAULT 0");
            }
            if (!isset($have['smtp_host'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN smtp_host TEXT DEFAULT ''");
            }
            if (!isset($have['smtp_port'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN smtp_port INTEGER DEFAULT 587");
            }
            if (!isset($have['smtp_user'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN smtp_user TEXT DEFAULT ''");
            }
            if (!isset($have['smtp_pass'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN smtp_pass TEXT DEFAULT ''");
            }
            if (!isset($have['smtp_secure'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN smtp_secure TEXT DEFAULT ''");
            }
            if (!isset($have['smtp_from_email'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN smtp_from_email TEXT DEFAULT ''");
            }
            if (!isset($have['smtp_from_name'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN smtp_from_name TEXT DEFAULT ''");
            }

            $pdo->exec("UPDATE businesses SET cancel_notice_minutes = CASE WHEN cancel_notice_minutes IS NULL OR cancel_notice_minutes < 0 THEN 0 ELSE cancel_notice_minutes END");
            $pdo->exec("UPDATE businesses SET smtp_enabled = CASE WHEN smtp_enabled IS NULL THEN 0 ELSE smtp_enabled END");
            $pdo->exec("UPDATE businesses SET smtp_host = COALESCE(smtp_host,'')");

            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','8')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Upgrade v8 -> v9 (portada + instagram + descripción)
    if ($version === 8) {
        $pdo->beginTransaction();
        try {
            $cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            $have = [];
            foreach ($cols as $c) { $have[$c['name']] = true; }

            if (!isset($have['cover_path'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN cover_path TEXT DEFAULT ''");
            }
            if (!isset($have['instagram_url'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN instagram_url TEXT DEFAULT ''");
            }
            if (!isset($have['intro_text'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN intro_text TEXT DEFAULT ''");
            }

            $pdo->exec("UPDATE businesses SET cover_path = COALESCE(cover_path,'')");
            $pdo->exec("UPDATE businesses SET instagram_url = COALESCE(instagram_url,'')");
            $pdo->exec("UPDATE businesses SET intro_text = COALESCE(intro_text,'')");

            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','9')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Upgrade v9 -> v10 (unificar estados + sacar galería + fotos profesionales + URL pública)
    if ($version === 9) {
        $pdo->beginTransaction();
        try {
            // 1) Normalizar estados legacy
            $pdo->exec("UPDATE appointments SET status='ACEPTADO' WHERE status IN ('CONFIRMADO')");

            // 2) Barbers: avatar/cover
            $colsB = $pdo->query("PRAGMA table_info(barbers)")->fetchAll();
            $haveB = [];
            foreach ($colsB as $c) { $haveB[$c['name']] = true; }
            if (!isset($haveB['avatar_path'])) {
                $pdo->exec("ALTER TABLE barbers ADD COLUMN avatar_path TEXT DEFAULT ''");
            }
            if (!isset($haveB['cover_path'])) {
                $pdo->exec("ALTER TABLE barbers ADD COLUMN cover_path TEXT DEFAULT ''");
            }
            $pdo->exec("UPDATE barbers SET avatar_path = COALESCE(avatar_path,'')");
            $pdo->exec("UPDATE barbers SET cover_path = COALESCE(cover_path,'')");

            // 3) Businesses: public_base_url (para links de gestión en emails)
            $colsBiz = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            $haveBiz = [];
            foreach ($colsBiz as $c) { $haveBiz[$c['name']] = true; }
            if (!isset($haveBiz['public_base_url'])) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN public_base_url TEXT DEFAULT ''");
            }
            $pdo->exec("UPDATE businesses SET public_base_url = COALESCE(public_base_url,'')");

	            // 4) Eliminar galería por completo
	            $pdo->exec("DROP TABLE IF EXISTS gallery_images");
	            // Si quedó una migración vieja a mitad, limpiamos cualquier tabla auxiliar.
	            $pdo->exec("DROP TABLE IF EXISTS businesses_old");
	            $pdo->exec("DROP TABLE IF EXISTS businesses_new");

	            // SQLite no soporta DROP COLUMN: si existe gallery_title, reconstruimos la tabla businesses.
	            // IMPORTANTÍSIMO: NO dependemos de businesses_old (puede no existir aunque el schema_version sea 9).
	            if (db_table_exists($pdo, 'businesses') && isset($haveBiz['gallery_title'])) {
	                $pdo->exec("CREATE TABLE businesses_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    owner_email TEXT DEFAULT '',
                    address TEXT DEFAULT '',
                    maps_url TEXT DEFAULT '',
                    whatsapp_phone TEXT DEFAULT '',
                    logo_path TEXT DEFAULT '',
                    cover_path TEXT DEFAULT '',
                    instagram_url TEXT DEFAULT '',
                    intro_text TEXT DEFAULT '',
                    timezone TEXT DEFAULT 'America/Argentina/Buenos_Aires',
                    slot_minutes INTEGER NOT NULL DEFAULT 30,
                    slot_capacity INTEGER NOT NULL DEFAULT 1,
                    cancel_notice_minutes INTEGER NOT NULL DEFAULT 0,
customer_choose_barber INTEGER NOT NULL DEFAULT 1,
                    smtp_enabled INTEGER NOT NULL DEFAULT 0,
                    smtp_host TEXT DEFAULT '',
                    smtp_port INTEGER NOT NULL DEFAULT 587,
                    smtp_user TEXT DEFAULT '',
                    smtp_pass TEXT DEFAULT '',
                    smtp_secure TEXT DEFAULT '',
                    smtp_from_email TEXT DEFAULT '',
                    smtp_from_name TEXT DEFAULT '',
                    public_base_url TEXT DEFAULT '',
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
	                )");

	                // Copiamos datos desde businesses (ignorando gallery_title)
	                $pdo->exec("INSERT INTO businesses_new (
                        id,name,owner_email,address,maps_url,whatsapp_phone,logo_path,cover_path,instagram_url,intro_text,timezone,
                        slot_minutes,slot_capacity,cancel_notice_minutes,customer_choose_barber,
                        smtp_enabled,smtp_host,smtp_port,smtp_user,smtp_pass,smtp_secure,smtp_from_email,smtp_from_name,public_base_url,created_at
                    )
                    SELECT
                        id,name,owner_email,address,maps_url,whatsapp_phone,logo_path,cover_path,instagram_url,intro_text,timezone,
                        slot_minutes,slot_capacity,cancel_notice_minutes,customer_choose_barber,
                        smtp_enabled,smtp_host,smtp_port,smtp_user,smtp_pass,smtp_secure,smtp_from_email,smtp_from_name,public_base_url,created_at
                    FROM businesses");

	                $pdo->exec("DROP TABLE businesses");
	                $pdo->exec("ALTER TABLE businesses_new RENAME TO businesses");
	            }

	            // Si por algún motivo businesses no existe (DB rota / incompleta), la recreamos mínima.
	            if (!db_table_exists($pdo, 'businesses')) {
	                $pdo->exec("CREATE TABLE IF NOT EXISTS businesses (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    owner_email TEXT DEFAULT '',
                    address TEXT DEFAULT '',
                    maps_url TEXT DEFAULT '',
                    whatsapp_phone TEXT DEFAULT '',
                    logo_path TEXT DEFAULT '',
                    cover_path TEXT DEFAULT '',
                    instagram_url TEXT DEFAULT '',
                    intro_text TEXT DEFAULT '',
                    timezone TEXT DEFAULT 'America/Argentina/Buenos_Aires',
                    slot_minutes INTEGER NOT NULL DEFAULT 30,
                    slot_capacity INTEGER NOT NULL DEFAULT 1,
                    cancel_notice_minutes INTEGER NOT NULL DEFAULT 0,
customer_choose_barber INTEGER NOT NULL DEFAULT 1,
                    smtp_enabled INTEGER NOT NULL DEFAULT 0,
                    smtp_host TEXT DEFAULT '',
                    smtp_port INTEGER NOT NULL DEFAULT 587,
                    smtp_user TEXT DEFAULT '',
                    smtp_pass TEXT DEFAULT '',
                    smtp_secure TEXT DEFAULT '',
                    smtp_from_email TEXT DEFAULT '',
                    smtp_from_name TEXT DEFAULT '',
                    public_base_url TEXT DEFAULT '',
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
	                )");
	                // Seed mínimo para que la app funcione.
	                $pdo->exec("INSERT OR IGNORE INTO businesses(id,name,timezone,slot_minutes) VALUES(1,'Turnera Demo','America/Argentina/Buenos_Aires',30)");
	            }

$pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','10')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Upgrade v10 -> v11 (recordatorios por email)
    if ($version === 10) {
        $pdo->beginTransaction();
        try {
            if (db_table_exists($pdo, 'appointments')) {
                $colsA = $pdo->query("PRAGMA table_info(appointments)")->fetchAll();
                $haveA = [];
                foreach ($colsA as $c) { $haveA[$c['name']] = true; }
                if (!isset($haveA['reminder_sent_at'])) {
                    $pdo->exec("ALTER TABLE appointments ADD COLUMN reminder_sent_at TEXT");
                }
                if (!isset($haveA['reminder_last_error'])) {
                    $pdo->exec("ALTER TABLE appointments ADD COLUMN reminder_last_error TEXT");
                }
                $pdo->exec("UPDATE appointments SET reminder_sent_at = COALESCE(reminder_sent_at, NULL)");
                $pdo->exec("UPDATE appointments SET reminder_last_error = COALESCE(reminder_last_error, NULL)");
            }

            // Dejamos la DB en v11 para que en la próxima carga corra la migración v11->v12.
            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','12')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Upgrade v11 -> v12 (multi-sucursal)
    if ($version === 11) {
        $pdo->beginTransaction();
        try {
            ensure_multibranch_schema($pdo);
            $pdo->prepare("INSERT OR REPLACE INTO meta(key,value) VALUES('schema_version','12')")->execute();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }

    // Repair (idempotente): si la DB quedó marcada como v12 pero faltan columnas/tablas.
    if ($version >= 12) {
        ensure_multibranch_schema($pdo);
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

    // Barbers (2 demo)
    $pdo->exec("INSERT INTO barbers (business_id, name, is_active) VALUES (1,'Profesional 1',1), (1,'Profesional 2',1)");
    $bh = $pdo->query("SELECT weekday, open_time, close_time, is_closed FROM business_hours WHERE business_id=1")->fetchAll();
    $barbers = $pdo->query("SELECT id FROM barbers WHERE business_id=1 ORDER BY id")->fetchAll();
    $ins = $pdo->prepare("INSERT OR REPLACE INTO barber_hours (business_id, barber_id, weekday, open_time, close_time, is_closed)
                           VALUES (1,:bid,:w,:o,:c,:closed)");
    foreach ($barbers as $b) {
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