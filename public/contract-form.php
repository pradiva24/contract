<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
$companyId = require_company_id();
$adminId = (int)($_SESSION['admin']['id'] ?? 0);
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $templateId = max(0, (int)($_POST['template_id'] ?? 0));
    $companyName = trim((string)($_POST['company_name'] ?? ''));
    $email = trim((string)($_POST['recipient_email'] ?? ''));
    if ($templateId < 1 || $companyName === '' || mb_strlen($companyName) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        flash('error', 'Pilih template dan masukkan nama perusahaan serta email yang valid.');
        redirect('contract-form.php');
    }

    $st = db()->prepare('SELECT id,name,orientation,document_json FROM contract_templates WHERE id=? AND company_id=? AND is_active=1 LIMIT 1');
    $st->execute([$templateId, $companyId]);
    $template = $st->fetch();
    if (!$template) {
        flash('error', 'Template tidak ditemukan atau tidak aktif untuk perusahaan ini.');
        redirect('contract-form.php');
    }
    $versionQuery = db()->prepare('SELECT id,version_number,document_json FROM contract_template_versions WHERE company_id=? AND template_id=? ORDER BY version_number DESC LIMIT 1');
    $versionQuery->execute([$companyId, $templateId]);
    $version = $versionQuery->fetch();
    $documentJson = (string)($version['document_json'] ?? $template['document_json']);
    $document = json_decode($documentJson, true);
    if (!is_array($document) || empty($document['pages']) || !is_array($document['pages'])) {
        flash('error', 'Isi template tidak valid. Buka kembali Contract Studio dan simpan template.');
        redirect('contract-form.php');
    }
    $snapshot = json_encode([
        'template_id' => (int)$template['id'],
        'template_name' => (string)$template['name'],
        'template_version' => (int)($version['version_number'] ?? 0),
        'orientation' => (string)$template['orientation'],
        'document' => $document,
        'variables' => ['company_name' => $companyName]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($snapshot === false || strlen($snapshot) > 8 * 1024 * 1024) {
        flash('error', 'Snapshot template tidak dapat disimpan atau melebihi batas 8 MB.');
        redirect('contract-form.php');
    }

    $number = 'CTR-' . strtoupper(base_convert((string)$companyId, 10, 36)) . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    try {
        $ins = db()->prepare("INSERT INTO contracts(company_id,contract_number,template_id,template_version_id,template_snapshot_json,company_name,recipient_email,contract_date,status,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?, 'draft',?,?)");
        $ins->execute([$companyId,$number,$templateId,$version ? (int)$version['id'] : null,$snapshot,$companyName,$email,date('Y-m-d'),$adminId,$adminId]);
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO contract_history(company_id,contract_id,admin_id,action,description) VALUES(?,?,?,?,?)')
            ->execute([$companyId,$id,$adminId,'created','Created from template '.$template['name']]);
        audit_company($companyId, 'contract_created', 'contract', $id, 'Created '.$number);
        flash('success', 'Kontrak berhasil dibuat. Anda sekarang dapat melihat pratinjau, mengunduh PDF, atau mengirimkannya melalui email dari Contract Records.');
        redirect('contracts.php');
    } catch (Throwable $e) {
        error_log('Contract create failed: '.$e->getMessage());
        flash('error', 'Kontrak gagal disimpan. Periksa konfigurasi database dan migration yang diperlukan.');
        redirect('contract-form.php');
    }
}

$st = db()->prepare('SELECT id,name,orientation,is_default FROM contract_templates WHERE company_id=? AND is_active=1 ORDER BY is_default DESC,name');
$st->execute([$companyId]);
$templates = $st->fetchAll();
page_header('Create Contract');
?>
<div class="card">
 <p class="muted">Pilih template yang akan digunakan. Harga dan isi kontrak mengikuti template; nama perusahaan akan mengisi variabel <code>{{company_name}}</code>.</p>
 <?php if (!$templates): ?>
  <p>Belum ada template aktif. Buat template terlebih dahulu di <a href="<?=e(url('contract-templates.php'))?>">Contract Studio</a>.</p>
 <?php else: ?>
 <form method="post">
  <?=csrf_field()?>
  <div class="formgrid">
   <div><label>Contract template *</label><select name="template_id" required><?php foreach($templates as $t):?><option value="<?=(int)$t['id']?>"><?=$t['is_default']?'★ Default · ':''?><?=e($t['name'])?> · <?=e(ucfirst($t['orientation']))?></option><?php endforeach;?></select></div>
   <div><label>Company name *</label><input name="company_name" maxlength="180" required placeholder="Example: Bali Swing Pioneer"></div>
   <div><label>Recipient email *</label><input type="email" name="recipient_email" maxlength="190" required placeholder="company@example.com"></div>
  </div>
  <p><button class="btn" type="submit">Create contract record</button> <a class="btn light" href="<?=e(url('contracts.php'))?>">Cancel</a></p>
 </form>
 <?php endif;?>
</div>
<?php page_footer(); ?>
