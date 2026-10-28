<?php
declare(strict_types=1);

/**
 * Minimal mailer: sends multipart (text + HTML) e-mail through
 *  - 'log'  : writes .eml files into private/data/mail/ (development)
 *  - 'mail' : PHP mail()
 *  - 'smtp' : an SMTP server (STARTTLS / SSL, AUTH LOGIN)
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $text, ?string $html = null, ?string $replyTo = null): bool
    {
        // Header values must never contain line breaks (header injection).
        [$to, $subject, $replyTo] = array_map(fn($v) => $v === null ? null : trim(preg_replace('/[\r\n\t]+/', ' ', $v)), [$to, $subject, $replyTo]);
        $fromEmail = (string)config('mail.from_email');
        $fromName = (string)config('mail.from_name');
        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers = [
            'Date' => date(DATE_RFC2822),
            'From' => self::addr($fromEmail, $fromName),
            'To' => $to,
            'Subject' => self::encode($subject),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . (parse_url((string)config('site_url'), PHP_URL_HOST) ?: 'localhost') . '>',
            'MIME-Version' => '1.0',
        ];
        if ($replyTo) $headers['Reply-To'] = $replyTo;
        $html ??= '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#011031">' . nl2br(h($text)) . '</div>';
        $headers['Content-Type'] = "multipart/alternative; boundary=\"$boundary\"";
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$boundary--\r\n";

        try {
            switch (config('mail.driver', 'log')) {
                case 'smtp':
                    return self::smtp($fromEmail, $to, $headers, $body);
                case 'mail':
                    $h = $headers;
                    unset($h['To'], $h['Subject']);
                    $hdr = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($h), $h));
                    return mail($to, $headers['Subject'], $body, $hdr, '-f' . $fromEmail);
                default:
                    $dir = EC_DATA . '/mail';
                    if (!is_dir($dir)) mkdir($dir, 0770, true);
                    $raw = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)) . "\r\n\r\n" . $body;
                    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
                    file_put_contents("$dir/$name", $raw);
                    // Plain copy for easy reading in the admin outbox.
                    file_put_contents("$dir/$name.json", json_encode(['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html, 'at' => time()]));
                    return true;
            }
        } catch (Throwable $e) {
            error_log('Mailer: ' . $e->getMessage());
            return false;
        }
    }

    private static function addr(string $email, string $name): string
    {
        return $name === '' ? $email : self::encode($name) . " <$email>";
    }

    private static function encode(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function smtp(string $from, string $to, array $headers, string $body): bool
    {
        $c = config('mail.smtp');
        $host = (string)$c['host'];
        $port = (int)($c['port'] ?? 587);
        $enc = (string)($c['encryption'] ?? 'tls');
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $fp = stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr");
        stream_set_timeout($fp, 20);
        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') break;
            }
            return $data;
        };
        $cmd = function (string $line, array $ok) use ($fp, $read): string {
            fwrite($fp, $line . "\r\n");
            $resp = $read();
            if (!in_array((int)substr($resp, 0, 3), $ok, true)) throw new RuntimeException('SMTP error after "' . explode(' ', $line)[0] . '": ' . trim($resp));
            return $resp;
        };
        $read();
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        $cmd($ehlo, [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('STARTTLS failed');
            $cmd($ehlo, [250]);
        }
        if (!empty($c['username'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string)$c['username']), [334]);
            $cmd(base64_encode((string)$c['password']), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        preg_match('/<([^>]+)>/', $to, $m);
        $cmd('RCPT TO:<' . ($m[1] ?? $to) . '>', [250, 251]);
        $cmd('DATA', [354]);
        $msg = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)) . "\r\n\r\n" . $body;
        $msg = preg_replace('/^\./m', '..', $msg);
        $cmd($msg . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        return true;
    }
}
