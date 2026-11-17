-- Pilot (Fase 7): absen manual guru untuk dibandingkan dengan hasil sistem, dan masukan pengguna
CREATE TABLE IF NOT EXISTS pilot_checks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  class_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  check_date DATE NOT NULL,
  manual_status ENUM('H','T','S','I','A','D') NOT NULL,
  explanation VARCHAR(500) NULL,
  explained_by INT UNSIGNED NULL,
  explained_at DATETIME NULL,
  entered_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_pilot_student_date (student_id, check_date),
  KEY idx_pilot_class_date (class_id, check_date),
  CONSTRAINT fk_pilot_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pilot_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedback (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  topic VARCHAR(40) NOT NULL,
  rating TINYINT UNSIGNED NULL,
  message TEXT NOT NULL,
  status ENUM('new','done') NOT NULL DEFAULT 'new',
  handled_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_feedback_status (status, created_at),
  CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
