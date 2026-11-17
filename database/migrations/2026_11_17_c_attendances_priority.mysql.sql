-- Prioritas status: koreksi manual > izin/sakit/dispensasi > scan > Alpa otomatis (Fase 5)
ALTER TABLE attendances ADD COLUMN leave_id INT UNSIGNED NULL, ADD COLUMN corrected TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE sessions ADD KEY idx_sessions_class_date (class_id, session_date);
