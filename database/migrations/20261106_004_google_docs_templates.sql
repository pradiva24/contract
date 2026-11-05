-- Company-scoped Google Docs master-template registry.
-- Apply after migrations 001-003. Back up the database first.
CREATE TABLE IF NOT EXISTS google_doc_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(180) NOT NULL,
 document_id VARCHAR(180) NOT NULL,
 document_url VARCHAR(1000) NOT NULL,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_google_doc_company_document (company_id, document_id),
 INDEX idx_google_doc_templates_company (company_id, is_active),
 CONSTRAINT fk_google_doc_templates_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
 CONSTRAINT fk_google_doc_templates_created_by FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
 CONSTRAINT fk_google_doc_templates_updated_by FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
