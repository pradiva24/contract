<?php
declare(strict_types=1);

/**
 * Contract PDF and email services.
 * All public entry points must verify the current company context.
 */
function contract_get_for_company(int $id, int $companyId): array {
    $st = db()->prepare('SELECT * FROM contracts WHERE id=? AND company_id=? LIMIT 1');
    $st->execute([$id, $companyId]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('Contract not found.');
    return $row;
}

function contract_snapshot_document(array $contract): array {
    $snapshot = json_decode((string)$contract['template_snapshot_json'], true);
    if (!is_array($snapshot) || !isset($snapshot['document']['pages']) || !is_array($snapshot['document']['pages'])) {
        throw new RuntimeException('Contract template snapshot is invalid.');
    }
    $document = $snapshot['document'];
    $companyName = (string)($snapshot['variables']['company_name'] ?? $contract['company_name']);
    foreach ($document['pages'] as &$page) {
        if (!isset($page['objects']) || !is_array($page['objects'])) continue;
        foreach ($page['objects'] as &$object) {
            if (is_array($object) && ($object['type'] ?? '') === 'text' && isset($object['text'])) {
                $object['text'] = str_replace(['{{company_name}}', '{company_name}'], $companyName, (string)$object['text']);
            }
        }
        unset($object);
    }
    unset($page);
    return $document;
}

function contract_pdf_image_src(string $src): string {
    if (!preg_match('~^data:image/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+/=]+)$~', $src, $m)) return '';
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) > 5 * 1024 * 1024) return '';
    $mime = strtolower($m[1]);
    if ($mime === 'jpg') $mime = 'jpeg';
    if ($mime === 'webp') {
        if (!function_exists('imagecreatefromstring')) return '';
        $image = @imagecreatefromstring($bytes);
        if (!$image) return '';
        ob_start(); imagepng($image); $png = (string)ob_get_clean(); imagedestroy($image);
        return 'data:image/png;base64,' . base64_encode($png);
    }
    return 'data:image/' . $mime . ';base64,' . base64_encode($bytes);
}

function generate_contract_pdf(int $id, int $companyId): string {
    $contract = contract_get_for_company($id, $companyId);
    if (!class_exists(\Dompdf\Dompdf::class)) throw new RuntimeException('Dompdf is not installed. Run composer install.');
    $snapshot = json_decode((string)$contract['template_snapshot_json'], true);
    $document = contract_snapshot_document($contract);
    $orientation = ($snapshot['orientation'] ?? $document['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
    $pageWidth = $orientation === 'landscape' ? '297mm' : '210mm';
    $pageHeight = $orientation === 'landscape' ? '210mm' : '297mm';
    $pagesHtml = '';
    foreach ($document['pages'] as $page) {
        $background = (string)($page['background'] ?? '#ffffff');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $background)) $background = '#ffffff';
        $elements = '';
        $backgroundImage = contract_pdf_image_src((string)($page['backgroundImage'] ?? ''));
        if ($backgroundImage !== '') $elements .= '<img src="'.$backgroundImage.'" style="position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover" alt="">';
        foreach (($page['objects'] ?? []) as $object) {
            if (!is_array($object)) continue;
            $type = (string)($object['type'] ?? '');
            $x = max(-5000, min(10000, (float)($object['x'] ?? 0)));
            $y = max(-5000, min(10000, (float)($object['y'] ?? 0)));
            $w = max(1, min(10000, (float)($object['width'] ?? 100)));
            $h = max(1, min(10000, (float)($object['height'] ?? 40)));
            $style = 'left:'.$x.'px;top:'.$y.'px;width:'.$w.'px;height:'.$h.'px;';
            if ($type === 'text') {
                $text = htmlspecialchars((string)($object['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $fontSize = max(6, min(160, (float)($object['fontSize'] ?? 32)));
                $font = preg_replace('/[^a-zA-Z0-9 ,_-]/', '', (string)($object['fontFamily'] ?? 'Arial')) ?: 'Arial';
                $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($object['fill'] ?? '')) ? $object['fill'] : '#111111';
                $align = in_array(($object['align'] ?? 'left'), ['left','center','right'], true) ? $object['align'] : 'left';
                $style .= 'font-family:'.htmlspecialchars($font, ENT_QUOTES, 'UTF-8').',DejaVu Sans,sans-serif;font-size:'.$fontSize.'px;color:'.$color.';text-align:'.$align.';font-weight:'.(($object['fontStyle'] ?? '') === 'bold' ? '700' : '400').';white-space:pre-wrap;overflow-wrap:break-word;';
                $elements .= '<div class="element text" style="'.$style.'">'.$text.'</div>';
            } elseif ($type === 'rect') {
                $fill = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($object['fill'] ?? '')) ? $object['fill'] : '#286900';
                $radius = max(0, min(100, (float)($object['cornerRadius'] ?? 0)));
                $elements .= '<div class="element" style="'.$style.'background:'.$fill.';border-radius:'.$radius.'px"></div>';
            } elseif ($type === 'table') {
                $rows = is_array($object['rows'] ?? null) ? $object['rows'] : [];
                $tableHtml = '<table style="width:100%;height:100%;border-collapse:collapse;table-layout:fixed;font-size:12px">';
                foreach ($rows as $ri => $row) {
                    if (!is_array($row)) continue;
                    $tableHtml .= '<tr>';
                    foreach ($row as $cell) {
                        $cellBg = $ri === 0 ? '#e9eef7' : '#ffffff';
                        $tableHtml .= '<td style="border:1px solid #667085;padding:5px;background:'.$cellBg.';vertical-align:middle">'.htmlspecialchars((string)$cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td>';
                    }
                    $tableHtml .= '</tr>';
                }
                $tableHtml .= '</table>';
                $elements .= '<div class="element" style="'.$style.'">'.$tableHtml.'</div>';
            } elseif ($type === 'image') {
                $src = contract_pdf_image_src((string)($object['src'] ?? ''));
                if ($src !== '') $elements .= '<img class="element image" src="'.$src.'" style="'.$style.'object-fit:contain" alt="">';
            }
        }
        $pagesHtml .= '<div class="sheet" style="width:'.$pageWidth.';height:'.$pageHeight.';background:'.$background.'">'.$elements.'</div>';
    }
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
      @page{size:A4 '.$orientation.';margin:0}
      html,body{margin:0;padding:0;font-family:DejaVu Sans,sans-serif}
      .sheet{position:relative;overflow:hidden;page-break-after:always}
      .sheet:last-child{page-break-after:auto}
      .element{position:absolute;box-sizing:border-box}
      .text{line-height:1.15}
      .image{display:block}
    </style></head><body>'.$pagesHtml.'</body></html>';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $pdf = new \Dompdf\Dompdf($options);
    $pdf->setPaper('A4', $orientation);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->render();
    $bytes = $pdf->output();
    if (!str_starts_with($bytes, '%PDF-')) throw new RuntimeException('PDF renderer did not return a valid PDF.');
    $dir = BASE_PATH . '/storage/contracts';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Unable to create contract storage directory.');
    $path = $dir . '/contract-' . $id . '.pdf';
    $tmp = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
    if (file_put_contents($tmp, $bytes, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to save contract PDF.');
    }
    $relative = 'contracts/contract-' . $id . '.pdf';
    db()->prepare("UPDATE contracts SET pdf_path=?,status='generated',updated_by=? WHERE id=? AND company_id=?")
        ->execute([$relative, $_SESSION['admin']['id'] ?? null, $id, $companyId]);
    db()->prepare('INSERT INTO contract_history(company_id,contract_id,admin_id,action,description) VALUES(?,?,?,?,?)')
        ->execute([$companyId,$id,$_SESSION['admin']['id'] ?? null,'pdf_generated','Generated contract PDF']);
    audit_company($companyId, 'contract_pdf_generated', 'contract', $id, 'Generated contract PDF');
    return $path;
}

function contract_pdf_path(int $id, int $companyId): string {
    $contract = contract_get_for_company($id, $companyId);
    $relative = (string)($contract['pdf_path'] ?? '');
    if ($relative === '' || !str_starts_with($relative, 'contracts/')) return generate_contract_pdf($id, $companyId);
    $root = realpath(BASE_PATH . '/storage');
    $path = realpath(BASE_PATH . '/storage/' . $relative);
    if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) return generate_contract_pdf($id, $companyId);
    return $path;
}

function send_contract_email(int $id, int $companyId): void {
    $contract = contract_get_for_company($id, $companyId);
    $attachment = contract_pdf_path($id, $companyId);
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) throw new RuntimeException('PHPMailer is not installed. Run composer install.');
    $subject = 'Contract ' . $contract['contract_number'] . ' — ' . $contract['company_name'];
    $body = '<p>Dear '.htmlspecialchars((string)$contract['company_name'], ENT_QUOTES, 'UTF-8').',</p><p>Please find your contract attached as a PDF document.</p><p>Best regards,<br>'.htmlspecialchars(company_setting($companyId,'business_name','Bali Tarantula Adventure'), ENT_QUOTES, 'UTF-8').'</p>';
    $log = db()->prepare("INSERT INTO contract_email_logs(company_id,contract_id,recipient_email,subject,status) VALUES(?,?,?,?, 'queued')");
    $log->execute([$companyId,$id,$contract['recipient_email'],$subject]);
    $logId = (int)db()->lastInsertId();
    try {
        $get = static fn(string $key, string $fallback=''): string => company_setting($companyId,$key,$fallback);
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $get('mail_host',(string)config('MAIL_HOST',config('SMTP_HOST','')));
        $mail->SMTPAuth = true;
        $mail->Username = $get('mail_username',(string)config('MAIL_USERNAME',config('SMTP_USERNAME','')));
        $mail->Password = (string)config('MAIL_PASSWORD',config('SMTP_PASSWORD',''));
        $mail->Port = (int)$get('mail_port',(string)config('MAIL_PORT',config('SMTP_PORT','587')));
        $encryption = strtolower($get('mail_encryption',(string)config('MAIL_ENCRYPTION',config('SMTP_ENCRYPTION','tls'))));
        if ($encryption === 'tls') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        elseif ($encryption === 'ssl') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        elseif ($encryption === 'none') { $mail->SMTPSecure=''; $mail->SMTPAutoTLS=false; }
        $from = $get('mail_from_address',(string)config('MAIL_FROM_ADDRESS',config('SMTP_FROM_EMAIL','')));
        $fromName = $get('mail_from_name',(string)config('MAIL_FROM_NAME',config('SMTP_FROM_NAME','Bali Tarantula Adventure')));
        if ($mail->Host === '' || $mail->Username === '' || $mail->Password === '') throw new RuntimeException('SMTP host, username, and password must be configured in Settings/.env.');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid From email address is required in Settings.');
        $mail->CharSet='UTF-8'; $mail->Timeout=25;
        $mail->setFrom($from,$fromName);
        $mail->addAddress((string)$contract['recipient_email'],(string)$contract['company_name']);
        $mail->Subject=$subject; $mail->isHTML(true); $mail->Body=$body;
        $mail->AltBody='Please find your contract attached as a PDF document.';
        $mail->addAttachment($attachment, $contract['contract_number'].'.pdf', 'base64', 'application/pdf');
        $mail->send();
        db()->prepare("UPDATE contract_email_logs SET status='sent',sent_at=NOW() WHERE id=? AND company_id=?")->execute([$logId,$companyId]);
        db()->prepare('INSERT INTO contract_history(company_id,contract_id,admin_id,action,description) VALUES(?,?,?,?,?)')
            ->execute([$companyId,$id,$_SESSION['admin']['id'] ?? null,'email_sent','Contract email sent to '.$contract['recipient_email']]);
        audit_company($companyId,'contract_email_sent','contract',$id,'Contract email sent to '.$contract['recipient_email']);
    } catch (Throwable $e) {
        db()->prepare("UPDATE contract_email_logs SET status='failed',error_message=? WHERE id=? AND company_id=?")
            ->execute([substr($e->getMessage(),0,60000),$logId,$companyId]);
        db()->prepare('INSERT INTO contract_history(company_id,contract_id,admin_id,action,description) VALUES(?,?,?,?,?)')
            ->execute([$companyId,$id,$_SESSION['admin']['id'] ?? null,'email_failed','Email failed: '.substr($e->getMessage(),0,350)]);
        throw $e;
    }
}
