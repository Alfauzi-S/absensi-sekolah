-- Metrik kecepatan per scan dari kiosk (Fase 4): semua dalam milidetik.
CREATE TABLE IF NOT EXISTS scan_metrics (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scan_id TEXT NULL UNIQUE,
  device_id INTEGER NULL,
  source TEXT NULL,
  result TEXT NULL,
  t_decode_ms INTEGER NULL,
  t_feedback_ms INTEGER NULL,
  t_roundtrip_ms INTEGER NULL,
  t_server_ms REAL NULL,
  t_total_ms INTEGER NULL,
  local_hit INTEGER NOT NULL DEFAULT 0,
  queued INTEGER NOT NULL DEFAULT 0,
  queue_wait_ms INTEGER NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_metrics_device ON scan_metrics (device_id, created_at);
CREATE INDEX IF NOT EXISTS idx_metrics_time ON scan_metrics (created_at);
