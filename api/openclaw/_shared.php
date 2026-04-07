<?php

require_once dirname(__DIR__, 2) . '/app/openclaw_lib.php';

header('Content-Type: application/json');

try {
    $conn = webtest_db_connection();
} catch (RuntimeException $e) {
    webtest_openclaw_json_response(500, ['error' => $e->getMessage()]);
}
