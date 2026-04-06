<?php
require_once dirname(__DIR__) . '/bootstrap.php';

const BUGCATCHER_MAIL_PREVIEW_SESSION_KEY = 'bugcatcher_mail_preview_messages';

function bugcatcher_mail_autoload_path(): string
{
    return dirname(__DIR__, 2) . '/vendor/autoload.php';
}

function bugcatcher_mail_dependency_available(): bool
{
    return is_file(bugcatcher_mail_autoload_path());
}

function bugcatcher_mail_default_mailer(): string
{
    return bugcatcher_config('APP_ENV', 'development') === 'production' ? 'smtp' : 'preview';
}

function bugcatcher_mail_mailer_name(): string
{
    $mailer = trim((string) bugcatcher_config('MAIL_MAILER', ''));
    if ($mailer === '') {
        return bugcatcher_mail_default_mailer();
    }

    return strtolower($mailer);
}

function bugcatcher_mail_from_address(): string
{
    return trim((string) bugcatcher_config('MAIL_FROM_ADDRESS', bugcatcher_config('MAIL_FROM_EMAIL', '')));
}

function bugcatcher_mail_from_name(): string
{
    return trim((string) bugcatcher_config('MAIL_FROM_NAME', 'WebTest'));
}

function bugcatcher_mail_normalized_config(): array
{
    return [
        'mailer' => bugcatcher_mail_mailer_name(),
        'host' => trim((string) bugcatcher_config('MAIL_HOST', '')),
        'port' => (int) bugcatcher_config('MAIL_PORT', 587),
        'username' => trim((string) bugcatcher_config('MAIL_USERNAME', '')),
        'password' => (string) bugcatcher_config('MAIL_PASSWORD', ''),
        'encryption' => strtolower(trim((string) bugcatcher_config('MAIL_ENCRYPTION', 'tls'))),
        'from_address' => bugcatcher_mail_from_address(),
        'from_name' => bugcatcher_mail_from_name(),
    ];
}

function bugcatcher_mail_validate_config(?string $mailer = null): ?string
{
    $config = bugcatcher_mail_normalized_config();
    $selectedMailer = strtolower(trim((string) ($mailer ?? $config['mailer'] ?? '')));

    if (!in_array($selectedMailer, ['preview', 'smtp'], true)) {
        return 'Email delivery driver is invalid.';
    }

    if ($selectedMailer === 'preview') {
        return null;
    }

    if (!bugcatcher_mail_dependency_available()) {
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
