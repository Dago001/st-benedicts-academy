<?php
// includes/mailer.php - minimal SMTP client (STARTTLS / implicit TLS, AUTH LOGIN/PLAIN) with mail() fallback.
// Configure in config/local.php:
//   define('SMTP_HOST', 'smtp.example.com'); define('SMTP_PORT', 587); define('SMTP_SECURE', 'tls'); // tls | ssl | ''
//   define('SMTP_USER', '...'); define('SMTP_PASS', '...'); define('MAIL_FROM', 'noreply@stbenedictsacademy.com.ng');

class Mailer {
    private $sock;
    public $lastError = '';

    public static function send($to, $subject, $html) {
        $to = trim((string)$to);
        if (preg_match('/[\r\n]/', $to . $subject) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = defined('MAIL_FROM') ? MAIL_FROM : SCHOOL_EMAIL;
        $m = new self();
        if (defined('SMTP_HOST') && SMTP_HOST !== '') {
            $ok = $m->viaSmtp($to, $subject, $html, $from);
            if (!$ok) error_log('Mailer SMTP failure: ' . $m->lastError);
            return $ok;
        }
        return $m->viaMail($to, $subject, $html, $from);
    }

    public function buildMessage($to, $subject, $html, $from) {
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $b = 'b' . bin2hex(random_bytes(8));
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $h = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeHeader(SCHOOL_NAME) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
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

    private function viaMail($to, $subject, $html, $from) {
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . SCHOOL_NAME . " <$from>\r\n";
        return @mail($to, $this->encodeHeader($subject), $html, $headers);
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
        $ctx = stream_context_create(['ssl' => ['verify_peer' => !defined('SMTP_VERIFY_PEER') || SMTP_VERIFY_PEER, 'verify_peer_name' => !defined('SMTP_VERIFY_PEER') || SMTP_VERIFY_PEER]]);
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
}
