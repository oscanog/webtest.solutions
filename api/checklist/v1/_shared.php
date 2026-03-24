<?php

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/checklist_lib.php';
require_once dirname(__DIR__, 3) . '/app/openclaw_lib.php';
require_once dirname(__DIR__, 3) . '/app/checklist_api_lib.php';

bugcatcher_start_session();
header('Content-Type: application/json');

try {
    $conn = bugcatcher_db_connection();
} catch (RuntimeException $e) {
    checklist_api_json_error(500, 'db_connection_failed', $e->getMessage());
}

bugcatcher_checklist_ensure_schema($conn);
$context = checklist_api_require_context($conn);
