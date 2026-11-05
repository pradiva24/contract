<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
admin_required();
$companyId=require_company_id();
$st=db()->prepare('SELECT * FROM companies WHERE id=? AND is_active=1 LIMIT 1');
$st->execute([$companyId]);
$company=$st->fetch();
if(!$company){http_response_code(404);exit('Business not found or inactive.');}
$fields=['business_name'=>'Business display name','business_address'=>'Business address','business_phone'=>'Phone / WhatsApp','business_email'=>'Contact email','business_website'=>'Website','mail_host'=>'SMTP host','mail_port'=>'SMTP port','mail_username'=>'SMTP username','mail_encryption'=>'SMTP encryption','mail_from_address'=>'From email address','mail_from_name'=>'From name','mail_reply_to'=>'Reply-to email','contract_email_subject'=>'Contract email subject','contract_email_body'=>'Contract email body (HTML supported)'];
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'save');
 if($action==='save'){
  $values=[];foreach($fields as $key=>$label)$values[$key]=trim((string)($_POST[$key]??''));
  if($values['business_name']==='')$errors[]='Business display name is required.';
  if($values['mail_host']===''||$values['mail_username']==='')$errors[]='SMTP host and username are required.';
  if(!ctype_digit($values['mail_port'])||(int)$values['mail_port']<1||(int)$values['mail_port']>65535)$errors[]='SMTP port must be between 1 and 65535.';
  if(!in_array($values['mail_encryption'],['tls','ssl','none'],true))$errors[]='Select a valid SMTP encryption option.';
  foreach(['business_email','mail_from_address','mail_reply_to'] as $key)if($values[$key]!==''&&!filter_var($values[$key],FILTER_VALIDATE_EMAIL))$errors[]=$fields[$key].' is not a valid email address.';
  if($values['business_website']!==''&&!filter_var($values['business_website'],FILTER_VALIDATE_URL))$errors[]='Website must be a valid URL.';
  if(!$errors){try{
   db()->beginTransaction();
   db()->prepare('UPDATE companies SET name=?,website_url=? WHERE id=?')->execute([$values['business_name'],$values['business_website']?:null,$companyId]);
   foreach($values as $key=>$value)save_company_setting($companyId,$key,$value);
   $password=(string)($_POST['smtp_password']??'');if($password!=='')save_company_setting($companyId,'smtp_password_encrypted',company_secret_encrypt($password));
   db()->commit();audit_company($companyId,'company_settings_updated','company',$companyId,'Updated company profile and contract email settings.');
   flash('success','Business profile and contract email settings saved.');redirect('company-settings.php');
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$errors[]='Unable to save settings: '.$e->getMessage();}}
 }elseif($action==='test'){
  $recipient=trim((string)($_POST['test_email']??''));
  if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))$errors[]='Enter a valid test recipient email.';
  else{try{
   if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class))throw new RuntimeException('PHPMailer is not installed. Run composer install.');
   $get=static fn(string $key,string $default=''):string=>company_setting($companyId,$key,$default);
   $mail=new \PHPMailer\PHPMailer\PHPMailer(true);$mail->isSMTP();$mail->Host=$get('mail_host');$mail->SMTPAuth=true;$mail->Username=$get('mail_username');
   $mail->Password=company_secret($companyId,'smtp_password_encrypted',(string)config('MAIL_PASSWORD',config('SMTP_PASSWORD','')));
   $mail->Port=(int)$get('mail_port','587');$enc=$get('mail_encryption','tls');
   if($enc==='tls')$mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;elseif($enc==='ssl')$mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;else{$mail->SMTPSecure='';$mail->SMTPAutoTLS=false;}
   $from=$get('mail_from_address');$fromName=$get('mail_from_name',$company['name']);
   if($mail->Host===''||$mail->Username===''||$mail->Password==='')throw new RuntimeException('Complete SMTP host, username, and password first.');
   if(!filter_var($from,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Configure a valid From email address first.');
   $mail->CharSet='UTF-8';$mail->Timeout=20;$mail->setFrom($from,$fromName);$reply=$get('mail_reply_to');if($reply!=='')$mail->addReplyTo($reply,$fromName);
   $mail->addAddress($recipient);$mail->Subject='SMTP test - '.$company['name'];$mail->isHTML(true);$mail->Body='<p>SMTP test successful.</p><p>This message was sent using '.$company['name'].' company email settings.</p>';$mail->AltBody='SMTP test successful for '.$company['name'].'.';$mail->send();
   flash('success','Test email sent to '.$recipient.'. Check inbox and spam folder.');redirect('company-settings.php');
  }catch(Throwable $e){$errors[]='SMTP test failed: '.$e->getMessage();}}
 }
}
$values=[];foreach($fields as $key=>$label)$values[$key]=company_setting($companyId,$key,$key==='business_name'?(string)$company['name']:($key==='business_website'?(string)($company['website_url']??''):($key==='mail_port'?'587':($key==='mail_encryption'?'tls':($key==='contract_email_subject'?'Your contract from {business_name}':($key==='contract_email_body'?'Dear {company_name},<br><br>Please find your contract attached as a PDF document.<br><br>Best regards,<br>{business_name}':''))))));
page_header('Company Settings');
?>
<?php foreach($errors as $error):?><div class="alert error"><?=e($error)?></div><?php endforeach;?>
<form method="post" class="card"><?=csrf_field()?><input type="hidden" name="action" value="save"><h2>Business profile</h2><p class="muted">Profile information belongs to the selected business and is separate from other businesses.</p><div class="formgrid">
<?php foreach(array_slice($fields,0,5,true) as $key=>$label):?><div><label for="<?=$key?>"><?=e($label)?></label><input id="<?=$key?>" name="<?=$key?>" value="<?=e($values[$key])?>" <?=$key==='business_name'?'required':''?>></div><?php endforeach;?>
</div><hr><h2>Contract SMTP settings</h2><p class="muted">Contract emails use this business's SMTP account. Certificate email settings remain unchanged.</p><div class="formgrid">
<?php foreach(array_slice($fields,5,6,true) as $key=>$label):?><div><label for="<?=$key?>"><?=e($label)?></label><?php if($key==='mail_encryption'):?><select id="<?=$key?>" name="<?=$key?>"><?php foreach(['tls'=>'TLS / STARTTLS','ssl'=>'SSL','none'=>'None'] as $v=>$l):?><option value="<?=$v?>" <?=$values[$key]===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select><?php else:?><input id="<?=$key?>" name="<?=$key?>" value="<?=e($values[$key])?>" <?=$key==='mail_port'?'type="number" min="1" max="65535"':''?> <?=$key==='mail_host'||$key==='mail_username'?'required':''?>><?php endif;?></div><?php endforeach;?>
<div><label for="smtp_password">SMTP password</label><input id="smtp_password" type="password" name="smtp_password" autocomplete="new-password" placeholder="Leave blank to keep saved password"><small class="muted">Encrypted with APP_KEY. Never displayed after saving.</small></div></div>
<hr><h2>Contract email content</h2><p class="muted">Available placeholders: <code>{business_name}</code>, <code>{company_name}</code>, <code>{contract_number}</code>, <code>{contract_date}</code>.</p><label for="contract_email_subject">Email subject</label><input id="contract_email_subject" name="contract_email_subject" value="<?=e($values['contract_email_subject'])?>"><label for="contract_email_body">Email body</label><textarea id="contract_email_body" name="contract_email_body" rows="7"><?=e($values['contract_email_body'])?></textarea><p><button class="btn" type="submit">Save company settings</button></p></form>
<div class="card"><h2>Test company SMTP</h2><p class="muted">Save settings before sending a test email. The test uses only this business's SMTP credentials.</p><form method="post" class="actions"><?=csrf_field()?><input type="hidden" name="action" value="test"><input type="email" name="test_email" placeholder="recipient@example.com" required><button class="btn dark" type="submit">Send test email</button></form></div>
<?php page_footer(); ?>
