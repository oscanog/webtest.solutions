<?php

require_once __DIR__ . '/_shared.php';

webtest_openclaw_require_internal_request();
$payload = webtest_openclaw_json_request_body();

try {
    $result = webtest_openclaw_create_batch_from_payload($conn, $payload);
} catch (Throwable $e) {
    webtest_openclaw_json_response(422, ['error' => $e->getMessage()]);
}

webtest_openclaw_json_response(201, $result);
