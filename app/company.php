<?php
declare(strict_types=1);

/**
 * Tenant context helpers.
 * Super admins may explicitly switch between active companies.
 * Company admins are always restricted to their assigned company.
 */
function is_global_super_admin(): bool {
    return !empty($_SESSION['admin']) && ($_SESSION['admin']['role'] ?? '') === 'super_admin';
}

function current_company_id(): ?int {
    if (is_global_super_admin()) {
        $selected = $_SESSION['admin']['selected_company_id'] ?? null;
        return $selected === null || (int)$selected < 1 ? null : (int)$selected;
    }
    $id = $_SESSION['admin']['company_id'] ?? null;
    return $id === null || (int)$id < 1 ? null : (int)$id;
}

function require_company_id(): int {
    $companyId = current_company_id();
    if ($companyId === null) {
        http_response_code(403);
        exit('Super Admin: pilih perusahaan melalui menu Company Context terlebih dahulu.');
    }
    return $companyId;
}

function assert_company_access(int $resourceCompanyId): void {
    if (is_global_super_admin()) {
        $selected = current_company_id();
        if ($selected === null || $resourceCompanyId !== $selected) {
            http_response_code(404);
            exit('Resource not found in the selected company context.');
        }
        return;
    }
    if ($resourceCompanyId !== require_company_id()) {
        http_response_code(404);
        exit('Resource not found.');
    }
}

function company_setting(int $companyId, string $key, string $default = ''): string {
    $st = db()->prepare('SELECT setting_value FROM company_settings WHERE company_id=? AND setting_key=? LIMIT 1');
    $st->execute([$companyId, $key]);
    $value = $st->fetchColumn();
    if ($value !== false && $value !== null && $value !== '') return (string)$value;
    return setting($key, $default);
}

function audit_company(int $companyId, string $action, ?string $type = null, ?int $id = null, string $description = ''): void {
    $st = db()->prepare('INSERT INTO audit_logs(company_id,admin_id,action,entity_type,entity_id,description,ip_address) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$companyId, $_SESSION['admin']['id'] ?? null, $action, $type, $id, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
}

/**
 * Encrypt per-company SMTP credentials with an application secret.
 * Set APP_KEY in .env to a random, private value of at least 32 characters.
 */
function company_secret_encrypt(string $plain): string {
    $appKey = (string)config('APP_KEY', '');
    if (strlen($appKey) < 32) throw new RuntimeException('APP_KEY must be at least 32 characters in .env before saving SMTP passwords.');
    $key = hash('sha256', $appKey, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Unable to encrypt company SMTP password.');
    return base64_encode($iv . $tag . $cipher);
}

function company_secret_decrypt(string $encoded): string {
    $appKey = (string)config('APP_KEY', '');
    if (strlen($appKey) < 32) throw new RuntimeException('APP_KEY is missing or invalid. Configure it in .env.');
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Stored company SMTP password is invalid.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', $appKey, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) throw new RuntimeException('Unable to decrypt company SMTP password. Check APP_KEY.');
    return $plain;
}

function company_secret(int $companyId, string $key, string $default = ''): string {
    $st = db()->prepare('SELECT setting_value FROM company_settings WHERE company_id=? AND setting_key=? LIMIT 1');
    $st->execute([$companyId, $key]);
    $value = $st->fetchColumn();
    if ($value === false || $value === null || $value === '') return $default;
    return company_secret_decrypt((string)$value);
}

function save_company_setting(int $companyId, string $key, string $value): void {
    $st = db()->prepare('INSERT INTO company_settings(company_id,setting_key,setting_value) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $st->execute([$companyId, $key, $value]);
}

