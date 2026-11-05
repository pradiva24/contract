<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
function login_admin(string $email,string $password): bool {
 $st=db()->prepare('SELECT * FROM admins WHERE email=? AND is_active=1 LIMIT 1'); $st->execute([$email]); $a=$st->fetch();
 if(!$a || !password_verify($password,$a['password_hash'])) return false;
 session_regenerate_id(true);
 $_SESSION['admin']=['id'=>(int)$a['id'],'name'=>$a['name'],'email'=>$a['email'],'role'=>$a['role'],'company_id'=>isset($a['company_id'])?(int)$a['company_id']:null];
 db()->prepare('UPDATE admins SET last_login_at=NOW() WHERE id=?')->execute([$a['id']]);
 audit('login','admin',(int)$a['id'],'Administrator logged in');
 return true;
}
