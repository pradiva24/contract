<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
admin_required();

$total=(int)db()->query('SELECT COUNT(*) FROM certificates')->fetchColumn();
$issued=(int)db()->query("SELECT COUNT(*) FROM certificates WHERE status='issued'")->fetchColumn();
$revoked=(int)db()->query("SELECT COUNT(*) FROM certificates WHERE status='revoked'")->fetchColumn();
$sent=(int)db()->query("SELECT COUNT(*) FROM email_logs WHERE status='sent'")->fetchColumn();

$recent=db()->query("
 SELECT c.*,
   (SELECT el.status
      FROM email_logs el
     WHERE el.certificate_id=c.id
     ORDER BY el.id DESC
     LIMIT 1) AS email_status,
   (SELECT el.sent_at
      FROM email_logs el
     WHERE el.certificate_id=c.id AND el.status='sent'
     ORDER BY el.id DESC
     LIMIT 1) AS email_sent_at
 FROM certificates c
 ORDER BY c.id DESC
 LIMIT 8
")->fetchAll();

page_header('Dashboard');
?>
<div class="grid">
 <div class="card"><div class="muted">Total certificates</div><div class="stat"><?=$total?></div></div>
 <div class="card"><div class="muted">Issued</div><div class="stat"><?=$issued?></div></div>
 <div class="card"><div class="muted">Revoked</div><div class="stat"><?=$revoked?></div></div>
 <div class="card"><div class="muted">Emails sent</div><div class="stat"><?=$sent?></div></div>
</div>

<div class="card">
 <div class="actions">
  <a class="btn" href="<?=e(url('certificate-form.php'))?>">+ Create certificate</a>
  <a class="btn dark" href="<?=e(url('templates.php'))?>">Manage templates</a>
  <a class="btn dark" href="<?=e(url('contract-templates.php'))?>">Contract Studio</a>
  <a class="btn dark" href="<?=e(url('contract-products.php'))?>">Manage contract products</a>
 </div>
</div>

<div class="card">
 <h2>Recent certificates</h2>
 <table>
  <thead>
   <tr>
    <th>Number</th>
    <th>Recipient</th>
    <th>Date</th>
    <th>Certificate Status</th>
    <th>Email Status</th>
   </tr>
  </thead>
  <tbody>
  <?php foreach($recent as $r): ?>
   <tr>
    <td><?=e($r['certificate_number'])?></td>
    <td><?=e($r['recipient_name'])?></td>
    <td><?=e($r['activity_date'])?></td>
    <td><span class="badge <?=e($r['status'])?>"><?=e($r['status'])?></span></td>
    <td>
     <?php if(($r['email_status']??'')==='sent'): ?>
      <span class="email-status email-sent" title="<?=e(!empty($r['email_sent_at'])?'Sent at '.$r['email_sent_at']:'Email sent')?>">
       <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 10.2l3.1 3.1L15.5 6"/></svg>
       Sent
      </span>
     <?php elseif(($r['email_status']??'')==='failed'): ?>
      <span class="email-status email-failed" title="The latest email attempt failed">Failed</span>
     <?php elseif(($r['email_status']??'')==='queued'): ?>
      <span class="email-status email-pending">Pending</span>
     <?php else: ?>
      <span class="email-status email-not-sent">Not sent</span>
     <?php endif; ?>
    </td>
   </tr>
  <?php endforeach; ?>
  <?php if(!$recent): ?>
   <tr><td colspan="5" class="muted">No certificates yet.</td></tr>
  <?php endif; ?>
  </tbody>
 </table>
</div>

<style>
.email-status{display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap}
.email-status svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2.3;stroke-linecap:round;stroke-linejoin:round}
.email-sent{background:#dcfce7;color:#166534}
.email-failed{background:#fee2e2;color:#991b1b}
.email-pending{background:#fef3c7;color:#92400e}
.email-not-sent{background:#f3f4f6;color:#6b7280}
@media(max-width:700px){.card table{min-width:620px}}
</style>
<?php page_footer(); ?>
