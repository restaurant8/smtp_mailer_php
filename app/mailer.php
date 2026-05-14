<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function send_smtp_mail(string $toEmail, string $subject, string $htmlBody, string $textBody): void
{
    $cfg = config()['smtp'];
    $mail = new PHPMailer(true);

    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = (int) $cfg['port'];
    $mail->Timeout = (int) ($cfg['timeout'] ?? 30);
    $mail->SMTPAuth = $cfg['username'] !== '';
    $mail->Username = $cfg['username'];
    $mail->Password = $cfg['password'];
    $mail->SMTPAutoTLS = (bool) ($cfg['auto_tls'] ?? true);

    if (!empty($cfg['debug'])) {
        $debugLog = $cfg['debug_log'] ?? (__DIR__ . '/../storage/logs/smtp-debug.log');
        $debugDir = dirname($debugLog);
        if (!is_dir($debugDir)) {
            mkdir($debugDir, 0755, true);
        }
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function (string $message, int $level) use ($debugLog): void {
            file_put_contents(
                $debugLog,
                '[' . date('Y-m-d H:i:s') . "] [level {$level}] {$message}" . PHP_EOL,
                FILE_APPEND
            );
        };
    }

    if (($cfg['encryption'] ?? '') === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif (($cfg['encryption'] ?? '') === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    }

    if (!empty($cfg['allow_self_signed'])) {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    $mail->setFrom($cfg['from_email'], $cfg['from_name']);
    if (!empty($cfg['reply_to'])) {
        $mail->addReplyTo($cfg['reply_to']);
    }
    $mail->addAddress($toEmail);
    $mail->addCustomHeader('List-Unsubscribe', '<' . unsubscribe_url($toEmail) . '>');

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody;

    $mail->send();
}
