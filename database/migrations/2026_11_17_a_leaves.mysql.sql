-- Izin, sakit, dispensasi per rentang tanggal (Fase 5)
CREATE TABLE IF NOT EXISTS leaves (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  class_id INT UNSIGNED NULL,
  kind ENUM('I','S','D') NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  reason VARCHAR(255) NOT NULL,
  attachment VARCHAR(255) NULL,
  status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  cancelled_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  KEY idx_leaves_student (student_id, date_from, date_to),
  KEY idx_leaves_class (class_id, date_from),
  CONSTRAINT fk_leaves_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
