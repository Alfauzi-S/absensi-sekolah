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
