CREATE TABLE IF NOT EXISTS scan_metrics (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scan_id VARCHAR(64) NULL,
  device_id INT UNSIGNED NULL,
  source VARCHAR(10) NULL,
  result VARCHAR(20) NULL,
  t_decode_ms INT UNSIGNED NULL,
  t_feedback_ms INT UNSIGNED NULL,
  t_roundtrip_ms INT UNSIGNED NULL,
  t_server_ms DECIMAL(8,2) NULL,
  t_total_ms INT UNSIGNED NULL,
  local_hit TINYINT(1) NOT NULL DEFAULT 0,
  queued TINYINT(1) NOT NULL DEFAULT 0,
  queue_wait_ms INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_metrics_scan (scan_id),
  KEY idx_metrics_device (device_id, created_at),
  KEY idx_metrics_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
