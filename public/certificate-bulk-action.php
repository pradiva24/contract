<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/certificate.php';
admin_required();
verify_csrf();

if($_SERVER['REQUEST_METHOD']!=='POST'){
 http_response_code(405);exit('Method not allowed.');
}
$action=(string)($_POST['bulk_action']??'');
$ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])),static fn($id)=>$id>0)));
if(!$ids){flash('error','Please select at least one certificate.');redirect('certificates.php');}
$ids=array_slice($ids,0,300);
$marks=implode(',',array_fill(0,count($ids),'?'));
$st=db()->prepare("SELECT id,certificate_number,recipient_email,verification_token,pdf_path,participant_photo_path FROM certificates WHERE id IN ($marks)");
$st->execute($ids);
$certificates=$st->fetchAll();

if($action==='email'){
 $sent=0;$failed=0;$errors=[];
 foreach($certificates as $cert){
  try{send_certificate_email((int)$cert['id']);$sent++;}
  catch(Throwable $e){$failed++;if(count($errors)<3)$errors[]=$cert['certificate_number'].': '.$e->getMessage();}
 }
 $message="Bulk email finished. Sent: {$sent}; failed: {$failed}.";
 if($errors)$message.=' Details: '.implode(' | ',$errors);
 flash($failed?'error':'success',$message);
 redirect('certificates.php');
}

if($action==='delete'){
 $deleted=0;
 foreach($certificates as $cert){
  $id=(int)$cert['id'];
  audit('certificate_deleted','certificate',$id,'Deleted '.$cert['certificate_number'].' by admin.');
  db()->prepare('DELETE FROM certificates WHERE id=?')->execute([$id]);
  $deleted++;
  // Remove generated files belonging to this certificate from local storage
  // (and therefore from the same storage directory deployed on hosting).
  foreach(['pdf_path','participant_photo_path'] as $field){
   $relative=(string)($cert[$field]??'');
   $allowed=$field==='pdf_path'?'certificates/':'certificates/photos/';
   if($relative!=='' && str_starts_with($relative,$allowed) && !str_contains($relative,'..')){
    $path=BASE_PATH.'/storage/'.$relative;
    if(is_file($path)) @unlink($path);
   }
  }
  // QR files are named by the verification token and are not stored in pdf_path.
  $token=(string)($cert['verification_token']??'');
  if($token!=='' && preg_match('/^[a-f0-9]{64}$/i',$token)){
   $qrPath=BASE_PATH.'/storage/qr/'.$token.'.png';
   if(is_file($qrPath)) @unlink($qrPath);
  }
 }
 flash('success',"Deleted {$deleted} certificate(s). Related email logs were removed by database cascade.");
 redirect('certificates.php');
}

flash('error','Invalid bulk action.');
redirect('certificates.php');
