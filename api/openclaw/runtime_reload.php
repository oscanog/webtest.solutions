<?php

require_once __DIR__ . '/_shared.php';

bugcatcher_openclaw_require_internal_request();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bugcatcher_openclaw_json_response(405, ['error' => 'Method not allowed.']);
}

$payload = bugcatcher_openclaw_json_request_body();
$reason = trim((string) ($payload['reason'] ?? 'manual_runtime_reload'));
$requestedByUserId = isset($payload['requested_by_user_id']) ? (int) $payload['requested_by_user_id'] : 0;
$reloadRequestId = bugcatcher_openclaw_queue_reload_request($conn, $requestedByUserId > 0 ? $requestedByUserId : null, $reason);

bugcatcher_openclaw_json_response(202, [
    'queued' => true,
    'reload_request_id' => $reloadRequestId,
    'pending_reload_request' => bugcatcher_openclaw_fetch_pending_reload_request($conn),
]);
