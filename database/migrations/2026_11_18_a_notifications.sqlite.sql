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
