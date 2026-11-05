<?php
declare(strict_types=1);

/**
 * Store every accepted uploaded raster image as WebP.
 * Requires PHP GD with WebP support; fail clearly rather than silently storing
 * a different format. Transparent PNGs retain their alpha channel.
 */
function bta_optimize_uploaded_image(string $source, string $destination, string $kind='asset'): array {
    if (!is_file($source)) throw new RuntimeException('Uploaded image file is missing.');
    $info=@getimagesize($source);
    if(!$info) throw new RuntimeException('The uploaded file is not a valid image.');
    $mime=$info['mime']??'';
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)) throw new RuntimeException('Only JPG, PNG, and WEBP images are supported.');
    if(!extension_loaded('gd') || !function_exists('imagewebp')) throw new RuntimeException('PHP GD with WebP support is required. Enable the GD extension in MAMP, then retry the upload.');
    $src=match($mime){
        'image/jpeg'=>@imagecreatefromjpeg($source),
        'image/png'=>@imagecreatefrompng($source),
        'image/webp'=>@imagecreatefromwebp($source),
        default=>false
    };
    if(!$src) throw new RuntimeException('Unable to decode image. Check that MAMP GD supports this format.');
    $w=(int)$info[0];$h=(int)$info[1];
    $maxW=$kind==='background'?3508:1800;$maxH=$kind==='background'?2480:1800;
    $scale=min(1,$maxW/max(1,$w),$maxH/max(1,$h));
    $nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));
    $dst=imagecreatetruecolor($nw,$nh);
    imagealphablending($dst,false);imagesavealpha($dst,true);
    $transparent=imagecolorallocatealpha($dst,0,0,0,127);
    imagefilledrectangle($dst,0,0,$nw,$nh,$transparent);
    imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
    $target=preg_replace('/\.[^.]+$/','',$destination).'.webp';
    $quality=$kind==='background'?82:86;
    $ok=imagewebp($dst,$target,$quality);
    imagedestroy($src);imagedestroy($dst);
    if(!$ok) throw new RuntimeException('Failed to encode image as WebP.');
    return ['path'=>$target,'mime'=>'image/webp','width'=>$nw,'height'=>$nh,'optimized'=>true];
}
