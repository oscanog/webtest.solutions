<?php
require_once dirname(__DIR__) . '/bootstrap.php';

const WEBTEST_MAIL_PREVIEW_SESSION_KEY = 'webtest_mail_preview_messages';

function webtest_mail_autoload_path(): string
{
    return dirname(__DIR__, 2) . '/vendor/autoload.php';
}

function webtest_mail_dependency_available(): bool
{
    return is_file(webtest_mail_autoload_path());
}

function webtest_mail_default_mailer(): string
{
    return webtest_config('APP_ENV', 'development') === 'production' ? 'smtp' : 'preview';
}

function webtest_mail_mailer_name(): string
{
    $mailer = trim((string) webtest_config('MAIL_MAILER', ''));
    if ($mailer === '') {
        return webtest_mail_default_mailer();
    }

    return strtolower($mailer);
}

function webtest_mail_from_address(): string
{
    return trim((string) webtest_config('MAIL_FROM_ADDRESS', webtest_config('MAIL_FROM_EMAIL', '')));
}

function webtest_mail_from_name(): string
{
    return trim((string) webtest_config('MAIL_FROM_NAME', 'WebTest'));
}

function webtest_mail_normalized_config(): array
{
    return [
        'mailer' => webtest_mail_mailer_name(),
        'host' => trim((string) webtest_config('MAIL_HOST', '')),
        'port' => (int) webtest_config('MAIL_PORT', 587),
        'username' => trim((string) webtest_config('MAIL_USERNAME', '')),
        'password' => (string) webtest_config('MAIL_PASSWORD', ''),
        'encryption' => strtolower(trim((string) webtest_config('MAIL_ENCRYPTION', 'tls'))),
        'from_address' => webtest_mail_from_address(),
        'from_name' => webtest_mail_from_name(),
    ];
}

function webtest_mail_validate_config(?string $mailer = null): ?string
{
    $config = webtest_mail_normalized_config();
    $selectedMailer = strtolower(trim((string) ($mailer ?? $config['mailer'] ?? '')));

    if (!in_array($selectedMailer, ['preview', 'smtp'], true)) {
        return 'Email delivery driver is invalid.';
    }

    if ($selectedMailer === 'preview') {
        return null;
    }

    if (!webtest_mail_dependency_available()) {
        return 'Email dependency is not installed.';
    }

    if (
        $config['host'] === '' ||
        $config['port'] <= 0 ||
        $config['username'] === '' ||
        $config['password'] === '' ||
        $config['from_address'] === ''
    ) {
        return 'Email delivery is not configured.';
    }

    if (!filter_var($config['from_address'], FILTER_VALIDATE_EMAIL)) {
        return 'Email delivery is not configured.';
    }

    if (!in_array($config['encryption'], ['', 'none', 'ssl', 'tls'], true)) {
        return 'Email delivery encryption is invalid.';
    }

    return null;
}
