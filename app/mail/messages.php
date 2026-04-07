<?php
require_once __DIR__ . '/config.php';

function webtest_mail_message(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody = '',
    array $metadata = [],
    string $tag = ''
): array {
    return [
        'to' => [
            'email' => trim($toEmail),
            'name' => trim($toName),
        ],
        'subject' => $subject,
        'html' => $htmlBody,
        'text' => ($textBody !== '') ? $textBody : trim(strip_tags($htmlBody)),
        'metadata' => $metadata,
        'tag' => trim($tag),
    ];
}

function webtest_mail_password_reset_message(string $toEmail, string $toName, string $otp, int $ttlSeconds): array
{
    $appName = webtest_mail_from_name();
    $minutes = (int) ceil($ttlSeconds / 60);
    $htmlBody = sprintf(
        '<p>You requested a password reset for %s.</p><p>Your 6-digit reset code is <strong style="font-size:22px; letter-spacing:4px;">%s</strong>.</p><p>This code expires in %d minutes.</p><p>If you did not request this, you can ignore this email.</p>',
        htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($otp, ENT_QUOTES, 'UTF-8'),
        $minutes
    );
    $textBody = sprintf(
        "You requested a password reset for %s.\n\nYour 6-digit reset code is: %s\n\nThis code expires in %d minutes.\n\nIf you did not request this, you can ignore this email.",
        $appName,
        $otp,
        $minutes
    );

    return webtest_mail_message(
        $toEmail,
        $toName,
        $appName . ' password reset code',
        $htmlBody,
        $textBody,
        ['otp' => $otp],
        'password_reset_otp'
    );
}
