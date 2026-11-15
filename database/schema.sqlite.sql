-- Absensi Sekolah: skema SQLite (pengujian / instalasi kecil). Versi 1 (Fase 1: data master dan peran).
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
