<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
admin_required();

// Lightweight status endpoint used by the list page to sync status badges.
if (isset($_GET['status_check'])) {
 header('Content-Type: application/json; charset=utf-8');
 $statusRows=db()->query("SELECT c.id,c.status,
   (SELECT el.status FROM email_logs el WHERE el.certificate_id=c.id ORDER BY el.id DESC LIMIT 1) AS email_status
   FROM certificates c ORDER BY c.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
 echo json_encode(['ok'=>true,'items'=>$statusRows], JSON_UNESCAPED_SLASHES);
 exit;
}

$q=trim($_GET['q']??'');
$sql="SELECT c.*,
 (SELECT el.status FROM email_logs el WHERE el.certificate_id=c.id ORDER BY el.id DESC LIMIT 1) AS email_status
 FROM certificates c";
$args=[];
if($q!==''){
 $sql.=' WHERE certificate_number LIKE ? OR recipient_name LIKE ? OR recipient_email LIKE ?';
 $args=["%$q%","%$q%","%$q%"];
}
$sql.=' ORDER BY id DESC LIMIT 300';
$st=db()->prepare($sql);
$st->execute($args);
$rows=$st->fetchAll();
page_header('Certificates');
?>
<style>
.cert-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.cert-toolbar form.search-form{display:flex;gap:8px;flex:1;min-width:240px}
.cert-bulk-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.cert-table-wrap{overflow-x:auto}
.cert-table{width:100%;border-collapse:collapse;min-width:900px}
.cert-table th,.cert-table td{padding:11px 10px;text-align:left;vertical-align:middle;border-bottom:1px solid #e8e8e8}
.cert-table th:first-child,.cert-table td:first-child{width:38px}
.cert-actions{display:flex;gap:6px;flex-wrap:nowrap;align-items:center}
.cert-icon-btn{display:inline-flex!important;align-items:center;justify-content:center;width:34px;height:34px;min-width:34px;padding:0!important;border-radius:7px;text-decoration:none;cursor:pointer}
.cert-icon-btn svg{width:17px;height:17px;display:block;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.cert-icon-btn:hover{filter:brightness(.96)}
.cert-icon-btn:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
.btn.danger{background:#b42318;color:#fff;border:1px solid #b42318}
.btn.view{background:#eef4ff;color:#174ea6;border:1px solid #d5e2ff}
.cert-email-sent{background:#dcfce7!important;color:#166534!important;border:1px solid #86efac!important}
.cert-email-failed{background:#fee2e2!important;color:#b91c1c!important;border:1px solid #fca5a5!important}
.cert-email-not-sent{background:#f1f5f9!important;color:#64748b!important;border:1px solid #cbd5e1!important}
.cert-email-pending{background:#ffedd5!important;color:#c2410c!important;border:1px solid #fdba74!important}
.cert-muted{font-size:12px;color:#777}
</style>
<div class="card">
 <div class="cert-toolbar">
  <a class="btn" href="<?=e(url('certificate-form.php'))?>">+ Create certificate</a>
  <form method="get" class="search-form">
   <input name="q" placeholder="Search number, name, email" value="<?=e($q)?>">
   <button class="btn dark" type="submit">Search</button>
   <?php if($q!==''):?><a class="btn light" href="<?=e(url('certificates.php'))?>">Reset</a><?php endif;?>
  </form>
 </div>
 <p class="cert-muted">Select certificates to send emails together or delete multiple records.</p>
</div>
<div class="card">
 <form method="post" action="<?=e(url('certificate-bulk-action.php'))?>" id="bulkCertificateForm" onsubmit="return confirmBulkAction();">
  <?=csrf_field()?>
  <div class="cert-toolbar" style="justify-content:space-between;margin-bottom:12px">
   <div class="cert-bulk-form">
    <select name="bulk_action" id="bulkAction" required>
     <option value="">Choose bulk action</option>
     <option value="email">Send email to selected</option>
     <option value="delete">Delete selected</option>
    </select>
    <button class="btn dark cert-icon-btn" type="submit" title="Apply selected action" aria-label="Apply selected action"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
   </div>
   <span class="cert-muted"><?=count($rows)?> records shown (maximum 300)</span>
  </div>
  <div class="cert-table-wrap">
   <table class="cert-table">
    <thead><tr>
     <th><input type="checkbox" id="selectAll" aria-label="Select all certificates"></th>
     <th>Certificate no.</th><th>Recipient</th><th>Email</th><th>Activity date</th><th>Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php if(!$rows):?><tr><td colspan="7">No certificates found.</td></tr><?php endif;?>
    <?php foreach($rows as $r):?>
     <tr>
      <td><input type="checkbox" class="cert-check" name="ids[]" value="<?= (int)$r['id']?>" aria-label="Select <?=e($r['certificate_number'])?>"></td>
      <td><strong><?=e($r['certificate_number'])?></strong></td>
      <td><?=e($r['recipient_name'])?></td>
      <td><?=e($r['recipient_email'])?></td>
      <td><?=e($r['activity_date'])?></td>
      <td><span class="badge cert-status-badge" data-certificate-id="<?= (int)$r['id']?>" data-status="<?=e($r['status'])?>"><?=e($r['status'])?></span></td>
      <td><div class="cert-actions">
       <a class="btn view cert-icon-btn" target="_blank" rel="noopener" title="View certificate" aria-label="View certificate" href="<?=e(url('certificate-view.php?id='.(int)$r['id']))?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></a>
       <a class="btn light cert-icon-btn" title="Edit certificate" aria-label="Edit certificate" href="<?=e(url('certificate-form.php?id='.(int)$r['id']))?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 5 5 5M4 20l4.2-.8L19 8.4 15.6 5 4.8 15.8 4 20Z"/></svg></a>
       <a class="btn dark cert-icon-btn" title="Download PDF" aria-label="Download PDF" href="<?=e(url('certificate-action.php?id='.(int)$r['id'].'&action=pdf'))?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 19v2h16v-2"/></svg></a>
       <?php
        $emailState=strtolower(trim((string)($r['email_status']??'')));
        $emailClass='cert-email-not-sent'; $emailTitle='Not sent';
        if(in_array($emailState,['sent','success','successful'],true)){ $emailClass='cert-email-sent'; $emailTitle='Email sent'; }
        elseif(in_array($emailState,['failed','error'],true)){ $emailClass='cert-email-failed'; $emailTitle='Email failed'; }
        elseif(in_array($emailState,['pending','queued','processing'],true)){ $emailClass='cert-email-pending'; $emailTitle='Email pending'; }
       ?>
       <a class="btn cert-icon-btn <?=$emailClass?>" data-email-status="<?=e($emailState ?: 'not_sent')?>" data-certificate-id="<?= (int)$r['id']?>" title="<?=$emailTitle?> — click to send" aria-label="<?=$emailTitle?> — send email" href="<?=e(url('certificate-action.php?id='.(int)$r['id'].'&action=email'))?>" onclick="return confirm('Send certificate to <?=e(addslashes($r['recipient_email']))?>?')">
        <?php if($emailClass==='cert-email-sent'): ?><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg><?php else: ?><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg><?php endif; ?>
       </a>
       <button class="btn danger cert-icon-btn" type="submit" form="deleteCert<?= (int)$r['id']?>" title="Delete certificate" aria-label="Delete certificate"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg></button>
      </div>
      <form id="deleteCert<?= (int)$r['id']?>" method="post" action="<?=e(url('certificate-bulk-action.php'))?>" onsubmit="return confirm('Permanently delete certificate <?=e(addslashes($r['certificate_number']))?>?')" style="display:none">
       <?=csrf_field()?> <input type="hidden" name="bulk_action" value="delete"><input type="hidden" name="ids[]" value="<?= (int)$r['id']?>">
      </form></td>
     </tr>
    <?php endforeach;?>
    </tbody>
   </table>
  </div>
 </form>
</div>
<script>
(function(){
 const all=document.getElementById('selectAll');
 if(all) all.addEventListener('change',()=>document.querySelectorAll('.cert-check').forEach(c=>c.checked=all.checked));
 document.querySelectorAll('.cert-check').forEach(c=>c.addEventListener('change',()=>{if(all&&!c.checked)all.checked=false;}));

 // Check status in the background without reloading the page or disturbing selections.
 const statusUrl = new URL(window.location.href);
 statusUrl.searchParams.set('status_check', '1');
 async function syncCertificateStatuses(){
  try {
   const response = await fetch(statusUrl.toString(), {headers:{'Accept':'application/json'}, cache:'no-store'});
   if(!response.ok) return;
   const payload = await response.json();
   if(!payload || !payload.ok || !Array.isArray(payload.items)) return;
   payload.items.forEach(item=>{
    const badge=document.querySelector('.cert-status-badge[data-certificate-id="'+Number(item.id)+'"]');
    if(!badge || !['draft','issued','revoked'].includes(item.status)) return;
    if(badge.dataset.status !== item.status){
     badge.dataset.status=item.status;
     badge.textContent=item.status;
     badge.classList.remove('draft','issued','revoked');
     badge.classList.add(item.status);
    }
    const emailButton=document.querySelector('.cert-icon-btn[data-email-status][data-certificate-id="'+Number(item.id)+'"]');
    if(emailButton){
     const state=String(item.email_status||'').toLowerCase().trim();
     let cls='cert-email-not-sent', title='Not sent';
     if(['sent','success','successful'].includes(state)){cls='cert-email-sent';title='Email sent';}
     else if(['failed','error'].includes(state)){cls='cert-email-failed';title='Email failed';}
     else if(['pending','queued','processing'].includes(state)){cls='cert-email-pending';title='Email pending';}
     emailButton.classList.remove('cert-email-sent','cert-email-failed','cert-email-not-sent','cert-email-pending');
     emailButton.classList.add(cls);
     emailButton.dataset.emailStatus=state||'not_sent';
     emailButton.title=title+' — click to send';
     emailButton.setAttribute('aria-label',title+' — send email');
     emailButton.innerHTML=cls==='cert-email-sent'
      ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>'
      : '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>';
    }
   });
  } catch (error) {
   // Keep the page usable if background status checking is temporarily unavailable.
  }
 }
 window.setInterval(syncCertificateStatuses, 4000);
})();
function confirmBulkAction(){
 const count=document.querySelectorAll('.cert-check:checked').length;
 const action=document.getElementById('bulkAction').value;
 if(!count){alert('Please select at least one certificate.');return false;}
 if(action==='email')return confirm('Send certificate emails to '+count+' selected recipient(s)?');
 if(action==='delete')return confirm('Permanently delete '+count+' selected certificate(s)? This cannot be undone.');
 alert('Please choose a bulk action.');return false;
}
</script>
<?php page_footer();?>
