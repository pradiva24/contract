<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/contract.php';
admin_required();
$companyId = require_company_id();
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$mode = (string)($_GET['action'] ?? $_POST['action'] ?? '');
try {
    $contract = contract_get_for_company($id, $companyId);
    if ($mode === 'preview' || $mode === 'download') {
        $path = contract_pdf_path($id, $companyId);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($mode === 'preview' ? 'inline' : 'attachment') . '; filename="' . basename($contract['contract_number']) . '.pdf"');
        readfile($path);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required.'); }
    verify_csrf();
    if ($mode === 'generate') {
        generate_contract_pdf($id, $companyId);
        flash('success', 'Contract PDF generated successfully.');
    } elseif ($mode === 'email') {
        send_contract_email($id, $companyId);
        flash('success', 'Contract PDF sent to ' . $contract['recipient_email'] . '.');
    } else {
        http_response_code(400); exit('Unknown contract action.');
    }
} catch (Throwable $e) {
    error_log('Contract action failed: ' . $e->getMessage());
    flash('error', 'Action failed: ' . $e->getMessage());
}
redirect('contracts.php');
