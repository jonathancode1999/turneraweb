<?php

// Mail helper.
// - If SMTP is enabled in Admin > Configuración, it will send via SMTP (AUTH LOGIN).
// - Otherwise, it will fallback to PHP mail().
// v2 improvements:
// - detailed result function (ok + error)
// - logs SMTP errors to /data/mail.log

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

function mail_log(string $msg): void {
    try {
        $path = __DIR__ . '/../data/mail.log';
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        @file_put_contents($path, $line, FILE_APPEND);
    } catch (Throwable $e) {}
}

/**
 * Returns ['ok'=>bool, 'error'=>?string]
 */
function send_mail_html_result(string $to, string $subject, string $html, string $fromEmail, string $fromName = ''): array {
    $cfg = app_config();
    $bid = (int)($cfg['business_id'] ?? 1);

    $pdo = db();
    $stmt = $pdo->prepare('SELECT smtp_enabled, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from_email, smtp_from_name FROM businesses WHERE id=:id');
    $stmt->execute([':id' => $bid]);
    $biz = $stmt->fetch() ?: [];

    $smtpEnabled = !empty($biz['smtp_enabled']) && trim((string)($biz['smtp_host'] ?? '')) !== '';
    if ($smtpEnabled) {
        $smtp = [
            'host' => trim((string)($biz['smtp_host'] ?? '')),
            'port' => (int)($biz['smtp_port'] ?? 587),
            'user' => trim((string)($biz['smtp_user'] ?? '')),
            'pass' => (string)($biz['smtp_pass'] ?? ''),
            'secure' => (string)($biz['smtp_secure'] ?? ''), // '', 'tls', 'ssl'
        ];

        $fromEmail2 = trim((string)($biz['smtp_from_email'] ?? '')) !== '' ? trim((string)$biz['smtp_from_email']) : $fromEmail;
        $fromName2 = trim((string)($biz['smtp_from_name'] ?? '')) !== '' ? trim((string)$biz['smtp_from_name']) : $fromName;

        return smtp_send_html_result($smtp, $to, $subject, $html, $fromEmail2, $fromName2);
    }

    // Fallback mail()
    $fromNameUse = trim($fromName) !== '' ? $fromName : $fromEmail;
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . _mimeheader($fromNameUse, 'UTF-8') . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;

    $ok = @mail($to, _mimeheader($subject, 'UTF-8'), $html, implode("\r\n", $headers));
    if (!$ok) {
        mail_log('mail() failed to ' . $to . ' subject=' . $subject);
        return ['ok' => false, 'error' => 'mail() falló. Activá SMTP en Configuración para mayor confiabilidad.'];
    }
    return ['ok' => true, 'error' => null];
}

function send_mail_html(string $to, string $subject, string $html, string $fromEmail, string $fromName = ''): bool {
    $r = send_mail_html_result($to, $subject, $html, $fromEmail, $fromName);
    return (bool)$r['ok'];
}

function smtp_send_html_result(array $smtp, string $toEmail, string $subject, string $html, string $fromEmail, string $fromName): array {
    $host = (string)($smtp['host'] ?? '');
    $port = (int)($smtp['port'] ?? 587);
    $user = (string)($smtp['user'] ?? '');
    $pass = (string)($smtp['pass'] ?? '');
    $secure = (string)($smtp['secure'] ?? ''); // '', 'tls', 'ssl'

    if ($host === '' || $port <= 0) return ['ok' => false, 'error' => 'SMTP host/port inválido.'];

    $remote = ($secure === 'ssl') ? 'ssl://' . $host . ':' . $port : $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        $err = 'No se pudo conectar al SMTP: ' . $errstr . ' (' . $errno . ')';
        mail_log($err . ' host=' . $remote);
        return ['ok' => false, 'error' => $err];
    }
    stream_set_timeout($fp, 12);

    $send = function(string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = function(array $codes) use ($fp): string {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int)substr($data, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($data));
        }
        return $data;
    };

    try {
        $expect([220]);
        $send('EHLO localhost');
        $expect([250]);

        if ($secure === 'tls') {
            $send('STARTTLS');
            $expect([220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP error: no se pudo iniciar STARTTLS');
            }
            $send('EHLO localhost');
            $expect([250]);
        }

        if ($user !== '') {
            $send('AUTH LOGIN');
            $expect([334]);
            $send(base64_encode($user));
            $expect([334]);
            $send(base64_encode($pass));
            $expect([235]);
        }

        $fromHeader = trim($fromName) !== '' ? _mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>' : $fromEmail;

        $send('MAIL FROM:<' . $fromEmail . '>');
        $expect([250]);

        $send('RCPT TO:<' . $toEmail . '>');
        $expect([250, 251]);

        $send('DATA');
        $expect([354]);

        $headers = [];
        $headers[] = 'From: ' . $fromHeader;
        $headers[] = 'To: <' . $toEmail . '>';
        $headers[] = 'Subject: ' . _mimeheader($subject, 'UTF-8');
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Date: ' . date('r');

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $html;
        $data = preg_replace('/\r\n\./', "\r\n..", $data);
        fwrite($fp, $data . "\r\n.\r\n");
        $expect([250]);

        $send('QUIT');
        @fclose($fp);
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        try { $send('QUIT'); } catch (Throwable $e2) {}
        @fclose($fp);
        $err = $e->getMessage();
        mail_log('SMTP send failed to ' . $toEmail . ' subject=' . $subject . ' err=' . $err);
        return ['ok' => false, 'error' => $err];
    }
}

/**
 * Debug SMTP send used by Admin "Probar SMTP"
 * Returns ['ok'=>bool,'log'=>string,'error'=>?string]
 */
function smtp_send_html_debug(array $smtp, string $toEmail, string $subject, string $html, string $fromEmail, string $fromName): array {
    $log = '';
    $host = (string)($smtp['host'] ?? '');
    $port = (int)($smtp['port'] ?? 587);
    $user = (string)($smtp['user'] ?? '');
    $pass = (string)($smtp['pass'] ?? '');
    $secure = (string)($smtp['secure'] ?? '');

    if ($host === '' || $port <= 0) return ['ok' => false, 'log' => '', 'error' => 'SMTP host/port inválido.'];

    $remote = ($secure === 'ssl') ? 'ssl://' . $host . ':' . $port : $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
    if (!$fp) return ['ok' => false, 'log' => '', 'error' => 'No se pudo conectar: ' . $errstr . ' (' . $errno . ')'];
    stream_set_timeout($fp, 12);

    $send = function(string $cmd) use ($fp, &$log): void {
        $log .= "C: " . $cmd . "\n";
        fwrite($fp, $cmd . "\r\n");
    };
    $expect = function(array $codes) use ($fp, &$log): string {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $log .= "S: " . trim($data) . "\n";
        $code = (int)substr($data, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($data));
        }
        return $data;
    };

    try {
        $expect([220]);
        $send('EHLO localhost');
        $expect([250]);

        if ($secure === 'tls') {
            $send('STARTTLS');
            $expect([220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP error: no se pudo iniciar STARTTLS');
            }
            $send('EHLO localhost');
            $expect([250]);
        }

        if ($user !== '') {
            $send('AUTH LOGIN');
            $expect([334]);
            $send(base64_encode($user));
            $expect([334]);
            $send(base64_encode($pass));
            $expect([235]);
        }

        $send('MAIL FROM:<' . $fromEmail . '>');
        $expect([250]);

        $send('RCPT TO:<' . $toEmail . '>');
        $expect([250, 251]);

        $send('DATA');
        $expect([354]);

        $fromHeader = trim($fromName) !== '' ? _mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>' : $fromEmail;

        $headers = [];
        $headers[] = 'From: ' . $fromHeader;
        $headers[] = 'To: <' . $toEmail . '>';
        $headers[] = 'Subject: ' . _mimeheader($subject, 'UTF-8');
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $html;
        $data = preg_replace('/\r\n\./', "\r\n..", $data);
        fwrite($fp, $data . "\r\n.\r\n");
        $expect([250]);

        $send('QUIT');
        @fclose($fp);
        return ['ok' => true, 'log' => $log, 'error' => null];
    } catch (Throwable $e) {
        try { $send('QUIT'); } catch (Throwable $e2) {}
        @fclose($fp);
        return ['ok' => false, 'log' => $log, 'error' => $e->getMessage()];
    }
}

// Internal helper for MIME headers
function _mimeheader(string $text, string $charset='UTF-8'): string {
    // If ASCII only, return as-is
    if (preg_match('/^[\x20-\x7E]*$/', $text)) return $text;
    return '=?' . $charset . '?B?' . base64_encode($text) . '?=';
}
