
-- ===== Fase 3: scanner kiosk dan inti absensi =====

-- Perangkat scanner (kiosk) per ruangan. Token hanya disimpan sebagai hash; kode pasangan 6 digit berumur 15 menit.
CREATE TABLE IF NOT EXISTS devices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  room_id INTEGER NULL REFERENCES rooms(id) ON DELETE SET NULL,
  token_hash TEXT NULL UNIQUE,
  pair_code TEXT NULL,
  pair_expires_at TEXT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','active','revoked')),
  paired_at TEXT NULL,
  last_seen_at TEXT NULL,
  last_ip TEXT NULL,
  app_version TEXT NULL,
  created_by INTEGER NULL,
  created_at TEXT NOT NULL,
  revoked_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_devices_room ON devices (room_id, status);
CREATE INDEX IF NOT EXISTS idx_devices_pair ON devices (pair_code);

-- Sesi absensi: satu per jadwal per tanggal (per jam) atau per kelas per tanggal (masuk/pulang).
-- slot_key menjamin pembuatan otomatis tidak ganda walau dua scanner datang bersamaan.
CREATE TABLE IF NOT EXISTS sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slot_key TEXT NULL UNIQUE,
  schedule_id INTEGER NULL REFERENCES schedules(id) ON DELETE SET NULL,
  semester_id INTEGER NULL REFERENCES semesters(id) ON DELETE SET NULL,
  class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
  room_id INTEGER NULL REFERENCES rooms(id) ON DELETE SET NULL,
  subject_id INTEGER NULL REFERENCES subjects(id) ON DELETE SET NULL,
  teacher_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  session_date TEXT NOT NULL,
  period_start INTEGER NULL,
  period_end INTEGER NULL,
  scan_from TEXT NOT NULL,                -- scan mulai diterima
  starts_at TEXT NOT NULL,                -- acuan hitung keterlambatan
  late_after TEXT NOT NULL,               -- starts_at + toleransi
  ends_at TEXT NOT NULL,                  -- scan berhenti diterima, setelah ini sesi ditutup → Alpa
  kind TEXT NOT NULL DEFAULT 'jadwal' CHECK (kind IN ('jadwal','tambahan','harian_masuk','harian_pulang')),
  status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed','cancelled')),
  note TEXT NULL,
  opened_by INTEGER NULL,
  closed_at TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_date ON sessions (session_date, class_id);
CREATE INDEX IF NOT EXISTS idx_sessions_open ON sessions (status, ends_at);

-- Kehadiran: satu baris per (sesi, siswa). Status: H hadir, T terlambat, I izin, S sakit, D dispensasi, A alpa.
CREATE TABLE IF NOT EXISTS attendances (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
  student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
  class_id INTEGER NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('H','T','I','S','D','A')),
  scanned_at TEXT NULL,
  late_minutes INTEGER NOT NULL DEFAULT 0,
  method TEXT NOT NULL DEFAULT 'scan' CHECK (method IN ('scan','manual','auto')),
  device_id INTEGER NULL,
  scan_id TEXT NULL,
  note TEXT NULL,
  changed_by INTEGER NULL,
  changed_at TEXT NULL,
  created_at TEXT NOT NULL,
  UNIQUE (session_id, student_id)
);
CREATE INDEX IF NOT EXISTS idx_att_student ON attendances (student_id, created_at);
CREATE INDEX IF NOT EXISTS idx_att_class ON attendances (class_id, created_at);

-- Log semua scan termasuk yang ditolak; scan_id unik membuat pengiriman ulang idempoten.
CREATE TABLE IF NOT EXISTS scan_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scan_id TEXT NULL UNIQUE,
  device_id INTEGER NULL,
  code TEXT NULL,
  session_id INTEGER NULL,
  student_id INTEGER NULL,
  result TEXT NOT NULL,
  response TEXT NULL,
  scanned_at TEXT NULL,
  received_at TEXT NOT NULL,
  t_server_ms REAL NULL,
  metrics TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_scanlog_time ON scan_logs (received_at);
CREATE INDEX IF NOT EXISTS idx_scanlog_device ON scan_logs (device_id, received_at);
