-- Phase 1: multi-company foundation
-- Target: MySQL 8.0+, InnoDB. Take a database backup before running.
-- This migration is intentionally additive; existing certificate data is retained.
-- Run once against the application's active database.

CREATE TABLE IF NOT EXISTS companies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(160) NOT NULL UNIQUE,
  code VARCHAR(30) NOT NULL UNIQUE,
  website_url VARCHAR(255) NULL,
  logo_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_company_setting (company_id, setting_key),
  CONSTRAINT fk_company_settings_company FOREIGN KEY (company_id)
    REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add nullable tenant keys first so the existing records can be safely backfilled.
SET @schema_name = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='admins' AND COLUMN_NAME='company_id')=0,
  'ALTER TABLE admins ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='templates' AND COLUMN_NAME='company_id')=0,
  'ALTER TABLE templates ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='certificates' AND COLUMN_NAME='company_id')=0,
  'ALTER TABLE certificates ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='audit_logs' AND COLUMN_NAME='company_id')=0,
  'ALTER TABLE audit_logs ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER admin_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing single-company installation becomes the first tenant.
INSERT INTO companies (name, slug, code, website_url, is_active)
VALUES ('Bali Tarantula Adventure', 'bali-tarantula', 'BTA', 'https://balitarantula.com', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), website_url=VALUES(website_url);

SET @bta_company_id = (SELECT id FROM companies WHERE slug='bali-tarantula' LIMIT 1);

-- Preserve a global super admin; scope ordinary admins and existing business data to BTA.
UPDATE admins SET company_id=@bta_company_id WHERE company_id IS NULL AND role<>'super_admin';
UPDATE templates SET company_id=@bta_company_id WHERE company_id IS NULL;
UPDATE certificates SET company_id=@bta_company_id WHERE company_id IS NULL;
UPDATE audit_logs SET company_id=@bta_company_id
 WHERE company_id IS NULL
   AND admin_id IN (SELECT id FROM admins WHERE company_id=@bta_company_id);

-- Indexes are created conditionally to tolerate a partially applied migration.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='admins' AND INDEX_NAME='idx_admins_company')=0,
  'CREATE INDEX idx_admins_company ON admins(company_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='templates' AND INDEX_NAME='idx_templates_company')=0,
  'CREATE INDEX idx_templates_company ON templates(company_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='certificates' AND INDEX_NAME='idx_certificates_company')=0,
  'CREATE INDEX idx_certificates_company ON certificates(company_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='audit_logs' AND INDEX_NAME='idx_audit_company')=0,
  'CREATE INDEX idx_audit_company ON audit_logs(company_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add foreign keys only when missing.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema_name AND TABLE_NAME='admins' AND CONSTRAINT_NAME='fk_admins_company')=0,
  'ALTER TABLE admins ADD CONSTRAINT fk_admins_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema_name AND TABLE_NAME='templates' AND CONSTRAINT_NAME='fk_templates_company')=0,
  'ALTER TABLE templates ADD CONSTRAINT fk_templates_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema_name AND TABLE_NAME='certificates' AND CONSTRAINT_NAME='fk_certificates_company')=0,
  'ALTER TABLE certificates ADD CONSTRAINT fk_certificates_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema_name AND TABLE_NAME='audit_logs' AND CONSTRAINT_NAME='fk_audit_company')=0,
  'ALTER TABLE audit_logs ADD CONSTRAINT fk_audit_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Copy current global settings as the initial BTA company settings.
INSERT INTO company_settings (company_id, setting_key, setting_value)
SELECT @bta_company_id, setting_key, setting_value
FROM settings
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

-- Bali Swing is provisioned as a separate tenant. No user or business records
-- are assigned to it until an authorized super admin creates its users.
INSERT INTO companies (name, slug, code, website_url, is_active)
VALUES ('Bali Swing', 'bali-swing', 'BSW', NULL, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);
