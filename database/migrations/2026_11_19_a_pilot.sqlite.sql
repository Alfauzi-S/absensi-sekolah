-- Pilot (Fase 7): absen manual guru untuk dibandingkan dengan hasil sistem, dan masukan pengguna
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
