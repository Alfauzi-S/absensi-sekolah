-- Absensi Sekolah: skema MySQL/MariaDB. Versi 3 (Fase 1 data master, Fase 2 kartu, Fase 3 absensi).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(60) NOT NULL PRIMARY KEY,
  svalue TEXT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(40) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super', 'kepsek', 'tu', 'guru') NOT NULL,
  nip VARCHAR(30) NULL,
  phone VARCHAR(20) NULL,
  status ENUM('Aktif', 'Nonaktif') NOT NULL DEFAULT 'Aktif',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_years (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(20) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_ay_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS semesters (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academic_year_id INT UNSIGNED NOT NULL,
  name ENUM('Ganjil', 'Genap') NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sem (academic_year_id, name),
  CONSTRAINT fk_sem_ay FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_rooms_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  academic_year_id INT UNSIGNED NOT NULL,
  name VARCHAR(30) NOT NULL,
  grade TINYINT UNSIGNED NOT NULL,
  homeroom_teacher_id INT UNSIGNED NULL,
  room_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_classes (academic_year_id, name),
  CONSTRAINT fk_classes_ay FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE,
  CONSTRAINT fk_classes_teacher FOREIGN KEY (homeroom_teacher_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_classes_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nis VARCHAR(30) NOT NULL,
  nisn VARCHAR(30) NULL,
  full_name VARCHAR(120) NOT NULL,
  gender ENUM('L', 'P') NOT NULL,
  birth_date DATE NULL,
  photo_path VARCHAR(255) NULL,
  parent_name VARCHAR(120) NULL,
  parent_phone VARCHAR(20) NULL,
  status ENUM('Aktif', 'Lulus', 'Pindah', 'Keluar') NOT NULL DEFAULT 'Aktif',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_students_nis (nis),
  KEY idx_students_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_member (student_id, academic_year_id),
  KEY idx_members_class (class_id),
  CONSTRAINT fk_member_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE,
  CONSTRAINT fk_member_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_member_ay FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kartu QR siswa: kode acak 12 karakter (bukan NIS), berversi; kartu lama dicabut saat diganti
CREATE TABLE IF NOT EXISTS cards (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  code VARCHAR(16) NOT NULL,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  issued_by INT UNSIGNED NULL,
  issued_at DATETIME NOT NULL,
  printed_at DATETIME NULL,
  revoked_at DATETIME NULL,
  revoke_reason VARCHAR(120) NULL,
  UNIQUE KEY uq_cards_code (code),
  KEY idx_cards_student (student_id, status),
  CONSTRAINT fk_cards_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_subjects_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS periods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  UNIQUE KEY uq_periods_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  semester_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NULL,
  room_id INT UNSIGNED NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  period_start TINYINT UNSIGNED NOT NULL,
  period_end TINYINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_sched_room_day (semester_id, room_id, day_of_week),
  KEY idx_sched_class_day (semester_id, class_id, day_of_week),
  KEY idx_sched_teacher (semester_id, teacher_id),
  CONSTRAINT fk_sched_sem FOREIGN KEY (semester_id) REFERENCES semesters (id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sched_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_sched_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE NOT NULL,
  name VARCHAR(120) NOT NULL,
  UNIQUE KEY uq_holiday (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== Fase 3: scanner kiosk dan inti absensi =====

CREATE TABLE IF NOT EXISTS devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  room_id INT UNSIGNED NULL,
  token_hash CHAR(64) NULL,
  pair_code CHAR(6) NULL,
  pair_expires_at DATETIME NULL,
  status ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
  paired_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  last_ip VARCHAR(45) NULL,
  app_version VARCHAR(20) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_devices_token (token_hash),
  KEY idx_devices_room (room_id, status),
  KEY idx_devices_pair (pair_code),
  CONSTRAINT fk_devices_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slot_key VARCHAR(64) NULL,
  schedule_id INT UNSIGNED NULL,
  semester_id INT UNSIGNED NULL,
  class_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NULL,
  subject_id INT UNSIGNED NULL,
  teacher_id INT UNSIGNED NULL,
  session_date DATE NOT NULL,
  period_start TINYINT UNSIGNED NULL,
  period_end TINYINT UNSIGNED NULL,
  scan_from DATETIME NOT NULL,
  starts_at DATETIME NOT NULL,
  late_after DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  kind ENUM('jadwal','tambahan','harian_masuk','harian_pulang') NOT NULL DEFAULT 'jadwal',
  status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
  note VARCHAR(200) NULL,
  opened_by INT UNSIGNED NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_sessions_slot (slot_key),
  KEY idx_sessions_date (session_date, class_id),
  KEY idx_sessions_open (status, ends_at),
  CONSTRAINT fk_sessions_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE SET NULL,
  CONSTRAINT fk_sessions_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
  CONSTRAINT fk_sessions_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  CONSTRAINT fk_sessions_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
  CONSTRAINT fk_sessions_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendances (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NOT NULL,
  status ENUM('H','T','I','S','D','A') NOT NULL,
  scanned_at DATETIME NULL,
  late_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  method ENUM('scan','manual','auto') NOT NULL DEFAULT 'scan',
  device_id INT UNSIGNED NULL,
  scan_id VARCHAR(64) NULL,
  note VARCHAR(200) NULL,
  changed_by INT UNSIGNED NULL,
  changed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_att_session_student (session_id, student_id),
  KEY idx_att_student (student_id, created_at),
  KEY idx_att_class (class_id, created_at),
  CONSTRAINT fk_att_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scan_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scan_id VARCHAR(64) NULL,
  device_id INT UNSIGNED NULL,
  code VARCHAR(32) NULL,
  session_id INT UNSIGNED NULL,
  student_id INT UNSIGNED NULL,
  result VARCHAR(20) NOT NULL,
  response TEXT NULL,
  scanned_at DATETIME NULL,
  received_at DATETIME NOT NULL,
  t_server_ms DECIMAL(8,2) NULL,
  metrics TEXT NULL,
  UNIQUE KEY uq_scanlog_scan (scan_id),
  KEY idx_scanlog_time (received_at),
  KEY idx_scanlog_device (device_id, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(40) NULL,
  role VARCHAR(10) NULL,
  class_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  object_type VARCHAR(40) NULL,
  object_id VARCHAR(64) NULL,
  result VARCHAR(20) NOT NULL DEFAULT 'ok',
  meta TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_audit_time (created_at),
  KEY idx_audit_class (class_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rl_key VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rl (rl_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  name VARCHAR(120) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
