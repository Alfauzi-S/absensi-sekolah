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
