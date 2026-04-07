<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$internalSecret = trim((string) webtest_config('REALTIME_NOTIFICATIONS_INTERNAL_SHARED_SECRET', ''));
if ($internalSecret === '') {
    $internalSecret = trim((string) webtest_config('OPENCLAW_INTERNAL_SHARED_SECRET', ''));
}
if ($internalSecret === '' || $internalSecret === 'replace-me-too') {
    $internalSecret = 'webtest-realtime-dev-secret';
}

$socketSecret = trim((string) webtest_config('REALTIME_NOTIFICATIONS_SOCKET_SECRET', ''));
if ($socketSecret === '') {
    $socketSecret = trim((string) webtest_config('OPENCLAW_ENCRYPTION_KEY', ''));
}
if ($socketSecret === '' || $socketSecret === 'replace-with-32-byte-secret') {
    $socketSecret = trim((string) webtest_config('OPENCLAW_INTERNAL_SHARED_SECRET', ''));
}
if ($socketSecret === '' || $socketSecret === 'replace-me-too') {
    $socketSecret = 'webtest-realtime-dev-secret';
}

echo json_encode([
    'enabled' => (bool) webtest_config('REALTIME_NOTIFICATIONS_ENABLED', true),
    'host' => (string) webtest_config('REALTIME_NOTIFICATIONS_HOST', '127.0.0.1'),
    'port' => (int) webtest_config('REALTIME_NOTIFICATIONS_PORT', 8090),
    'path' => (string) webtest_config('REALTIME_NOTIFICATIONS_PATH', '/ws/notifications'),
    'internal_shared_secret' => $internalSecret,
    'socket_secret' => $socketSecret,
], JSON_UNESCAPED_SLASHES);
