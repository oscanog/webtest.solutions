<?php

require_once __DIR__ . '/_shared.php';

$method = checklist_api_require_methods(['GET', 'PATCH', 'DELETE']);
$batchId = checklist_api_require_id_from_query('id');

if ($method === 'GET') {
    $batch = checklist_api_find_batch_or_404($conn, (int) $context['org_id'], $batchId);
    $items = bugcatcher_checklist_fetch_items_for_batch($conn, $batchId);
    $attachments = bugcatcher_openclaw_fetch_batch_attachments($conn, $batchId);
    $response = [
        'batch' => $batch,
        'items' => $items,
        'attachments' => $attachments,
    ];
    if (bugcatcher_checklist_is_manager_role((string) $context['org_role'])) {
        $response['assignable_qa_leads'] = array_map(static function (array $member): array {
            return [
                'user_id' => (int) ($member['id'] ?? 0),
                'username' => (string) ($member['username'] ?? ''),
                'role' => (string) ($member['role'] ?? ''),
            ];
        }, bugcatcher_checklist_fetch_org_members($conn, (int) $context['org_id'], ['QA Lead']));
    }

    checklist_api_json_response(200, $response);
}

checklist_api_require_manager($context);

if ($method === 'PATCH') {
    $payload = checklist_api_json_body(true);
    $batch = checklist_api_update_batch($conn, $context, $batchId, $payload);
    checklist_api_json_response(200, [
        'batch' => $batch,
    ]);
}

checklist_api_delete_batch($conn, (int) $context['org_id'], $batchId);
checklist_api_json_response(200, [
    'deleted' => true,
    'id' => $batchId,
]);
