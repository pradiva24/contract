<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/certificate.php';

$token=trim((string)($_GET['token']??''));
$type=(string)($_GET['type']??'');
$download=(string)($_GET['download']??'')==='1';

if(!preg_match('/^[a-f0-9]{64}$/i',$token)||!in_array($type,['pdf','photo'],true)){
 http_response_code(400);
 exit('Invalid file request.');
}

$st=db()->prepare('SELECT id,status,pdf_path,participant_photo_path FROM certificates WHERE verification_token=? LIMIT 1');
$st->execute([$token]);
$certificate=$st->fetch();

if(!$certificate||$certificate['status']!=='issued'){
 http_response_code(404);
 exit('Certificate file not found.');
}

if($type==='pdf'){
 // Always rebuild from the currently active template before serving.
 // This also replaces PDFs generated before the text-rendering fix.
 try {
  $generatedPath=certificate_pdf_get_or_generate((int)$certificate['id']);
 } catch(Throwable $e) {
  error_log('Certificate PDF regeneration failed for certificate ID '.(int)$certificate['id'].': '.$e->getMessage());
  http_response_code(500);
  exit('Certificate PDF could not be generated. Please contact the administrator.');
 }
 $file=realpath($generatedPath);
} else {
 $relativePath=(string)($certificate['participant_photo_path']??'');
 if($relativePath===''||str_contains($relativePath,'..')){
  http_response_code(404);
  exit('Certificate file not found.');
 }
 $storageRoot=realpath(dirname(__DIR__).'/storage');
 $file=realpath(dirname(__DIR__).'/storage/'.ltrim($relativePath,'/'));
 if(!$storageRoot||!$file||!str_starts_with($file,$storageRoot.DIRECTORY_SEPARATOR)){
  http_response_code(404);
  exit('Certificate file not found.');
 }
}

$storageRoot=realpath(dirname(__DIR__).'/storage');
if(!$storageRoot||!$file||!str_starts_with($file,$storageRoot.DIRECTORY_SEPARATOR)||!is_file($file)){
 http_response_code(404);
 exit('Certificate file not found.');
}

$mime=mime_content_type($file)?:'application/octet-stream';
$allowed=$type==='pdf'?['application/pdf']:['image/jpeg','image/png','image/webp'];
if(!in_array($mime,$allowed,true)){
 http_response_code(415);
 exit('Unsupported certificate file.');
}

$filename=$type==='pdf'
 ? preg_replace('/[^A-Za-z0-9._-]/','_',basename($file))
 : 'participant-photo.'.match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',default=>'img'};

header('Content-Type: '.$mime);
header('Content-Length: '.(string)filesize($file));
header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.$filename.'"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($file);
exit;
