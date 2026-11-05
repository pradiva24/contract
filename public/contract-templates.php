<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();

$isSuper = is_global_super_admin();
$ownCompanyId = $isSuper ? null : require_company_id();
$companies = [];
if ($isSuper) {
    $companies = db()->query('SELECT id,name FROM companies WHERE is_active=1 ORDER BY name')->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = max(0, (int)($_POST['id'] ?? 0));
    $action = (string)($_POST['action'] ?? '');
    $st = db()->prepare('SELECT id,company_id,is_default FROM contract_templates WHERE id=? AND is_active=1 LIMIT 1');
    $st->execute([$id]);
    $target = $st->fetch();
    if (!$target || (!$isSuper && (int)$target['company_id'] !== $ownCompanyId)) {
        flash('error', 'Template not found or access denied.');
        redirect('contract-templates.php');
    }
    $companyId = (int)$target['company_id'];

    if ($action === 'delete') {
        if ((int)$target['is_default'] === 1) {
            flash('error', 'Set another template as default before deleting this template.');
            redirect('contract-templates.php');
        }
        db()->prepare('UPDATE contract_templates SET is_active=0 WHERE id=? AND company_id=?')->execute([$id,$companyId]);
        audit_company($companyId,'contract_template_deleted','contract_template',$id,'Soft-deleted contract template.');
        flash('success','Template deleted. Existing contract records remain unchanged.');
        redirect('contract-templates.php');
    }
    if ($action === 'set_default') {
        db()->beginTransaction();
        try {
            db()->prepare('UPDATE contract_templates SET is_default=0 WHERE company_id=?')->execute([$companyId]);
            db()->prepare('UPDATE contract_templates SET is_default=1 WHERE id=? AND company_id=?')->execute([$id,$companyId]);
            audit_company($companyId,'contract_template_default_changed','contract_template',$id,'Set default contract template.');
            db()->commit();
            flash('success','Default template updated.');
        } catch (Throwable $e) {
            if(db()->inTransaction()) db()->rollBack();
            flash('error','Unable to update default template.');
        }
        redirect('contract-templates.php');
    }
    flash('error','Unknown template action.');
    redirect('contract-templates.php');
}

if ($isSuper) {
    $templates = db()->query('SELECT t.id,t.company_id,t.name,t.orientation,t.updated_at,t.is_default,c.name AS company_name FROM contract_templates t INNER JOIN companies c ON c.id=t.company_id WHERE t.is_active=1 ORDER BY c.name,t.is_default DESC,t.updated_at DESC')->fetchAll();
    $createCompanyId = max(0,(int)($_GET['company_id'] ?? 0));
    if ($createCompanyId && !in_array($createCompanyId,array_map(static fn($c)=>(int)$c['id'],$companies),true)) $createCompanyId=0;
} else {
    $st=db()->prepare('SELECT id,company_id,name,orientation,updated_at,is_default,? AS company_name FROM contract_templates WHERE company_id=? AND is_active=1 ORDER BY is_default DESC,updated_at DESC');
    $st->execute([ (string)(db()->query('SELECT name FROM companies WHERE id='.(int)$ownCompanyId)->fetchColumn() ?: 'Company'), $ownCompanyId ]);
    $templates=$st->fetchAll();
    $createCompanyId=$ownCompanyId;
}
page_header('Contract Studio Templates');
?>
<div class="card">
 <div class="actions" style="justify-content:space-between;align-items:center;flex-wrap:wrap">
  <div><h2 style="margin:0"><?= $isSuper ? 'All company templates' : 'Your contract templates' ?></h2><p class="muted"><?= $isSuper ? 'Super Admin can edit templates across every business without switching company context.' : 'Design reusable contract layouts for this company.' ?></p></div>
  <?php if ($isSuper): ?>
   <form method="get" class="actions"><label for="createCompany">Create template for</label><select id="createCompany" name="company_id" required><option value="">Select business</option><?php foreach($companies as $c):?><option value="<?=(int)$c['id']?>" <?=$createCompanyId===(int)$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select><button class="btn" type="submit">+ Create template</button></form>
  <?php else: ?><a class="btn" href="<?=e(url('contract-studio.php'))?>">+ Create template</a><?php endif;?>
 </div>
</div>
<div class="card" style="overflow-x:auto">
 <table><thead><tr><?php if($isSuper):?><th>Business</th><?php endif;?><th>Template name</th><th>Page orientation</th><th>Last updated</th><th>Default</th><th>Action</th></tr></thead><tbody>
 <?php foreach($templates as $row): ?>
  <tr><?php if($isSuper):?><td><strong><?=e($row['company_name'])?></strong></td><?php endif;?><td><strong><?=e($row['name'])?></strong></td><td><?=e(ucfirst($row['orientation']))?></td><td><?=e($row['updated_at'])?></td><td><?=$row['is_default']?'<span class="badge">Default</span>':'—'?></td><td><div class="actions"><a class="btn light" href="<?=e(url('contract-studio.php?id='.(int)$row['id'].'&company_id='.(int)$row['company_id']))?>">Open editor</a><?php if(!$row['is_default']):?><form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="set_default"><button class="btn dark" type="submit">Set default</button></form><?php endif;?><form method="post" style="margin:0" onsubmit="return confirm('Delete this template? Existing contracts will not be deleted.');"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$row['id']?>"><input type="hidden" name="action" value="delete"><button class="btn danger" type="submit">Delete</button></form></div></td></tr>
 <?php endforeach; ?>
 <?php if(!$templates): ?><tr><td colspan="<?=$isSuper?6:5?>" class="muted">No contract templates yet. Create your first design.</td></tr><?php endif; ?>
 </tbody></table>
</div>
<?php page_footer(); ?>
