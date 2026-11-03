<?php
// includes/mailer.php - Robust Mailer & Minimal SMTP client (STARTTLS / implicit TLS, AUTH LOGIN/PLAIN) with mail() fallback.
// Configure in config/local.php:
//   define('SMTP_HOST', 'mail.stbenedictsacademy.com.ng'); define('SMTP_PORT', 465); define('SMTP_SECURE', 'ssl');
//   define('SMTP_USER', 'mail@stbenedictsacademy.com.ng'); define('SMTP_PASS', '...'); define('MAIL_FROM', 'mail@stbenedictsacademy.com.ng');

class Mailer {
    private $sock;
    public $lastError = '';

    /**
     * Resolves the primary official school sender email address.
     */
    public static function getSchoolEmail(): string {
        if (defined('MAIL_FROM') && MAIL_FROM !== '') {
            return MAIL_FROM;
        }
        if (function_exists('school_email') && ($se = school_email()) !== '') {
            return $se;
        }
        if (defined('SCHOOL_EMAIL') && SCHOOL_EMAIL !== '') {
            return SCHOOL_EMAIL;
        }
        return 'mail@stbenedictsacademy.com.ng';
    }

    /**
     * Sends an email via configured SMTP with fallback to local mail().
     */
    public static function send($to, $subject, $html) {
        $to = trim((string)$to);
        if (preg_match('/[\r\n]/', $to . $subject) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = self::getSchoolEmail();
        $m = new self();
        if (defined('SMTP_HOST') && SMTP_HOST !== '') {
            $ok = $m->viaSmtp($to, $subject, $html, $from);
            if (!$ok) {
                error_log('Mailer SMTP failure: ' . $m->lastError . ' - falling back to local sendmail/exim');
                return $m->viaMail($to, $subject, $html, $from);
            }
            return $ok;
        }
        return $m->viaMail($to, $subject, $html, $from);
    }

    /**
     * Builds a full MIME multipart/alternative message string.
     */
    public function buildMessage($to, $subject, $html, $from) {
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $b = 'b' . bin2hex(random_bytes(8));
        $domain = substr(strrchr($from, '@') ?: '@stbenedictsacademy.com.ng', 1);
        $schoolName = defined('SCHOOL_NAME') ? SCHOOL_NAME : "St. Benedict's Early Years British Academy";
        $h = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeHeader($schoolName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: <' . $from . '>',
            'Return-Path: <' . $from . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'X-Mailer: StBenedictsAcademy-Mailer/2.0',
            'Auto-Submitted: auto-generated',
            'Content-Type: multipart/alternative; boundary="' . $b . '"',
        ];
        $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text)) . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "--$b--\r\n";
        return implode("\r\n", $h) . "\r\n\r\n" . $body;
    }

    private function encodeHeader($v) {
        return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }

    /**
     * Dispatches mail via PHP mail() with envelope sender parameter (-f) for SPF alignment.
     */
    private function viaMail($to, $subject, $html, $from) {
        $schoolName = defined('SCHOOL_NAME') ? SCHOOL_NAME : "St. Benedict's Early Years British Academy";
        $domain = substr(strrchr($from, '@') ?: '@stbenedictsacademy.com.ng', 1);
        $messageId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . $domain . '>';

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $this->encodeHeader($schoolName) . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'Return-Path: <' . $from . '>',
            'Message-ID: ' . $messageId,
            'X-Mailer: StBenedictsAcademy-Mailer/2.0',
            'Auto-Submitted: auto-generated',
        ];

        // Envelope sender flag -f is essential on cPanel Exim for passing SPF on phone clients (Gmail, Apple Mail, Outlook)
        $cleanFrom = preg_replace('/[^a-zA-Z0-9@._-]/', '', $from);
        $extraParams = '-f' . $cleanFrom;

        $sent = @mail($to, $this->encodeHeader($subject), $html, implode("\r\n", $headers), $extraParams);
        if (!$sent) {
            // Fallback without 5th parameter if local environment does not support it (e.g. basic Windows mail setup)
            $sent = @mail($to, $this->encodeHeader($subject), $html, implode("\r\n", $headers));
        }
        return $sent;
    }

    private function read() {
        $out = '';
        while (($line = fgets($this->sock, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    }

    private function cmd($c, array $ok) {
        if ($c !== null) fwrite($this->sock, $c . "\r\n");
        $r = $this->read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) {
            $this->lastError = trim($r) ?: 'no response';
            return false;
        }
        return $r;
    }

    public function viaSmtp($to, $subject, $html, $from) {
        $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
        $port = defined('SMTP_PORT') ? (int)SMTP_PORT : ($secure === 'ssl' ? 465 : 587);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . $port;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => defined('SMTP_VERIFY_PEER') ? SMTP_VERIFY_PEER : false,
            'verify_peer_name' => defined('SMTP_VERIFY_PEER') ? SMTP_VERIFY_PEER : false,
            'allow_self_signed' => true
        ]]);
        $this->sock = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) { $this->lastError = "connect: $errstr"; return false; }
        stream_set_timeout($this->sock, 10);
        $helo = $_SERVER['SERVER_NAME'] ?? 'localhost';
        try {
            if (!$this->cmd(null, [220])) return false;
            if (!$this->cmd("EHLO $helo", [250])) return false;
            if ($secure === 'tls') {
                if (!$this->cmd('STARTTLS', [220])) return false;
                if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $this->lastError = 'TLS negotiation failed'; return false; }
                if (!$this->cmd("EHLO $helo", [250])) return false;
            }
            if (defined('SMTP_USER') && SMTP_USER !== '') {
                if (!$this->cmd('AUTH LOGIN', [334])) return false;
                if (!$this->cmd(base64_encode(SMTP_USER), [334])) return false;
                if (!$this->cmd(base64_encode(SMTP_PASS), [235])) return false;
            }
            if (!$this->cmd("MAIL FROM:<$from>", [250])) return false;
            if (!$this->cmd("RCPT TO:<$to>", [250, 251])) return false;
            if (!$this->cmd('DATA', [354])) return false;
            $msg = preg_replace('/^\./m', '..', $this->buildMessage($to, $subject, $html, $from));
            fwrite($this->sock, $msg . "\r\n.\r\n");
            $r = $this->read();
            if ((int)substr($r, 0, 3) !== 250) { $this->lastError = trim($r); return false; }
            $this->cmd('QUIT', [221, 250]);
            return true;
        } finally {
            if (is_resource($this->sock)) fclose($this->sock);
        }
    }

    /**
     * Formats an email using St. Benedict's Academy branded, mobile-first responsive template.
     * Engineered specifically for high readability and clarity on smartphone screens (Gmail, Apple Mail, Outlook).
     */
    public static function formatTemplate($title, $bodyHtml, $actionUrl = null, $actionText = null, $footerNotes = null) {
        $schoolName = defined('SCHOOL_NAME') ? SCHOOL_NAME : "ST. BENEDICT'S EARLY YEARS BRITISH ACADEMY";
        $schoolAddress = defined('SCHOOL_ADDRESS') ? SCHOOL_ADDRESS : 'No 560 A A New G.R.A TRANS-EKULU, ENUGU';
        $schoolPhone = defined('SCHOOL_PHONE') ? SCHOOL_PHONE : '09044472688';
        $schoolEmail = self::getSchoolEmail();
        $siteUrl = defined('BASE_URL') ? BASE_URL : 'https://stbenedictsacademy.com.ng';
        $logoUrl = $siteUrl . '/assets/images/logo.png';
        $year = date('Y');

        $actionBtnHtml = '';
        if ($actionUrl && $actionText) {
            $actionBtnHtml = '
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin: 28px 0 20px 0; width: 100%;">
                <tr>
                    <td align="center">
                        <a href="' . htmlspecialchars($actionUrl) . '" target="_blank" style="background-color: #002855; border: 1px solid #001d3d; border-radius: 8px; color: #ffffff !important; display: inline-block; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 600; line-height: 48px; text-align: center; text-decoration: none; min-width: 200px; padding: 0 28px; box-shadow: 0 4px 6px -1px rgba(0, 40, 85, 0.2);">
                            ' . htmlspecialchars($actionText) . ' &rarr;
                        </a>
                    </td>
                </tr>
            </table>';
        }

        $footerExtraHtml = $footerNotes ? '<p style="margin: 12px 0 0 0; font-size: 12px; color: #64748b; line-height: 1.5;">' . $footerNotes . '</p>' : '';

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>' . htmlspecialchars($title) . '</title>
<style>
body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
body { margin: 0; padding: 0; width: 100% !important; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1e293b; }
@media only screen and (max-width: 600px) {
    .email-container { width: 100% !important; max-width: 100% !important; }
    .email-card { padding: 20px 16px !important; border-radius: 0 !important; }
    .email-header { padding: 22px 14px !important; }
    .header-logo { width: 60px !important; height: auto !important; }
    .header-title { font-size: 16px !important; }
    .content-title { font-size: 18px !important; }
}
</style>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9;">
<center style="width: 100%; background-color: #f1f5f9; padding: 24px 0;">
    <div style="max-width: 620px; margin: 0 auto;" class="email-container">
        <!-- School Banner Header -->
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background: linear-gradient(135deg, #002855 0%, #001938 100%); border-radius: 12px 12px 0 0; border-bottom: 3px solid #f59e0b;" class="email-header">
            <tr>
                <td align="center" style="padding: 24px 20px;">
                    <img src="' . htmlspecialchars($logoUrl) . '" alt="St. Benedict\'s Academy" width="70" style="display: block; width: 70px; height: auto; margin-bottom: 12px; border-radius: 8px;" class="header-logo">
                    <h1 style="margin: 0; color: #ffffff; font-size: 17px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;" class="header-title">' . htmlspecialchars($schoolName) . '</h1>
                    <p style="margin: 4px 0 0 0; color: #cbd5e1; font-size: 12px; letter-spacing: 0.3px;">ENUGU, NIGERIA &bull; OFFICIAL SCHOOL COMMUNICATION</p>
                </td>
            </tr>
        </table>

        <!-- Main Card -->
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #ffffff; border-radius: 0 0 12px 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; border-top: none;">
            <tr>
                <td style="padding: 32px 28px;" class="email-card">
                    <h2 style="margin: 0 0 18px 0; color: #002855; font-size: 21px; font-weight: 700; line-height: 1.3;" class="content-title">' . htmlspecialchars($title) . '</h2>
                    <div style="font-size: 15px; line-height: 1.65; color: #334155;">
                        ' . $bodyHtml . '
                    </div>
                    ' . $actionBtnHtml . '
                </td>
            </tr>

            <!-- Footer Details -->
            <tr>
                <td style="background-color: #f8fafc; padding: 22px 24px; border-top: 1px solid #e2e8f0; border-radius: 0 0 12px 12px; text-align: center; font-size: 12px; color: #64748b;">
                    <p style="margin: 0 0 6px 0; font-weight: 600; color: #475569;">' . htmlspecialchars($schoolName) . '</p>
                    <p style="margin: 0 0 6px 0;">' . htmlspecialchars($schoolAddress) . '</p>
                    <p style="margin: 0 0 8px 0;">
                        <span>&phone; <a href="tel:' . htmlspecialchars($schoolPhone) . '" style="color: #002855; text-decoration: none; font-weight: 600;">' . htmlspecialchars($schoolPhone) . '</a></span> &bull; 
                        <span>&#9993; <a href="mailto:' . htmlspecialchars($schoolEmail) . '" style="color: #002855; text-decoration: none; font-weight: 600;">' . htmlspecialchars($schoolEmail) . '</a></span>
                    </p>
                    <p style="margin: 0; font-size: 11px; color: #94a3b8;">
                        &copy; ' . $year . ' St. Benedict\'s Early Years British Academy. All rights reserved.
                    </p>
                    ' . $footerExtraHtml . '
                </td>
            </tr>
        </table>
    </div>
</center>
</body>
</html>';
    }

    /**
     * Convenience method to build and send an email wrapped in the school template.
     */
    public static function sendFormatted($to, $subject, $title, $bodyHtml, $actionUrl = null, $actionText = null, $footerNotes = null) {
        $html = self::formatTemplate($title, $bodyHtml, $actionUrl, $actionText, $footerNotes);
        return self::send($to, $subject, $html);
    }
}
