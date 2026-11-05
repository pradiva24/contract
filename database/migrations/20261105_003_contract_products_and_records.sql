-- Phase 2: company-scoped contract product catalog and immutable contract records
-- Requires 20261105_001_multi_company_foundation.sql and 20261105_002_contract_studio.sql.
-- MySQL 8.0+, InnoDB. Back up the database before applying.

CREATE TABLE IF NOT EXISTS contract_products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(80) NULL,
  name VARCHAR(180) NOT NULL,
  short_description VARCHAR(500) NULL,
  description LONGTEXT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'IDR',
  publish_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  contract_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contract_product_company_sku (company_id, sku),
  INDEX idx_contract_products_company_active (company_id, is_active, sort_order),
  CONSTRAINT fk_contract_products_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_products_created_by FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
  CONSTRAINT fk_contract_products_updated_by FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_product_inclusions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(220) NOT NULL,
  description VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contract_product_inclusions_product (company_id, product_id, sort_order),
  CONSTRAINT fk_cpi_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_cpi_product FOREIGN KEY (product_id) REFERENCES contract_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_product_gallery (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  alt_text VARCHAR(220) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contract_product_gallery_product (company_id, product_id, sort_order),
  CONSTRAINT fk_cpg_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_cpg_product FOREIGN KEY (product_id) REFERENCES contract_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contracts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  contract_number VARCHAR(100) NOT NULL,
  status ENUM('draft','sent','signed','cancelled') NOT NULL DEFAULT 'draft',
  customer_name VARCHAR(180) NOT NULL,
  customer_company VARCHAR(180) NULL,
  customer_email VARCHAR(190) NULL,
  customer_phone VARCHAR(60) NULL,
  customer_address TEXT NULL,
  contract_date DATE NOT NULL,
  valid_until DATE NULL,
  currency CHAR(3) NOT NULL DEFAULT 'IDR',
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  grand_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  template_id BIGINT UNSIGNED NULL,
  template_version_id BIGINT UNSIGNED NULL,
  template_snapshot_json JSON NULL,
  terms_snapshot LONGTEXT NULL,
  notes TEXT NULL,
  pdf_path VARCHAR(500) NULL,
  sent_at DATETIME NULL,
  signed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contract_company_number (company_id, contract_number),
  INDEX idx_contracts_company_status_date (company_id, status, contract_date),
  INDEX idx_contracts_company_customer (company_id, customer_name),
  CONSTRAINT fk_contracts_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_contracts_template FOREIGN KEY (template_id) REFERENCES contract_templates(id) ON DELETE SET NULL,
  CONSTRAINT fk_contracts_template_version FOREIGN KEY (template_version_id) REFERENCES contract_template_versions(id) ON DELETE SET NULL,
  CONSTRAINT fk_contracts_created_by FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
  CONSTRAINT fk_contracts_updated_by FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  contract_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  product_snapshot_json JSON NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  publish_unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  contract_unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contract_items_contract (company_id, contract_id, sort_order),
  CONSTRAINT fk_contract_items_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_items_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_items_product FOREIGN KEY (product_id) REFERENCES contract_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  contract_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  snapshot_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contract_version (contract_id, version_number),
  INDEX idx_contract_versions_company_contract (company_id, contract_id),
  CONSTRAINT fk_contract_versions_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_versions_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_versions_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  contract_id BIGINT UNSIGNED NOT NULL,
  admin_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  description VARCHAR(500) NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contract_history_contract (company_id, contract_id, created_at),
  CONSTRAINT fk_contract_history_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_history_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
  CONSTRAINT fk_contract_history_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
