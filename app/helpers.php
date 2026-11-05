<?php
declare(strict_types=1);

function config(string $key, mixed $default=null): mixed { return $_ENV[$key] ?? $default; }
function db(): PDO {
 static $pdo=null;
 if ($pdo instanceof PDO) return $pdo;
 $dsn='mysql:host='.config('DB_HOST','127.0.0.1').';port='.config('DB_PORT','8889').';dbname='.config('DB_NAME','tarantula_certificate').';charset='.config('DB_CHARSET','utf8mb4');
 $pdo=new PDO($dsn,(string)config('DB_USER','root'),(string)config('DB_PASS',''),[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES=>false
 ]);
 return $pdo;
}
function e(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path=''): string { return rtrim((string)config('APP_URL',''),'/').'/'.ltrim($path,'/'); }
function redirect(string $path): never { header('Location: '.url($path)); exit; }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void {
 if ($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
  http_response_code(419); exit('Invalid security token. Refresh and try again.');
 }
}
function flash(string $type, string $message): void { $_SESSION['flash']=['type'=>$type,'message'=>$message]; }
function get_flash(): ?array { $f=$_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function setting(string $key, string $default=''): string {
 $st=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?'); $st->execute([$key]);
 return (string)($st->fetchColumn() ?: $default);
}
function audit(string $action, ?string $type=null, ?int $id=null, string $description=''): void {
 $st=db()->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id,description,ip_address) VALUES(?,?,?,?,?,?)');
 $st->execute([$_SESSION['admin']['id']??null,$action,$type,$id,$description,$_SERVER['REMOTE_ADDR']??null]);
}
function admin_required(): void { if (empty($_SESSION['admin'])) redirect('login.php'); }
function is_admin(): bool { return !empty($_SESSION['admin']); }
function page_header(string $title): void {
 $flash=get_flash();
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' · BTA Certificates</title><link rel="stylesheet" href="'.e(url('assets/app.css')).'"></head><body><header class="top"><a href="'.e(url('dashboard.php')).'" class="brand">BTA <span>CERTIFICATES</span></a><nav>'.(is_admin()?'<a href="'.e(url('dashboard.php')).'">Dashboard</a><a href="'.e(url('certificates.php')).'">Certificates</a><a href="'.e(url('templates.php')).'">Templates</a><a href="'.e(url('settings.php')).'">Settings</a><a href="'.e(url('logout.php')).'">Logout</a>':'<a href="'.e(url('login.php')).'">Admin login</a>').'</nav></header><main class="wrap">';
 if($flash) echo '<div class="alert '.e($flash['type']).'">'.e($flash['message']).'</div>';
 echo '<h1>'.e($title).'</h1>';
}
function page_footer(): void { echo '</main><footer>© '.date('Y').' '.e(setting('business_name','Bali Tarantula Adventure')).'</footer></body></html>'; }
