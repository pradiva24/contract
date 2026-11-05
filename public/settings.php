<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
admin_required();
verify_csrf();

$generalKeys=['business_name','website_url','certificate_prefix','default_activity_name'];
$mailKeys=['mail_host','mail_port','mail_username','mail_encryption','mail_from_address','mail_from_name','email_subject','email_body','email_signature'];
$errors=[];

function save_setting_value(string $key, string $value): void {
    $st=db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $st->execute([$key,$value]);
}
function mail_config_value(string $key, string $default=''): string {
    $value=setting($key,'');
    return $value!=='' ? $value : $default;
}

if($_SERVER['REQUEST_METHOD']==='POST') {
    $mode=$_POST['mode']??'save';
    if($mode==='save') {
        foreach($generalKeys as $k) {
            $v=trim((string)($_POST[$k]??''));
            if($k==='certificate_prefix') {
                $v=strtoupper($v);
                if(!preg_match('/^[A-Z0-9]{2,8}$/',$v)) $v='BTA';
            }
            save_setting_value($k,$v);
        }
        foreach($mailKeys as $k) {
            $v=trim((string)($_POST[$k]??''));
            if($k==='mail_port' && ($v==='' || !ctype_digit($v) || (int)$v<1 || (int)$v>65535)) $v='587';
            if($k==='mail_encryption' && !in_array($v,['tls','ssl','none'],true)) $v='tls';
            if($k==='mail_from_address' && $v!=='' && !filter_var($v,FILTER_VALIDATE_EMAIL)) $errors[]='From email address is invalid.';
            save_setting_value($k,$v);
        }
        if(!$errors) {
            audit('settings_updated','settings',null,'General and email settings updated.');
            flash('success','Settings saved successfully. SMTP password remains protected in the project .env file.');
            redirect('settings.php');
        }
    } elseif($mode==='test_smtp') {
        $testEmail=trim((string)($_POST['test_email']??''));
        if(!filter_var($testEmail,FILTER_VALIDATE_EMAIL)) {
            $errors[]='Enter a valid test recipient email address.';
        } else {
            try {
                if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) throw new RuntimeException('PHPMailer is not loaded. Run composer install.');
                $host=mail_config_value('mail_host',(string)config('MAIL_HOST',config('SMTP_HOST','')));
                $username=mail_config_value('mail_username',(string)config('MAIL_USERNAME',config('SMTP_USERNAME','')));
                $password=(string)config('MAIL_PASSWORD',config('SMTP_PASSWORD',''));
                $port=(int)mail_config_value('mail_port',(string)config('MAIL_PORT',config('SMTP_PORT','587')));
                $encryption=mail_config_value('mail_encryption',(string)config('MAIL_ENCRYPTION',config('SMTP_ENCRYPTION','tls')));
                if($host==='' || $username==='' || $password==='') throw new RuntimeException('SMTP host, username, and password must be configured. Set MAIL_PASSWORD in .env.');
                $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP(); $mail->Host=$host; $mail->SMTPAuth=true; $mail->Username=$username; $mail->Password=$password; $mail->Port=$port;
                if($encryption==='tls') $mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                elseif($encryption==='ssl') $mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                elseif($encryption==='none') { $mail->SMTPSecure=''; $mail->SMTPAutoTLS=false; }
                $mail->Timeout=15;
                if(!$mail->smtpConnect()) throw new RuntimeException('Could not establish SMTP connection.');
                $mail->smtpClose();
                $from=mail_config_value('mail_from_address',(string)config('MAIL_FROM_ADDRESS',config('SMTP_FROM_EMAIL','')));
                $fromName=mail_config_value('mail_from_name',(string)config('MAIL_FROM_NAME',config('SMTP_FROM_NAME','Bali Tarantula Adventure')));
                if($from==='' || !filter_var($from,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configure a valid From email address before testing.');
                $mail->setFrom($from,$fromName);
                $mail->addAddress($testEmail);
                $mail->Subject='SMTP Test - Bali Tarantula Certificate';
                $mail->isHTML(true);
                $mail->Body='<p>SMTP connection test successful.</p><p>This is a test message from Bali Tarantula Certificate System.</p>';
                $mail->AltBody='SMTP connection test successful. This is a test message from Bali Tarantula Certificate System.';
                $mail->send();
                flash('success','SMTP test email sent to '.$testEmail.'. Please check the inbox and spam folder.');
                redirect('settings.php');
            } catch(Throwable $e) { $errors[]='SMTP test failed: '.$e->getMessage(); }
        }
    }
}

page_header('Settings');
?>
<?php foreach($errors as $error): ?><div class="alert error"><?=e($error)?></div><?php endforeach; ?>
<form method="post">
<div class="card">
<h2>General settings</h2>
<?=csrf_field()?>
<input type="hidden" name="mode" value="save">
<div class="formgrid">
<?php foreach($generalKeys as $k): ?>
<div><label for="<?=$k?>"><?=e(ucwords(str_replace('_',' ',$k)))?></label><input id="<?=$k?>" name="<?=$k?>" value="<?=e(setting($k))?>"><?php if($k==='certificate_prefix'): ?><small class="muted">Format: PREFIX-0000001, for example BTA-0000001.</small><?php endif; ?></div>
<?php endforeach; ?>
</div>
</div>
<div class="card">
<h2>Email / SMTP settings</h2>
<p class="muted">Configure SMTP server and sender identity here. For security, the SMTP password is kept in the private project <code>.env</code> file and is never displayed in this form.</p>
<div class="formgrid">
<div><label for="mail_host">SMTP Host</label><input id="mail_host" name="mail_host" value="<?=e(mail_config_value('mail_host',(string)config('MAIL_HOST',config('SMTP_HOST',''))))?>" placeholder="mail.yourdomain.com" required></div>
<div><label for="mail_port">SMTP Port</label><input id="mail_port" name="mail_port" type="number" min="1" max="65535" value="<?=e(mail_config_value('mail_port',(string)config('MAIL_PORT',config('SMTP_PORT','587'))))?>" required></div>
<div><label for="mail_username">SMTP Username</label><input id="mail_username" name="mail_username" value="<?=e(mail_config_value('mail_username',(string)config('MAIL_USERNAME',config('SMTP_USERNAME',''))))?>" autocomplete="username" required></div>
<div><label for="mail_encryption">Encryption</label><select id="mail_encryption" name="mail_encryption"><?php $enc=mail_config_value('mail_encryption',(string)config('MAIL_ENCRYPTION',config('SMTP_ENCRYPTION','tls'))); foreach(['tls'=>'TLS / STARTTLS','ssl'=>'SSL','none'=>'None'] as $v=>$label): ?><option value="<?=$v?>" <?=$enc===$v?'selected':''?>><?=$label?></option><?php endforeach; ?></select></div>
<div><label for="mail_from_address">From Email Address</label><input id="mail_from_address" type="email" name="mail_from_address" value="<?=e(mail_config_value('mail_from_address',(string)config('MAIL_FROM_ADDRESS',config('SMTP_FROM_EMAIL',''))))?>" placeholder="certificate@yourdomain.com" required></div>
<div><label for="mail_from_name">From Name</label><input id="mail_from_name" name="mail_from_name" value="<?=e(mail_config_value('mail_from_name',(string)config('MAIL_FROM_NAME',config('SMTP_FROM_NAME','Bali Tarantula Adventure'))))?>" required></div>
</div>
<div class="alert" style="margin-top:15px">SMTP password: set <code>MAIL_PASSWORD</code> in the root <code>.env</code> file. Keep that file private and do not upload it to a public repository.</div>
</div>
<div class="card">
<h2>Certificate email content</h2>
<p class="muted">You can use HTML in the body and signature. Available placeholders: <code>{name}</code>, <code>{certificate_number}</code>, <code>{activity_name}</code>, <code>{activity_date}</code>, <code>{verify_url}</code>.</p>
<label for="email_subject">Email Subject</label><input id="email_subject" name="email_subject" value="<?=e(setting('email_subject','Your ATV Adventure Certificate'))?>">
<label for="email_body">Email Body (HTML supported)</label><textarea id="email_body" name="email_body" rows="7"><?=e(setting('email_body','Thank you for joining Bali Tarantula Adventure. Your certificate is attached.'))?></textarea>
<label for="email_signature">Email Signature (HTML supported)</label><textarea id="email_signature" name="email_signature" rows="5" placeholder="Best regards,<br><strong>Bali Tarantula Adventure Team</strong>"><?=e(setting('email_signature','Best regards,<br><strong>Bali Tarantula Adventure Team</strong>'))?></textarea>
<p><button class="btn" type="submit">Save email settings</button></p>
</div>
</form>
<div class="card">
<h2>Test SMTP delivery</h2>
<p class="muted">Save the settings first, then send a test message to verify SMTP authentication and email delivery.</p>
<form method="post">
<?=csrf_field()?>
<input type="hidden" name="mode" value="test_smtp">
<label for="test_email">Send test email to</label>
<div class="formgrid"><input id="test_email" type="email" name="test_email" placeholder="you@example.com" required><button class="btn dark" type="submit">Test SMTP & Send Email</button></div>
</form>
</div>
<?php page_footer(); ?>
