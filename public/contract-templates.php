<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
$companyId = require_company_id();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = max(0, (int)($_POST['id'] ?? 0));
    $check = db()->prepare('SELECT id FROM contract_templates WHERE id=? AND company_id=? AND is_active=1 LIMIT 1');
    $check->execute([$id,$companyId]);
    if (!$check->fetchColumn()) { flash('error','Template not found.'); redirect('contract-templates.php'); }
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE contract_templates SET is_default=0 WHERE company_id=?')->execute([$companyId]);
        db()->prepare('UPDATE contract_templates SET is_default=1 WHERE id=? AND company_id=?')->execute([$id,$companyId]);
        audit_company($companyId,'contract_template_default_changed','contract_template',$id,'Set default contract template.');
        db()->commit();
        flash('success','Default template updated.');
    } catch (Throwable $e) {
        if(db()->inTransaction())db()->rollBack();
        flash('error','Unable to update default template.');
    }
    redirect('contract-templates.php');
}
$st = db()->prepare('SELECT id,name,orientation,updated_at,is_default FROM contract_templates WHERE company_id=? AND is_active=1 ORDER BY is_default DESC,updated_at DESC');
$st->execute([$companyId]);
$templates = $st->fetchAll();
page_header('Contract Studio Templates');
?>
<div class="card">
 <div class="actions" style="justify-content:space-between;align-items:center">
  <div><h2 style="margin:0">Your contract templates</h2><p class="muted">Design reusable contract layouts for this company. Set one default template to preselect it on Create Contract.</p></div>
  <a class="btn" href="<?=e(url('contract-studio.php'))?>">+ Create template</a>
 </div>
</div>
<div class="card" style="overflow-x:auto">
 <table><thead><tr><th>Template name</th><th>Page orientation</th><th>Last updated</th><th>Default</th><th>Action</th></tr></thead><tbody>
 <?php foreach($templates as $row): ?>
  <tr><td><strong><?=e($row['name'])?></strong></td><td><?=e(ucfirst($row['orientation']))?></td><td><?=e($row['updated_at'])?></td><td><?=$row['is_default']?'<span class="badge">Default</span>':'—'?></td><td><div class="actions"><a class="btn light" href="<?=e(url('contract-studio.php?id='.(int)$row['id']))?>">Open editor</a><?php if(!$row['is_default']):?><form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn dark" type="submit">Set default</button></form><?php endif;?></div></td></tr>
 <?php endforeach; ?>
 <?php if(!$templates): ?><tr><td colspan="5" class="muted">No contract templates yet. Create your first design.</td></tr><?php endif; ?>
 </tbody></table>
</div>
<?php page_footer(); ?>
