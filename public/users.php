<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
admin_required();
if(!is_global_super_admin()){http_response_code(403);exit('Only Super Admin can manage users.');}
$companies=db()->query('SELECT id,name FROM companies WHERE is_active=1 ORDER BY name')->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'');$id=max(0,(int)($_POST['id']??0));
 if($action==='toggle'){
  if($id===(int)$_SESSION['admin']['id']){flash('error','You cannot deactivate your own account.');redirect('users.php');}
  $st=db()->prepare('SELECT is_active FROM admins WHERE id=?');$st->execute([$id]);$active=$st->fetchColumn();
  if($active===false){flash('error','User not found.');redirect('users.php');}
  $new=(int)$active?0:1;db()->prepare('UPDATE admins SET is_active=? WHERE id=?')->execute([$new,$id]);audit('user_status_changed','admin',$id,'Active status '.$new);flash('success','User status updated.');redirect('users.php');
 }
 if($action==='save'){
  $name=trim((string)($_POST['name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$role=(string)($_POST['role']??'staff');$companyId=max(0,(int)($_POST['company_id']??0));$password=(string)($_POST['password']??'');
  if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,['admin','staff','super_admin'],true)){flash('error','Invalid name, email, or role.');redirect('users.php');}
  if($role==='super_admin')$companyId=0;
  else{$st=db()->prepare('SELECT id FROM companies WHERE id=? AND is_active=1');$st->execute([$companyId]);if(!$st->fetchColumn()){flash('error','Select an active business.');redirect('users.php');}}
  if($id===(int)$_SESSION['admin']['id']&&$role!=='super_admin'){flash('error','You cannot remove your own Super Admin role.');redirect('users.php');}
  if(($id===0&&strlen($password)<12)||($id>0&&$password!==''&&strlen($password)<12)){flash('error','Password must be at least 12 characters.');redirect('users.php'.($id?'?edit='.$id:''));}
  try{
   if($id){
    if($password!=='')db()->prepare('UPDATE admins SET name=?,email=?,role=?,company_id=?,password_hash=? WHERE id=?')->execute([$name,$email,$role,$companyId?:null,password_hash($password,PASSWORD_DEFAULT),$id]);
    else db()->prepare('UPDATE admins SET name=?,email=?,role=?,company_id=? WHERE id=?')->execute([$name,$email,$role,$companyId?:null,$id]);
    audit('user_updated','admin',$id,'Updated '.$email);
   }else{
    db()->prepare('INSERT INTO admins(name,email,password_hash,role,company_id,is_active) VALUES(?,?,?,?,?,1)')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,$companyId?:null]);
    $id=(int)db()->lastInsertId();audit('user_created','admin',$id,'Created '.$email);
   }
   flash('success','User saved.');
  }catch(PDOException $e){flash('error','Unable to save user. Email may already exist.');}
  redirect('users.php');
 }
}
$edit=null;$editId=max(0,(int)($_GET['edit']??0));
if($editId){$st=db()->prepare('SELECT id,name,email,role,company_id,is_active FROM admins WHERE id=?');$st->execute([$editId]);$edit=$st->fetch()?:null;}
$filter=max(0,(int)($_GET['company_id']??0));
$sql='SELECT a.id,a.name,a.email,a.role,a.company_id,a.is_active,a.last_login_at,c.name company_name FROM admins a LEFT JOIN companies c ON c.id=a.company_id';
$args=[];if($filter){$sql.=' WHERE a.company_id=?';$args[]=$filter;}$sql.=' ORDER BY c.name,a.name';$st=db()->prepare($sql);$st->execute($args);$users=$st->fetchAll();
page_header('User Management');
?>
<div class="card"><h2><?=$edit?'Edit user':'Create user'?></h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>">
<div class="formgrid">
<div><label>Name *</label><input name="name" required maxlength="120" value="<?=e($edit['name']??'')?>"></div>
<div><label>Email *</label><input type="email" name="email" required value="<?=e($edit['email']??'')?>"></div>
<div><label>Role *</label><select name="role"><option value="admin" <?=($edit['role']??'')==='admin'?'selected':''?>>Company Admin</option><option value="staff" <?=($edit['role']??'staff')==='staff'?'selected':''?>>Staff</option><option value="super_admin" <?=($edit['role']??'')==='super_admin'?'selected':''?>>Super Admin</option></select></div>
<div><label>Business</label><select name="company_id"><option value="0">Select business</option><?php foreach($companies as $c):?><option value="<?=(int)$c['id']?>" <?=((int)($edit['company_id']??0)===(int)$c['id'])?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></div>
<div><label><?=$edit?'New password (blank = unchanged)':'Password *'?></label><input type="password" name="password" minlength="12" <?=$edit?'':'required'?>></div>
</div><p class="muted">Each Company Admin or Staff account is assigned to exactly one business. Super Admin has access to all businesses. Password minimum 12 characters.</p><button class="btn"><?=$edit?'Save user':'Create user'?></button><?php if($edit):?> <a class="btn light" href="<?=e(url('users.php'))?>">Cancel</a><?php endif;?></form></div>
<div class="card" style="overflow-x:auto"><div class="actions"><h2>All users</h2><form method="get"><select name="company_id" onchange="this.form.submit()"><option value="0">All businesses</option><?php foreach($companies as $c):?><option value="<?=(int)$c['id']?>" <?=$filter===(int)$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></form></div>
<table><thead><tr><th>User</th><th>Role</th><th>Business</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead><tbody>
<?php foreach($users as $u):?><tr><td><strong><?=e($u['name'])?></strong><br><?=e($u['email'])?></td><td><?=$u['role']==='super_admin'?'Super Admin':($u['role']==='admin'?'Company Admin':'Staff')?></td><td><?=e($u['company_name']??'All businesses')?></td><td><?=$u['is_active']?'Active':'Inactive'?></td><td><?=e($u['last_login_at']??'—')?></td><td><div class="actions"><a class="btn light" href="<?=e(url('users.php?edit='.(int)$u['id']))?>">Edit</a><?php if((int)$u['id']!==(int)$_SESSION['admin']['id']):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><button class="btn dark"><?=$u['is_active']?'Deactivate':'Activate'?></button></form><?php endif;?></div></td></tr><?php endforeach;?>
</tbody></table></div><?php page_footer(); ?>