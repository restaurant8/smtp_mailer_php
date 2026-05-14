<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

use PHPMailer\PHPMailer\PHPMailer;

function send_smtp_mail(string $toEmail, string $subject, string $htmlBody, string $textBody): void
{
    $cfg = config()['smtp'];
    $mail = new PHPMailer(true);

    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = (int) $cfg['port'];
    $mail->SMTPAuth = $cfg['username'] !== '';
    $mail->Username = $cfg['username'];
    $mail->Password = $cfg['password'];

    if (($cfg['encryption'] ?? '') === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif (($cfg['encryption'] ?? '') === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
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
