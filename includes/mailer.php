<?php
/**
 * Minimal SMTP mailer (no external dependency). Uses SMTP with authentication
 * so the sending mailbox's SPF/DKIM signatures apply — keeps OTP emails out of
 * the spam folder. Configure SMTP_* constants in config.php.
 *
 * If SMTP_PASS is still the placeholder, falls back to PHP mail() (local test only).
 */

require_once __DIR__ . '/config.php';

function send_mail(string $to, string $subject, string $html_body, string $text_body = ''): bool {
    if (empty($text_body)) {
        $text_body = trim(preg_replace('/\s+/', ' ', strip_tags($html_body)));
    }
    if (SMTP_PASS === 'CHANGE_ME_IN_HOSTINGER') {
        return _send_mail_via_phpmail($to, $subject, $html_body, $text_body);
    }
    return _send_mail_via_smtp($to, $subject, $html_body, $text_body);
}

function _send_mail_via_phpmail(string $to, string $subject, string $html, string $text): bool {
    $boundary = '=_' . bin2hex(random_bytes(12));
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $text . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $html . "\r\n";
    $body .= "--$boundary--";

    return @mail($to, $subject, $body, $headers, '-f' . SMTP_FROM_EMAIL);
}

function _send_mail_via_smtp(string $to, string $subject, string $html, string $text): bool {
    $host = SMTP_HOST; $port = (int) SMTP_PORT;
    $user = SMTP_USER; $pass = SMTP_PASS;
    $from_email = SMTP_FROM_EMAIL; $from_name = SMTP_FROM_NAME;

    $transport = (SMTP_SECURE === 'ssl') ? "ssl://$host" : $host;
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("$transport:$port", $errno, $errstr, 20);
    if (!$fp) { error_log("SMTP connect failed: $errstr ($errno)"); return false; }
    stream_set_timeout($fp, 20);

    $expect = function($code) use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return strpos($data, (string)$code) === 0;
    };
    $send = function($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };

    if (!$expect(220)) { fclose($fp); return false; }
    $send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (!$expect(250)) { fclose($fp); return false; }

    if (SMTP_SECURE === 'tls') {
        $send('STARTTLS');
        if (!$expect(220)) { fclose($fp); return false; }
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        if (!$expect(250)) { fclose($fp); return false; }
    }

    $send('AUTH LOGIN');           if (!$expect(334)) { fclose($fp); return false; }
    $send(base64_encode($user));    if (!$expect(334)) { fclose($fp); return false; }
    $send(base64_encode($pass));    if (!$expect(235)) { fclose($fp); return false; }
    $send('MAIL FROM:<' . $from_email . '>'); if (!$expect(250)) { fclose($fp); return false; }
    $send('RCPT TO:<' . $to . '>');           if (!$expect(250)) { fclose($fp); return false; }
    $send('DATA');                            if (!$expect(354)) { fclose($fp); return false; }

    $boundary = '=_' . bin2hex(random_bytes(12));
    $headers  = 'From: ' . "\"$from_name\" <$from_email>" . "\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= 'Subject: ' . $subject . "\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . parse_url(APP_URL, PHP_URL_HOST) . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $text . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $html . "\r\n";
    $body .= "--$boundary--\r\n";

    $send($headers . "\r\n" . $body . "\r\n.");
    if (!$expect(250)) { fclose($fp); return false; }
    $send('QUIT');
    fclose($fp);
    return true;
}

function otp_email_html(string $name, string $code, string $purpose = 'verification'): string {
    $pretty = ucfirst($purpose);
    return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto;padding:24px;background:#ffffff;color:#222">
  <h2 style="color:#4f46e5;margin:0 0 12px">AI Platform — Email {$pretty}</h2>
  <p>Hello {$name},</p>
  <p>Your one-time code is:</p>
  <div style="font-size:28px;font-weight:700;letter-spacing:6px;padding:12px 16px;background:#f4f4ff;border-radius:8px;display:inline-block">{$code}</div>
  <p style="margin-top:16px">This code expires in 10 minutes. If you did not request it, ignore this email.</p>
  <p style="color:#888;font-size:12px;margin-top:24px">— AI Platform</p>
</div>
HTML;
}
