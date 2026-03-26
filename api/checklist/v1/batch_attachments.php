<?php

require_once __DIR__ . '/_shared.php';

checklist_api_require_methods(['POST']);
checklist_api_require_manager($context);

$batchId = checklist_api_get_int($_POST, 'batch_id');
if ($batchId <= 0) {
    checklist_api_json_error(422, 'invalid_batch', 'batch_id is required.');
}
checklist_api_find_batch_or_404($conn, (int) $context['org_id'], $batchId);

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
    if (checklist_api_store_uploaded_batch_file($conn, $batchId, $tmp, $name, $size, (int) $context['current_user_id'])) {
        $uploadedCount++;
    } else {
        $failed[] = [
            'name' => $name,
            'error' => 'invalid_file',
        ];
    }
}

if ($uploadedCount <= 0) {
    checklist_api_json_error(422, 'upload_failed', 'No valid batch attachments were uploaded.', $failed);
}

$attachments = bugcatcher_openclaw_fetch_batch_attachments($conn, $batchId);
checklist_api_json_response(200, [
    'uploaded_count' => $uploadedCount,
    'failed' => $failed,
    'attachments' => $attachments,
]);
