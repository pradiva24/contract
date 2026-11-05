-- Contract Studio foundation (Canvas-first editor)
-- Run after 20261105_001_multi_company_foundation.sql.
-- MySQL 8.0+, backup before applying.

CREATE TABLE IF NOT EXISTS contract_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  editor_mode ENUM('design','document') NOT NULL DEFAULT 'design',
  page_size VARCHAR(20) NOT NULL DEFAULT 'A4',
  orientation ENUM('portrait','landscape') NOT NULL DEFAULT 'portrait',
  document_json JSON NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_contract_templates_company (company_id, is_active),
  CONSTRAINT fk_contract_templates_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_templates_created_by FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_templates_updated_by FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_template_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  template_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  document_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contract_template_version (template_id, version_number),
  INDEX idx_contract_template_versions_company (company_id, template_id),
  CONSTRAINT fk_ctv_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_ctv_template FOREIGN KEY (template_id) REFERENCES contract_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_ctv_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
