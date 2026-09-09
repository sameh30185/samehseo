<?php
declare(strict_types=1);

namespace Sameh\Mail;

use Sameh\App;
use Sameh\Config;

/**
 * Mailer interface + default implementation.
 * If SMTP not configured: write to storage/mail-outbox as file (same success path for UX).
 *
 * Required SMTP config keys (config.php):
 *   smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from, smtp_encryption (tls|ssl|none)
 */
final class Mailer
{
    public static function isSmtpConfigured(): bool
    {
        $host = trim((string) Config::get('smtp_host', ''));
        $from = trim((string) Config::get('smtp_from', ''));
        return $host !== '' && $from !== '';
    }

    /**
     * @return array{ok:bool,via:string,path?:string,error?:string}
     */
    public static function send(string $to, string $subject, string $bodyText): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'via' => 'none', 'error' => 'invalid_to'];
        }

        if (self::isSmtpConfigured()) {
            $r = self::sendSmtp($to, $subject, $bodyText);
            if ($r['ok']) {
                return $r;
            }
            // Fall through to outbox so recovery still works
            $out = self::writeOutbox($to, $subject, $bodyText, $r['error'] ?? 'smtp_failed');
            return ['ok' => true, 'via' => 'outbox_fallback', 'path' => $out];
        }

        $path = self::writeOutbox($to, $subject, $bodyText, null);
        return ['ok' => true, 'via' => 'outbox', 'path' => $path];
    }

    private static function writeOutbox(string $to, string $subject, string $body, ?string $note): string
    {
        $dir = App::basePath() . '/storage/mail-outbox';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml.json';
        $path = $dir . '/' . $name;
        $payload = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'created_at' => gmdate('c'),
            'note' => $note,
            'smtp_configured' => self::isSmtpConfigured(),
        ];
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($path, 0600);
        return $path;
    }

    /** Minimal SMTP via PHP stream — no Composer. */
    private static function sendSmtp(string $to, string $subject, string $body): array
    {
        $host = (string) Config::get('smtp_host', '');
        $port = (int) Config::get('smtp_port', 587);
        $user = (string) Config::get('smtp_user', '');
        $pass = (string) Config::get('smtp_pass', '');
        $from = (string) Config::get('smtp_from', '');
        $enc = strtolower((string) Config::get('smtp_encryption', 'tls'));

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15);
        if (!$fp) {
            return ['ok' => false, 'via' => 'smtp', 'error' => "connect:$errno"];
        }
        stream_set_timeout($fp, 15);
        $read = static function () use ($fp): string {
            $data = '';
            while ($line = fgets($fp, 515)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $write = static function (string $cmd) use ($fp): void {
            fwrite($fp, $cmd . "\r\n");
        };

        try {
            $read();
            $write('EHLO sameh.local');
            $ehlo = $read();
            if ($enc === 'tls' && stripos($ehlo, 'STARTTLS') !== false) {
                $write('STARTTLS');
                $read();
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($fp);
                    return ['ok' => false, 'via' => 'smtp', 'error' => 'tls_failed'];
                }
                $write('EHLO sameh.local');
                $read();
            }
            if ($user !== '') {
                $write('AUTH LOGIN');
                $read();
                $write(base64_encode($user));
                $read();
                $write(base64_encode($pass));
                $auth = $read();
                if (!str_starts_with($auth, '235')) {
                    fclose($fp);
                    return ['ok' => false, 'via' => 'smtp', 'error' => 'auth_failed'];
                }
            }
            $write('MAIL FROM:<' . $from . '>');
            $read();
            $write('RCPT TO:<' . $to . '>');
            $read();
            $write('DATA');
            $read();
            $headers = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
            $write($headers . $body . "\r\n.");
            $dataResp = $read();
            $write('QUIT');
            fclose($fp);
            if (!str_starts_with($dataResp, '250')) {
                return ['ok' => false, 'via' => 'smtp', 'error' => 'data_rejected'];
            }
            return ['ok' => true, 'via' => 'smtp'];
        } catch (\Throwable $e) {
            fclose($fp);
            return ['ok' => false, 'via' => 'smtp', 'error' => 'exception'];
        }
    }
}
