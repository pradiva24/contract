<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
if (!is_global_super_admin()) { http_response_code(403); exit('Only Super Admin can manage companies.'); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
 verify_csrf();
 $action=(string)($_POST['action']??'');
 if ($action==='save') {
  $id=max(0,(int)($_POST['id']??0));
  $name=trim((string)($_POST['name']??''));
  $slug=strtolower(trim((string)($_POST['slug']??'')));
  $code=strtoupper(trim((string)($_POST['code']??'')));
  $website=trim((string)($_POST['website_url']??''));
  if($name===''||mb_strlen($name)>150||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)||!preg_match('/^[A-Z0-9_-]{2,30}$/',$code)||($website!==''&&!filter_var($website,FILTER_VALIDATE_URL))){
   flash('error','Check business name, slug, code, and website URL.');redirect('companies.php');
  }
  try{
   if($id>0){
    db()->prepare('UPDATE companies SET name=?,slug=?,code=?,website_url=? WHERE id=?')->execute([$name,$slug,$code,$website?:null,$id]);
    audit('company_updated','company',$id,'Updated '.$name);
   }else{
    db()->prepare('INSERT INTO companies(name,slug,code,website_url,is_active) VALUES(?,?,?,?,1)')->execute([$name,$slug,$code,$website?:null]);
    $id=(int)db()->lastInsertId();audit('company_created','company',$id,'Created '.$name);
   }
   flash('success','Business saved.');
  }catch(PDOException $e){flash('error','Could not save. Business slug or code may already exist.');}
  redirect('companies.php');
 }
 if($action==='toggle'){
  $id=max(0,(int)($_POST['id']??0));
  $st=db()->prepare('SELECT is_active FROM companies WHERE id=?');$st->execute([$id]);$active=$st->fetchColumn();
  if($active===false){flash('error','Business not found.');redirect('companies.php');}
  $new=(int)$active===1?0:1;
  db()->prepare('UPDATE companies SET is_active=? WHERE id=?')->execute([$new,$id]);
  audit('company_status_changed','company',$id,'Active status '.$new);
  flash('success',$new?'Business activated.':'Business deactivated.');
  redirect('companies.php');
 }
}
$edit=null;$editId=max(0,(int)($_GET['edit']??0));
if($editId){$st=db()->prepare('SELECT * FROM companies WHERE id=?');$st->execute([$editId]);$edit=$st->fetch()?:null;}
$rows=db()->query("SELECT c.*,(SELECT COUNT(*) FROM admins a WHERE a.company_id=c.id) user_count,(SELECT COUNT(*) FROM contract_templates t WHERE t.company_id=c.id AND t.is_active=1) template_count,(SELECT COUNT(*) FROM contracts k WHERE k.company_id=c.id) contract_count FROM companies c ORDER BY c.name")->fetchAll();
page_header('Business Management');
?>
<div class="card"><h2><?=$edit?'Edit business':'Create business'?></h2>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>">
<div class="formgrid">
<div><label>Business name *</label><input name="name" maxlength="150" required value="<?=e($edit['name']??'')?>"></div>
<div><label>URL slug *</label><input name="slug" maxlength="160" required placeholder="bali-swing" value="<?=e($edit['slug']??'')?>"></div>
<div><label>Business code *</label><input name="code" maxlength="30" required placeholder="BSW" value="<?=e($edit['code']??'')?>"></div>
<div><label>Website URL</label><input name="website_url" type="url" value="<?=e($edit['website_url']??'')?>"></div>
</div><p class="muted">Slug dan kode bisnis harus unik. User biasa hanya dapat terhubung ke satu bisnis.</p>
<button class="btn"><?=$edit?'Save changes':'Create business'?></button><?php if($edit):?> <a class="btn light" href="<?=e(url('companies.php'))?>">Cancel</a><?php endif;?>
</form></div>
<div class="card" style="overflow-x:auto"><h2>All businesses</h2><table><thead><tr><th>Business</th><th>Code</th><th>Users</th><th>Templates</th><th>Contracts</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><strong><?=e($r['name'])?></strong><br><small><?=e($r['slug'])?></small></td><td><?=e($r['code'])?></td><td><?=(int)$r['user_count']?></td><td><?=(int)$r['template_count']?></td><td><?=(int)$r['contract_count']?></td><td><?=$r['is_active']?'Active':'Inactive'?></td><td><div class="actions"><a class="btn light" href="<?=e(url('companies.php?edit='.(int)$r['id']))?>">Edit</a><form method="post" style="margin:0"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn dark"><?=$r['is_active']?'Deactivate':'Activate'?></button></form><a class="btn light" href="<?=e(url('users.php?company_id='.(int)$r['id']))?>">Manage users</a></div></td></tr><?php endforeach;?>
</tbody></table></div>
<?php page_footer(); ?>