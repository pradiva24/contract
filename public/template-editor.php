<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
admin_required();
verify_csrf();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = db()->prepare('SELECT * FROM templates WHERE id=?');
$st->execute([$id]);
$template = $st->fetch();
if (!$template) { http_response_code(404); exit('Template not found.'); }

$defaults = [
 'canvas_width'=>3508,'canvas_height'=>2480,
 'positions'=>[
  'name'=>['x'=>55,'y'=>61,'size'=>58,'color'=>'#111111','font'=>'Arial','weight'=>'700','align'=>'center'],
  'date'=>['x'=>40,'y'=>90,'size'=>26,'color'=>'#111111','font'=>'Arial','weight'=>'500','align'=>'center'],
  'number'=>['x'=>88,'y'=>9,'size'=>22,'color'=>'#111111','font'=>'Arial','weight'=>'500','align'=>'center'],
  'qr'=>['x'=>92,'y'=>88,'size'=>150],
  'officer'=>['x'=>69,'y'=>61,'size'=>24,'color'=>'#111111','font'=>'Arial','weight'=>'600','align'=>'center'],
  'officer_role'=>['x'=>69,'y'=>65,'size'=>16,'color'=>'#111111','font'=>'Arial','weight'=>'400','align'=>'center'],
  'signature'=>['x'=>69,'y'=>54,'width'=>220,'height'=>90],
  'stamp'=>['x'=>80,'y'=>70,'width'=>130,'height'=>130],
  'participant_photo'=>['x'=>15,'y'=>55,'width'=>300,'height'=>400,'fit'=>'cover','visible'=>true]
 ]
];
$config = json_decode($template['config_json'] ?: '{}', true);
$config = array_replace_recursive($defaults, is_array($config) ? $config : []);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['replace_background'])) {
    $file = $_FILES['background_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error','Pilih file background yang valid.');
        redirect('template-editor.php?id='.$id);
    }
    if ((int)$file['size'] > 12 * 1024 * 1024) {
        flash('error','Ukuran background maksimal 12 MB.');
        redirect('template-editor.php?id='.$id);
    }
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!$info || !in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
        flash('error','Background harus berupa JPG, PNG, atau WEBP yang valid.');
        redirect('template-editor.php?id='.$id);
    }
    $dir = BASE_PATH.'/storage/templates';
    if (!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) {
        flash('error','Folder penyimpanan template tidak dapat dibuat.');
        redirect('template-editor.php?id='.$id);
    }
    $base = 'background_'.$id.'_'.bin2hex(random_bytes(8)).'.jpg';
    $destination = $dir.'/'.$base;
    try {
        $optimizer = BASE_PATH.'/app/image_optimizer.php';
        if (is_file($optimizer)) {
            require_once $optimizer;
            $saved = bta_optimize_uploaded_image($file['tmp_name'], $destination, 'background');
            $storedName = basename($saved['path']);
        } else {
            $ext = $mime === 'image/jpeg' ? 'jpg' : ($mime === 'image/png' ? 'png' : 'webp');
            $storedName = pathinfo($base, PATHINFO_FILENAME).'.'.$ext;
            if (!move_uploaded_file($file['tmp_name'], $dir.'/'.$storedName)) {
                throw new RuntimeException('Gagal menyimpan file background.');
            }
        }
        $newPath = 'templates/'.$storedName;
        db()->prepare('UPDATE templates SET background_path=? WHERE id=?')->execute([$newPath,$id]);
        audit('template_background_replaced','template',$id,'Replaced certificate background.');
        flash('success','Background template berhasil diganti. Pengaturan posisi elemen tetap dipertahankan.');
    } catch (Throwable $e) {
        flash('error','Gagal mengganti background: '.$e->getMessage());
    }
    redirect('template-editor.php?id='.$id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['upload_asset']) || isset($_POST['upload_custom_image']))) {
    $isCustomImage=isset($_POST['upload_custom_image']);
    $kind = $isCustomImage ? 'custom' : ($_POST['upload_asset'] === 'stamp' ? 'stamp' : 'signature');
    if (!isset($_FILES['asset_file']) || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK) {
        flash('error','Pilih file gambar yang valid.'); redirect('template-editor.php?id='.$id);
    }
    $tmp=$_FILES['asset_file']['tmp_name']; $mime=mime_content_type($tmp);
    $ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null;
    if (!$ext || (int)$_FILES['asset_file']['size'] > 5*1024*1024) { flash('error','File harus PNG, JPG, atau WEBP maksimal 5 MB.'); redirect('template-editor.php?id='.$id); }
    $dir=BASE_PATH.'/storage/templates'; if(!is_dir($dir)) mkdir($dir,0775,true);
    $filename=$kind.'_'.$id.'_'.bin2hex(random_bytes(6)).'.webp';
    require_once BASE_PATH.'/app/image_optimizer.php';
    try{$saved=bta_optimize_uploaded_image($tmp,$dir.'/'.$filename,'asset');$filename=basename($saved['path']);}
    catch(Throwable $e){flash('error','Gagal mengonversi gambar ke WebP: '.$e->getMessage());redirect('template-editor.php?id='.$id);}
    $relative='templates/'.$filename;
    if($isCustomImage){$customId='img_'.bin2hex(random_bytes(4));$config['custom_images'][$customId]=['id'=>$customId,'label'=>pathinfo($_FILES['asset_file']['name'],PATHINFO_FILENAME),'path'=>$relative,'x'=>50,'y'=>50,'width'=>300,'height'=>200,'visible'=>true];}
    else{$config['assets'][$kind]=$relative;if(!isset($config['positions'][$kind]))$config['positions'][$kind]=['x'=>$kind==='stamp'?80:69,'y'=>$kind==='stamp'?70:54,'width'=>$kind==='stamp'?130:220,'height'=>$kind==='stamp'?130:90,'visible'=>true];}
    db()->prepare('UPDATE templates SET config_json=? WHERE id=?')->execute([json_encode($config,JSON_UNESCAPED_SLASHES),$id]);
    audit('template_asset_uploaded','template',$id,'Uploaded '.$kind.' image.'); flash('success',$isCustomImage?'Custom image berhasil ditambahkan.':ucfirst($kind).' berhasil diupload.'); redirect('template-editor.php?id='.$id);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = $_POST['config_json'] ?? '';
    $new = json_decode($raw, true);
    if (!is_array($new) || !isset($new['positions']) || !is_array($new['positions'])) {
        flash('error','Template settings were invalid. Please try again.');
        redirect('template-editor.php?id='.$id);
    }
    $allowed = ['name','date','number','qr','officer','officer_role','signature','stamp','participant_photo'];
    $clean = $defaults;
    $clean['assets'] = $config['assets'] ?? [];
    $styleClean = static function(array $source): array {
        $out=[];
        $out['textWidth']=max(20,min(3000,(float)($source['textWidth']??900)));
        $out['lineHeight']=max(.5,min(3,(float)($source['lineHeight']??1.2)));
        $out['letterSpacing']=max(-5,min(30,(float)($source['letterSpacing']??0)));
        $out['wordSpacing']=max(-5,min(50,(float)($source['wordSpacing']??0)));
        $out['textTransform']=in_array(($source['textTransform']??'none'),['none','uppercase','lowercase','capitalize'],true)?$source['textTransform']:'none';
        $out['textDecoration']=in_array(($source['textDecoration']??'none'),['none','underline','line-through'],true)?$source['textDecoration']:'none';
        $out['fontStyle']=in_array(($source['fontStyle']??'normal'),['normal','italic'],true)?$source['fontStyle']:'normal';
        $out['verticalAlign']=in_array(($source['verticalAlign']??'middle'),['top','middle','bottom'],true)?$source['verticalAlign']:'middle';
        return $out;
    };

    foreach ($allowed as $key) {
        if (!isset($new['positions'][$key]) || !is_array($new['positions'][$key])) continue;
        $p = $new['positions'][$key];
        $clean['positions'][$key]['visible'] = !isset($p['visible']) || (bool)$p['visible'];
        $clean['positions'][$key]['x'] = max(0,min(100,(float)($p['x'] ?? $defaults['positions'][$key]['x'])));
        $clean['positions'][$key]['y'] = max(0,min(100,(float)($p['y'] ?? $defaults['positions'][$key]['y'])));
        if ($key === 'signature' || $key === 'stamp' || $key === 'participant_photo') {
            $clean['positions'][$key]['width']=max(20,min(800,(int)($p['width']??160)));
            $clean['positions'][$key]['height']=max(20,min(800,(int)($p['height']??80)));
            if($key==='participant_photo') $clean['positions'][$key]['fit']=in_array(($p['fit']??'cover'),['cover','contain'],true)?$p['fit']:'cover';
        } elseif ($key === 'qr') {
            $clean['positions'][$key]['size'] = max(40,min(500,(int)($p['size'] ?? 150)));
        } else {
            $clean['positions'][$key]['size'] = max(8,min(180,(int)($p['size'] ?? 24)));
            $color = (string)($p['color'] ?? '#111111');
            $clean['positions'][$key]['color'] = preg_match('/^#[0-9a-fA-F]{6}$/',$color) ? $color : '#111111';
            $font = (string)($p['font'] ?? 'Arial');
            $allowedFonts = ['Arial','Helvetica','Georgia','Times New Roman','Courier New','Roboto','Open Sans','Montserrat','Poppins','Inter','Lato','Oswald','Barlow Condensed','Playfair Display','Bebas Neue'];
            $clean['positions'][$key]['font'] = in_array($font,$allowedFonts,true) ? $font : 'Arial';
            $weight = (string)($p['weight'] ?? '400');
            $clean['positions'][$key]['weight'] = in_array($weight,['400','500','600','700','800'],true) ? $weight : '400';
            $align = (string)($p['align'] ?? 'center');
            $clean['positions'][$key]['align'] = in_array($align,['left','center','right'],true) ? $align : 'center';
            $clean['positions'][$key] = array_merge($clean['positions'][$key],$styleClean($p));
        }
    }
    $clean['custom_text']=[];
    $submittedCustomText = array_key_exists('custom_text',$new) && is_array($new['custom_text']) ? $new['custom_text'] : ($config['custom_text'] ?? []);
    foreach($submittedCustomText as $key=>$item){if(!is_array($item))continue;$key=preg_replace('/[^a-zA-Z0-9_-]/','',(string)$key);$txt=trim((string)($item['text']??''));if($key===''||$txt==='')continue;$fonts=['Arial','Helvetica','Georgia','Times New Roman','Courier New','Roboto','Open Sans','Montserrat','Poppins','Inter','Lato','Oswald','Barlow Condensed','Playfair Display','Bebas Neue'];$font=(string)($item['font']??'Arial');$clean['custom_text'][$key]=['id'=>$key,'type'=>in_array(($item['type']??'text'),['text','paragraph'],true)?$item['type']:'text','text'=>mb_substr($txt,0,5000),'x'=>max(0,min(100,(float)($item['x']??50))),'y'=>max(0,min(100,(float)($item['y']??50))),'size'=>max(8,min(180,(int)($item['size']??28))),'color'=>preg_match('/^#[0-9a-fA-F]{6}$/',(string)($item['color']??''))?$item['color']:'#111111','font'=>in_array($font,$fonts,true)?$font:'Arial','weight'=>in_array((string)($item['weight']??'400'),['400','500','600','700','800'],true)?(string)$item['weight']:'400','align'=>in_array(($item['align']??'center'),['left','center','right'],true)?$item['align']:'center','visible'=>!isset($item['visible'])||(bool)$item['visible']];$clean['custom_text'][$key]=array_merge($clean['custom_text'][$key],$styleClean($item));}
    $clean['custom_images']=[];
    $submittedCustomImages = array_key_exists('custom_images',$new) && is_array($new['custom_images']) ? $new['custom_images'] : ($config['custom_images'] ?? []);
    foreach($submittedCustomImages as $key=>$item){if(!is_array($item))continue;$key=preg_replace('/[^a-zA-Z0-9_-]/','',(string)$key);$path=(string)($item['path']??'');if($key===''||!str_starts_with($path,'templates/'))continue;$clean['custom_images'][$key]=['id'=>$key,'label'=>mb_substr((string)($item['label']??'Image'),0,80),'path'=>$path,'x'=>max(0,min(100,(float)($item['x']??50))),'y'=>max(0,min(100,(float)($item['y']??50))),'width'=>max(20,min(1200,(int)($item['width']??300))),'height'=>max(20,min(1200,(int)($item['height']??200))),'visible'=>!isset($item['visible'])||(bool)$item['visible']];}
    $clean['canvas_width'] = 3508;
    $clean['canvas_height'] = 2480;
    $save = db()->prepare('UPDATE templates SET config_json=? WHERE id=?');
    $save->execute([json_encode($clean,JSON_UNESCAPED_SLASHES),$id]);
    audit('template_layout_updated','template',$id,'Updated visual template layout.');
    flash('success','Template layout saved.');
    redirect('template-editor.php?id='.$id);
}
$bgUrl = url('template-image.php?id='.$id);
$assetPreview=[]; foreach(['signature','stamp'] as $ak){$rel=$config['assets'][$ak]??'';$fp=$rel?BASE_PATH.'/storage/'.$rel:'';if($fp&&is_file($fp)){$mime=mime_content_type($fp);$assetPreview[$ak]='data:'.$mime.';base64,'.base64_encode(file_get_contents($fp));}}
$customImagePreview=[];foreach(($config['custom_images']??[]) as $ck=>$ci){$fp=BASE_PATH.'/storage/'.($ci['path']??'');if(is_file($fp)){$mime=mime_content_type($fp);$customImagePreview[$ck]=['src'=>'data:'.$mime.';base64,'.base64_encode(file_get_contents($fp)),'label'=>$ci['label']??'Custom image'];}}
page_header('Template designer');
?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;500;600;700;800&family=Bebas+Neue&family=Inter:wght@400;500;600;700;800&family=Lato:wght@400;700;900&family=Montserrat:wght@400;500;600;700;800&family=Open+Sans:wght@400;500;600;700;800&family=Oswald:wght@400;500;600;700&family=Playfair+Display:wght@400;700&family=Poppins:wght@400;500;600;700;800&family=Roboto:wght@400;500;700;900&display=swap" rel="stylesheet"><style>
.designer{display:grid;grid-template-columns:320px minmax(0,1fr);gap:0;align-items:stretch;min-height:calc(100vh - 190px);background:#f1f3f6;border:1px solid #dfe3e8;border-radius:14px;overflow:hidden}
.designer .panel{grid-column:1;grid-row:1;position:relative;top:auto;max-height:calc(100vh - 190px);height:100%;overflow-y:auto;border:0;border-right:1px solid #dfe3e8;border-radius:0;box-shadow:none;background:#fff}
.designer .canvas-wrap{grid-column:2;grid-row:1;align-self:stretch;display:flex;align-items:center;justify-content:center;min-width:0;min-height:650px;border:0;border-radius:0;padding:34px;background:#eef1f5;overflow:auto}
.designer .canvas{width:min(100%,1100px);flex:0 0 auto}
.canvas-wrap{background:linear-gradient(135deg,#e9edf3,#dfe4eb);border:1px solid #d9dee7;border-radius:16px;padding:16px;overflow:auto}
.canvas{position:relative;width:100%;aspect-ratio:3508/2480;container-type:inline-size;background:#fff center/100% 100% no-repeat;box-shadow:0 12px 32px #17203324;border:1px solid #d5dae2;border-radius:3px;touch-action:none;overflow:hidden;user-select:none;-webkit-user-select:none}
.participant-photo-layer{width:8.55%;height:16.13%;box-sizing:border-box;overflow:hidden;border:1px dashed #8993a5;background:rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-size:clamp(7px,.35cqw,12px);line-height:1.2;padding:4px;color:#6b7280;letter-spacing:.04em;text-align:center}.layer{position:absolute;pointer-events:auto;-webkit-user-drag:none;transform:translate(-50%,-50%);white-space:nowrap;cursor:move;user-select:none;padding:2px 5px;border:1px dashed transparent;text-shadow:0 0 2px #fff,0 0 2px #fff}
.layer.custom-text-layer{user-select:none;-webkit-user-select:none;white-space:pre-wrap;overflow-wrap:anywhere;min-width:20px;min-height:1em;cursor:move}.layer.image-layer{object-fit:fill;cursor:move}.layer.selected{border:1.5px dashed #f04438;background:#ffffff70;outline:2px solid #fff;box-shadow:0 0 0 3px #f0443825}.layer.hidden-layer{display:none}
.layer.qr{width:5%;aspect-ratio:1;white-space:normal;background:#fff;border:1px dashed #555;display:flex;align-items:center;justify-content:center;font-size:clamp(8px,1vw,16px);text-align:center}
.panel{position:sticky;top:12px;max-height:calc(100vh - 24px);overflow-y:auto;padding:0!important;border:1px solid #e2e6ed;border-radius:16px;box-shadow:0 8px 28px #17203312;background:#fff}
.panel-head{padding:17px 16px 14px;background:#fff;color:#202938;border-bottom:1px solid #e8ebef;border-radius:0}
.panel-head h2{color:#202938;font-size:15px}.panel-head p{color:#7a8492}
.panel-body{padding:13px 14px 18px}
.designer-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:#202938;color:#fff;border-radius:12px 12px 0 0}
.designer-toolbar .toolbar-title{font-size:13px;font-weight:750;letter-spacing:.02em}
.designer-toolbar .toolbar-meta{font-size:11px;color:#c7ced8}
.designer-toolbar .toolbar-actions{display:flex;align-items:center;gap:8px}
.designer-toolbar button{border:1px solid #596273;background:#303a4a;color:#fff;border-radius:7px;padding:7px 10px;font-size:12px;cursor:pointer}
.designer-toolbar button:hover{background:#465267}
.designer-shell{border-radius:12px;overflow:hidden;box-shadow:0 10px 30px #1d293914}
.canvas-stage{width:100%;display:flex;align-items:center;justify-content:center}
.canvas-stage .canvas{transition:width .15s ease}
.panel .tool-card{border-radius:8px;background:#fff;border-color:#e9edf2;padding:11px}
.panel .tool-card h3{font-size:11px;color:#313b4b}
.panel .field-label{font-size:9px}
.panel .btn{min-height:36px}
.panel-head h2{font-size:17px;margin:0 0 5px;color:white}.panel-head p{font-size:12px;margin:0;color:#cbd2df;line-height:1.45}
.panel-body{padding:15px 16px 18px}
.panel label{display:block;font-size:11px;font-weight:700;letter-spacing:.035em;color:#596273;margin:0 0 6px}
.panel input:not([type=checkbox]):not([type=color]),.panel select,.panel textarea{width:100%;min-height:38px;padding:8px 10px;border:1px solid #d9dee7;border-radius:8px;background:#fff;color:#202938;font-size:13px;box-sizing:border-box;margin:0 0 10px;outline:none}
.panel input:focus,.panel select:focus,.panel textarea:focus{border-color:#f04438;box-shadow:0 0 0 3px #f0443818}
.panel input[type=color]{width:100%;height:38px;padding:3px;border:1px solid #d9dee7;border-radius:8px;background:white;margin:0 0 10px}
.panel input[type=checkbox]{width:16px;height:16px;accent-color:#ef4438;margin:0}
.panel .field-label{font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#7b8493;font-weight:800;margin:10px 0 6px}
.panel .row2{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.panel .btn{width:100%;min-height:39px;border-radius:9px;font-weight:700;font-size:13px;margin:5px 0;display:flex;align-items:center;justify-content:center;gap:7px}
.panel .btn.light{background:#f5f6f8;border:1px solid #e0e4eb;color:#303949}
.panel .btn.light:hover{background:#eceff4}
.panel .btn.dark{background:#202938;color:#fff}
.panel .btn:not(.light):not(.dark){background:#ef4438;color:white}
.panel hr{border:0;border-top:1px solid #edf0f4;margin:15px 0}
.tool-card{padding:12px;border:1px solid #e5e9ef;border-radius:12px;background:#fbfcfd;margin:12px 0}
.tool-card h3{font-size:12px;text-transform:uppercase;letter-spacing:.07em;color:#374151;margin:0 0 10px;display:flex;align-items:center;gap:7px}
.tool-card .hint{font-size:11px;line-height:1.45;color:#818a98;margin:0 0 9px}
.tool-upload{display:grid;gap:7px;margin-top:8px}
.tool-upload input[type=file],.panel input[type=file]{font-size:11px;width:100%;padding:8px;background:#fff;border:1px dashed #cbd2dc;border-radius:8px}
.visibility-row{display:flex!important;align-items:center;gap:8px;padding:9px 10px;background:#f7f8fa;border:1px solid #e8ebf0;border-radius:9px;margin:8px 0 10px!important;color:#465061!important;font-size:12px!important;letter-spacing:0!important}
.action-bar{position:sticky;bottom:-18px;background:#fff;padding:12px 0 0;margin-top:12px;border-top:1px solid #edf0f4;display:grid;grid-template-columns:.8fr 1.2fr;gap:8px}
.panel .muted{font-size:11px;line-height:1.5;color:#87909e}
@media(max-width:1000px){.designer{grid-template-columns:290px minmax(0,1fr)}.designer .canvas-wrap{padding:20px;min-height:500px}}
@media(max-width:760px){.designer{grid-template-columns:1fr}.designer .panel{grid-column:1;grid-row:2;max-height:none;height:auto;border-right:0;border-top:1px solid #dfe3e8}.designer .canvas-wrap{grid-column:1;grid-row:1;min-height:300px;padding:12px}.designer .canvas{width:100%}.designer-toolbar{flex-wrap:wrap}.designer-toolbar .toolbar-actions{width:100%;justify-content:flex-end}.action-bar{position:static}}
.canvas .layer{pointer-events:auto;touch-action:none;cursor:move;-webkit-user-drag:none}.canvas img.layer{draggable:false}
.canvas .layer{pointer-events:auto!important;touch-action:none;cursor:move;-webkit-user-drag:none;user-select:none;-webkit-user-select:none}
.canvas .layer *{pointer-events:none;user-select:none;-webkit-user-select:none}
.canvas img.layer{-webkit-user-drag:none}.canvas{touch-action:none}\n
.canvas .layer{pointer-events:auto!important;cursor:move!important;touch-action:none;-webkit-user-drag:none;user-select:none;-webkit-user-select:none}
.canvas .layer *{pointer-events:none;user-select:none;-webkit-user-select:none}
.canvas img.layer{draggable:false}
</style>
<div class="designer-shell">
 <div class="designer-toolbar">
  <div><div class="toolbar-title">BTA Certificate Designer</div><div class="toolbar-meta">Visual editor · A4 Landscape · <?=e($template['name'] ?? 'Certificate template')?></div></div>
  <div class="toolbar-actions"><button type="button" id="zoomOut" aria-label="Zoom out">−</button><span id="zoomLabel" class="toolbar-meta">100%</span><button type="button" id="zoomIn" aria-label="Zoom in">+</button><button type="button" id="zoomFit">Fit</button></div>
 </div>
 <div class="designer">
  <div class="canvas-wrap"><div class="canvas-stage"><div id="certificateCanvas" class="canvas" style="background-image:url('<?=e($bgUrl)?>')">
   <div class="layer" data-key="number">BTA – 20261030-0001</div>
   <div class="layer" data-key="name">GUEST NAME</div>
   <div class="layer" data-key="date">DATE OF ADVENTURE</div>
   <div class="layer qr" data-key="qr">QR CODE</div>
   <div class="layer participant-photo-layer" data-key="participant_photo">PARTICIPANT PHOTO</div>
   <div class="layer" data-key="officer">I KADEK ADNYANA</div>
   <div class="layer" data-key="officer_role">SAFETY OFFICER</div>
   <?php foreach(['signature','stamp'] as $ak): if(isset($assetPreview[$ak])): ?><img class="layer image-layer" data-key="<?=$ak?>" src="<?=e($assetPreview[$ak])?>" alt="<?=$ak?>"><?php endif; endforeach; ?>
   <?php foreach($customImagePreview as $ck=>$ci): ?><img class="layer image-layer custom-image-layer" data-key="<?=e($ck)?>" src="<?=e($ci['src'])?>" alt="<?=e($ci['label'])?>"><?php endforeach; ?>
   <?php foreach(($config['custom_text']??[]) as $ck=>$ct): ?><div class="layer custom-text-layer" data-key="<?=e($ck)?>"><?=e($ct['text']??'Custom text')?></div><?php endforeach; ?>
  </div></div></div>
  <aside class="card panel">
   <div class="panel-head"><h2>Design tools</h2><p>Select an element, edit its appearance and position, then save your layout.</p></div>
   <div class="panel-body">
    <label for="elementSelect">Selected element</label>
    <select id="elementSelect">
     <option value="name">Guest name</option><option value="date">Activity date</option><option value="number">Certificate number</option><option value="qr">QR Code</option><option value="officer">Safety Officer name</option><option value="officer_role">Safety Officer position</option><option value="signature">Signature image</option><option value="stamp">Stamp image</option><option value="participant_photo">Participant Photo (optional)</option><?php foreach(($config['custom_text']??[]) as $ck=>$ct): ?><option value="<?=e($ck)?>">Custom text: <?=e($ct['text']??'Text')?></option><?php endforeach; ?><?php foreach($customImagePreview as $ck=>$ci): ?><option value="<?=e($ck)?>">Custom image: <?=e($ci['label'])?></option><?php endforeach; ?>
    </select>
    <section class="tool-card" id="textControls">
     <h3>✎ Typography</h3>
     <div id="customTextWrap" style="display:none"><div class="field-label">Text content</div><textarea id="customText" rows="2"></textarea></div>
     <div class="field-label">Font family</div><select id="font"><option>Arial</option><option>Helvetica</option><option>Georgia</option><option>Times New Roman</option><option>Courier New</option><option>Roboto</option><option>Open Sans</option><option>Montserrat</option><option>Poppins</option><option>Inter</option><option>Lato</option><option>Oswald</option><option>Barlow Condensed</option><option>Playfair Display</option><option>Bebas Neue</option></select>
     <div class="row2"><div><div class="field-label">Font size (px)</div><input id="size" type="number" min="8" max="180"></div><div><div class="field-label">Font weight</div><select id="weight"><option value="400">Regular</option><option value="500">Medium</option><option value="600">Semi bold</option><option value="700">Bold</option><option value="800">Extra bold</option></select></div></div>
     <div class="row2"><div><div class="field-label">Text color</div><input id="color" type="color"></div><div><div class="field-label">Alignment</div><select id="align"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></div></div>
     <div class="row2"><div><div class="field-label">Text box width (px)</div><input id="textWidth" type="number" min="20" max="3000" value="900"></div><div><div class="field-label">Line height</div><input id="lineHeight" type="number" min="0.5" max="3" step="0.05" value="1.2"></div></div>
     <div class="row2"><div><div class="field-label">Letter spacing (px)</div><input id="letterSpacing" type="number" min="-5" max="30" step="0.1" value="0"></div><div><div class="field-label">Word spacing (px)</div><input id="wordSpacing" type="number" min="-5" max="50" step="0.1" value="0"></div></div>
     <div class="row2"><div><div class="field-label">Text transform</div><select id="textTransform"><option value="none">Normal</option><option value="uppercase">UPPERCASE</option><option value="lowercase">lowercase</option><option value="capitalize">Capitalize</option></select></div><div><div class="field-label">Text decoration</div><select id="textDecoration"><option value="none">None</option><option value="underline">Underline</option><option value="line-through">Line through</option></select></div></div>
     <div class="row2"><div><div class="field-label">Font style</div><select id="fontStyle"><option value="normal">Normal</option><option value="italic">Italic</option></select></div><div><div class="field-label">Vertical alignment</div><select id="verticalAlign"><option value="top">Top</option><option value="middle">Middle</option><option value="bottom">Bottom</option></select></div></div>
    </section>
    <section class="tool-card" id="imageControls" style="display:none">
     <h3>▧ Image size</h3>
     <div id="photoFitWrap" style="display:none"><div class="field-label">Photo crop</div><select id="photoFit"><option value="cover">Cover</option><option value="contain">Contain</option></select></div>
     <div class="row2"><div><div class="field-label">Width (px)</div><input id="imgWidth" type="number" min="20" max="800"></div><div><div class="field-label">Height (px)</div><input id="imgHeight" type="number" min="20" max="800"></div></div>
    </section>
    <section class="tool-card" id="qrControls" style="display:none">
     <h3>▦ QR code</h3><div class="field-label">QR size (px)</div><input id="qrSize" type="number" min="40" max="500">
    </section>
    <section class="tool-card">
     <h3>⌖ Position & visibility</h3>
     <div class="row2"><div><div class="field-label">Position X (%)</div><input id="posX" type="number" min="0" max="100" step=".1"></div><div><div class="field-label">Position Y (%)</div><input id="posY" type="number" min="0" max="100" step=".1"></div></div>
     <label class="visibility-row"><input id="visible" type="checkbox" checked> Show this element</label>
     <button class="btn light" id="remove" type="button">Hide element</button>
     <button class="btn light" id="deleteCustom" type="button" style="display:none;color:#b91c1c">Delete custom element</button>
    </section>
    <section class="tool-card">
     <h3>＋ Add elements</h3>
     <p class="hint">Add a single-line text, multi-line paragraph, or upload an image. Every item can be selected and dragged on the canvas.</p>
     <p class="hint">Participant Photo is a built-in optional certificate field. It appears on generated certificates only when a guest photo has been uploaded in Certificate Form.</p>
     <div class="row2">
      <button class="btn light" id="addText" type="button">＋ Add text</button>
      <button class="btn light" id="addParagraph" type="button">＋ Add paragraph</button>
     </div>
     <form method="post" enctype="multipart/form-data" class="tool-upload"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="upload_custom_image" value="1"><label>Upload custom image · PNG / JPG / WEBP · max 5 MB</label><input type="file" name="asset_file" accept="image/png,image/jpeg,image/webp" required><button class="btn light" type="submit">＋ Add custom image</button></form>
    </section>
    <section class="tool-card">
     <h3>▧ Replace certificate background</h3>
     <p class="hint">Ganti gambar dasar sertifikat tanpa membuat template baru. Posisi, ukuran, font, QR, tanda tangan, dan elemen lain tetap dipertahankan.</p>
     <form method="post" enctype="multipart/form-data" class="tool-upload" onsubmit="return confirm('Ganti background template ini? Pengaturan elemen tidak akan dihapus.');">
      <?=csrf_field()?>
      <input type="hidden" name="id" value="<?=$id?>">
      <input type="hidden" name="replace_background" value="1">
      <label>File background baru · JPG / PNG / WEBP · maks. 12 MB</label>
      <input type="file" name="background_file" accept="image/jpeg,image/png,image/webp" required>
      <button class="btn light" type="submit">⇧ Replace background</button>
     </form>
    </section>
    <section class="tool-card">
     <h3>⇧ Signature & stamp</h3>
     <form method="post" enctype="multipart/form-data" class="tool-upload"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><label>Image type</label><select name="upload_asset"><option value="signature">Signature / TTD</option><option value="stamp">Stamp / Stempel</option></select><input type="file" name="asset_file" accept="image/png,image/jpeg,image/webp" required><button class="btn light" type="submit">Upload signature / stamp</button></form>
    </section>
    <button id="apply" type="button" style="display:none">Apply</button><div class="action-bar"><a class="btn light" href="<?=e(url('templates.php'))?>">← Back</a><button class="btn dark" id="save" type="button">Save layout</button></div>
    <form id="saveForm" method="post" style="display:none"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="config_json" id="configJson"></form>
    <p class="muted">Drag elements directly on the preview. Changes are stored only after clicking Save layout. Actual guest details and QR are inserted when generating a certificate.</p>
   </div>
  </aside>
 </div>
</div>
<script>
const cfg = <?=json_encode($config,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
cfg.custom_text=Object.assign({},cfg.custom_text||{});cfg.custom_images=Object.assign({},cfg.custom_images||{});
const canvas=document.getElementById('certificateCanvas');
const $=id=>document.getElementById(id);
let zoom=100;
function setZoom(next){zoom=Math.max(35,Math.min(160,next));canvas.style.width=zoom+'%';$('zoomLabel').textContent=zoom+'%';}
$('zoomIn').addEventListener('click',()=>setZoom(zoom+10));
$('zoomOut').addEventListener('click',()=>setZoom(zoom-10));
$('zoomFit').addEventListener('click',()=>{zoom=100;canvas.style.width='min(100%, 1100px)';$('zoomLabel').textContent='Fit';});

const labels={name:'GUEST NAME',date:'DATE OF ADVENTURE',number:'BTA – 20261030-0001',qr:'QR CODE',officer:'I KADEK ADNYANA',officer_role:'SAFETY OFFICER'};
let selected='name';
function textDefaults(p){return Object.assign({textWidth:900,lineHeight:1.2,letterSpacing:0,wordSpacing:0,textTransform:'none',textDecoration:'none',fontStyle:'normal',verticalAlign:'middle'},p||{});}
function applyTextStyle(el,p){p=textDefaults(p);el.style.width=(Number(p.textWidth||900)/3508*100)+'%';el.style.height='auto';el.style.whiteSpace='pre-wrap';el.style.lineHeight=String(p.lineHeight||1.2);el.style.letterSpacing=(Number(p.letterSpacing)||0)+'px';el.style.wordSpacing=(Number(p.wordSpacing)||0)+'px';el.style.textTransform=p.textTransform||'none';el.style.textDecoration=p.textDecoration||'none';el.style.fontStyle=p.fontStyle||'normal';el.style.display=p.visible===false?'none':'flex';el.style.alignItems=p.verticalAlign==='top'?'flex-start':(p.verticalAlign==='bottom'?'flex-end':'center');el.style.justifyContent='center';}
function draw(){
 document.querySelectorAll('.layer').forEach(el=>{
  const k=el.dataset.key,p=cfg.positions[k]||cfg.custom_text[k]||cfg.custom_images[k];if(!p)return;el.style.display=p.visible===false?'none':'';el.style.left=p.x+'%';el.style.top=p.y+'%';
  if(cfg.custom_images[k]||k==='signature'||k==='stamp'||k==='participant_photo'){el.style.width=(p.width/3508*100)+'%';el.style.height=(p.height/2480*100)+'%';el.style.padding='0';el.style.boxSizing='border-box';}
  else if(k==='qr'){el.style.width=(p.size/3508*100)+'%';el.style.height='auto';}
  else if(k==='signature'||k==='stamp'){el.style.width=(p.width/3508*100)+'%';el.style.height='auto';el.style.padding='0';el.style.border='1px dashed transparent';}
  else {el.style.fontFamily=p.font||'Arial';el.style.fontSize=(p.size/3508*100)+'cqw';el.style.color=p.color||'#111';el.style.fontWeight=p.weight||'400';el.style.textAlign=p.align||'center';applyTextStyle(el,p);if(cfg.custom_text[k])el.textContent=p.text;}
  el.classList.toggle('selected',k===selected);
 });
}
function select(k){selected=k;$('elementSelect').value=k;let p=cfg.positions[k]||cfg.custom_text[k]||cfg.custom_images[k];$('posX').value=p.x;$('posY').value=p.y;$('visible').checked=p.visible!==false;
 const qr=k==='qr',img=(k==='signature'||k==='stamp'||k==='participant_photo'||!!cfg.custom_images[k]),custom=!!cfg.custom_text[k];$('deleteCustom').style.display=(custom||!!cfg.custom_images[k])?'block':'none';$('textControls').style.display=(qr||img)?'none':'block';$('customTextWrap').style.display=custom?'block':'none';if(custom){$('customText').value=p.text;$('customText').rows=p.type==='paragraph'?5:2;$('customText').placeholder=p.type==='paragraph'?'Write paragraph text...':'Enter text...';}$('qrControls').style.display=qr?'block':'none';$('imageControls').style.display=img?'block':'none';
 if(qr){$('qrSize').value=p.size;}else if(img){$('imgWidth').value=p.width|| (k==='participant_photo'?300:160);$('imgHeight').value=p.height|| (k==='participant_photo'?400:80);$('photoFitWrap').style.display=k==='participant_photo'?'block':'none';if(k==='participant_photo')$('photoFit').value=p.fit||'cover';}else{p=textDefaults(p);$('font').value=p.font||'Arial';$('size').value=p.size;$('color').value=p.color||'#111111';$('weight').value=p.weight||'400';$('align').value=p.align||'center';$('textWidth').value=p.textWidth;$('lineHeight').value=p.lineHeight;$('letterSpacing').value=p.letterSpacing;$('wordSpacing').value=p.wordSpacing;$('textTransform').value=p.textTransform;$('textDecoration').value=p.textDecoration;$('fontStyle').value=p.fontStyle;$('verticalAlign').value=p.verticalAlign;}
 draw();
}
$('elementSelect').addEventListener('change',e=>select(e.target.value));
// Pointer Events drag controller: supports mouse, trackpad, pen and touch.
let activeDrag=null;
function getLayerFromTarget(target){
  if(!target || !target.closest) return null;
  const layer=target.closest('.layer');
  return layer && canvas.contains(layer) ? layer : null;
}
function beginLayerDrag(e){
  if(e.pointerType==='mouse' && e.button!==0) return;
  const layer=getLayerFromTarget(e.target);
  if(!layer) return;
  const key=layer.dataset.key;
  const p=cfg.positions[key] || cfg.custom_text[key] || cfg.custom_images[key];
  if(!p || p.visible===false) return;
  const rect=layer.getBoundingClientRect();
  e.preventDefault();
  e.stopPropagation();
  select(key);
  activeDrag={
    key,
    pointerId:e.pointerId,
    offsetX:e.clientX-(rect.left+rect.width/2),
    offsetY:e.clientY-(rect.top+rect.height/2)
  };
  layer.style.cursor='grabbing';
  try{canvas.setPointerCapture(e.pointerId);}catch(err){}
}
function moveLayerDrag(e){
  if(!activeDrag || e.pointerId!==activeDrag.pointerId) return;
  const p=cfg.positions[activeDrag.key] || cfg.custom_text[activeDrag.key] || cfg.custom_images[activeDrag.key];
  if(!p) return;
  const rect=canvas.getBoundingClientRect();
  if(rect.width<=0 || rect.height<=0) return;
  p.x=Math.max(0,Math.min(100,((e.clientX-activeDrag.offsetX-rect.left)/rect.width)*100));
  p.y=Math.max(0,Math.min(100,((e.clientY-activeDrag.offsetY-rect.top)/rect.height)*100));
  selected=activeDrag.key;
  $('posX').value=p.x.toFixed(1);
  $('posY').value=p.y.toFixed(1);
  draw();
  if(e.cancelable) e.preventDefault();
}
function endLayerDrag(e){
  if(!activeDrag || (e && e.pointerId!==undefined && e.pointerId!==activeDrag.pointerId)) return;
  const key=activeDrag.key;
  const pointerId=activeDrag.pointerId;
  activeDrag=null;
  const layer=Array.from(canvas.querySelectorAll('.layer')).find(el=>el.dataset.key===key);
  if(layer) layer.style.cursor='move';
  try{if(canvas.hasPointerCapture(pointerId)) canvas.releasePointerCapture(pointerId);}catch(err){}
}
canvas.addEventListener('pointerdown',beginLayerDrag,true);
window.addEventListener('pointermove',moveLayerDrag,{passive:false});
window.addEventListener('pointerup',endLayerDrag);
window.addEventListener('pointercancel',endLayerDrag);
canvas.addEventListener('lostpointercapture',()=>{if(activeDrag) endLayerDrag({pointerId:activeDrag.pointerId});});
canvas.addEventListener('dragstart',e=>e.preventDefault());
canvas.querySelectorAll('img.layer').forEach(img=>{img.draggable=false;});
function applyControls(){
 const p=cfg.positions[selected]||cfg.custom_text[selected]||cfg.custom_images[selected];p.x=Math.max(0,Math.min(100,Number($('posX').value)||0));p.y=Math.max(0,Math.min(100,Number($('posY').value)||0));p.visible=$('visible').checked;
 if(selected==='qr')p.size=Math.max(40,Math.min(500,Number($('qrSize').value)||150));
 else if(selected==='signature'||selected==='stamp'||selected==='participant_photo'||cfg.custom_images[selected]){p.width=Math.max(20,Math.min(800,Number($('imgWidth').value)||160));p.height=Math.max(20,Math.min(800,Number($('imgHeight').value)||80));if(selected==='participant_photo')p.fit=$('photoFit').value;}
 else {if(cfg.custom_text[selected]){p.text=$('customText').value;const option=$('elementSelect').querySelector('option[value=\"'+selected+'\"]');if(option)option.textContent=(p.type==='paragraph'?'Paragraph: ':'Text: ')+p.text.replace(/\\n/g,' ').slice(0,55);}p.font=$('font').value;p.size=Math.max(8,Math.min(180,Number($('size').value)||24));p.color=$('color').value;p.weight=$('weight').value;p.align=$('align').value;p.textWidth=Math.max(20,Math.min(3000,Number($('textWidth').value)||900));p.lineHeight=Math.max(.5,Math.min(3,Number($('lineHeight').value)||1.2));p.letterSpacing=Math.max(-5,Math.min(30,Number($('letterSpacing').value)||0));p.wordSpacing=Math.max(-5,Math.min(50,Number($('wordSpacing').value)||0));p.textTransform=$('textTransform').value;p.textDecoration=$('textDecoration').value;p.fontStyle=$('fontStyle').value;p.verticalAlign=$('verticalAlign').value;}
 draw();
}
['posX','posY','font','size','color','weight','align','textWidth','lineHeight','letterSpacing','wordSpacing','textTransform','textDecoration','fontStyle','verticalAlign','qrSize','imgWidth','imgHeight','visible','customText'].forEach(id=>$(id).addEventListener('input',applyControls));
$('apply').addEventListener('click',applyControls);
$('remove').addEventListener('click',()=>{$('visible').checked=false;applyControls();});
$('deleteCustom').addEventListener('click',()=>{if(cfg.custom_text[selected])delete cfg.custom_text[selected];else if(cfg.custom_images[selected])delete cfg.custom_images[selected];else return;const opt=$('elementSelect').querySelector('option[value="'+selected+'"]');if(opt)opt.remove();const layer=canvas.querySelector('[data-key="'+selected+'"]');if(layer)layer.remove();selected='name';select('name');});
// Dynamic custom layers use the delegated drag listeners above.
function dragBind(el){
  if(!el) return;
  if(el.tagName==='IMG') el.draggable=false;
  el.style.touchAction='none';
}
function addTextElement(kind){
 const id=(kind==='paragraph'?'para_':'txt_')+Math.random().toString(36).slice(2,10);
 const value=kind==='paragraph'?'NEW PARAGRAPH\\nWrite your text here':'NEW TEXT';
 cfg.custom_text[id]={id,type:kind,text:value,x:50,y:50,size:kind==='paragraph'?32:36,color:'#111111',font:'Arial',weight:kind==='paragraph'?'400':'700',align:kind==='paragraph'?'left':'center',textWidth:kind==='paragraph'?1200:900,lineHeight:1.25,letterSpacing:0,wordSpacing:0,textTransform:'none',textDecoration:'none',fontStyle:'normal',verticalAlign:kind==='paragraph'?'top':'middle',visible:true};
 const op=document.createElement('option');op.value=id;op.textContent=(kind==='paragraph'?'Paragraph: ':'Text: ')+value.replace(/\\n/g,' ');$('elementSelect').appendChild(op);
 const el=document.createElement('div');el.className='layer custom-text-layer';el.dataset.key=id;el.textContent=value;canvas.appendChild(el);dragBind(el);select(id);applyControls();
}
$('addText').addEventListener('click',()=>addTextElement('text'));
$('addParagraph').addEventListener('click',()=>addTextElement('paragraph'));
$('save').addEventListener('click',()=>{$('configJson').value=JSON.stringify(cfg);$('saveForm').submit();});
draw();select('name');
</script>
<?php page_footer(); ?>
