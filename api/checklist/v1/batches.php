<?php

require_once __DIR__ . '/_shared.php';

$method = checklist_api_require_methods(['GET', 'POST']);

if ($method === 'GET') {
    $projectId = checklist_api_get_int($_GET, 'project_id');
    $status = trim((string) ($_GET['status'] ?? ''));
    $search = trim((string) ($_GET['q'] ?? ''));

    $batches = webtest_checklist_fetch_batches($conn, (int) $context['org_id'], $projectId, $status, $search);
    checklist_api_json_response(200, [
        'batches' => $batches,
    ]);
}

checklist_api_require_manager($context);
$payload = checklist_api_json_body(true);
$batch = checklist_api_create_batch($conn, $context, $payload);

checklist_api_json_response(201, [
    'batch' => $batch,
]);

