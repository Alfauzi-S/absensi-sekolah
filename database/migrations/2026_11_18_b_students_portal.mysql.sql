-- Kode akses portal orang tua (hash), Fase 6
ALTER TABLE students ADD COLUMN portal_code_hash VARCHAR(255) NULL, ADD COLUMN portal_code_at DATETIME NULL;
