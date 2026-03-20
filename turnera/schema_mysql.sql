-- Turnera v1 - MySQL schema
-- Generated from _template/includes/db.php (migrate_if_needed MySQL branch)

SET NAMES utf8mb4;
SET time_zone = '+00:00';


CREATE TABLE IF NOT EXISTS meta (
  `key` VARCHAR(190) PRIMARY KEY,
  `value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- WhatsApp / Messaging templates (used by admin/wa_action.php)
-- --------------------------------------------------------

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

-- -----------------------------------------------------------------------------
-- Time off / vacaciones por profesional (usado por includes/availability.php)
-- -----------------------------------------------------------------------------

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

-- Horarios por profesional (si no existe, se asume el horario del negocio)
CREATE TABLE IF NOT EXISTS barber_hours (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  branch_id INT NOT NULL DEFAULT 1,
  professional_id INT NOT NULL,
  weekday INT NOT NULL, -- 0=Sun .. 6=Sat
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
  -- Snapshot of service price at booking time (for reporting)
  price_snapshot_ars INT NOT NULL DEFAULT 0,
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

-- Appointment events (timeline/audit)
CREATE TABLE IF NOT EXISTS appointment_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id INT NOT NULL,
  branch_id INT NOT NULL,
  appointment_id INT NOT NULL,
  actor_type VARCHAR(24) NOT NULL DEFAULT 'system',
  actor_user_id INT NULL,
  event_type VARCHAR(50) NOT NULL,
  note TEXT NULL,
  meta_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_appt_events_appt (business_id, branch_id, appointment_id),
  KEY idx_appt_events_created (business_id, branch_id, created_at),
  CONSTRAINT fk_appt_events_appt
    FOREIGN KEY (appointment_id) REFERENCES appointments(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_appt_events_user
    FOREIGN KEY (actor_user_id) REFERENCES users(id)
    ON DELETE SET NULL
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
