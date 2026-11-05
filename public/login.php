<?php
require_once dirname(__DIR__).'/app/auth.php';
verify_csrf(); if(is_admin())redirect('dashboard.php'); $error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(login_admin(trim($_POST['email']??''),$_POST['password']??''))redirect('dashboard.php');
 $error='Email or password is incorrect.';
}
?><!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"><title>Admin login</title></head><body><main class="wrap"><div class="card" style="max-width:440px;margin:70px auto"><h1>Administrator Login</h1><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif?><form method="post"><?=csrf_field()?><label>Email</label><input type="email" name="email" required><label>Password</label><input type="password" name="password" required><p><button class="btn">Login</button></p></form><p class="muted">First time? <a href="<?=e(url('setup.php'))?>">Create administrator</a></p></div></main></body></html>
