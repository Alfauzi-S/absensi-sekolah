-- Migrasi aditif: tabel penugasan Admin Event dan audit log.
-- Hanya membuat tabel baru (CREATE TABLE IF NOT EXISTS). Tidak mengubah atau menghapus data lama.

CREATE TABLE IF NOT EXISTS event_admin_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  permissions TEXT NOT NULL,
  assigned_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_assign (user_id, event_id),
  KEY idx_assign_event (event_id),
  CONSTRAINT fk_assign_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_assign_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(60) NULL,
  role VARCHAR(10) NULL,
  event_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  object_type VARCHAR(40) NULL,
  object_id VARCHAR(60) NULL,
  result VARCHAR(10) NOT NULL DEFAULT 'ok',
  meta TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_audit_event (event_id, created_at),
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

