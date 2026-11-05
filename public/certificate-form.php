<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/certificate.php';admin_required();verify_csrf();
$id=(int)($_GET['id']??0);$c=['recipient_name'=>'','recipient_email'=>'','recipient_whatsapp'=>'','activity_name'=>setting('default_activity_name','ATV Adventure'),'activity_date'=>date('Y-m-d'),'template_id'=>'','notes'=>'','participant_photo_path'=>null];
if($id){$st=db()->prepare('SELECT * FROM certificates WHERE id=?');$st->execute([$id]);$c=$st->fetch()?:$c;}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['remove_participant_photo']) && $id){
 $old=(string)($c['participant_photo_path']??'');
 if($old && str_starts_with($old,'certificates/photos/')){ $fp=BASE_PATH.'/storage/'.$old; if(is_file($fp))@unlink($fp); }
 db()->prepare('UPDATE certificates SET participant_photo_path=NULL WHERE id=?')->execute([$id]);
 flash('success','Participant photo removed.'); redirect('certificate-form.php?id='.$id);
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $name=trim($_POST['recipient_name']??'');$email=filter_var($_POST['recipient_email']??'',FILTER_VALIDATE_EMAIL);$whatsappRaw=trim($_POST['recipient_whatsapp']??'');$whatsapp=preg_replace('/[^0-9]/','',$whatsappRaw);$activity=trim($_POST['activity_name']??'ATV Adventure');$date=$_POST['activity_date']??'';$template=(int)($_POST['template_id']??0)?:null;$notes=trim($_POST['notes']??'');
 // Store WhatsApp in international digits-only format (country code included, no leading +).
 if($whatsapp!=='' && (strlen($whatsapp)<8 || strlen($whatsapp)>15)){$whatsappValid=false;}else{$whatsappValid=true;}
 $photoPath=(string)($c['participant_photo_path']??'');
 if(isset($_FILES['participant_photo']) && $_FILES['participant_photo']['error']!==UPLOAD_ERR_NO_FILE){
  if($_FILES['participant_photo']['error']!==UPLOAD_ERR_OK){flash('error','Participant photo upload failed.');redirect('certificate-form.php'.($id?'?id='.$id:''));}
  $tmp=$_FILES['participant_photo']['tmp_name'];$mime=mime_content_type($tmp);
  $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
  if(!$ext || (int)$_FILES['participant_photo']['size']>5*1024*1024){flash('error','Participant photo must be JPG, PNG or WEBP, maximum 5 MB.');redirect('certificate-form.php'.($id?'?id='.$id:''));}
  $dir=BASE_PATH.'/storage/certificates/photos';if(!is_dir($dir))mkdir($dir,0775,true);
  $filename='participant_'.($id?:'new').'_'.bin2hex(random_bytes(8)).'.webp';
  require_once BASE_PATH.'/app/image_optimizer.php';
  try{$saved=bta_optimize_uploaded_image($tmp,$dir.'/'.$filename,'participant');$filename=basename($saved['path']);}
  catch(Throwable $e){flash('error','Could not convert participant photo to WebP: '.$e->getMessage());redirect('certificate-form.php'.($id?'?id='.$id:''));}
  $photoPath='certificates/photos/'.$filename;
 }

 if(!$name||!$email||!$whatsappValid||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){flash('error','Please enter a valid name, email, WhatsApp number (8–15 digits including country code), and activity date.');redirect('certificate-form.php'.($id?'?id='.$id:''));}
 if($id){
  $st=db()->prepare("UPDATE certificates SET recipient_name=?,recipient_email=?,recipient_whatsapp=?,activity_name=?,activity_date=?,template_id=?,notes=?,participant_photo_path=?,updated_by=? WHERE id=? AND status<>'revoked'");
  $st->execute([$name,$email,$whatsapp?:null,$activity,$date,$template,$notes,$photoPath?:null,$_SESSION['admin']['id'],$id]);audit('certificate_updated','certificate',$id,'Certificate details updated.');
 }else{
  $number=next_certificate_number(db(),$date);$token=bin2hex(random_bytes(32));
  $st=db()->prepare("INSERT INTO certificates(certificate_number,verification_token,recipient_name,recipient_email,recipient_whatsapp,activity_name,activity_date,template_id,notes,participant_photo_path,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
  $st->execute([$number,$token,$name,$email,$whatsapp?:null,$activity,$date,$template,$notes,$photoPath?:null,$_SESSION['admin']['id'],$_SESSION['admin']['id']]);$id=(int)db()->lastInsertId();audit('certificate_created','certificate',$id,'Created '.$number);
 }
 flash('success','Certificate saved.');redirect('certificates.php');
}
$templates=db()->query('SELECT id,name FROM templates WHERE is_active=1 ORDER BY name')->fetchAll();
page_header($id?'Edit certificate':'Create certificate');
?>
<style>
#certificateSavingOverlay{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(10,15,25,.68);backdrop-filter:blur(5px)}
#certificateSavingOverlay.is-visible{display:flex}
.certificate-saving-box{width:min(390px,100%);padding:30px 26px;border-radius:18px;background:#fff;text-align:center;box-shadow:0 24px 70px rgba(0,0,0,.28)}
.certificate-saving-spinner{width:48px;height:48px;margin:0 auto 18px;border:4px solid #eee;border-top-color:#f47721;border-radius:50%;animation:certificateSpin .8s linear infinite}
.certificate-saving-title{margin:0 0 7px;color:#171717;font-size:18px;font-weight:700}
.certificate-saving-text{margin:0 0 20px;color:#737373;font-size:13px}
.certificate-saving-track{height:7px;overflow:hidden;border-radius:20px;background:#eee}
.certificate-saving-track span{display:block;width:40%;height:100%;border-radius:20px;background:linear-gradient(90deg,#f47721,#ffad45);animation:certificateProgress 1.25s ease-in-out infinite}
@keyframes certificateSpin{to{transform:rotate(360deg)}}
@keyframes certificateProgress{0%{transform:translateX(-110%)}100%{transform:translateX(280%)}}
@media(prefers-reduced-motion:reduce){.certificate-saving-spinner,.certificate-saving-track span{animation:none}.certificate-saving-track span{width:100%}}
</style>
<div id="certificateSavingOverlay" role="status" aria-live="polite" aria-hidden="true">
 <div class="certificate-saving-box">
  <div class="certificate-saving-spinner" aria-hidden="true"></div>
  <p class="certificate-saving-title">Saving certificate...</p>
  <p class="certificate-saving-text">Please wait. Do not close or refresh this page.</p>
  <div class="certificate-saving-track"><span></span></div>
 </div>
</div>
<div class="card"><form id="certificateForm" method="post" enctype="multipart/form-data"><?=csrf_field()?><div class="formgrid"><div><label>Guest name</label><input name="recipient_name" value="<?=e($c['recipient_name'])?>" required></div><div><label>Guest email</label><input type="email" name="recipient_email" value="<?=e($c['recipient_email'])?>" required></div><div><label>Guest WhatsApp number (optional)</label><input type="tel" name="recipient_whatsapp" value="<?=e($c['recipient_whatsapp']??'')?>" placeholder="6281234567890" inputmode="tel" autocomplete="tel"><small>Include country code, digits only. Example: 6281234567890. This number can be used for future WhatsApp certificate delivery.</small></div><div><label>Activity</label><input name="activity_name" value="<?=e($c['activity_name'])?>" required></div><div><label>Activity date</label><input type="date" name="activity_date" value="<?=e($c['activity_date'])?>" required></div><div><label>Certificate template</label><select name="template_id"><option value="">Default (no background)</option><?php foreach($templates as $t):?><option value="<?=$t['id']?>" <?=((string)$c['template_id']===(string)$t['id']?'selected':'')?>><?=e($t['name'])?></option><?php endforeach?></select></div></div><div class="formgrid"><div style="grid-column:1/-1"><label>Participant photo (optional)</label><input type="file" name="participant_photo" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WEBP · maximum 5 MB. If no photo is uploaded, nothing will be printed.</small><?php if(!empty($c['participant_photo_path'])):?><p><strong>Current photo:</strong> <?=e(basename($c['participant_photo_path']))?></p><button class="btn light" type="submit" name="remove_participant_photo" value="1" formnovalidate>Remove participant photo</button><?php endif;?></div></div><label>Internal notes</label><textarea name="notes" rows="3"><?=e($c['notes'])?></textarea><p><button class="btn" id="saveCertificateButton" type="submit">Save certificate</button> <a class="btn light" href="<?=e(url('certificates.php'))?>">Cancel</a></p></form></div>
<script>
(function(){
 const form=document.getElementById('certificateForm');
 const overlay=document.getElementById('certificateSavingOverlay');
 const saveButton=document.getElementById('saveCertificateButton');
 if(!form||!overlay)return;
 form.addEventListener('submit',function(event){
  if(event.submitter && event.submitter.name==='remove_participant_photo')return;
  if(!form.checkValidity())return;
  overlay.classList.add('is-visible');
  overlay.setAttribute('aria-hidden','false');
  if(saveButton){saveButton.disabled=true;saveButton.textContent='Saving...';}
 });
})();
</script>
<?php page_footer();?>
