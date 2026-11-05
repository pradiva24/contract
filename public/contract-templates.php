<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
$companyId = require_company_id();
$st = db()->prepare('SELECT id,name,orientation,updated_at FROM contract_templates WHERE company_id=? AND is_active=1 ORDER BY updated_at DESC');
$st->execute([$companyId]);
$templates = $st->fetchAll();
page_header('Contract Studio Templates');
?>
<div class="card">
 <div class="actions" style="justify-content:space-between;align-items:center">
  <div><h2 style="margin:0">Your contract templates</h2><p class="muted">Design reusable contract layouts for this company.</p></div>
  <a class="btn" href="<?=e(url('contract-studio.php'))?>">+ Create template</a>
 </div>
</div>
<div class="card">
 <table>
  <thead><tr><th>Template name</th><th>Page orientation</th><th>Last updated</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach($templates as $row): ?>
   <tr><td><strong><?=e($row['name'])?></strong></td><td><?=e(ucfirst($row['orientation']))?></td><td><?=e($row['updated_at'])?></td><td><a class="btn" href="<?=e(url('contract-studio.php?id='.(int)$row['id']))?>">Open editor</a></td></tr>
  <?php endforeach; ?>
  <?php if(!$templates): ?><tr><td colspan="4" class="muted">No contract templates yet. Create your first design.</td></tr><?php endif; ?>
  </tbody>
 </table>
</div>
<?php page_footer(); ?>
