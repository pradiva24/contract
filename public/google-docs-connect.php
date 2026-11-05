<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/google_docs.php';
admin_required();
$companyId=require_company_id();
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 if(($_POST['action']??'')==='disconnect'){
  google_docs_disconnect($companyId);
  audit_company($companyId,'google_docs_disconnected','company',$companyId,'Disconnected Google Docs account.');
  flash('success','Google account disconnected from this business.');
  redirect('google-docs-connect.php');
 }
 if(($_POST['action']??'')==='connect'){
  try{header('Location: '.google_docs_authorization_url($companyId));exit;}
  catch(Throwable $e){$errors[]=$e->getMessage();}
 }
}
$connected=google_docs_connected($companyId);
$email=company_setting($companyId,'google_docs_account_email','');
page_header('Google Docs Integration');
?>
<?php foreach($errors as $error):?><div class="alert error"><?=e($error)?></div><?php endforeach;?>
<div class="card">
 <h2>Google Docs account for this business</h2>
 <p class="muted">Connect the Google account that owns or can access the master contract documents. The connection and encrypted refresh token belong only to this business.</p>
 <?php if($connected):?>
  <div class="alert success">Connected<?= $email!==''?' as '.e($email):'' ?>.</div>
  <form method="post" onsubmit="return confirm('Disconnect Google Docs for this business? Existing documents will not be deleted.');"><?=csrf_field()?><input type="hidden" name="action" value="disconnect"><button class="btn danger" type="submit">Disconnect Google account</button></form>
 <?php else:?>
  <p><span class="badge">Not connected</span></p>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="connect"><button class="btn" type="submit">Connect Google account</button></form>
 <?php endif;?>
</div>
<div class="card">
 <h3>Google Cloud configuration</h3>
 <p>Add these private values to the application root <code>.env</code> file:</p>
 <pre>GOOGLE_CLIENT_ID=your-oauth-client-id
GOOGLE_CLIENT_SECRET=your-oauth-client-secret
GOOGLE_REDIRECT_URI=<?=e(url('google-docs-callback.php'))?></pre>
 <p class="muted">Enable Google Docs API and Google Drive API in Google Cloud Console. Configure an OAuth Web application and add the redirect URI shown above. PHP cURL and OpenSSL must be enabled.</p>
 <p class="muted">Requested permissions: edit Google Docs documents and access files created/opened by this application. No full-Drive access is requested.</p>
</div>
<?php page_footer();?>
