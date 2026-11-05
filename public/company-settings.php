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

function save_company_brand_asset(int $companyId, string $key, string $field): void {
 if(!isset($_FILES[$field]) || (int)($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return;
 $file=$_FILES[$field];
 if((int)$file['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Upload failed for '.$field.'.');
 if((int)$file['size']<1||(int)$file['size']>5*1024*1024)throw new RuntimeException('Brand image must be smaller than 5 MB.');
 $info=@getimagesize($file['tmp_name']);
 if(!$info||!in_array($info['mime'],['image/png','image/jpeg'],true))throw new RuntimeException('Only PNG and JPG images are supported for company branding.');
 $ext=$info['mime']==='image/png'?'png':'jpg';
 $dir=BASE_PATH.'/storage/company-branding/'.$companyId;
 if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Unable to create company branding storage.');
 $name=$key.'-'.bin2hex(random_bytes(8)).'.'.$ext;
 if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Unable to save uploaded brand image.');
 save_company_setting($companyId,$key,'company-branding/'.$companyId.'/'.$name);
}
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
   foreach(['brand_logo_enabled','brand_stamp_enabled','brand_signature_enabled','brand_footer_enabled'] as $toggle)save_company_setting($companyId,$toggle,isset($_POST[$toggle])?'1':'0');
   foreach(['brand_logo_x','brand_logo_y','brand_logo_width','brand_logo_height','brand_stamp_x','brand_stamp_y','brand_signature_x','brand_signature_y'] as $metric){
    $raw=trim((string)($_POST[$metric]??''));
    if($raw!==''&&!is_numeric($raw))throw new RuntimeException('Brand position and size values must be numeric.');
    if($raw!=='')save_company_setting($companyId,$metric,(string)max(0,min(10000,(float)$raw)));
   }
   save_company_brand_asset($companyId,'brand_logo_path','brand_logo');
   save_company_brand_asset($companyId,'brand_stamp_path','brand_stamp');
   save_company_brand_asset($companyId,'brand_signature_path','brand_signature');
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
<form method="post" enctype="multipart/form-data" class="card"><?=csrf_field()?><input type="hidden" name="action" value="save"><h2>Business profile</h2><p class="muted">Profile information belongs to the selected business and is separate from other businesses.</p><div class="formgrid">
<?php foreach(array_slice($fields,0,5,true) as $key=>$label):?><div><label for="<?=$key?>"><?=e($label)?></label><input id="<?=$key?>" name="<?=$key?>" value="<?=e($values[$key])?>" <?=$key==='business_name'?'required':''?>></div><?php endforeach;?>
</div><hr><h2>Contract SMTP settings</h2><p class="muted">Contract emails use this business's SMTP account. Certificate email settings remain unchanged.</p><div class="formgrid">
<?php foreach(array_slice($fields,5,6,true) as $key=>$label):?><div><label for="<?=$key?>"><?=e($label)?></label><?php if($key==='mail_encryption'):?><select id="<?=$key?>" name="<?=$key?>"><?php foreach(['tls'=>'TLS / STARTTLS','ssl'=>'SSL','none'=>'None'] as $v=>$l):?><option value="<?=$v?>" <?=$values[$key]===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select><?php else:?><input id="<?=$key?>" name="<?=$key?>" value="<?=e($values[$key])?>" <?=$key==='mail_port'?'type="number" min="1" max="65535"':''?> <?=$key==='mail_host'||$key==='mail_username'?'required':''?>><?php endif;?></div><?php endforeach;?>
<div><label for="smtp_password">SMTP password</label><input id="smtp_password" type="password" name="smtp_password" autocomplete="new-password" placeholder="Leave blank to keep saved password"><small class="muted">Encrypted with APP_KEY. Never displayed after saving.</small></div></div>
<hr><h2>PDF branding</h2>
<p class="muted">Upload PNG/JPG assets (maximum 5 MB each). Logo, stamp and signature are overlaid on every generated PDF page. Adjust positions in canvas pixels; generate a new PDF to apply changes.</p>
<div class="formgrid">
<div><label for="brand_logo">Company logo</label><input id="brand_logo" type="file" name="brand_logo" accept="image/png,image/jpeg"><small class="muted">Current: <?=e(company_setting($companyId,'brand_logo_path')?'Uploaded':'Not uploaded')?></small></div>
<div><label for="brand_stamp">Company stamp</label><input id="brand_stamp" type="file" name="brand_stamp" accept="image/png,image/jpeg"><small class="muted">Current: <?=e(company_setting($companyId,'brand_stamp_path')?'Uploaded':'Not uploaded')?></small></div>
<div><label for="brand_signature">Authorized signature</label><input id="brand_signature" type="file" name="brand_signature" accept="image/png,image/jpeg"><small class="muted">Current: <?=e(company_setting($companyId,'brand_signature_path')?'Uploaded':'Not uploaded')?></small></div>
</div>
<div class="formgrid">
<?php foreach(['brand_logo_enabled'=>'Show logo on every page','brand_stamp_enabled'=>'Show stamp on every page','brand_signature_enabled'=>'Show signature on every page','brand_footer_enabled'=>'Show business contact footer on every page'] as $key=>$label):?><div><label><input type="checkbox" name="<?=$key?>" value="1" <?=company_setting($companyId,$key,$key==='brand_logo_enabled'?'1':'0')==='1'?'checked':''?>> <?=e($label)?></label></div><?php endforeach;?>
</div>
<h3>Logo placement (pixels)</h3><div class="formgrid">
<?php foreach(['brand_logo_x'=>'Logo X','brand_logo_y'=>'Logo Y','brand_logo_width'=>'Logo width','brand_logo_height'=>'Logo height'] as $key=>$label):?><div><label for="<?=$key?>"><?=e($label)?></label><input type="number" min="0" max="10000" name="<?=$key?>" id="<?=$key?>" value="<?=e(company_setting($companyId,$key,['brand_logo_x'=>'24','brand_logo_y'=>'24','brand_logo_width'=>'120','brand_logo_height'=>'60'][$key]))?>"></div><?php endforeach;?>
</div>
<h3>Stamp and signature placement (pixels)</h3><div class="formgrid">
<?php foreach(['brand_stamp_x'=>'Stamp X','brand_stamp_y'=>'Stamp Y','brand_signature_x'=>'Signature X','brand_signature_y'=>'Signature Y'] as $key=>$label):?><div><label for="<?=$key?>"><?=e($label)?></label><input type="number" min="0" max="10000" name="<?=$key?>" id="<?=$key?>" value="<?=e(company_setting($companyId,$key,['brand_stamp_x'=>'680','brand_stamp_y'=>'900','brand_signature_x'=>'500','brand_signature_y'=>'900'][$key]))?>"></div><?php endforeach;?>
</div>
<hr><h2>Contract email content</h2><p class="muted">Available placeholders: <code>{business_name}</code>, <code>{company_name}</code>, <code>{contract_number}</code>, <code>{contract_date}</code>.</p><label for="contract_email_subject">Email subject</label><input id="contract_email_subject" name="contract_email_subject" value="<?=e($values['contract_email_subject'])?>"><label for="contract_email_body">Email body</label><textarea id="contract_email_body" name="contract_email_body" rows="7"><?=e($values['contract_email_body'])?></textarea><p><button class="btn" type="submit">Save company settings</button></p></form>
<div class="card"><h2>Test company SMTP</h2><p class="muted">Save settings before sending a test email. The test uses only this business's SMTP credentials.</p><form method="post" class="actions"><?=csrf_field()?><input type="hidden" name="action" value="test"><input type="email" name="test_email" placeholder="recipient@example.com" required><button class="btn dark" type="submit">Send test email</button></form></div>
<?php page_footer(); ?>
