<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/google_docs.php';
admin_required();
$companyId=(int)($_SESSION['google_docs_oauth_company']??0);
$expected=(string)($_SESSION['google_docs_oauth_state']??'');
$received=(string)($_GET['state']??'');
unset($_SESSION['google_docs_oauth_state'],$_SESSION['google_docs_oauth_company']);
if($companyId<1||$expected===''||!hash_equals($expected,$received)){http_response_code(400);exit('Invalid OAuth state. Start again.');}
if(!is_global_super_admin()&&$companyId!==require_company_id()){http_response_code(403);exit('Business access denied.');}
if(isset($_GET['error'])){flash('error','Google connection cancelled or denied.');redirect('google-docs-connect.php');}
try{
 $code=trim((string)($_GET['code']??''));
 if($code==='')throw new RuntimeException('Google did not return an authorization code.');
 $token=google_docs_exchange_code($code);
 if(empty($token['refresh_token']))throw new RuntimeException('No refresh token returned. Revoke this app in Google Account permissions, then connect again and approve access.');
 save_company_setting($companyId,'google_docs_refresh_token',company_secret_encrypt((string)$token['refresh_token']));
 try{
  $profile=google_docs_http('https://www.googleapis.com/oauth2/v2/userinfo','GET',['Authorization: Bearer '.(string)($token['access_token']??'')]);
  if(!empty($profile['email']))save_company_setting($companyId,'google_docs_account_email',(string)$profile['email']);
 }catch(Throwable $ignored){}
 audit_company($companyId,'google_docs_connected','company',$companyId,'Connected Google Docs account.');
 flash('success','Google account connected successfully.');
}catch(Throwable $e){
 error_log('Google Docs OAuth callback failed: '.$e->getMessage());
 flash('error','Google connection failed: '.$e->getMessage());
}
redirect('google-docs-connect.php');
