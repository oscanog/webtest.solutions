<?php
require_once __DIR__ . '/mail/config.php';
require_once __DIR__ . '/mail/messages.php';
require_once __DIR__ . '/mail/preview_mailer.php';
require_once __DIR__ . '/mail/symfony_mailer.php';

function webtest_mail_send_message(array $message): void
{
    $configError = webtest_mail_validate_config();
    if ($configError !== null) {
        throw new RuntimeException($configError);
    }

    $toEmail = trim((string) ($message['to']['email'] ?? ''));
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Recipient email is invalid.');
    }

    $config = webtest_mail_normalized_config();
    if ($config['mailer'] === 'preview') {
        webtest_mail_preview_store($message);
        return;
    }

    webtest_mail_send_via_symfony($message, $config);
}

function webtest_mail_send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): void
{
    webtest_mail_send_message(
        webtest_mail_message($toEmail, $toName, $subject, $htmlBody, $textBody)
    );
}
