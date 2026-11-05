<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
$token=trim((string)($_GET['token']??''));
$number=trim((string)($_GET['number']??''));

// Public clean URL contains only the random verification token, never the sequential BTA number.
if ($token === '') {
    $routePath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    if (preg_match('~/verify/([a-f0-9]{64})/?$~i', $routePath, $routeMatch)) {
        $token = strtolower($routeMatch[1]);
    }
}

// Search form posts to this page, then redirects to the clean URL.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = trim((string)($_POST['certificate_query'] ?? ''));
    if ($submitted !== '') {
        if (preg_match('/^[a-f0-9]{64}$/i', $submitted)) {
            $submittedToken = strtolower($submitted);
        } else {
            $lookup = db()->prepare('SELECT verification_token FROM certificates WHERE certificate_number = ? LIMIT 1');
            $lookup->execute([$submitted]);
            $submittedToken = (string)($lookup->fetchColumn() ?: '');
        }
        if ($submittedToken !== '') {
            header('Location: ' . url('verify/' . rawurlencode($submittedToken)), true, 303);
            exit;
        }
        header('Location: ' . url('verify.php?not_found=1'), true, 303);
        exit;
    }
}
$c=null;
if($token!==''||$number!==''){
    $sql='SELECT certificate_number,verification_token,recipient_name,activity_name,activity_date,status,issued_at,revoked_at,revoked_reason,pdf_path,participant_photo_path FROM certificates WHERE '.($token!==''?'verification_token=?':'certificate_number=?').' LIMIT 1';
    $st=db()->prepare($sql);
    $st->execute([$token!==''?$token:$number]);
    $c=$st->fetch()?:null;
}
$isValid=$c && $c['status']==='issued';
$hasSearch=$token!==''||$number!==''||isset($_GET['not_found']);
$pdfUrl='';
$photoUrl='';
if($c && $isValid){
    // Storage is outside /public and protected by storage/.htaccess.
    // Serve files through a token-checked endpoint instead of broken /public/storage URLs.
    $assetQuery='token='.rawurlencode((string)$c['verification_token']);
    $pdfUrl=url('certificate-file.php?'.$assetQuery.'&type=pdf');
    if(!empty($c['participant_photo_path'])) $photoUrl=url('certificate-file.php?'.$assetQuery.'&type=photo');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#111318">
<title>Certificate Verification | Bali Tarantula Adventure</title>
<style>
:root{--orange:#ff4b12;--orange2:#ff741d;--ink:#15191f;--muted:#657080;--line:#e2e6eb;--green:#087d36}
*{box-sizing:border-box}body{margin:0;background:#111820;color:var(--ink);font-family:Inter,Arial,Helvetica,sans-serif;min-height:100vh}
.page-bg{position:fixed;inset:0;z-index:-2;background:radial-gradient(ellipse at 18% 8%,rgba(255,89,20,.34),transparent 32%),radial-gradient(ellipse at 90% 70%,rgba(255,89,20,.2),transparent 30%),linear-gradient(135deg,#17232b 0%,#26343b 48%,#10151a 100%)}
.page-bg:before,.page-bg:after{content:"";position:absolute;pointer-events:none}.page-bg:before{inset:0;background:linear-gradient(90deg,rgba(0,0,0,.28),rgba(0,0,0,.12)),repeating-linear-gradient(125deg,transparent 0 90px,rgba(255,255,255,.025) 91px 93px)}.page-bg:after{width:460px;height:460px;right:-180px;bottom:-220px;border:48px solid rgba(255,75,18,.85);transform:rotate(42deg);box-shadow:0 0 0 18px rgba(255,75,18,.12)}
.wrap{width:min(1180px,calc(100% - 36px));margin:0 auto;padding:26px 0 48px}.hero{text-align:center;color:#fff;padding:12px 12px 20px}.brand-mark{display:inline-flex;align-items:center;justify-content:center;padding:10px 22px;border:1px solid rgba(255,255,255,.28);border-radius:12px;background:rgba(10,14,18,.48);font-weight:950;font-style:italic;font-size:clamp(19px,3vw,29px);letter-spacing:-1px;text-transform:uppercase;text-shadow:2px 2px 0 #a52a0b}.brand-mark span{color:#ff6b24;margin-left:5px}.brand-sub{margin:9px 0 14px;font-size:12px;letter-spacing:5px;font-weight:700}.hero h1{margin:0;font-size:clamp(38px,7vw,78px);line-height:.88;font-weight:1000;letter-spacing:-2px;text-transform:uppercase;text-shadow:0 4px 24px #000}.hero h1 span{display:block;color:var(--orange);font-style:italic;transform:skew(-7deg)}.hero p{margin:18px 0 0;color:#edf2f5;font-size:15px;letter-spacing:1.2px}
.search-panel{max-width:930px;margin:0 auto 16px;padding:16px 20px;background:rgba(255,255,255,.97);border:1px solid rgba(255,255,255,.6);border-radius:19px;box-shadow:0 18px 55px rgba(0,0,0,.24);display:flex;align-items:center;gap:18px}.search-icon{width:54px;height:54px;flex:0 0 54px;border-radius:50%;display:grid;place-items:center;background:#eef0f2;color:#18212b}.search-icon svg{width:29px;height:29px}.search-fields{flex:1;min-width:0}.search-fields label{display:block;font-size:14px;font-weight:650;margin:0 0 7px}.search-row{display:flex;gap:12px}.search-row input{height:49px;min-width:0;flex:1;border:1px solid #cbd2dc;border-radius:10px;padding:0 15px;font-size:19px;outline:none}.search-row input:focus{border-color:var(--orange);box-shadow:0 0 0 3px rgba(255,75,18,.12)}.btn{height:49px;border:0;border-radius:10px;padding:0 20px;background:linear-gradient(135deg,var(--orange),#f03d08);color:white;font-size:15px;font-weight:800;cursor:pointer;white-space:nowrap}.btn:hover{filter:brightness(1.06)}
.result{background:rgba(255,255,255,.98);border-radius:22px;padding:20px 26px 16px;box-shadow:0 20px 65px rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.7)}.status{display:flex;align-items:center;gap:20px;padding:15px 22px;border-radius:13px;margin-bottom:16px}.status.valid{background:linear-gradient(100deg,#dcfce7,#e8fff0)}.status.invalid{background:#fff0ed}.status-icon{width:50px;height:50px;flex:0 0 50px;border-radius:15px;background:#0c8a3d;color:white;display:grid;place-items:center}.invalid .status-icon{background:#d93420}.status-icon svg{width:30px;height:30px}.status strong{display:block;font-size:23px;line-height:1.15;font-weight:950}.status small{display:block;margin-top:4px;font-size:14px;color:#34434a}.result-grid{display:grid;grid-template-columns:minmax(130px,190px) minmax(230px,1fr) minmax(300px,410px);gap:24px;align-items:center;padding:0 0 18px}.photo-wrap{border:1px solid #d9dee5;border-radius:12px;padding:6px;background:#fff;box-shadow:0 5px 15px rgba(16,24,40,.06)}.photo{display:block;width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:7px;background:linear-gradient(135deg,#e8ebef,#f8fafc)}.photo-placeholder{aspect-ratio:3/4;display:grid;place-items:center;color:#a2aab5;background:#f0f2f5;border-radius:7px}.photo-placeholder svg{width:54px;height:54px}.details{display:grid;gap:17px}.detail{display:grid;grid-template-columns:48px 1fr;gap:13px;align-items:center}.detail-icon{height:46px;width:46px;border-radius:11px;background:#fff0e9;color:#f45116;display:grid;place-items:center}.detail-icon svg{width:24px;height:24px}.detail-label{font-size:13px;color:#667080;margin-bottom:3px}.detail-value{font-size:18px;font-weight:800;overflow-wrap:anywhere}.preview{border-left:1px solid var(--line);padding-left:24px;min-width:0}.pdf-frame{width:100%;height:245px;border:1px solid #e1e5ea;border-radius:12px;background:#f5f6f7;box-shadow:0 5px 18px rgba(16,24,40,.09)}.pdf-empty{height:245px;border:1px dashed #cbd2dc;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:#758092;background:#f8fafb;text-align:center;padding:18px}.pdf-empty svg{width:34px;height:34px;color:#f45116}.pdf-actions{display:flex;gap:10px;margin-top:10px}.pdf-link{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 13px;background:#fff0e9;color:#d83d0c;text-decoration:none;border-radius:9px;font-size:13px;font-weight:800}.revocation{padding:12px 14px;background:#fff5f2;border-radius:10px;color:#9b2c1c;font-size:14px}.footer{border-top:1px solid #e1e5ea;padding:14px 0 0;margin-top:2px;text-align:center;color:#687386;font-size:13px}.footer strong{color:#202833}.footer-note{margin-top:6px;font-size:12px}.empty{background:#fff;border-radius:18px;padding:22px;text-align:center;color:#566171;box-shadow:0 14px 45px rgba(0,0,0,.2)}
@media(max-width:850px){.result-grid{grid-template-columns:150px 1fr}.preview{grid-column:1/-1;border-left:0;border-top:1px solid var(--line);padding:18px 0 0}.pdf-frame,.pdf-empty{height:300px}}
@media(max-width:560px){.wrap{width:min(100% - 22px,1180px);padding-top:14px}.hero{padding:8px 6px 17px}.brand-sub{letter-spacing:3px;font-size:10px}.hero h1{font-size:43px}.hero p{font-size:12px;line-height:1.5}.search-panel{padding:13px;border-radius:15px;gap:10px}.search-icon{display:none}.search-row{flex-direction:column}.search-row input,.btn{width:100%;height:47px}.result{padding:13px;border-radius:16px}.status{padding:12px;gap:12px}.status-icon{width:42px;height:42px;flex-basis:42px}.status strong{font-size:18px}.status small{font-size:12px}.result-grid{grid-template-columns:105px 1fr;gap:13px}.detail{grid-template-columns:36px 1fr;gap:9px}.detail-icon{width:36px;height:36px}.detail-icon svg{width:20px;height:20px}.details{gap:13px}.detail-label{font-size:11px}.detail-value{font-size:14px}.preview{padding-top:14px}.pdf-frame,.pdf-empty{height:230px}.pdf-actions{flex-wrap:wrap}.footer{font-size:11px}}
</style>
</head>
<body><div class="page-bg"></div><main class="wrap">
<header class="hero"><div class="brand-mark">TARANTULA <span>ADVENTURE</span></div><div class="brand-sub">BALI TARANTULA ADVENTURE</div><h1>Certificate <span>Verification</span></h1><p>Check the authenticity of your Bali Tarantula Adventure certificate</p></header>
<section class="search-panel"><div class="search-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></svg></div><form class="search-fields" method="post" action="<?=e(url('verify.php'))?>"><label for="certificate-query">Certificate number or QR token</label><div class="search-row"><input id="certificate-query" name="certificate_query" value="<?=e($token?:$number)?>" placeholder="BTA-0000012" autocomplete="off" required><button class="btn" type="submit"><span aria-hidden="true">⌕</span> Verify Certificate</button></div></form></section>
<?php if($c): ?>
<section class="result">
<div class="status <?= $isValid?'valid':'invalid' ?>"><div class="status-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><path d="m5 12 4.5 4.5L19 7"/></svg></div><div><strong><?= $isValid?'VALID CERTIFICATE':strtoupper(e($c['status'])).' CERTIFICATE' ?></strong><small><?= $isValid?'This certificate is authentic and issued by Bali Tarantula Adventure.':'This certificate is not currently valid. Please contact Bali Tarantula Adventure for assistance.' ?></small></div></div>
<div class="result-grid">
<div class="photo-wrap"><?php if($photoUrl): ?><img class="photo" src="<?=e($photoUrl)?>" alt="Participant photo" loading="lazy"><?php else: ?><div class="photo-placeholder" aria-label="No participant photo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 21c.5-4.2 3.1-6.5 7.5-6.5s7 2.3 7.5 6.5"/></svg></div><?php endif ?></div>
<div class="details">
<div class="detail"><div class="detail-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h8l4 4v14H7z"/><path d="M15 3v5h5M10 13h6M10 17h6"/></svg></div><div><div class="detail-label">Certificate number</div><div class="detail-value"><?=e($c['certificate_number'])?></div></div></div>
<div class="detail"><div class="detail-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 21c.5-4.2 3.1-6.5 7.5-6.5s7 2.3 7.5 6.5"/></svg></div><div><div class="detail-label">Recipient</div><div class="detail-value"><?=e($c['recipient_name'])?></div></div></div>
<div class="detail"><div class="detail-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m3 19 6-9 4 5 3-4 5 8H3Z"/><circle cx="17" cy="6" r="2"/></svg></div><div><div class="detail-label">Activity</div><div class="detail-value"><?=e($c['activity_name'])?></div></div></div>
<div class="detail"><div class="detail-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></div><div><div class="detail-label">Date</div><div class="detail-value"><?=e($c['activity_date'])?></div></div></div>
</div>
<div class="preview"><?php if($pdfUrl && $isValid): ?><iframe class="pdf-frame" src="<?=e($pdfUrl)?>#toolbar=0&navpanes=0&view=FitH" title="Certificate PDF preview" loading="lazy"></iframe><div class="pdf-actions"><a class="pdf-link" href="<?=e($pdfUrl)?>" target="_blank" rel="noopener">↗ View certificate PDF</a><a class="pdf-link" href="<?=e($pdfUrl)?>&amp;download=1">↓ Download PDF</a></div><?php else: ?><div class="pdf-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 2h8l5 5v15H6zM14 2v6h5M9 13h7M9 17h7"/></svg><strong>Certificate preview unavailable</strong><span>No issued PDF is available for this record.</span></div><?php endif ?></div>
</div>
<?php if($c['status']==='revoked' && !empty($c['revoked_reason'])): ?><div class="revocation"><strong>Revocation reason:</strong> <?=e($c['revoked_reason'])?></div><?php endif ?>
<footer class="footer"><strong>♢ &nbsp;Bali Tarantula Adventure</strong> &nbsp;·&nbsp; Official certificate verification<div class="footer-note">This page is provided to verify the authenticity of certificates issued by Bali Tarantula Adventure.</div></footer>
</section>
<?php elseif($hasSearch): ?><div class="empty">Certificate not found. Please check the certificate number or QR token and try again.</div><?php endif ?>
</main></body></html>
