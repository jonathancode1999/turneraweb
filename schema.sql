PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS businesses (
  id INTEGER PRIMARY KEY,
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
  slot_minutes INTEGER NOT NULL DEFAULT 15,
  slot_capacity INTEGER NOT NULL DEFAULT 1,
  cancel_notice_minutes INTEGER NOT NULL DEFAULT 0,
  pay_deadline_minutes INTEGER NOT NULL DEFAULT 15,
  customer_choose_barber INTEGER NOT NULL DEFAULT 1,
  smtp_enabled INTEGER NOT NULL DEFAULT 0,
  smtp_host TEXT DEFAULT '',
  smtp_port INTEGER DEFAULT 587,
  smtp_user TEXT DEFAULT '',
  smtp_pass TEXT DEFAULT '',
  smtp_secure TEXT DEFAULT '',
  smtp_from_email TEXT DEFAULT '',
  smtp_from_name TEXT DEFAULT '',
  public_base_url TEXT DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS branches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  address TEXT DEFAULT '',
  maps_url TEXT DEFAULT '',
  whatsapp_phone TEXT DEFAULT '',
  owner_email TEXT DEFAULT '',
  instagram_url TEXT DEFAULT '',
  logo_path TEXT DEFAULT '',
  cover_path TEXT DEFAULT '',
  smtp_host TEXT DEFAULT '',
  smtp_port INTEGER NOT NULL DEFAULT 587,
  smtp_user TEXT DEFAULT '',
  smtp_pass TEXT DEFAULT '',
  smtp_secure TEXT DEFAULT 'tls',
  smtp_from_email TEXT DEFAULT '',
  smtp_from_name TEXT DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE
);


CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  username TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'admin',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(business_id, username),
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  description TEXT DEFAULT '',
  duration_minutes INTEGER NOT NULL,
  price_ars INTEGER NOT NULL DEFAULT 0,
  deposit_ars INTEGER NOT NULL DEFAULT 0,
  image_url TEXT DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  avatar_path TEXT DEFAULT '',
  cover_path TEXT DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

-- Barbers / staff
CREATE TABLE IF NOT EXISTS barbers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  branch_id INTEGER NOT NULL DEFAULT 1,
  name TEXT NOT NULL,
  capacity INTEGER NOT NULL DEFAULT 1,
  is_active INTEGER NOT NULL DEFAULT 1,
  avatar_path TEXT DEFAULT '',
  cover_path TEXT DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS barber_hours (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  branch_id INTEGER NOT NULL DEFAULT 1,
  barber_id INTEGER NOT NULL,
  weekday INTEGER NOT NULL, -- 0=Sun..6=Sat
  open_time TEXT,
  close_time TEXT,
  is_closed INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(business_id, barber_id, weekday),
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  FOREIGN KEY(barber_id) REFERENCES barbers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS barber_timeoff (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  branch_id INTEGER NOT NULL DEFAULT 1,
  barber_id INTEGER NOT NULL,
  start_date TEXT NOT NULL, -- YYYY-MM-DD
  end_date TEXT NOT NULL,   -- YYYY-MM-DD
  reason TEXT DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  FOREIGN KEY(barber_id) REFERENCES barbers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS business_hours (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  branch_id INTEGER NOT NULL DEFAULT 1,
  weekday INTEGER NOT NULL, -- 0=Sun..6=Sat
  open_time TEXT,
  close_time TEXT,
  is_closed INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(business_id, branch_id, weekday),
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS blocks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  branch_id INTEGER NOT NULL DEFAULT 1,
  barber_id INTEGER, -- NULL = global block
  start_at TEXT NOT NULL, -- ISO datetime
  end_at TEXT NOT NULL,
  reason TEXT DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS appointments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  branch_id INTEGER NOT NULL DEFAULT 1,
  barber_id INTEGER NOT NULL,
  service_id INTEGER NOT NULL,
  customer_name TEXT NOT NULL,
  customer_phone TEXT NOT NULL,
  customer_email TEXT DEFAULT '',
  notes TEXT DEFAULT '',
  start_at TEXT NOT NULL,
  end_at TEXT NOT NULL,
  status TEXT NOT NULL, -- PENDIENTE_APROBACION/ACEPTADO/REPROGRAMACION_PENDIENTE/CANCELADO/VENCIDO/COMPLETADO/OCUPADO
  token TEXT NOT NULL,
  -- Reprogramación (opcional)
  requested_start_at TEXT,
  requested_end_at TEXT,
  requested_at TEXT,
  requested_barber_id INTEGER,
  requested_service_id INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at TEXT,
  -- Recordatorio por email (opcional)
  reminder_sent_at TEXT,
  reminder_last_error TEXT,
  UNIQUE(business_id, token),
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_appt_business_barber_start ON appointments(business_id, barber_id, start_at);

CREATE INDEX IF NOT EXISTS idx_appt_business_start ON appointments(business_id, start_at);
CREATE INDEX IF NOT EXISTS idx_appt_status ON appointments(status);

CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  business_id INTEGER NOT NULL,
  appointment_id INTEGER NOT NULL,
  provider TEXT NOT NULL,
  external_id TEXT,
  status TEXT NOT NULL, -- created/paid/failed/expired
  amount_ars INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  FOREIGN KEY(appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
);

-- Gallery (portfolio)

CREATE INDEX IF NOT EXISTS idx_pay_appt ON payments(appointment_id);
