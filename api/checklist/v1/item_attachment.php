<?php

require_once __DIR__ . '/_shared.php';

checklist_api_require_methods(['DELETE']);
$attachmentId = checklist_api_require_id_from_query('id');

$attachment = checklist_api_find_item_attachment_or_404($conn, (int) $context['org_id'], $attachmentId);
$item = checklist_api_find_item_or_404($conn, (int) $context['org_id'], (int) $attachment['item_id']);

if (!webtest_checklist_user_can_work_item($context, $item)) {
    checklist_api_json_error(403, 'forbidden', 'You cannot delete attachments from this item.');
}

webtest_checklist_delete_attachment($conn, $attachment);
checklist_api_json_response(200, [
    'deleted' => true,
    'id' => $attachmentId,
]);

