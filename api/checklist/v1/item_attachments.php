<?php

require_once __DIR__ . '/_shared.php';

checklist_api_require_methods(['POST']);

$itemId = checklist_api_get_int($_POST, 'item_id');
if ($itemId <= 0) {
    checklist_api_json_error(422, 'invalid_item', 'item_id is required.');
}

$item = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
if (!bugcatcher_checklist_user_can_work_item($context, $item)) {
    checklist_api_json_error(403, 'forbidden', 'You cannot upload attachments to this item.');
}

$uploads = checklist_api_uploaded_files('attachments');
if ($uploads === null) {
    checklist_api_json_error(422, 'validation_error', 'Select at least one file.');
}

$uploadedCount = 0;
$failed = [];
for ($i = 0; $i < count($uploads['name']); $i++) {
    $errCode = $uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    if ($errCode !== UPLOAD_ERR_OK) {
        $failed[] = [
            'name' => (string) ($uploads['name'][$i] ?? 'attachment'),
            'error' => $errCode,
        ];
        continue;
    }

    $tmp = (string) ($uploads['tmp_name'][$i] ?? '');
    $name = (string) ($uploads['name'][$i] ?? 'attachment');
    $size = (int) ($uploads['size'][$i] ?? 0);
    if (bugcatcher_checklist_store_uploaded_file($conn, $itemId, $tmp, $name, $size, true, (int) $context['current_user_id'])) {
        $uploadedCount++;
    } else {
        $failed[] = [
            'name' => $name,
            'error' => 'invalid_file',
        ];
    }
}

if ($uploadedCount <= 0) {
    checklist_api_json_error(422, 'upload_failed', 'No valid attachments were uploaded.', $failed);
}

$item = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
checklist_api_auto_create_issue_if_needed($conn, $context, $item);

$attachments = bugcatcher_checklist_fetch_item_attachments($conn, $itemId);
checklist_api_json_response(200, [
    'uploaded_count' => $uploadedCount,
    'failed' => $failed,
    'attachments' => $attachments,
]);
