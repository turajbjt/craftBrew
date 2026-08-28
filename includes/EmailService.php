<?php
/**
 * CraftBrew Email Service
 * Full-featured SMTP Transport (STARTTLS / SSL / AUTH LOGIN) with PHP mail() Fallback
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_check.php';

class EmailService {

    /**
     * Send email via configured SMTP or standard mail() fallback
     */
    public static function send(string $to, string $subject, string $body, bool $isHtml = false, string &$diagnosticLog = ''): bool {
        $smtpEnabled = (bool)get_site_setting('smtp_enabled', 0);
        $smtpHost    = trim(get_site_setting('smtp_host', ''));

        if ($smtpEnabled && !empty($smtpHost)) {
            return self::sendViaSmtp($to, $subject, $body, $isHtml, $diagnosticLog);
        }

        return self::sendViaNativeMail($to, $subject, $body, $isHtml, $diagnosticLog);
    }

    /**
     * Native PHP mail() dispatcher
     */
    private static function sendViaNativeMail(string $to, string $subject, string $body, bool $isHtml, string &$log): bool {
        $fromEmail = get_site_setting('smtp_from_email') ?: ('no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $fromName  = get_site_setting('smtp_from_name') ?: APP_NAME;

        $headers = [];
        $headers[] = "From: {$fromName} <{$fromEmail}>";
        $headers[] = "Reply-To: {$fromEmail}";
        $headers[] = "X-Mailer: CraftBrew-Mailer/2.5.0";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = $isHtml ? "Content-Type: text/html; charset=UTF-8" : "Content-Type: text/plain; charset=UTF-8";

        $headerStr = implode("\r\n", $headers);
        $success = @mail($to, $subject, $body, $headerStr);
        $log = $success ? "Dispatched successfully via PHP mail()." : "PHP mail() execution returned false.";
        return $success;
    }

    /**
     * Direct Socket SMTP Client with STARTTLS & AUTH LOGIN
     */
    private static function sendViaSmtp(string $to, string $subject, string $body, bool $isHtml, string &$log): bool {
        $host       = get_site_setting('smtp_host');
        $port       = (int)get_site_setting('smtp_port', 587);
        $encryption = strtolower(get_site_setting('smtp_encryption', 'tls'));
        $username   = get_site_setting('smtp_user');
        $password   = get_site_setting('smtp_pass');
        $fromEmail  = get_site_setting('smtp_from_email') ?: "no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $fromName   = get_site_setting('smtp_from_name') ?: APP_NAME;

        $timeout = 15;
        $logEntries = [];

        $socketHost = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $logEntries[] = "Connecting to {$socketHost}:{$port} (timeout: {$timeout}s)...";

        $socket = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            $logEntries[] = "Connection failed: [{$errno}] {$errstr}";
            $log = implode("\n", $logEntries);
            return false;
        }

        stream_set_timeout($socket, $timeout);

        $readResponse = function() use ($socket, &$logEntries) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            $logEntries[] = "S: " . trim($response);
            return $response;
        };

        $sendCommand = function($cmd) use ($socket, &$logEntries) {
            $logEntries[] = "C: " . (strpos($cmd, 'AUTH') === false && !preg_match('/^[A-Za-z0-9+\/=]{10,}$/', $cmd) ? $cmd : '[CREDENTIAL REDACTED]');
            fwrite($socket, $cmd . "\r\n");
        };

        $banner = $readResponse();
        if (substr($banner, 0, 3) !== '220') {
            fclose($socket);
            $log = implode("\n", $logEntries);
            return false;
        }

        // EHLO
        $clientHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $sendCommand("EHLO {$clientHost}");
        $readResponse();

        // STARTTLS
        if ($encryption === 'tls') {
            $sendCommand("STARTTLS");
            $tlsResp = $readResponse();
            if (substr($tlsResp, 0, 3) === '220') {
                $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$crypto) {
                    $logEntries[] = "TLS Handshake failed.";
                    fclose($socket);
                    $log = implode("\n", $logEntries);
                    return false;
                }
                $sendCommand("EHLO {$clientHost}");
                $readResponse();
            }
        }

        // AUTH LOGIN
        if (!empty($username)) {
            $sendCommand("AUTH LOGIN");
            $authResp = $readResponse();
            if (substr($authResp, 0, 3) !== '334') {
                fclose($socket);
                $log = implode("\n", $logEntries);
                return false;
            }

            $sendCommand(base64_encode($username));
            $userResp = $readResponse();
            if (substr($userResp, 0, 3) !== '334') {
                fclose($socket);
                $log = implode("\n", $logEntries);
                return false;
            }

            $sendCommand(base64_encode($password));
            $passResp = $readResponse();
            if (substr($passResp, 0, 3) !== '235') {
                $logEntries[] = "Authentication failed.";
                fclose($socket);
                $log = implode("\n", $logEntries);
                return false;
            }
        }

        // MAIL FROM
        $sendCommand("MAIL FROM:<{$fromEmail}>");
        $fromResp = $readResponse();
        if (substr($fromResp, 0, 3) !== '250') {
            fclose($socket);
            $log = implode("\n", $logEntries);
            return false;
        }

        // RCPT TO
        $sendCommand("RCPT TO:<{$to}>");
        $rcptResp = $readResponse();
        if (substr($rcptResp, 0, 3) !== '250' && substr($rcptResp, 0, 3) !== '251') {
            fclose($socket);
            $log = implode("\n", $logEntries);
            return false;
        }

        // DATA
        $sendCommand("DATA");
        $dataResp = $readResponse();
        if (substr($dataResp, 0, 3) !== '354') {
            fclose($socket);
            $log = implode("\n", $logEntries);
            return false;
        }

        $contentType = $isHtml ? "text/html; charset=UTF-8" : "text/plain; charset=UTF-8";
        $headers = [
            "Date: " . date('r'),
            "From: {$fromName} <{$fromEmail}>",
            "To: <{$to}>",
            "Subject: {$subject}",
            "MIME-Version: 1.0",
            "Content-Type: {$contentType}",
            "X-Mailer: CraftBrew-Mailer/2.5.0"
        ];

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        fwrite($socket, $payload . "\r\n");
        $finalResp = $readResponse();

        $sendCommand("QUIT");
        $readResponse();
        fclose($socket);

        $success = (substr($finalResp, 0, 3) === '250');
        if ($success) {
            $logEntries[] = "Email delivered to SMTP gateway successfully.";
        } else {
            $logEntries[] = "SMTP Gateway rejected message: {$finalResp}";
        }

        $log = implode("\n", $logEntries);
        return $success;
    }

    /**
     * Test SMTP Diagnostic tool
     */
    public static function testConnection(string $to, string &$diagnosticLog): bool {
        $subject = "[" . APP_NAME . "] SMTP Test Message - " . date('Y-m-d H:i:s');
        $body = "Congratulations!\n\nYour SMTP server configuration in " . APP_NAME . " is working perfectly.\n\nServer Time: " . date('r') . "\nHost: " . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return self::send($to, $subject, $body, false, $diagnosticLog);
    }
}

function app_send_mail($to, $subject, $body, $isHtml = false, &$log = '') {
    return EmailService::send($to, $subject, $body, $isHtml, $log);
}
