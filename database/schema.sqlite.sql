-- Absensi Sekolah: skema SQLite (pengujian / instalasi kecil). Versi 7 (Fase 1 data master, Fase 2 kartu, Fase 3 absensi, Fase 4 metrik, Fase 5 izin dan riwayat, Fase 6 notifikasi dan portal, Fase 7 pilot).
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS settings (
  skey TEXT PRIMARY KEY,
  svalue TEXT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('super', 'kepsek', 'tu', 'guru')),
  nip TEXT NULL,
  phone TEXT NULL,
  status TEXT NOT NULL DEFAULT 'Aktif' CHECK (status IN ('Aktif', 'Nonaktif')),
  must_change_password INTEGER NOT NULL DEFAULT 0,
  last_login_at TEXT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS academic_years (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,              -- 2026/2027
  start_date TEXT NOT NULL,
  end_date TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS semesters (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  academic_year_id INTEGER NOT NULL REFERENCES academic_years(id) ON DELETE CASCADE,
  name TEXT NOT NULL CHECK (name IN ('Ganjil', 'Genap')),
  start_date TEXT NOT NULL,
  end_date TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 0,
  UNIQUE (academic_year_id, name)
);

CREATE TABLE IF NOT EXISTS rooms (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,              -- R-01
  name TEXT NOT NULL,                     -- Ruang X-1 / Lab Komputer 1
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS classes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  academic_year_id INTEGER NOT NULL REFERENCES academic_years(id) ON DELETE CASCADE,
  name TEXT NOT NULL,                     -- X-1, XI-IPA-2
  grade INTEGER NOT NULL,                 -- 10, 11, 12 (atau 1..9)
  homeroom_teacher_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  room_id INTEGER NULL REFERENCES rooms(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL,
  UNIQUE (academic_year_id, name)
);

CREATE TABLE IF NOT EXISTS students (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nis TEXT NOT NULL UNIQUE,
  nisn TEXT NULL,
  full_name TEXT NOT NULL,
  gender TEXT NOT NULL CHECK (gender IN ('L', 'P')),
  birth_date TEXT NULL,
  photo_path TEXT NULL,
  parent_name TEXT NULL,
  parent_phone TEXT NULL,
  portal_code_hash TEXT NULL,
  portal_code_at TEXT NULL,
  status TEXT NOT NULL DEFAULT 'Aktif' CHECK (status IN ('Aktif', 'Lulus', 'Pindah', 'Keluar')),
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_students_name ON students (full_name);

CREATE TABLE IF NOT EXISTS class_members (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
  student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
  academic_year_id INTEGER NOT NULL REFERENCES academic_years(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL,
  UNIQUE (student_id, academic_year_id)   -- satu siswa satu kelas per tahun ajaran
);
CREATE INDEX IF NOT EXISTS idx_members_class ON class_members (class_id);

-- Kartu QR siswa: kode acak 12 karakter (bukan NIS), berversi; kartu lama dicabut saat diganti
CREATE TABLE IF NOT EXISTS cards (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
  code TEXT NOT NULL UNIQUE,
  version INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','revoked')),
  issued_by INTEGER NULL,
  issued_at TEXT NOT NULL,
  printed_at TEXT NULL,
  revoked_at TEXT NULL,
  revoke_reason TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_cards_student ON cards(student_id, status);

CREATE TABLE IF NOT EXISTS subjects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,              -- MTK
  name TEXT NOT NULL,                     -- Matematika
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS periods (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  number INTEGER NOT NULL UNIQUE,         -- jam ke-1 .. ke-10
  start_time TEXT NOT NULL,               -- 07:00
  end_time TEXT NOT NULL                  -- 07:45
);

CREATE TABLE IF NOT EXISTS schedules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  semester_id INTEGER NOT NULL REFERENCES semesters(id) ON DELETE CASCADE,
  class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
  subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE RESTRICT,
  teacher_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  room_id INTEGER NULL REFERENCES rooms(id) ON DELETE SET NULL,
  day_of_week INTEGER NOT NULL CHECK (day_of_week BETWEEN 1 AND 7), -- 1 = Senin
  period_start INTEGER NOT NULL,          -- jam ke-
  period_end INTEGER NOT NULL,            -- jam ke- (inklusif)
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sched_room_day ON schedules (semester_id, room_id, day_of_week);
CREATE INDEX IF NOT EXISTS idx_sched_class_day ON schedules (semester_id, class_id, day_of_week);
CREATE INDEX IF NOT EXISTS idx_sched_teacher ON schedules (semester_id, teacher_id);

CREATE TABLE IF NOT EXISTS holidays (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  holiday_date TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL
);

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
  queue_size INTEGER NOT NULL DEFAULT 0,
  clock_offset_ms INTEGER NULL,
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
CREATE INDEX IF NOT EXISTS idx_sessions_class_date ON sessions (class_id, session_date);

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
  leave_id INTEGER NULL,
  corrected INTEGER NOT NULL DEFAULT 0,
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

-- Metrik kecepatan per scan dari kiosk (Fase 4): semua dalam milidetik.
CREATE TABLE IF NOT EXISTS scan_metrics (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scan_id TEXT NULL UNIQUE,
  device_id INTEGER NULL,
  source TEXT NULL,
  result TEXT NULL,
  t_decode_ms INTEGER NULL,
  t_feedback_ms INTEGER NULL,
  t_roundtrip_ms INTEGER NULL,
  t_server_ms REAL NULL,
  t_total_ms INTEGER NULL,
  local_hit INTEGER NOT NULL DEFAULT 0,
  queued INTEGER NOT NULL DEFAULT 0,
  queue_wait_ms INTEGER NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_metrics_device ON scan_metrics (device_id, created_at);
CREATE INDEX IF NOT EXISTS idx_metrics_time ON scan_metrics (created_at);

CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NULL,
  username TEXT NULL,
  role TEXT NULL,
  class_id INTEGER NULL,
  action TEXT NOT NULL,
  object_type TEXT NULL,
  object_id TEXT NULL,
  result TEXT NOT NULL DEFAULT 'ok',
  meta TEXT NULL,
  ip TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_logs (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_class ON audit_logs (class_id, created_at);

CREATE TABLE IF NOT EXISTS rate_limits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  rl_key TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rl ON rate_limits (rl_key, created_at);

CREATE TABLE IF NOT EXISTS schema_migrations (
  name TEXT PRIMARY KEY,
  applied_at TEXT NOT NULL
);

-- Izin, sakit, dispensasi per rentang tanggal (Fase 5)
CREATE TABLE IF NOT EXISTS leaves (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
  class_id INTEGER NULL,
  kind TEXT NOT NULL CHECK (kind IN ('I','S','D')),
  date_from TEXT NOT NULL,
  date_to TEXT NOT NULL,
  reason TEXT NOT NULL,
  attachment TEXT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','cancelled')),
  created_by INTEGER NULL,
  created_at TEXT NOT NULL,
  cancelled_by INTEGER NULL,
  cancelled_at TEXT NULL,
  cancel_reason TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_leaves_student ON leaves (student_id, date_from, date_to);
CREATE INDEX IF NOT EXISTS idx_leaves_class ON leaves (class_id, date_from);

-- Riwayat perubahan status kehadiran: siapa, kapan, dari apa ke apa, alasan (Fase 5)
CREATE TABLE IF NOT EXISTS status_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  attendance_id INTEGER NULL,
  session_id INTEGER NOT NULL,
  student_id INTEGER NOT NULL,
  old_status TEXT NULL,
  new_status TEXT NULL,
  source TEXT NOT NULL,
  reason TEXT NULL,
  leave_id INTEGER NULL,
  actor_id INTEGER NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_history_session ON status_history (session_id, created_at);
CREATE INDEX IF NOT EXISTS idx_history_student ON status_history (student_id, created_at);

-- Antrean notifikasi WhatsApp ke orang tua (Fase 6): satu pesan per siswa per hari
CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
  notify_date TEXT NOT NULL,
  kind TEXT NOT NULL CHECK (kind IN ('A','T')),
  phone TEXT NULL,
  message TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sending','sent','failed','skipped')),
  attempts INTEGER NOT NULL DEFAULT 0,
  next_attempt_at TEXT NULL,
  last_error TEXT NULL,
  provider_ref TEXT NULL,
  claimed_at TEXT NULL,
  created_at TEXT NOT NULL,
  sent_at TEXT NULL,
  UNIQUE (student_id, notify_date)
);
CREATE INDEX IF NOT EXISTS idx_notif_queue ON notifications (status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_notif_date ON notifications (notify_date, status);

-- Pilot (Fase 7): absen manual guru untuk dibandingkan, dan masukan pengguna
CREATE TABLE IF NOT EXISTS pilot_checks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  class_id INTEGER NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
  student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
  check_date TEXT NOT NULL,
  manual_status TEXT NOT NULL CHECK (manual_status IN ('H','T','S','I','A','D')),
  explanation TEXT NULL,
  explained_by INTEGER NULL,
  explained_at TEXT NULL,
  entered_by INTEGER NULL,
  created_at TEXT NOT NULL,
  UNIQUE (student_id, check_date)
);
CREATE INDEX IF NOT EXISTS idx_pilot_class_date ON pilot_checks (class_id, check_date);

CREATE TABLE IF NOT EXISTS feedback (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  topic TEXT NOT NULL,
  rating INTEGER NULL,
  message TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new','done')),
  handled_note TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_feedback_status ON feedback (status, created_at);
