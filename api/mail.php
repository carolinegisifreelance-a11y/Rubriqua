<?php
// Rubriqua, envoi d'e-mail par SMTP (boîte IONOS), sans dépendance externe.

if (!defined('RUBRIQUA')) { http_response_code(404); exit; }

function encode_header(string $s): string {
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function send_mail(string $subject, string $body, string $replyTo, string $replyName): bool {
    $c = config();
    if (!$c['mail_to']) return false;
    $from = $c['mail_from'] ?: $c['mail_to'];

    $headers = [
        'Date: ' . date('r'),
        'From: ' . encode_header('Site Rubriqua') . " <$from>",
        'To: <' . $c['mail_to'] . '>',
        'Reply-To: ' . encode_header($replyName) . " <$replyTo>",
        'Subject: ' . encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $c['site_host'] . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));

    if (!$c['smtp_user'] || !$c['smtp_pass']) {
        // Pas de SMTP configuré : fonction mail() de l'hébergeur.
        $h = array_filter($headers, fn($l) => !preg_match('/^(To|Subject):/', $l));
        return @mail($c['mail_to'], encode_header($subject), chunk_split(base64_encode($body)), implode("\r\n", $h));
    }

    $port = (int)$c['smtp_port'];
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $c['smtp_host'] . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    };
    $cmd = function (string $line, array $ok) use ($fp, $read) {
        fwrite($fp, $line . "\r\n");
        $resp = $read();
        if (!in_array((int)substr($resp, 0, 3), $ok, true)) throw new RuntimeException(trim($resp));
        return $resp;
    };

    try {
        $greet = $read();
        if ((int)substr($greet, 0, 3) !== 220) throw new RuntimeException($greet);
        $cmd('EHLO ' . $c['site_host'], [250]);
        if ($port === 587) {
            $cmd('STARTTLS', [220]);
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO ' . $c['site_host'], [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($c['smtp_user']), [334]);
        $cmd(base64_encode($c['smtp_pass']), [235]);
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd('RCPT TO:<' . $c['mail_to'] . '>', [250, 251]);
        $cmd('DATA', [354]);
        $cmd(preg_replace('/^\./m', '..', $data) . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('Rubriqua SMTP : ' . $e->getMessage());
        fclose($fp);
        return false;
    }
}
