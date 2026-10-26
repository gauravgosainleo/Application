<?php
/*
 * Minimal dependency-free mailer.
 *  - 'smtp' : authenticated SMTP over SSL (465) or STARTTLS (587) - Hostinger: smtp.hostinger.com
 *  - 'mail' : PHP mail()
 *  - 'log'  : development, appends the message to app/storage/mail.log
 */

class Mailer
{
    public static $lastError = '';

    public static function send($to, $subject, $html, $text = null)
    {
        self::$lastError = '';
        $text = $text !== null ? $text : trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>|</h\d>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $transport = cfg('mail.transport', 'mail');
        try {
            if ($transport === 'log') {
                return self::sendLog($to, $subject, $text);
            }
            if ($transport === 'smtp') {
                return self::sendSmtp($to, $subject, $html, $text);
            }
            return self::sendMail($to, $subject, $html, $text);
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            log_error('Mail to ' . $to . ' failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function fromEmail()
    {
        $from = cfg('mail.from_email') ?: cfg('mail.username');
        return $from ?: 'no-reply@' . (isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : 'localhost');
    }

    private static function encodeHeader($value)
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private static function buildMessage($to, $subject, $html, $text, &$headersOut = null)
    {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $fromName = self::encodeHeader(cfg('mail.from_name', 'G VI Fan Hub'));
        $from = self::fromEmail();
        $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromName . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: GVIFanHub/' . APP_VERSION,
        ];
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . '--' . $boundary . "--\r\n";
        $headersOut = $headers;
        return $body;
    }

    private static function sendLog($to, $subject, $text)
    {
        $entry = "==== " . gmdate('Y-m-d H:i:s') . " UTC ====\nTo: {$to}\nSubject: {$subject}\n\n{$text}\n\n";
        file_put_contents(STORAGE_DIR . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
        return true;
    }

    private static function sendMail($to, $subject, $html, $text)
    {
        $headers = [];
        $body = self::buildMessage($to, $subject, $html, $text, $headers);
        $headers = array_filter($headers, function ($h) {
            return stripos($h, 'Date:') !== 0;
        });
        $ok = mail($to, self::encodeHeader($subject), $body, implode("\r\n", $headers), '-f' . self::fromEmail());
        if (!$ok) {
            throw new Exception('PHP mail() returned false');
        }
        return true;
    }

    private static function sendSmtp($to, $subject, $html, $text)
    {
        $host = cfg('mail.host');
        $port = (int)cfg('mail.port', 465);
        $enc = strtolower((string)cfg('mail.encryption', 'ssl'));
        $user = cfg('mail.username');
        $pass = cfg('mail.password');
        if (!$host) {
            throw new Exception('SMTP host is not configured');
        }

        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new Exception("Cannot connect to {$remote}: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, 25);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function ($command, $expect) use ($fp, $read) {
            if ($command !== null) {
                fwrite($fp, $command . "\r\n");
            }
            $resp = $read();
            $code = (int)substr($resp, 0, 3);
            if (!in_array($code, (array)$expect, true)) {
                $shown = $command !== null && stripos($command, 'AUTH') === false && strlen($command) < 80 ? $command : '(command)';
                throw new Exception("SMTP error after {$shown}: " . trim($resp));
            }
            return $resp;
        };

        $hostname = isset($_SERVER['SERVER_NAME']) ? preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['SERVER_NAME']) : 'localhost';
        $hostname = $hostname ?: 'localhost';
        $cmd(null, [220]);
        $cmd('EHLO ' . $hostname, [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!stream_socket_enable_crypto($fp, true, $method)) {
                throw new Exception('STARTTLS negotiation failed');
            }
            $cmd('EHLO ' . $hostname, [250]);
        }
        if ($user) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode($pass), [235]);
        }
        $from = self::fromEmail();
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        $headers = [];
        $body = self::buildMessage($to, $subject, $html, $text, $headers);
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $message = preg_replace('/^\./m', '..', $message);
        fwrite($fp, $message . "\r\n.\r\n");
        $cmd(null, [250]);
        try {
            $cmd('QUIT', [221]);
        } catch (Exception $e) {
            // ignore errors on QUIT
        }
        fclose($fp);
        return true;
    }
}

/** Branded HTML email layout. $code (optional) is shown as a big OTP box. */
function mail_layout($heading, $intro, $code = null, $outro = '')
{
    $app = htmlspecialchars(cfg('app.name', 'G VI Fan Hub'), ENT_QUOTES, 'UTF-8');
    $heading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $intro = nl2br(htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'));
    $outro = nl2br(htmlspecialchars($outro, ENT_QUOTES, 'UTF-8'));
    $codeHtml = '';
    if ($code !== null) {
        $spaced = htmlspecialchars(implode(' ', str_split($code)), ENT_QUOTES, 'UTF-8');
        $codeHtml = '<div style="margin:28px 0;text-align:center"><div style="display:inline-block;padding:16px 28px;border-radius:14px;'
            . 'background:#1d1233;border:1px solid #3b2763;font:700 34px/1 Consolas,Menlo,monospace;letter-spacing:6px;color:#ffffff">'
            . $spaced . '</div></div>';
    }
    return '<!doctype html><html><body style="margin:0;background:#0b0714;padding:24px 12px;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" style="max-width:520px;background:#140c25;border-radius:18px;overflow:hidden;border:1px solid #2a1b47">'
        . '<tr><td style="padding:22px 28px;background:linear-gradient(135deg,#ff3d8b,#ff8a3d);background-color:#ff3d8b">'
        . '<div style="font:800 22px/1.1 Segoe UI,Arial,sans-serif;color:#fff;letter-spacing:1px">' . $app . '</div>'
        . '<div style="font:500 12px/1.4 Segoe UI,Arial,sans-serif;color:#ffe9f2;letter-spacing:2px;text-transform:uppercase">Your GTA VI companion</div>'
        . '</td></tr><tr><td style="padding:28px;color:#e9e4f5;font-size:15px;line-height:1.6">'
        . '<h1 style="margin:0 0 12px;font-size:20px;color:#ffffff">' . $heading . '</h1>'
        . '<p style="margin:0">' . $intro . '</p>' . $codeHtml
        . ($outro ? '<p style="margin:0;color:#a59cbf;font-size:13px">' . $outro . '</p>' : '')
        . '</td></tr><tr><td style="padding:16px 28px;border-top:1px solid #2a1b47;color:#7d7396;font-size:11px;line-height:1.5">'
        . 'Unofficial fan app. Not affiliated with Rockstar Games or Take-Two Interactive.'
        . '</td></tr></table></td></tr></table></body></html>';
}

/** Sends an OTP email. Fails the request with a friendly error when the mail cannot be sent. */
function send_otp_email($email, $name, $code, $purpose)
{
    $app = cfg('app.name', 'G VI Fan Hub');
    $minutes = (int)(OTP_TTL / 60);
    $greeting = 'Hi ' . ($name ?: 'there') . ',';
    if ($purpose === 'register') {
        $subject = $code . ' is your ' . $app . ' verification code';
        $html = mail_layout('Verify your email', $greeting . "\nUse this one-time code to finish creating your account:", $code,
            "The code expires in {$minutes} minutes. If you didn't try to sign up, you can ignore this email.");
    } elseif ($purpose === 'reset') {
        $subject = $code . ' is your ' . $app . ' password reset code';
        $html = mail_layout('Reset your password', $greeting . "\nUse this one-time code to set a new password:", $code,
            "The code expires in {$minutes} minutes. If you didn't request a reset, your password is still safe and you can ignore this email.");
    } elseif ($purpose === 'delete') {
        $subject = $code . ' is your ' . $app . ' account deletion code';
        $html = mail_layout('Delete your account', $greeting . "\nSomeone (hopefully you) asked to permanently delete your account and its data. Enter this code to confirm:", $code,
            "The code expires in {$minutes} minutes. If you didn't ask for this, ignore this email and your account stays as it is.");
    } else {
        $subject = $code . ' is your ' . $app . ' login code';
        $html = mail_layout('Your login code', $greeting . "\nSomeone (hopefully you) is signing in to your account. Enter this code to continue:", $code,
            "The code expires in {$minutes} minutes. If this wasn't you, change your password right away.");
    }
    if (!Mailer::send($email, $subject, $html)) {
        fail('We could not send the email right now. Please try again in a moment (or contact the site admin).', 502, 'mail_failed');
    }
}
