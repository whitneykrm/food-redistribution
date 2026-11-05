<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Sends one email through the SMTP account in includes/mail-config.php.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): array
{
    $configPath = __DIR__ . '/mail-config.php';
    if (!file_exists($configPath)) {
        return [false, 'includes/mail-config.php is missing.'];
    }
    $cfg = require $configPath;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = $cfg['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) $cfg['port'];
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        return [true, ''];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}