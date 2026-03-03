<?php

const BUGCATCHER_SHARED_CONFIG_PATH = '/var/www/bugcatcher/shared/config.php';

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
    $paths[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'local.php';

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

function bugcatcher_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    $baseUrl = (string) bugcatcher_config('APP_BASE_URL', '');
    return stripos($baseUrl, 'https://') === 0;
}

function bugcatcher_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => bugcatcher_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

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
