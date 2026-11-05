<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
$companyId = require_company_id();
$q = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT c.*, t.name AS template_name,
 (SELECT el.status FROM contract_email_logs el WHERE el.contract_id=c.id AND el.company_id=c.company_id ORDER BY el.id DESC LIMIT 1) AS email_status,
 (SELECT el.sent_at FROM contract_email_logs el WHERE el.contract_id=c.id AND el.company_id=c.company_id AND el.status='sent' ORDER BY el.id DESC LIMIT 1) AS email_sent_at
 FROM contracts c LEFT JOIN contract_templates t ON t.id=c.template_id WHERE c.company_id=?";
$args = [$companyId];
if ($q !== '') { $sql .= " AND (c.contract_number LIKE ? OR c.company_name LIKE ? OR c.recipient_email LIKE ?)"; $args[]="%$q%"; $args[]="%$q%"; $args[]="%$q%"; }
$sql .= " ORDER BY c.id DESC LIMIT 300";
$st=db()->prepare($sql); $st->execute($args); $rows=$st->fetchAll();
page_header('Contract Records');
?>
<div class="card">
 <div class="actions" style="justify-content:space-between;align-items:center">
  <a class="btn" href="<?=e(url('contract-form.php'))?>">+ Create contract</a>
  <form method="get" class="actions"><input name="q" value="<?=e($q)?>" placeholder="Search contract, company, email"><button class="btn dark">Search</button></form>
 </div>
</div>
<div class="card">
 <table><thead><tr><th>Contract no.</th><th>Company</th><th>Email</th><th>Template</th><th>Date</th><th>Contract</th><th>Email status</th></tr></thead><tbody>
 <?php foreach($rows as $r):
  $es=(string)($r['email_status']??'not_sent'); $cls='email-not-sent'; $label='Not sent';
  if($es==='sent'){$cls='email-sent';$label='Sent';}elseif($es==='failed'){$cls='email-failed';$label='Failed';}elseif($es==='queued'){$cls='email-pending';$label='Pending';}
 ?>
 <tr><td><strong><?=e($r['contract_number'])?></strong></td><td><?=e($r['company_name'])?></td><td><?=e($r['recipient_email'])?></td><td><?=e($r['template_name']??'Template archived')?></td><td><?=e($r['contract_date'])?></td><td><span class="badge"><?=e($r['status'])?></span></td><td><span class="email-status <?=$cls?>"><?=$label?></span><?php if($r['email_sent_at']):?><br><small><?=e($r['email_sent_at'])?></small><?php endif;?></td></tr>
 <?php endforeach;?>
 <?php if(!$rows):?><tr><td colspan="7" class="muted">No contract records yet.</td></tr><?php endif;?>
 </tbody></table>
</div>
<style>
.email-status{display:inline-flex;padding:4px 9px;border-radius:20px;font-size:12px;font-weight:700}
.email-sent{background:#dcfce7;color:#166534}.email-failed{background:#fee2e2;color:#991b1b}.email-pending{background:#ffedd5;color:#c2410c}.email-not-sent{background:#f1f5f9;color:#64748b}
@media(max-width:700px){.card table{min-width:850px}}
</style>
<?php page_footer();?>
