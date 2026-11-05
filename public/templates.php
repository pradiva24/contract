<?php
require_once dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/image_optimizer.php';admin_required();verify_csrf();
if($_SERVER['REQUEST_METHOD']==='POST'){
 $name=trim($_POST['name']??'');
 if(!$name||empty($_FILES['background']['tmp_name'])){flash('error','Template name and background image are required.');redirect('templates.php');}
 $f=$_FILES['background'];
 if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || (int)$f['size']>12*1024*1024){flash('error','Background image must be JPG, PNG or WEBP up to 12MB.');redirect('templates.php');}
 $dir=dirname(__DIR__).'/storage/templates';@mkdir($dir,0775,true);$filename=bin2hex(random_bytes(12)).'.jpg';
 try{$optimized=bta_optimize_uploaded_image($f['tmp_name'],$dir.'/'.$filename,'background');$filename=basename($optimized['path']);}
 catch(Throwable $e){flash('error',$e->getMessage());redirect('templates.php');}
 $cfg=['canvas_width'=>3508,'canvas_height'=>2480,'positions'=>[
  'name'=>['x'=>50,'y'=>72,'size'=>58,'color'=>'#111111'],
  'date'=>['x'=>30,'y'=>88,'size'=>26,'color'=>'#111111'],
  'number'=>['x'=>84,'y'=>8,'size'=>22,'color'=>'#111111'],
  'qr'=>['x'=>90,'y'=>88,'size'=>150]
 ]];
 $st=db()->prepare('INSERT INTO templates(name,background_path,config_json,created_by) VALUES(?,?,?,?)');
 $st->execute([$name,'templates/'.$filename,json_encode($cfg),$_SESSION['admin']['id']]);audit('template_created','template',(int)db()->lastInsertId(),'Uploaded template '.$name);
 flash('success','Template uploaded. Open Template Designer to adjust text and QR positions.');redirect('templates.php');
}
$rows=db()->query('SELECT * FROM templates ORDER BY id DESC')->fetchAll();page_header('Certificate templates');
?><div class="card"><h2>Upload certificate background</h2><form method="post" enctype="multipart/form-data"><?=csrf_field()?><label>Template name</label><input name="name" required><label>Background PNG/JPG/WEBP (max 12MB · stored as WebP)</label><input type="file" name="background" accept="image/png,image/jpeg,image/webp" required><p><button class="btn">Upload template</button></p></form></div><div class="card"><table><tr><th>Name</th><th>Background file</th><th>Created</th><th>Actions</th></tr><?php foreach($rows as $r):?><tr><td><?=e($r['name'])?></td><td><?=e($r['background_path'])?></td><td><?=e($r['created_at'])?></td><td><a class="btn" href="<?=e(url('template-editor.php?id='.$r['id']))?>">Open designer</a></td></tr><?php endforeach?></table></div><?php page_footer();?>
