-- Migrasi aditif: tabel penugasan Admin Event dan audit log.
-- Hanya membuat tabel baru (CREATE TABLE IF NOT EXISTS). Tidak mengubah atau menghapus data lama.

CREATE TABLE IF NOT EXISTS event_admin_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  permissions TEXT NOT NULL,
  assigned_by INTEGER NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  UNIQUE (user_id, event_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NULL,
  username TEXT NULL,
  role TEXT NULL,
  event_id INTEGER NULL,
  action TEXT NOT NULL,
  object_type TEXT NULL,
  object_id TEXT NULL,
  result TEXT NOT NULL DEFAULT 'ok',
  meta TEXT NULL,
  ip TEXT NULL,
  created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_assign_event ON event_admin_assignments (event_id);
CREATE INDEX IF NOT EXISTS idx_audit_event ON audit_logs (event_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_time ON audit_logs (created_at);
