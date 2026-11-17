-- Antrean notifikasi WhatsApp ke orang tua (Fase 6): satu pesan per siswa per hari
CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  notify_date DATE NOT NULL,
  kind ENUM('A','T') NOT NULL,
  phone VARCHAR(20) NULL,
  message TEXT NOT NULL,
  status ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  provider_ref VARCHAR(100) NULL,
  claimed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  UNIQUE KEY uq_notif_student_day (student_id, notify_date),
  KEY idx_notif_queue (status, next_attempt_at),
  KEY idx_notif_date (notify_date, status),
  CONSTRAINT fk_notif_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
