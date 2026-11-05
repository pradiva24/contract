<?php
declare(strict_types=1);

/**
 * Tenant context helpers.
 *
 * A null company_id is reserved for a global super administrator. It must
 * never be interpreted as permission to access all tenant data implicitly.
 */
function current_company_id(): ?int {
    $id = $_SESSION['admin']['company_id'] ?? null;
    return $id === null || (int)$id < 1 ? null : (int)$id;
}

function is_global_super_admin(): bool {
    return !empty($_SESSION['admin'])
        && ($_SESSION['admin']['role'] ?? '') === 'super_admin'
        && current_company_id() === null;
}

/**
 * Require a tenant context for tenant-owned operations.
 * Global super admins must explicitly select/assume a company in a future
 * company-switching workflow before accessing tenant data.
 */
function require_company_id(): int {
    $companyId = current_company_id();
    if ($companyId === null) {
        http_response_code(403);
        exit('Select a company context before accessing company data.');
    }
    return $companyId;
}

function assert_company_access(int $resourceCompanyId): void {
    $companyId = require_company_id();
    if ($resourceCompanyId !== $companyId) {
        http_response_code(404);
        exit('Resource not found.');
    }
}

/** Read a tenant setting, falling back to the legacy global setting. */
function company_setting(int $companyId, string $key, string $default = ''): string {
    $st = db()->prepare('SELECT setting_value FROM company_settings WHERE company_id=? AND setting_key=? LIMIT 1');
    $st->execute([$companyId, $key]);
    $value = $st->fetchColumn();
    if ($value !== false && $value !== null && $value !== '') {
        return (string)$value;
    }
    return setting($key, $default);
}

/** Tenant-aware audit event for new company-scoped modules. */
function audit_company(int $companyId, string $action, ?string $type = null, ?int $id = null, string $description = ''): void {
    $st = db()->prepare(
        'INSERT INTO audit_logs(company_id,admin_id,action,entity_type,entity_id,description,ip_address)
         VALUES(?,?,?,?,?,?,?)'
    );
    $st->execute([
        $companyId,
        $_SESSION['admin']['id'] ?? null,
        $action,
        $type,
        $id,
        $description,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}
