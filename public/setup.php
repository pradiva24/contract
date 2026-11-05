<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
verify_csrf();
$count=(int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
if($count>0){http_response_code(403);exit('Setup is locked because an administrator already exists.');}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $name=trim($_POST['name']??'');$email=filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL);$pass=$_POST['password']??'';
 if(!$name||!$email||strlen($pass)<12)$error='Enter a name, valid email, and password of at least 12 characters.';
 else{
  $st=db()->prepare("INSERT INTO admins(name,email,password_hash,role) VALUES(?,?,?,'super_admin')");
  $st->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);
  header('Location: '.url('login.php'));exit;
 }
}
?><!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"><title>Initial setup</title></head><body><main class="wrap"><div class="card" style="max-width:520px;margin:60px auto"><h1>Initial Administrator Setup</h1><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif?><form method="post"><?=csrf_field()?><label>Full name</label><input name="name" required><label>Email</label><input name="email" type="email" required><label>Password (minimum 12 characters)</label><input name="password" type="password" minlength="12" required><p><button class="btn">Create administrator</button></p></form></div></main></body></html>
