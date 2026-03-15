<?php

const BUGCATCHER_SHARED_CONFIG_PATH = '/var/www/bugcatcher/shared/config.php';
const BUGCATCHER_INFRA_LOCAL_CONFIG_PATH = __DIR__ . '/../infra/config/local.php';
const BUGCATCHER_LEGACY_LOCAL_CONFIG_PATH = __DIR__ . '/../config/local.php';
const BUGCATCHER_KNOWN_USER_COOKIE = 'bugcatcher_known_user';

function bugcatcher_default_config(): array
{
    return [
        'APP_ENV' => 'development',
        'APP_BASE_URL' => 'http://localhost',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => 3306,
        'DB_NAME' => 'bug_catcher',
        'DB_USER' => 'root',
        'DB_PASS' => '',
        'UPLOADS_DIR' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'issues',
        'UPLOADS_URL' => 'uploads/issues',
        'CHECKLIST_UPLOADS_DIR' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'checklists',
        'CHECKLIST_UPLOADS_URL' => 'uploads/checklists',
        'CHECKLIST_BOT_SHARED_SECRET' => 'replace-me',
        'OPENCLAW_INTERNAL_SHARED_SECRET' => 'replace-me-too',
        'OPENCLAW_ENCRYPTION_KEY' => 'replace-with-32-byte-secret',
        'OPENCLAW_TEMP_UPLOAD_DIR' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'openclaw-tmp',
        'OPENCLAW_LOG_LEVEL' => 'info',
        'MAIL_MAILER' => '',
        'MAIL_HOST' => 'smtp.gmail.com',
        'MAIL_PORT' => 587,
        'MAIL_USERNAME' => '',
        'MAIL_PASSWORD' => '',
        'MAIL_ENCRYPTION' => 'tls',
        'MAIL_FROM_ADDRESS' => '',
        'MAIL_FROM_EMAIL' => 'no-reply@example.com',
        'MAIL_FROM_NAME' => 'BugCatcher',
        'PASSWORD_RESET_OTP_TTL_SECONDS' => 600,
        'PASSWORD_RESET_RESEND_COOLDOWN_SECONDS' => 60,
        'PASSWORD_RESET_MAX_VERIFY_ATTEMPTS' => 5,
        'PASSWORD_RESET_MAX_RESENDS' => 3,
    ];
}

function bugcatcher_candidate_config_paths(): array
{
    $paths = [];
    $envPath = getenv('BUGCATCHER_CONFIG_PATH');
    if (is_string($envPath) && $envPath !== '') {
        $paths[] = $envPath;
    }

    $paths[] = BUGCATCHER_SHARED_CONFIG_PATH;
    $paths[] = BUGCATCHER_LEGACY_LOCAL_CONFIG_PATH;
    $paths[] = BUGCATCHER_INFRA_LOCAL_CONFIG_PATH;

    return array_values(array_unique($paths));
}

function bugcatcher_load_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = bugcatcher_default_config();

    foreach (bugcatcher_candidate_config_paths() as $path) {
        if (!is_file($path)) {
            continue;
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException("Config file must return an array: {$path}");
        }

        $config = array_merge($config, $loaded);
        break;
    }

    $config['DB_PORT'] = (int) ($config['DB_PORT'] ?? 3306);
    $config['UPLOADS_URL'] = trim(str_replace('\\', '/', (string) ($config['UPLOADS_URL'] ?? 'uploads/issues')), '/');
    $config['UPLOADS_DIR'] = rtrim((string) ($config['UPLOADS_DIR'] ?? ''), "\\/");
    $config['CHECKLIST_UPLOADS_URL'] = trim(str_replace('\\', '/', (string) ($config['CHECKLIST_UPLOADS_URL'] ?? 'uploads/checklists')), '/');
    $config['CHECKLIST_UPLOADS_DIR'] = rtrim((string) ($config['CHECKLIST_UPLOADS_DIR'] ?? ''), "\\/");
    $config['CHECKLIST_BOT_SHARED_SECRET'] = (string) ($config['CHECKLIST_BOT_SHARED_SECRET'] ?? '');
    $config['OPENCLAW_INTERNAL_SHARED_SECRET'] = (string) ($config['OPENCLAW_INTERNAL_SHARED_SECRET'] ?? '');
    $config['OPENCLAW_ENCRYPTION_KEY'] = (string) ($config['OPENCLAW_ENCRYPTION_KEY'] ?? '');
    $config['OPENCLAW_TEMP_UPLOAD_DIR'] = rtrim((string) ($config['OPENCLAW_TEMP_UPLOAD_DIR'] ?? ''), "\\/");
    $config['OPENCLAW_LOG_LEVEL'] = (string) ($config['OPENCLAW_LOG_LEVEL'] ?? 'info');
    $config['MAIL_MAILER'] = strtolower(trim((string) ($config['MAIL_MAILER'] ?? '')));
    $config['MAIL_HOST'] = trim((string) ($config['MAIL_HOST'] ?? ''));
    $config['MAIL_PORT'] = (int) ($config['MAIL_PORT'] ?? 587);
    $config['MAIL_USERNAME'] = trim((string) ($config['MAIL_USERNAME'] ?? ''));
    $config['MAIL_PASSWORD'] = (string) ($config['MAIL_PASSWORD'] ?? '');
    $config['MAIL_ENCRYPTION'] = strtolower(trim((string) ($config['MAIL_ENCRYPTION'] ?? 'tls')));
    $config['MAIL_FROM_ADDRESS'] = trim((string) ($config['MAIL_FROM_ADDRESS'] ?? ''));
    if ($config['MAIL_FROM_ADDRESS'] === '') {
        $config['MAIL_FROM_ADDRESS'] = trim((string) ($config['MAIL_FROM_EMAIL'] ?? ''));
    }
    $config['MAIL_FROM_EMAIL'] = $config['MAIL_FROM_ADDRESS'];
    $config['MAIL_FROM_NAME'] = trim((string) ($config['MAIL_FROM_NAME'] ?? 'BugCatcher'));
    $config['PASSWORD_RESET_OTP_TTL_SECONDS'] = max(60, (int) ($config['PASSWORD_RESET_OTP_TTL_SECONDS'] ?? 600));
    $config['PASSWORD_RESET_RESEND_COOLDOWN_SECONDS'] = max(0, (int) ($config['PASSWORD_RESET_RESEND_COOLDOWN_SECONDS'] ?? 60));
    $config['PASSWORD_RESET_MAX_VERIFY_ATTEMPTS'] = max(1, (int) ($config['PASSWORD_RESET_MAX_VERIFY_ATTEMPTS'] ?? 5));
    $config['PASSWORD_RESET_MAX_RESENDS'] = max(0, (int) ($config['PASSWORD_RESET_MAX_RESENDS'] ?? 3));

    return $config;
}

function bugcatcher_config(?string $key = null, $default = null)
{
    $config = bugcatcher_load_config();
    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}

function bugcatcher_base_url(): string
{
    return rtrim((string) bugcatcher_config('APP_BASE_URL', ''), '/');
}

function bugcatcher_base_path(): string
{
    $path = parse_url(bugcatcher_base_url(), PHP_URL_PATH);
    if (!is_string($path)) {
        return '';
    }

    $path = trim($path, '/');
    return ($path === '') ? '' : '/' . $path;
}

function bugcatcher_path(string $path = ''): string
{
    $basePath = bugcatcher_base_path();
    $normalized = ltrim($path, '/');

    if ($normalized === '') {
        return ($basePath !== '') ? $basePath : '/';
    }

    return ($basePath !== '' ? $basePath : '') . '/' . $normalized;
}

function bugcatcher_href(string $href): string
{
    if ($href === '') {
        return bugcatcher_path();
    }

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $href)) {
        return $href;
    }

    if ($href[0] === '#' || $href[0] === '?') {
        return $href;
    }

    if (str_starts_with($href, '/')) {
        return bugcatcher_path(ltrim($href, '/'));
    }

    return $href;
}

function bugcatcher_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    $baseUrl = bugcatcher_base_url();
    return stripos($baseUrl, 'https://') === 0;
}

function bugcatcher_cookie_options(int $expires = 0): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'domain' => '',
        'secure' => bugcatcher_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function bugcatcher_mark_known_user_browser(): void
{
    setcookie(BUGCATCHER_KNOWN_USER_COOKIE, '1', bugcatcher_cookie_options(time() + (60 * 60 * 24 * 30)));
    $_COOKIE[BUGCATCHER_KNOWN_USER_COOKIE] = '1';
}

function bugcatcher_clear_known_user_browser(): void
{
    setcookie(BUGCATCHER_KNOWN_USER_COOKIE, '', bugcatcher_cookie_options(time() - 3600));
    unset($_COOKIE[BUGCATCHER_KNOWN_USER_COOKIE]);
}

function bugcatcher_is_known_user_browser(): bool
{
    return ($_COOKIE[BUGCATCHER_KNOWN_USER_COOKIE] ?? '') === '1';
}

function bugcatcher_normalize_system_role(?string $role): string
{
    return in_array($role, ['super_admin', 'admin', 'user'], true) ? (string) $role : 'user';
}

function bugcatcher_is_super_admin_role(?string $role): bool
{
    return bugcatcher_normalize_system_role($role) === 'super_admin';
}

function bugcatcher_is_system_admin_role(?string $role): bool
{
    return in_array(bugcatcher_normalize_system_role($role), ['super_admin', 'admin'], true);
}

function bugcatcher_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $cookieParams = bugcatcher_cookie_options();
    $cookieParams['lifetime'] = 0;
    unset($cookieParams['expires']);
    session_set_cookie_params($cookieParams);

    session_start();
}

function bugcatcher_db_connection(): mysqli
{
    $conn = new mysqli(
        (string) bugcatcher_config('DB_HOST'),
        (string) bugcatcher_config('DB_USER'),
        (string) bugcatcher_config('DB_PASS'),
        (string) bugcatcher_config('DB_NAME'),
        (int) bugcatcher_config('DB_PORT')
    );

    if ($conn->connect_error) {
        $isProduction = bugcatcher_config('APP_ENV') === 'production';
        $message = $isProduction
            ? 'Database connection failed.'
            : 'Database connection failed: ' . $conn->connect_error;
        throw new RuntimeException($message);
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

function bugcatcher_uploads_dir(): string
{
    return (string) bugcatcher_config('UPLOADS_DIR');
}

function bugcatcher_uploads_url(): string
{
    return (string) bugcatcher_config('UPLOADS_URL', 'uploads/issues');
}

function bugcatcher_upload_path_prefix(): string
{
    return bugcatcher_uploads_url() . '/';
}

function bugcatcher_upload_relative_path(string $fileName): string
{
    return bugcatcher_upload_path_prefix() . ltrim(str_replace('\\', '/', $fileName), '/');
}

function bugcatcher_upload_absolute_path(string $storedPath): ?string
{
    $baseDir = realpath(bugcatcher_uploads_dir());
    if ($baseDir === false) {
        return null;
    }

    $normalized = str_replace('\\', '/', $storedPath);
    $prefix = bugcatcher_upload_path_prefix();
    if (strpos($normalized, $prefix) === 0) {
        $normalized = substr($normalized, strlen($prefix));
    }

    $candidate = realpath($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized));
    if ($candidate === false) {
        return null;
    }

    if (strpos($candidate, $baseDir) !== 0 || !is_file($candidate)) {
        return null;
    }

    return $candidate;
}

function bugcatcher_checklist_uploads_dir(): string
{
    return (string) bugcatcher_config('CHECKLIST_UPLOADS_DIR');
}

function bugcatcher_checklist_uploads_url(): string
{
    return (string) bugcatcher_config('CHECKLIST_UPLOADS_URL', 'uploads/checklists');
}

function bugcatcher_checklist_upload_path_prefix(): string
{
    return bugcatcher_checklist_uploads_url() . '/';
}

function bugcatcher_checklist_upload_relative_path(string $fileName): string
{
    return bugcatcher_checklist_upload_path_prefix() . ltrim(str_replace('\\', '/', $fileName), '/');
}

function bugcatcher_checklist_upload_absolute_path(string $storedPath): ?string
{
    $baseDir = realpath(bugcatcher_checklist_uploads_dir());
    if ($baseDir === false) {
        return null;
    }

    $normalized = str_replace('\\', '/', $storedPath);
    $prefix = bugcatcher_checklist_upload_path_prefix();
    if (strpos($normalized, $prefix) === 0) {
        $normalized = substr($normalized, strlen($prefix));
    }

    $candidate = realpath($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized));
    if ($candidate === false) {
        return null;
    }

    if (strpos($candidate, $baseDir) !== 0 || !is_file($candidate)) {
        return null;
    }

    return $candidate;
}

function bugcatcher_openclaw_temp_dir(): string
{
    return (string) bugcatcher_config('OPENCLAW_TEMP_UPLOAD_DIR');
}
