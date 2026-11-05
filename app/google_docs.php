<?php
declare(strict_types=1);

/** Company-scoped Google Docs OAuth and API helpers. */
function google_docs_redirect_uri(): string {
    $configured = trim((string)config('GOOGLE_REDIRECT_URI', ''));
    return $configured !== '' ? $configured : url('google-docs-callback.php');
}
function google_docs_connected(int $companyId): bool {
    return company_setting($companyId, 'google_docs_refresh_token', '') !== '';
}
function google_docs_authorization_url(int $companyId): string {
    $clientId = trim((string)config('GOOGLE_CLIENT_ID', ''));
    if ($clientId === '') throw new RuntimeException('Set GOOGLE_CLIENT_ID in .env first.');
    $state = bin2hex(random_bytes(24));
    $_SESSION['google_docs_oauth_state'] = $state;
    $_SESSION['google_docs_oauth_company'] = $companyId;
    $params = [
        'client_id'=>$clientId, 'redirect_uri'=>google_docs_redirect_uri(),
        'response_type'=>'code',
        'scope'=>'https://www.googleapis.com/auth/documents https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email',
        'access_type'=>'offline', 'prompt'=>'consent', 'include_granted_scopes'=>'true', 'state'=>$state
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}
function google_docs_http(string $url, string $method='GET', array $headers=[], ?string $body=null): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required.');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>45,CURLOPT_POSTFIELDS=>$body]);
    $response=curl_exec($ch); $error=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if(!is_string($response)) throw new RuntimeException('Google API connection failed: '.$error);
    $data=json_decode($response,true);
    if($status<200||$status>=300){
        $message=is_array($data)?(string)($data['error']['message']??$data['error_description']??$data['error']??'Google API request failed.'):'Google API request failed.';
        throw new RuntimeException('Google API ('.$status.'): '.substr($message,0,500));
    }
    return is_array($data)?$data:['raw'=>$response];
}
function google_docs_exchange_code(string $code): array {
    $id=trim((string)config('GOOGLE_CLIENT_ID','')); $secret=trim((string)config('GOOGLE_CLIENT_SECRET',''));
    if($id===''||$secret==='') throw new RuntimeException('Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env.');
    return google_docs_http('https://oauth2.googleapis.com/token','POST',['Content-Type: application/x-www-form-urlencoded'],http_build_query([
        'code'=>$code,'client_id'=>$id,'client_secret'=>$secret,'redirect_uri'=>google_docs_redirect_uri(),'grant_type'=>'authorization_code'
    ]));
}
function google_docs_access_token(int $companyId): string {
    $encrypted=company_setting($companyId,'google_docs_refresh_token','');
    if($encrypted==='') throw new RuntimeException('Connect a Google account for this business first.');
    $refresh=company_secret_decrypt($encrypted);
    $data=google_docs_http('https://oauth2.googleapis.com/token','POST',['Content-Type: application/x-www-form-urlencoded'],http_build_query([
        'client_id'=>(string)config('GOOGLE_CLIENT_ID',''),'client_secret'=>(string)config('GOOGLE_CLIENT_SECRET',''),
        'refresh_token'=>$refresh,'grant_type'=>'refresh_token'
    ]));
    if(empty($data['access_token'])) throw new RuntimeException('Google did not return an access token.');
    return (string)$data['access_token'];
}
function google_docs_api(int $companyId,string $url,string $method='GET',?array $payload=null): array {
    $headers=['Authorization: Bearer '.google_docs_access_token($companyId),'Accept: application/json']; $body=null;
    if($payload!==null){$headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    return google_docs_http($url,$method,$headers,$body);
}
function google_docs_disconnect(int $companyId): void {
    $encrypted=company_setting($companyId,'google_docs_refresh_token','');
    if($encrypted!==''){try{$token=company_secret_decrypt($encrypted);google_docs_http('https://oauth2.googleapis.com/revoke','POST',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['token'=>$token]));}catch(Throwable $ignored){}}
    db()->prepare('DELETE FROM company_settings WHERE company_id=? AND setting_key IN (?,?)')->execute([$companyId,'google_docs_refresh_token','google_docs_account_email']);
}
