<?php

require_once __DIR__ . '/_shared.php';

bugcatcher_openclaw_require_internal_request();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bugcatcher_openclaw_json_response(405, ['error' => 'Method not allowed.']);
}

$payload = bugcatcher_openclaw_json_request_body();
$status = bugcatcher_openclaw_record_runtime_status($conn, $payload);

bugcatcher_openclaw_json_response(200, [
    'ok' => true,
    'runtime_status' => $status,
    'pending_reload_request' => bugcatcher_openclaw_fetch_pending_reload_request($conn),
]);
