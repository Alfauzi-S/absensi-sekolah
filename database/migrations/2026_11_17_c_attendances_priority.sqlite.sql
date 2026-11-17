-- Prioritas status: koreksi manual > izin/sakit/dispensasi > scan > Alpa otomatis (Fase 5)
ALTER TABLE attendances ADD COLUMN leave_id INTEGER NULL;
ALTER TABLE attendances ADD COLUMN corrected INTEGER NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_sessions_class_date ON sessions (class_id, session_date);
