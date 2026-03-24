<?php

require_once __DIR__ . '/_shared.php';

$method = checklist_api_require_methods(['GET', 'PATCH', 'DELETE']);
$itemId = checklist_api_require_id_from_query('id');

if ($method === 'GET') {
    $item = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
    if (!bugcatcher_checklist_user_can_work_item($context, $item)) {
        checklist_api_json_error(403, 'forbidden', 'You cannot view this checklist item.');
    }
    $attachments = bugcatcher_checklist_fetch_item_attachments($conn, $itemId);
    $response = [
        'item' => $item,
        'attachments' => $attachments,
    ];
    if (bugcatcher_checklist_is_manager_role((string) $context['org_role'])) {
        $response['assignable_testers'] = array_map(static function (array $member): array {
            return [
                'user_id' => (int) ($member['id'] ?? 0),
                'username' => (string) ($member['username'] ?? ''),
                'role' => (string) ($member['role'] ?? ''),
            ];
        }, bugcatcher_checklist_fetch_org_members($conn, (int) $context['org_id'], ['QA Tester']));
    }
    checklist_api_json_response(200, $response);
}

if ($method === 'PATCH') {
    $payload = checklist_api_json_body(true);
    $item = checklist_api_patch_item($conn, $context, $itemId, $payload);
    checklist_api_json_response(200, [
        'item' => $item,
    ]);
}

checklist_api_require_manager($context);

checklist_api_delete_item($conn, (int) $context['org_id'], $itemId);
checklist_api_json_response(200, [
    'deleted' => true,
    'id' => $itemId,
]);
