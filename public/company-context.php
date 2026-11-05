<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
verify_csrf();

if (!is_global_super_admin()) {
    http_response_code(403);
    exit('Only Super Admin can switch company context.');
}

$companyId = max(0, (int)($_POST['company_id'] ?? 0));
if ($companyId < 1) {
    unset($_SESSION['admin']['selected_company_id']);
    flash('success', 'Company context cleared. Select a company to access its data.');
    redirect('dashboard.php');
}

$st = db()->prepare('SELECT id,name FROM companies WHERE id=? AND is_active=1 LIMIT 1');
$st->execute([$companyId]);
$company = $st->fetch();
if (!$company) {
    http_response_code(404);
    exit('Company not found or inactive.');
}

$_SESSION['admin']['selected_company_id'] = (int)$company['id'];
audit('company_context_switched', 'company', (int)$company['id'], 'Super Admin switched context to '.$company['name']);
flash('success', 'Company context changed to '.$company['name'].'.');
redirect('dashboard.php');
