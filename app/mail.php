<?php
require_once __DIR__ . '/bootstrap.php';

function bugcatcher_mail_autoload_path(): string
{
    return dirname(__DIR__) . '/vendor/autoload.php';
}

function bugcatcher_mail_dependency_available(): bool
{
    return is_file(bugcatcher_mail_autoload_path());
}

function bugcatcher_mail_validate_config(): ?string
{
    if (!bugcatcher_mail_dependency_available()) {
        return 'Email dependency is not installed.';
    }

    $host = (string) bugcatcher_config('MAIL_HOST', '');
    $port = (int) bugcatcher_config('MAIL_PORT', 0);
    $username = (string) bugcatcher_config('MAIL_USERNAME', '');
    $password = (string) bugcatcher_config('MAIL_PASSWORD', '');
    $fromEmail = (string) bugcatcher_config('MAIL_FROM_EMAIL', '');
    $encryption = (string) bugcatcher_config('MAIL_ENCRYPTION', 'tls');

    if ($host === '' || $port <= 0 || $username === '' || $password === '' || $fromEmail === '') {
        return 'Email delivery is not configured.';
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return 'Email delivery is not configured.';
    }

    if (!in_array($encryption, ['', 'none', 'ssl', 'tls'], true)) {
        return 'Email delivery encryption is invalid.';
    }

    return null;
}

function bugcatcher_mail_send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): void
{
    $configError = bugcatcher_mail_validate_config();
    if ($configError !== null) {
        throw new RuntimeException($configError);
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Recipient email is invalid.');
    }

    require_once bugcatcher_mail_autoload_path();

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new RuntimeException('Email dependency is not available.');
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = (string) bugcatcher_config('MAIL_HOST', '');
        $mail->Port = (int) bugcatcher_config('MAIL_PORT', 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) bugcatcher_config('MAIL_USERNAME', '');
        $mail->Password = (string) bugcatcher_config('MAIL_PASSWORD', '');
        $mail->SMTPAutoTLS = true;

        $encryption = (string) bugcatcher_config('MAIL_ENCRYPTION', 'tls');
        if ($encryption !== '' && $encryption !== 'none') {
            $mail->SMTPSecure = $encryption;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(
            (string) bugcatcher_config('MAIL_FROM_EMAIL', ''),
            (string) bugcatcher_config('MAIL_FROM_NAME', 'BugCatcher')
        );
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = ($textBody !== '') ? $textBody : trim(strip_tags($htmlBody));
        $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        throw new RuntimeException('Mail delivery failed: ' . $e->getMessage(), 0, $e);
    }
}
