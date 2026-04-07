<?php

require_once __DIR__ . '/_shared.php';

webtest_openclaw_require_internal_request();
$payload = webtest_openclaw_json_request_body();
$orgId = ctype_digit((string) ($payload['org_id'] ?? '')) ? (int) $payload['org_id'] : 0;
$projectId = ctype_digit((string) ($payload['project_id'] ?? '')) ? (int) $payload['project_id'] : 0;
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

if ($orgId <= 0 || $projectId <= 0 || !$items) {
    webtest_openclaw_json_response(422, ['error' => 'org_id, project_id, and items are required.']);
}

$project = webtest_checklist_fetch_project($conn, $orgId, $projectId);
if (!$project) {
    webtest_openclaw_json_response(404, ['error' => 'Project not found in the provided organization.']);
}

webtest_openclaw_json_response(200, [
    'project_id' => $projectId,
    'duplicates' => webtest_openclaw_find_duplicates($conn, $projectId, $items),
]);
