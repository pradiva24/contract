CREATE DATABASE IF NOT EXISTS tarantula_certificate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tarantula_certificate;

CREATE TABLE admins (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('super_admin','admin','staff') NOT NULL DEFAULT 'admin',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 last_login_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 background_path VARCHAR(255) NOT NULL,
 canvas_width INT UNSIGNED NOT NULL DEFAULT 3508,
 canvas_height INT UNSIGNED NOT NULL DEFAULT 2480,
 config_json JSON NULL,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_templates_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE certificate_sequences (
 issue_date DATE PRIMARY KEY,
 last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE certificates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 certificate_number VARCHAR(40) NOT NULL UNIQUE,
 verification_token CHAR(64) NOT NULL UNIQUE,
 template_id BIGINT UNSIGNED NULL,
 recipient_name VARCHAR(180) NOT NULL,
 recipient_email VARCHAR(190) NOT NULL,
 activity_name VARCHAR(150) NOT NULL DEFAULT 'ATV Adventure',
 activity_date DATE NOT NULL,
 status ENUM('draft','issued','revoked') NOT NULL DEFAULT 'draft',
 pdf_path VARCHAR(255) NULL,
 issued_at DATETIME NULL,
 revoked_at DATETIME NULL,
 revoked_reason VARCHAR(500) NULL,
 notes TEXT NULL,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_cert_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL,
 CONSTRAINT fk_cert_creator FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL,
 CONSTRAINT fk_cert_updater FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL,
 INDEX idx_cert_email (recipient_email),
 INDEX idx_cert_date (activity_date),
 INDEX idx_cert_status (status)
) ENGINE=InnoDB;

CREATE TABLE email_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 certificate_id BIGINT UNSIGNED NOT NULL,
 recipient_email VARCHAR(190) NOT NULL,
 subject VARCHAR(255) NOT NULL,
 status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
 provider_message_id VARCHAR(190) NULL,
 error_message TEXT NULL,
 sent_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_email_cert FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE CASCADE,
 INDEX idx_email_status (status)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admin_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 entity_type VARCHAR(80) NULL,
 entity_id BIGINT UNSIGNED NULL,
 description TEXT NULL,
 ip_address VARCHAR(45) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL,
 INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE settings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 setting_key VARCHAR(120) NOT NULL UNIQUE,
 setting_value TEXT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
('business_name','Bali Tarantula Adventure'),
('website_url','https://balitarantula.com'),
('certificate_prefix','BTA'),
('default_activity_name','ATV Adventure'),
('email_subject','Your ATV Adventure Certificate'),
('email_body','Thank you for joining Bali Tarantula Adventure. Your certificate is attached.')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
