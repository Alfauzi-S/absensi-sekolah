-- Riwayat perubahan status kehadiran: siapa, kapan, dari apa ke apa, alasan (Fase 5)
CREATE TABLE IF NOT EXISTS status_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT UNSIGNED NULL,
  session_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  old_status CHAR(1) NULL,
  new_status CHAR(1) NULL,
  source VARCHAR(20) NOT NULL,
  reason VARCHAR(255) NULL,
  leave_id INT UNSIGNED NULL,
  actor_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_history_session (session_id, created_at),
  KEY idx_history_student (student_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
