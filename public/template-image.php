<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
admin_required();
$id=(int)($_GET['id']??0);
$st=db()->prepare('SELECT background_path FROM templates WHERE id=?');
$st->execute([$id]);
$path=$st->fetchColumn();
if(!$path){http_response_code(404);exit('Not found');}
$file=BASE_PATH.'/storage/'.$path;
if(!is_file($file)){http_response_code(404);exit('Image not found');}
$mime=mime_content_type($file);
if(!in_array($mime,['image/jpeg','image/png'],true)){http_response_code(415);exit('Unsupported image');}
header('Content-Type: '.$mime);
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($file);
