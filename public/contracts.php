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
if ($q !== '') { $sql .= " AND (c.contract_number LIKE ? OR c.company_name LIKE ? OR c.recipient_email LIKE ?)"; array_push($args,"%$q%","%$q%","%$q%"); }
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
<div class="card" style="overflow-x:auto">
 <table><thead><tr><th>Contract no.</th><th>Company</th><th>Email</th><th>Template</th><th>Date</th><th>PDF</th><th>Email status</th><th>Actions</th></tr></thead><tbody>
 <?php foreach($rows as $r):
  $es=(string)($r['email_status']??'not_sent'); $cls='email-not-sent'; $label='Not Sent';
  if($es==='sent'){$cls='email-sent';$label='Sent ✓';}elseif($es==='failed'){$cls='email-failed';$label='Failed';}elseif($es==='queued'){$cls='email-pending';$label='Pending';}
 ?>
 <tr>
  <td><strong><?=e($r['contract_number'])?></strong></td><td><?=e($r['company_name'])?></td><td><?=e($r['recipient_email'])?></td><td><?=e($r['template_name']??'Template archived')?></td><td><?=e($r['contract_date'])?></td>
  <td><span class="badge"><?=e($r['status'])?></span></td>
  <td><span class="email-status <?=$cls?>"><?=$label?></span><?php if($r['email_sent_at']):?><br><small><?=e($r['email_sent_at'])?></small><?php endif;?></td>
  <td><div class="contract-actions">
   <a class="btn light" target="_blank" rel="noopener" href="<?=e(url('contract-action.php?id='.(int)$r['id'].'&action=preview'))?>">Preview</a>
   <a class="btn dark" href="<?=e(url('contract-action.php?id='.(int)$r['id'].'&action=download'))?>">Download</a>
   <form method="post" action="<?=e(url('contract-action.php'))?>" onsubmit="return confirm('Generate or refresh this contract PDF?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input type="hidden" name="action" value="generate"><button class="btn light" type="submit">Generate</button></form>
   <form method="post" action="<?=e(url('contract-action.php'))?>" onsubmit="return confirm('Send contract PDF to <?=e(addslashes($r['recipient_email']))?>?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input type="hidden" name="action" value="email"><button class="btn" type="submit">Send Email</button></form>
  </div></td>
 </tr>
 <?php endforeach;?>
 <?php if(!$rows):?><tr><td colspan="8" class="muted">No contract records yet.</td></tr><?php endif;?>
 </tbody></table>
</div>
<style>
.email-status{display:inline-flex;padding:4px 9px;border-radius:20px;font-size:12px;font-weight:700}
.email-sent{background:#dcfce7;color:#166534}.email-failed{background:#fee2e2;color:#991b1b}.email-pending{background:#ffedd5;color:#c2410c}.email-not-sent{background:#f1f5f9;color:#64748b}
.contract-actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap;min-width:310px}.contract-actions form{margin:0}
.contract-actions .btn{font-size:12px;padding:7px 9px}
@media(max-width:700px){.card table{min-width:1100px}}
</style>
<?php page_footer();?>
