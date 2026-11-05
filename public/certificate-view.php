<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/certificate.php';
admin_required();
$id=(int)($_GET['id']??0);
if($id<1){http_response_code(400);exit('Invalid certificate ID.');}
try{
 $st=db()->prepare('SELECT certificate_number FROM certificates WHERE id=?');
 $st->execute([$id]);
 $cert=$st->fetch();
 if(!$cert){http_response_code(404);exit('Certificate not found.');}
 $file=generate_certificate_pdf($id);
 if(!is_file($file)){throw new RuntimeException('Generated certificate PDF is missing.');}
 header('Content-Type: application/pdf');
 header('Content-Disposition: inline; filename="'.basename($cert['certificate_number']).'.pdf"');
 header('Content-Length: '.filesize($file));
 readfile($file);
 exit;
}catch(Throwable $e){
 http_response_code(500);
 echo 'Unable to display certificate: '.e($e->getMessage());
}
