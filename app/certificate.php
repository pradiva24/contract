<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function next_certificate_number(PDO $pdo, string $date): string {
 // The sequence is global: activity dates must not reset certificate numbers.
 $prefix = (string) setting('certificate_prefix', 'BTA');
 $pdo->beginTransaction();
 try {
  // The migration creates one locked sequence row (id = 1).
  $pdo->exec('INSERT IGNORE INTO certificate_sequences (id, last_number) VALUES (1, 0)');
  $st = $pdo->query('SELECT last_number FROM certificate_sequences WHERE id = 1 FOR UPDATE');
  $current = $st->fetchColumn();
  if ($current === false) {
   throw new RuntimeException('Global certificate sequence row is missing. Run the certificate sequence migration first.');
  }
  $next = (int) $current + 1;
  $up = $pdo->prepare('UPDATE certificate_sequences SET last_number = ? WHERE id = 1');
  $up->execute([$next]);
  $pdo->commit();
  return sprintf('%s-%07d', $prefix, $next);
 } catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
 }
}


/** Build a cache fingerprint from the certificate data and the exact template version.
 * The fingerprint is stored beside the generated PDF, so no database migration is needed.
 */
function certificate_pdf_cache_key(int $id): string {
 $st=db()->prepare('SELECT c.*,t.id AS joined_template_id,t.name AS template_name,t.background_path,t.config_json,t.updated_at AS template_updated_at FROM certificates c LEFT JOIN templates t ON t.id=c.template_id WHERE c.id=? LIMIT 1');
 $st->execute([$id]);
 $row=$st->fetch();
 if(!$row) throw new RuntimeException('Certificate not found.');
 if(empty($row['joined_template_id'])) {
  $fallback=db()->query('SELECT id,name,background_path,config_json,updated_at AS template_updated_at FROM templates WHERE is_active=1 ORDER BY is_default DESC,updated_at DESC,id DESC LIMIT 1')->fetch();
  $row['_fallback_template']=$fallback?:null;
 }
 // Include file metadata for template assets referenced in the template JSON.
 $assetMeta=[];
 $cfg=json_decode((string)($row['config_json']??($row['_fallback_template']['config_json']??'')),true);
 $walk=function($value) use (&$walk,&$assetMeta) {
  if(!is_array($value)) return;
  foreach($value as $key=>$item) {
   if(is_string($item) && preg_match('~(?:^|/)(?:storage/)?[A-Za-z0-9_./-]+\.(?:png|jpe?g|webp|gif)$~i',$item)) {
    $relative=ltrim(str_replace('\\','/',$item),'/');
    $candidate=BASE_PATH.'/storage/'.preg_replace('~^(?:storage/)+~','',$relative);
    if(is_file($candidate)) { $st=@stat($candidate); $assetMeta[$relative]=[$st['size']??0,$st['mtime']??0]; }
   } elseif(is_array($item)) $walk($item);
  }
 };
 if(is_array($cfg)) $walk($cfg);
 $row['_asset_meta']=$assetMeta;
 $row['_pdf_renderer_version']='bta-pdf-renderer-2026-11-01-email-cache-v1';
 $row['_certificate_officer_name']=(string)setting('certificate_officer_name','I KADEK ADNYANA');
 $row['_certificate_officer_role']=(string)setting('certificate_officer_role','SAFETY OFFICER');
 $row['_verify_url_base']=url('verify.php');
 return hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR)?:serialize($row));
}

function generate_certificate_pdf(int $id): string {
 $st=db()->prepare('SELECT c.*,t.id AS joined_template_id,t.background_path,t.config_json FROM certificates c LEFT JOIN templates t ON t.id=c.template_id WHERE c.id=?');
 $st->execute([$id]); $c=$st->fetch();
 if(!$c) throw new RuntimeException('Certificate not found.');
 if(!class_exists(\Dompdf\Dompdf::class)) throw new RuntimeException('Dompdf is not loaded. Check Composer autoload and PHP version.');

 // If a certificate was created without a template_id, use the active default
 // template (or most recently updated active template) rather than silently
 // generating a plain white certificate.
 if(empty($c['joined_template_id'])) {
  $fallback=db()->query('SELECT id,background_path,config_json FROM templates WHERE is_active=1 ORDER BY is_default DESC, updated_at DESC, id DESC LIMIT 1')->fetch();
  if($fallback) {
   $c['background_path']=$fallback['background_path'];
   $c['config_json']=$fallback['config_json'];
  }
 }

 $token=(string)$c['verification_token'];
 $verifyUrl=url('verify.php?token='.$token);
 $qrPath=BASE_PATH.'/storage/qr/'.$token.'.png';
 @mkdir(dirname($qrPath),0775,true);
 if(class_exists(\Endroid\QrCode\Builder\Builder::class)) {
  $result=(new \Endroid\QrCode\Builder\Builder())->build(data:$verifyUrl,size:260,margin:4);
  $result->saveToFile($qrPath);
 } else {
  throw new RuntimeException('Endroid QR Code library is not loaded.');
 }

 $cfg=json_decode((string)($c['config_json']??'{}'),true);
 $cfg=is_array($cfg)?$cfg:[];
 $pos=$cfg['positions']??[];
 $defaults=[
  'name'=>['x'=>50,'y'=>61,'size'=>58,'color'=>'#111111','font'=>'Arial','weight'=>'700','align'=>'center','visible'=>true],
  'date'=>['x'=>30,'y'=>90,'size'=>26,'color'=>'#111111','font'=>'Arial','weight'=>'500','align'=>'center','visible'=>true],
  'number'=>['x'=>88,'y'=>9,'size'=>22,'color'=>'#111111','font'=>'Arial','weight'=>'500','align'=>'center','visible'=>true],
  'qr'=>['x'=>92,'y'=>88,'size'=>150,'visible'=>true],
  'officer'=>['x'=>69,'y'=>61,'size'=>24,'color'=>'#111111','font'=>'Arial','weight'=>'600','align'=>'center','visible'=>true],
  'officer_role'=>['x'=>69,'y'=>65,'size'=>16,'color'=>'#111111','font'=>'Arial','weight'=>'400','align'=>'center','visible'=>true],
  'signature'=>['x'=>69,'y'=>54,'width'=>220,'height'=>90,'visible'=>true],
  'stamp'=>['x'=>80,'y'=>70,'width'=>130,'height'=>130,'visible'=>true],
  'participant_photo'=>['x'=>15,'y'=>35,'width'=>260,'height'=>347,'visible'=>true,'fit'=>'cover']
 ];
 $pos=array_replace_recursive($defaults,is_array($pos)?$pos:[]);

 // Designer stores element dimensions in source-canvas pixels (3508 x 2480).
 // Convert those dimensions proportionally to the A4 landscape PDF CSS canvas.
 $canvasW=1122.52;
 $canvasH=793.70;
 $scaleX=$canvasW/3508;
 $scaleY=$canvasH/2480;
 // Dompdf is most reliable with PNG/JPEG. Convert stored WebP assets in memory
 // so the library can render WebP uploads without changing the stored originals.
 $pdfImageUri=function(string $path): string {
  if(!is_file($path)) return '';
  $mime=mime_content_type($path) ?: '';
  if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)) return '';

  // Downsample and recompress raster assets before embedding them in the PDF.
  // A4 landscape at 150 DPI is approximately 1754 x 1240 pixels.
  if(!function_exists('imagecreatefromstring')) {
   throw new RuntimeException('PDF image optimization requires the GD extension in MAMP PHP.');
  }
  $raw=(string)file_get_contents($path);
  $image=@imagecreatefromstring($raw);
  if(!$image) throw new RuntimeException('Unable to decode an image used by the certificate.');
  $srcW=imagesx($image); $srcH=imagesy($image);
  $maxW=1800; $maxH=1273;
  $ratio=min(1,$maxW/max(1,$srcW),$maxH/max(1,$srcH));
  $dstW=max(1,(int)round($srcW*$ratio)); $dstH=max(1,(int)round($srcH*$ratio));
  if($dstW!==$srcW || $dstH!==$srcH) {
   $resized=imagecreatetruecolor($dstW,$dstH);
   if($mime==='image/png' || $mime==='image/webp') {
    imagealphablending($resized,false); imagesavealpha($resized,true);
    $transparent=imagecolorallocatealpha($resized,0,0,0,127);
    imagefilledrectangle($resized,0,0,$dstW,$dstH,$transparent);
   }
   imagecopyresampled($resized,$image,0,0,0,0,$dstW,$dstH,$srcW,$srcH);
   imagedestroy($image); $image=$resized;
  }
  ob_start();
  if($mime==='image/jpeg') {
   imagejpeg($image,null,72);
   $outMime='image/jpeg';
  } else {
   // Keep transparency for stamps and logos.
   imagepng($image,null,9);
   $outMime='image/png';
  }
  $bytes=(string)ob_get_clean();
  imagedestroy($image);
  return 'data:'.$outMime.';base64,'.base64_encode($bytes);
 };

 $bgPath=!empty($c['background_path'])?BASE_PATH.'/storage/'.$c['background_path']:'';
 $backgroundHtml='';
 if($bgPath && is_file($bgPath)) {
  $uri=$pdfImageUri($bgPath);
  if($uri!=='') $backgroundHtml='<img class="background" src="'.$uri.'" alt="">';
 } else {
  throw new RuntimeException('Template background image not found. Check the selected certificate template and storage/templates file.');
 }

 $allowedFonts=['Arial','Helvetica','Georgia','Times New Roman','Courier New','Roboto','Open Sans','Montserrat','Poppins','Inter','Lato','Oswald','Barlow Condensed','Playfair Display','Bebas Neue'];
 $textCss=function(array $p,bool $paragraph=false) use($allowedFonts,$scaleX): string {
  // Use Dompdf's bundled Unicode font for generated certificate text.
  // The uploaded/custom designer fonts can render with broken glyph mapping
  // in Dompdf when their font metrics/cache are incomplete.
  $font='DejaVu Sans';
  $weight=in_array((string)($p['weight']??'400'),['400','500','600','700','800'],true)?(string)$p['weight']:'400';
  $align=in_array(($p['align']??'center'),['left','center','right'],true)?$p['align']:'center';
  $color=preg_match('/^#[0-9a-fA-F]{6}$/',(string)($p['color']??''))?$p['color']:'#111111';
  $size=max(1,(float)($p['size']??24)*$scaleX);
  $width=max(20,min(3000,(float)($p['textWidth']??($paragraph?1200:3000)))*$scaleX);
  $line=max(.5,min(3,(float)($p['lineHeight']??1.2)));
  // Avoid PDF glyph displacement caused by CSS letter/word spacing in Dompdf.
  // Text remains centered and uses the same font size, color and weight.
  $letter=0;
  $word=0;
  $transform=in_array(($p['textTransform']??'none'),['none','uppercase','lowercase','capitalize'],true)?$p['textTransform']:'none';
  $decoration=in_array(($p['textDecoration']??'none'),['none','underline','line-through'],true)?$p['textDecoration']:'none';
  $fontStyle=($p['fontStyle']??'normal')==='italic'?'italic':'normal';
  $whiteSpace=$paragraph?'pre-wrap':'nowrap';
  return 'font-family:\''.htmlspecialchars($font,ENT_QUOTES,'UTF-8').'\';font-size:'.$size.'px;color:'.$color.';font-weight:'.$weight.';text-align:'.$align.';width:'.$width.'px;line-height:'.$line.';letter-spacing:0;word-spacing:0;text-transform:'.$transform.';text-decoration:'.$decoration.';font-style:'.$fontStyle.';white-space:'.$whiteSpace.';';
 };
 $safeText=function(string $key,string $value) use($pos,$textCss): string {
  $p=$pos[$key]??[];
  if(($p['visible']??true)===false) return '';
  $x=max(0,min(100,(float)($p['x']??50)));
  $y=max(0,min(100,(float)($p['y']??50)));
  return '<div class="element text" style="left:'.$x.'%;top:'.$y.'%;'.$textCss($p,false).'">'.htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div>';
 };

 $htmlElements='';
 $htmlElements.=$safeText('number',(string)$c['certificate_number']);
 $htmlElements.=$safeText('name',(string)$c['recipient_name']);
 $htmlElements.=$safeText('date',date('F d, Y',strtotime((string)$c['activity_date'])));
 $htmlElements.=$safeText('officer',(string)setting('certificate_officer_name','I KADEK ADNYANA'));
 $htmlElements.=$safeText('officer_role',(string)setting('certificate_officer_role','SAFETY OFFICER'));

 // QR dimensions use the same source-canvas pixel scale as the Designer.
 $qp=$pos['qr'];
 if(($qp['visible']??true)!==false && is_file($qrPath)) {
  $q=base64_encode((string)file_get_contents($qrPath));
  $x=max(0,min(100,(float)($qp['x']??92))); $y=max(0,min(100,(float)($qp['y']??88)));
  $size=max(8,(float)($qp['size']??150)*$scaleX);
  $htmlElements.='<img class="element image" src="data:image/png;base64,'.$q.'" style="left:'.$x.'%;top:'.$y.'%;width:'.$size.'px;height:'.$size.'px" alt="QR">';
 }

 foreach(['signature','stamp'] as $assetKey) {
  $ap=$pos[$assetKey]??[];
  $rel=$cfg['assets'][$assetKey]??'';
  $assetPath=$rel?BASE_PATH.'/storage/'.$rel:'';
  if(($ap['visible']??true)!==false && $assetPath && is_file($assetPath)) {
   $uri=$pdfImageUri($assetPath); if($uri==='') continue;
   $x=max(0,min(100,(float)($ap['x']??50))); $y=max(0,min(100,(float)($ap['y']??50)));
   $w=max(1,(float)($ap['width']??150)*$scaleX);
   $h=max(1,(float)($ap['height']??80)*$scaleY);
   $htmlElements.='<img class="element image" src="'.$uri.'" style="left:'.$x.'%;top:'.$y.'%;width:'.$w.'px;height:'.$h.'px" alt="">';
  }
 }


 // Optional participant photo: render only when the certificate has a real uploaded photo.
 // No empty frame or placeholder is added when the photo path is null or the file is missing.
 $photoRel=(string)($c['participant_photo_path']??'');
 $photoPos=$pos['participant_photo']??['x'=>15,'y'=>35,'width'=>260,'height'=>347,'visible'=>true,'fit'=>'cover'];
 if($photoRel!=='' && ($photoPos['visible']??true)!==false && str_starts_with($photoRel,'certificates/photos/')) {
  $photoPath=BASE_PATH.'/storage/'.$photoRel;
  if(is_file($photoPath)) {
   $photoUri=$pdfImageUri($photoPath);
   if($photoUri!=='') {
    $px=max(0,min(100,(float)($photoPos['x']??15))); $py=max(0,min(100,(float)($photoPos['y']??35)));
    $pw=max(20,(float)($photoPos['width']??260)*$scaleX); $ph=max(20,(float)($photoPos['height']??347)*$scaleY);
    $fit=($photoPos['fit']??'cover')==='contain'?'contain':'cover';
    $htmlElements.='<img class="element image participant-photo" src="'.$photoUri.'" style="left:'.$px.'%;top:'.$py.'%;width:'.$pw.'px;height:'.$ph.'px;object-fit:'.$fit.'" alt="Participant photo">';
   }
  }
 }

 // Additional user-created text and paragraph elements from Template Designer.
 foreach(($cfg['custom_text']??[]) as $customKey=>$item) {
  if(!is_array($item) || ($item['visible']??true)===false) continue;
  $x=max(0,min(100,(float)($item['x']??50))); $y=max(0,min(100,(float)($item['y']??50)));
  $isParagraph=($item['type']??'text')==='paragraph';
  $content=htmlspecialchars((string)($item['text']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $htmlElements.='<div class="element text custom-text" style="left:'.$x.'%;top:'.$y.'%;'.$textCss($item,$isParagraph).'">'.$content.'</div>';
 }
 // Additional uploaded custom images.
 foreach(($cfg['custom_images']??[]) as $customKey=>$item) {
  if(!is_array($item) || ($item['visible']??true)===false) continue;
  $rel=(string)($item['path']??'');
  if($rel==='' || !str_starts_with($rel,'templates/')) continue;
  $assetPath=BASE_PATH.'/storage/'.$rel;
  if(!is_file($assetPath)) continue;
  $uri=$pdfImageUri($assetPath); if($uri==='') continue;
  $x=max(0,min(100,(float)($item['x']??50))); $y=max(0,min(100,(float)($item['y']??50)));
  $w=max(1,(float)($item['width']??300)*$scaleX); $h=max(1,(float)($item['height']??200)*$scaleY);
  $htmlElements.='<img class="element image" src="'.$uri.'" style="left:'.$x.'%;top:'.$y.'%;width:'.$w.'px;height:'.$h.'px" alt="">';
 }

 $html='<!doctype html><html><head><meta charset="utf-8"><style>
 @page{size:A4 landscape;margin:0}
 *{box-sizing:border-box}
 html,body{margin:0;padding:0;width:100%;height:100%;font-family:DejaVu Sans,sans-serif}
 .sheet{position:relative;width:100%;height:100%;overflow:hidden}
 .background{position:absolute;left:0;top:0;width:100%;height:100%;display:block}
 .element{position:absolute;transform:translate(-50%,-50%);z-index:2}
 .text{white-space:pre-wrap;overflow-wrap:break-word;line-height:1.05;padding:0;margin:0;}
 .image{object-fit:contain}
 </style></head><body><div class="sheet">'.$backgroundHtml.$htmlElements.'</div></body></html>';

 $options=new \Dompdf\Options();
 $options->set('isRemoteEnabled',false);
 $options->set('isHtml5ParserEnabled',true);
 $options->set('defaultFont','DejaVu Sans');
 $dompdf=new \Dompdf\Dompdf($options);
 $dompdf->setPaper('A4','landscape');
 $dompdf->loadHtml($html,'UTF-8');
 $dompdf->render();

 $dir=BASE_PATH.'/storage/certificates'; if(!is_dir($dir))mkdir($dir,0775,true);
 $file=$dir.'/'.$c['certificate_number'].'.pdf';
 $pdfBytes=$dompdf->output();
 if(!is_string($pdfBytes) || !str_starts_with($pdfBytes,'%PDF-')) {
  throw new RuntimeException('Dompdf did not return a valid PDF document.');
 }
 $tmpFile=$file.'.'.bin2hex(random_bytes(6)).'.tmp';
 if(file_put_contents($tmpFile,$pdfBytes,LOCK_EX)===false || !rename($tmpFile,$file)) {
  @unlink($tmpFile);
  throw new RuntimeException('Unable to save the generated certificate PDF.');
 }
 $relative='certificates/'.$c['certificate_number'].'.pdf';
 db()->prepare('UPDATE certificates SET pdf_path=? WHERE id=?')->execute([$relative,$id]);
 return $file;
}

/**
 * Return the latest valid PDF, generating it only when the certificate/template
 * fingerprint has changed or the cached PDF/sidecar is missing.
 */
function certificate_pdf_get_or_generate(int $id): string {
 $key=certificate_pdf_cache_key($id);
 $st=db()->prepare('SELECT certificate_number,pdf_path FROM certificates WHERE id=? LIMIT 1');
 $st->execute([$id]);
 $row=$st->fetch();
 if(!$row) throw new RuntimeException('Certificate not found.');

 $relative=(string)($row['pdf_path']??'');
 $candidate=$relative!==''?BASE_PATH.'/storage/'.$relative:BASE_PATH.'/storage/certificates/'.$row['certificate_number'].'.pdf';
 $candidateReal=realpath($candidate);
 $storageRoot=realpath(BASE_PATH.'/storage');
 $safeFile=$candidateReal && $storageRoot && str_starts_with($candidateReal,$storageRoot.DIRECTORY_SEPARATOR) && is_file($candidateReal);
 $sidecar=$candidate.'.sha256';

 if($safeFile && is_file($sidecar)) {
  $stored=trim((string)@file_get_contents($sidecar));
  if($stored!=='' && hash_equals($key,$stored)) return $candidateReal;
 }

 $generated=generate_certificate_pdf($id);
 $generatedReal=realpath($generated);
 if(!$generatedReal || !is_file($generatedReal) || !str_starts_with($generatedReal,BASE_PATH.'/storage/')) {
  throw new RuntimeException('Generated certificate PDF path is invalid.');
 }
 $sidecar=$generatedReal.'.sha256';
 $tmp=$sidecar.'.'.bin2hex(random_bytes(5)).'.tmp';
 if(file_put_contents($tmp,$key,LOCK_EX)===false || !rename($tmp,$sidecar)) {
  @unlink($tmp);
  // The PDF itself is valid; failure to persist cache metadata must not block
  // email delivery or preview. It will simply be regenerated next time.
 }
 return $generatedReal;
}

function send_certificate_email(int $id): bool {
 $st=db()->prepare('SELECT * FROM certificates WHERE id=?'); $st->execute([$id]); $c=$st->fetch();
 if(!$c) throw new RuntimeException('Certificate not found.');
 $attachmentPath=certificate_pdf_get_or_generate($id);
 if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) throw new RuntimeException('PHPMailer is not loaded. Check Composer autoload and PHP version.');
 $verifyUrl=url('verify.php?token='.$c['verification_token']);
 $replacements=['{name}'=>$c['recipient_name'],'{certificate_number}'=>$c['certificate_number'],'{activity_name}'=>$c['activity_name'],'{activity_date}'=>$c['activity_date'],'{verify_url}'=>$verifyUrl];
 $subject=strtr(setting('email_subject','Your ATV Adventure Certificate'),$replacements);
 $body=strtr(setting('email_body','Thank you for joining Bali Tarantula Adventure. Your certificate is attached.'),$replacements);
 $signature=strtr(setting('email_signature','Best regards,<br><strong>Bali Tarantula Adventure Team</strong>'),$replacements);
 $log=db()->prepare("INSERT INTO email_logs(certificate_id,recipient_email,subject,status) VALUES(?,?,?,'queued')");
 $log->execute([$id,$c['recipient_email'],$subject]); $logId=(int)db()->lastInsertId();
 try {
  $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
  $mail->isSMTP();
  $mail->Host=(string)setting('mail_host',(string)config('MAIL_HOST',config('SMTP_HOST','')));
  $mail->SMTPAuth=true;
  $mail->Username=(string)setting('mail_username',(string)config('MAIL_USERNAME',config('SMTP_USERNAME','')));
  $mail->Password=(string)config('MAIL_PASSWORD',config('SMTP_PASSWORD',''));
  $mail->Port=(int)setting('mail_port',(string)config('MAIL_PORT',config('SMTP_PORT','587')));
  $enc=strtolower((string)setting('mail_encryption',(string)config('MAIL_ENCRYPTION',config('SMTP_ENCRYPTION','tls'))));
  if($enc==='tls') $mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
  elseif($enc==='ssl') $mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
  elseif($enc==='none') { $mail->SMTPSecure=''; $mail->SMTPAutoTLS=false; }
  $from=(string)setting('mail_from_address',(string)config('MAIL_FROM_ADDRESS',config('SMTP_FROM_EMAIL','')));
  $fromName=(string)setting('mail_from_name',(string)config('MAIL_FROM_NAME',config('SMTP_FROM_NAME','Bali Tarantula Adventure')));
  if($mail->Host==='' || $mail->Username==='' || $mail->Password==='') throw new RuntimeException('SMTP host, username, or password is missing. Check Settings and MAIL_PASSWORD in .env.');
  if($from==='' || !filter_var($from,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid From email address is required in Settings.');
  $mail->CharSet='UTF-8';
  $mail->setFrom($from,$fromName);
  $mail->addAddress($c['recipient_email'],$c['recipient_name']);
  $mail->Subject=$subject;
  $mail->isHTML(true);
  $mail->Body='<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#222">'.$body.'<p>Verify certificate: <a href="'.htmlspecialchars($verifyUrl,ENT_QUOTES,'UTF-8').'">'.htmlspecialchars($verifyUrl,ENT_QUOTES,'UTF-8').'</a></p><div>'.$signature.'</div></div>';
  $mail->AltBody=trim(strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",$body.' '.$signature)))."\nVerify certificate: ".$verifyUrl;
  if(!is_file($attachmentPath)) throw new RuntimeException('Generated certificate PDF is missing.');
  $mail->addAttachment($attachmentPath,basename($attachmentPath),'base64','application/pdf');
  $mail->send();
  db()->prepare("UPDATE email_logs SET status='sent',sent_at=NOW() WHERE id=?")->execute([$logId]);
  audit('email_sent','certificate',$id,'Certificate email sent to '.$c['recipient_email']);
  return true;
 } catch(Throwable $e) {
  db()->prepare("UPDATE email_logs SET status='failed',error_message=? WHERE id=?")->execute([substr($e->getMessage(),0,60000),$logId]);
  throw $e;
 }
}
