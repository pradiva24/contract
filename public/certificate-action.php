<?php
require_once dirname(__DIR__).'/app/bootstrap.php';admin_required();
require_once dirname(__DIR__).'/app/certificate.php';
$id=(int)($_GET['id']??0);$action=$_GET['action']??'';
$st=db()->prepare('SELECT * FROM certificates WHERE id=?');$st->execute([$id]);$c=$st->fetch();if(!$c){http_response_code(404);exit('Not found');}
try{
 if($action==='pdf'){
  $path=generate_certificate_pdf($id, (($_GET['regenerate']??'')==='1'));
  if($c['status']==='draft'){db()->prepare("UPDATE certificates SET status='issued',issued_at=NOW() WHERE id=?")->execute([$id]);audit('certificate_issued','certificate',$id,'Issued while generating PDF.');}
  header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.basename($path).'"');readfile($path);exit;
 }
 if($action==='email'){
  if($c['status']==='draft'){db()->prepare("UPDATE certificates SET status='issued',issued_at=NOW() WHERE id=?")->execute([$id]);}
  send_certificate_email($id);flash('success','Certificate email sent.'); 
 } elseif($action==='revoke'){
  if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('POST required');}
  verify_csrf();db()->prepare("UPDATE certificates SET status='revoked',revoked_at=NOW(),revoked_reason=? WHERE id=?")->execute([trim($_POST['reason']??'Revoked by administrator'),$id]);audit('certificate_revoked','certificate',$id,'Certificate revoked.');
  flash('success','Certificate revoked.');
 } else {flash('error','Unknown action.');}
}catch(Throwable $e){flash('error','Action failed: '.$e->getMessage());}
redirect('certificates.php');
