<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
$companyId=require_company_id();
$id=max(0,(int)($_GET['id']??0));
$st=db()->prepare('SELECT contract_number,company_name,recipient_email FROM contracts WHERE id=? AND company_id=? LIMIT 1');
$st->execute([$id,$companyId]);$contract=$st->fetch();
if(!$contract){http_response_code(404);exit('Contract not found.');}
$logs=db()->prepare('SELECT recipient_email,subject,status,error_message,sent_at,created_at FROM contract_email_logs WHERE contract_id=? AND company_id=? ORDER BY id DESC');
$logs->execute([$id,$companyId]);$rows=$logs->fetchAll();
page_header('Contract Email History');
?>
<div class="card">
 <h2><?=e($contract['contract_number'])?></h2>
 <p><?=e($contract['company_name'])?> · <?=e($contract['recipient_email'])?></p>
 <a class="btn light" href="<?=e(url('contracts.php'))?>">Back to Contract Records</a>
</div>
<div class="card" style="overflow-x:auto">
 <table><thead><tr><th>Attempt date</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Details</th></tr></thead><tbody>
 <?php foreach($rows as $r):$status=(string)$r['status'];$cls=$status==='sent'?'sent':($status==='failed'?'failed':'pending'); ?>
 <tr><td><?=e($r['created_at'])?></td><td><?=e($r['recipient_email'])?></td><td><?=e($r['subject'])?></td><td><span class="status <?=$cls?>"><?=e(ucfirst($status))?></span><?php if($r['sent_at']):?><br><small><?=e($r['sent_at'])?></small><?php endif;?></td><td><?=e($r['error_message']??'—')?></td></tr>
 <?php endforeach;?>
 <?php if(!$rows):?><tr><td colspan="5">No email attempts recorded.</td></tr><?php endif;?>
 </tbody></table>
</div>
<style>.status{display:inline-block;padding:4px 9px;border-radius:20px;font-size:12px;font-weight:700}.sent{background:#dcfce7;color:#166534}.failed{background:#fee2e2;color:#991b1b}.pending{background:#ffedd5;color:#c2410c}</style>
<?php page_footer();?>
