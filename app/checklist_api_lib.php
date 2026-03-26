<?php

require_once __DIR__ . '/notification_lib.php';

function checklist_api_json_response(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    echo json_encode([
        'ok' => true,
        'data' => bugcatcher_augment_datetime_iso_fields($data),
    ]);
    exit;
}

function checklist_api_json_error(int $statusCode, string $code, string $message, $details = null): void
{
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== null) {
        $error['details'] = $details;
    }

    http_response_code($statusCode);
    echo json_encode([
        'ok' => false,
        'error' => $error,
    ]);
    exit;
}

function checklist_api_require_methods(array $allowedMethods): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $normalized = array_map('strtoupper', $allowedMethods);
    if (!in_array($method, $normalized, true)) {
        checklist_api_json_error(405, 'method_not_allowed', 'Method not allowed.', ['allowed' => $normalized]);
    }

    return $method;
}

function checklist_api_json_body(bool $required = false): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        if ($required) {
            checklist_api_json_error(400, 'invalid_json', 'Request body must be valid JSON.');
        }
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        checklist_api_json_error(400, 'invalid_json', 'Request body must be valid JSON.');
    }

    return $decoded;
}

function checklist_api_get_int(array $source, string $key, int $default = 0): int
{
    $value = $source[$key] ?? null;
    if (is_int($value)) {
        return $value;
    }

    if (!is_string($value) && !is_numeric($value)) {
        return $default;
    }

    $stringValue = (string) $value;
    return ctype_digit($stringValue) ? (int) $stringValue : $default;
}

function checklist_api_require_id_from_query(string $key = 'id'): int
{
    $id = checklist_api_get_int($_GET, $key, 0);
    if ($id <= 0) {
        checklist_api_json_error(422, 'invalid_id', "{$key} must be a positive integer.");
    }
    return $id;
}

function checklist_api_require_context(mysqli $conn): array
{
    $userId = (int) ($_SESSION['id'] ?? 0);
    if ($userId <= 0) {
        checklist_api_json_error(401, 'unauthorized', 'Login session is required.');
    }

    $body = checklist_api_json_body(false);
    if (!$body && !empty($_POST)) {
        $body = $_POST;
    }

    $requestedOrgId = checklist_api_get_int($_GET, 'org_id', 0);
    if ($requestedOrgId <= 0) {
        $requestedOrgId = checklist_api_get_int($body, 'org_id', 0);
    }

    $activeOrgId = $requestedOrgId > 0 ? $requestedOrgId : (int) ($_SESSION['active_org_id'] ?? 0);
    if ($activeOrgId <= 0) {
        checklist_api_json_error(403, 'org_context_required', 'Active organization is required.');
    }

    $stmt = $conn->prepare("
        SELECT om.role, o.name AS org_name, o.owner_id
        FROM org_members om
        JOIN organizations o ON o.id = om.org_id
        WHERE om.org_id = ? AND om.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $activeOrgId, $userId);
    $stmt->execute();
    $membership = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$membership) {
        checklist_api_json_error(403, 'org_membership_required', 'You are not a member of the active organization.');
    }

    return [
        'org_id' => $activeOrgId,
        'org_name' => $membership['org_name'],
        'org_owner_id' => (int) $membership['owner_id'],
        'org_role' => (string) $membership['role'],
        'is_org_owner' => (int) $membership['owner_id'] === $userId,
        'current_user_id' => $userId,
        'current_username' => (string) ($_SESSION['username'] ?? 'User'),
        'current_role' => bugcatcher_normalize_system_role((string) ($_SESSION['role'] ?? 'user')),
    ];
}

function checklist_api_uploaded_files(string $field): ?array
{
    foreach ([$field, $field . '[]'] as $candidate) {
        $files = $_FILES[$candidate] ?? null;
        if (!is_array($files)) {
            continue;
        }

        if (is_array($files['name'] ?? null)) {
            return $files;
        }

        $singleName = trim((string) ($files['name'] ?? ''));
        if ($singleName !== '') {
            return [
                'name' => [$singleName],
                'type' => [(string) ($files['type'] ?? '')],
                'tmp_name' => [(string) ($files['tmp_name'] ?? '')],
                'error' => [(int) ($files['error'] ?? UPLOAD_ERR_NO_FILE)],
                'size' => [(int) ($files['size'] ?? 0)],
            ];
        }
    }

    return null;
}

function checklist_api_require_manager(array $context): void
{
    if (!bugcatcher_checklist_is_manager_role((string) $context['org_role'])) {
        checklist_api_json_error(403, 'forbidden', 'Only checklist managers can perform this action.');
    }
}

function checklist_api_batch_link_path(int $batchId): string
{
    return '/app/checklist/batches/' . $batchId;
}

function checklist_api_item_link_path(int $itemId): string
{
    return '/app/checklist/items/' . $itemId;
}

function checklist_api_notify_batch(mysqli $conn, array $batch, array $payload, array $recipientUserIds): void
{
    bugcatcher_notifications_send($conn, $recipientUserIds, [
        'type' => 'checklist',
        'event_key' => (string) ($payload['event_key'] ?? 'checklist_updated'),
        'title' => (string) ($payload['title'] ?? 'Checklist updated'),
        'body' => (string) ($payload['body'] ?? ''),
        'severity' => (string) ($payload['severity'] ?? 'default'),
        'link_path' => checklist_api_batch_link_path((int) $batch['id']),
        'actor_user_id' => (int) ($payload['actor_user_id'] ?? 0),
        'org_id' => (int) ($batch['org_id'] ?? 0),
        'project_id' => (int) ($batch['project_id'] ?? 0),
        'checklist_batch_id' => (int) ($batch['id'] ?? 0),
        'checklist_item_id' => max(0, (int) ($payload['checklist_item_id'] ?? 0)),
        'meta' => $payload['meta'] ?? null,
    ]);
}

function checklist_api_notify_item(mysqli $conn, array $item, array $payload, array $recipientUserIds): void
{
    bugcatcher_notifications_send($conn, $recipientUserIds, [
        'type' => 'checklist',
        'event_key' => (string) ($payload['event_key'] ?? 'checklist_item_updated'),
        'title' => (string) ($payload['title'] ?? 'Checklist item updated'),
        'body' => (string) ($payload['body'] ?? ''),
        'severity' => (string) ($payload['severity'] ?? 'default'),
        'link_path' => (string) ($payload['link_path'] ?? checklist_api_item_link_path((int) ($item['id'] ?? 0))),
        'actor_user_id' => (int) ($payload['actor_user_id'] ?? 0),
        'org_id' => (int) ($item['org_id'] ?? 0),
        'project_id' => (int) ($item['project_id'] ?? 0),
        'checklist_batch_id' => (int) ($item['batch_id'] ?? 0),
        'checklist_item_id' => (int) ($item['id'] ?? 0),
        'meta' => $payload['meta'] ?? null,
    ]);
}

function checklist_api_find_batch_or_404(mysqli $conn, int $orgId, int $batchId): array
{
    $batch = bugcatcher_checklist_fetch_batch($conn, $orgId, $batchId);
    if (!$batch) {
        checklist_api_json_error(404, 'batch_not_found', 'Checklist batch not found.');
    }

    return $batch;
}

function checklist_api_find_item_or_404(mysqli $conn, int $orgId, int $itemId): array
{
    $item = bugcatcher_checklist_fetch_item($conn, $orgId, $itemId);
    if (!$item) {
        checklist_api_json_error(404, 'item_not_found', 'Checklist item not found.');
    }

    return $item;
}

function checklist_api_find_item_attachment_or_404(mysqli $conn, int $orgId, int $attachmentId): array
{
    $stmt = $conn->prepare("
        SELECT ca.*,
               ci.id AS item_id,
               ci.org_id,
               ci.assigned_to_user_id,
               ci.status AS item_status
        FROM checklist_attachments ca
        JOIN checklist_items ci ON ci.id = ca.checklist_item_id
        WHERE ca.id = ? AND ci.org_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $attachmentId, $orgId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        checklist_api_json_error(404, 'attachment_not_found', 'Checklist item attachment not found.');
    }

    return $row;
}

function checklist_api_find_batch_attachment_or_404(mysqli $conn, int $orgId, int $attachmentId): array
{
    $stmt = $conn->prepare("
        SELECT cba.*, cb.org_id
        FROM checklist_batch_attachments cba
        JOIN checklist_batches cb ON cb.id = cba.checklist_batch_id
        WHERE cba.id = ? AND cb.org_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $attachmentId, $orgId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        checklist_api_json_error(404, 'batch_attachment_not_found', 'Checklist batch attachment not found.');
    }

    return $row;
}

function checklist_api_validate_batch_payload(mysqli $conn, array $context, array $payload): array
{
    $projectId = checklist_api_get_int($payload, 'project_id');
    $title = trim((string) ($payload['title'] ?? ''));
    $moduleName = trim((string) ($payload['module_name'] ?? ''));
    $submoduleName = trim((string) ($payload['submodule_name'] ?? ''));
    $status = bugcatcher_checklist_normalize_enum(
        (string) ($payload['status'] ?? 'open'),
        BUGCATCHER_BATCH_STATUSES,
        'open'
    );
    $assignedQaLeadId = checklist_api_get_int($payload, 'assigned_qa_lead_id');
    $notes = trim((string) ($payload['notes'] ?? ''));
    $pageUrlInput = trim((string) ($payload['page_url'] ?? ''));
    $pageUrl = bugcatcher_checklist_normalize_page_url($pageUrlInput);

    $project = $projectId > 0 ? bugcatcher_checklist_fetch_project($conn, (int) $context['org_id'], $projectId) : null;
    if (!$project) {
        checklist_api_json_error(422, 'invalid_project', 'Select a valid project in the active organization.');
    }
    if ($title === '' || $moduleName === '') {
        checklist_api_json_error(422, 'validation_error', 'title and module_name are required.');
    }
    if ($pageUrlInput !== '' && $pageUrl === '') {
        checklist_api_json_error(422, 'invalid_page_url', 'page_url must be a valid http:// or https:// URL.');
    }
    if (
        $assignedQaLeadId > 0 &&
        !bugcatcher_checklist_member_has_role($conn, (int) $context['org_id'], $assignedQaLeadId, ['QA Lead'])
    ) {
        checklist_api_json_error(422, 'invalid_qa_lead', 'assigned_qa_lead_id must belong to a QA Lead in this organization.');
    }

    return [
        'project_id' => $projectId,
        'title' => $title,
        'module_name' => $moduleName,
        'submodule_name' => $submoduleName,
        'status' => $status,
        'assigned_qa_lead_id' => $assignedQaLeadId,
        'notes' => $notes,
        'page_url' => $pageUrl,
    ];
}

function checklist_api_create_batch(mysqli $conn, array $context, array $payload): array
{
    $validated = checklist_api_validate_batch_payload($conn, $context, $payload);

    $stmt = $conn->prepare("
        INSERT INTO checklist_batches
            (org_id, project_id, title, module_name, submodule_name, status, created_by, updated_by, assigned_qa_lead_id, notes, page_url)
        VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, NULLIF(?, 0), NULLIF(?, ''), NULLIF(?, ''))
    ");
    $stmt->bind_param(
        'iissssiiiss',
        $context['org_id'],
        $validated['project_id'],
        $validated['title'],
        $validated['module_name'],
        $validated['submodule_name'],
        $validated['status'],
        $context['current_user_id'],
        $context['current_user_id'],
        $validated['assigned_qa_lead_id'],
        $validated['notes'],
        $validated['page_url']
    );
    $stmt->execute();
    $batchId = (int) $conn->insert_id;
    $stmt->close();

    $batch = checklist_api_find_batch_or_404($conn, (int) $context['org_id'], $batchId);
    checklist_api_notify_batch($conn, $batch, [
        'event_key' => 'checklist_batch_created',
        'title' => 'Checklist batch created',
        'body' => (string) $batch['title'],
        'severity' => 'success',
        'actor_user_id' => (int) $context['current_user_id'],
    ], array_values(array_diff(
        bugcatcher_notification_org_manager_ids($conn, (int) $context['org_id']),
        [(int) $context['current_user_id']]
    )));
    return $batch;
}

function checklist_api_update_batch(mysqli $conn, array $context, int $batchId, array $payload): array
{
    checklist_api_find_batch_or_404($conn, (int) $context['org_id'], $batchId);
    $validated = checklist_api_validate_batch_payload($conn, $context, $payload);

    $stmt = $conn->prepare("
        UPDATE checklist_batches
        SET project_id = ?, title = ?, module_name = ?, submodule_name = NULLIF(?, ''),
            status = ?, assigned_qa_lead_id = NULLIF(?, 0), notes = NULLIF(?, ''), page_url = NULLIF(?, ''), updated_by = ?
        WHERE id = ? AND org_id = ?
    ");
    $stmt->bind_param(
        'issssissiii',
        $validated['project_id'],
        $validated['title'],
        $validated['module_name'],
        $validated['submodule_name'],
        $validated['status'],
        $validated['assigned_qa_lead_id'],
        $validated['notes'],
        $validated['page_url'],
        $context['current_user_id'],
        $batchId,
        $context['org_id']
    );
    $stmt->execute();
    $stmt->close();

    $batch = checklist_api_find_batch_or_404($conn, (int) $context['org_id'], $batchId);
    checklist_api_notify_batch($conn, $batch, [
        'event_key' => 'checklist_batch_updated',
        'title' => 'Checklist batch updated',
        'body' => (string) $batch['title'],
        'severity' => 'default',
        'actor_user_id' => (int) $context['current_user_id'],
    ], array_values(array_diff(
        bugcatcher_notification_org_manager_ids($conn, (int) $context['org_id']),
        [(int) $context['current_user_id']]
    )));
    return $batch;
}

function checklist_api_validate_item_payload(mysqli $conn, array $context, array $batch, array $payload, bool $isCreate): array
{
    $sequenceNo = checklist_api_get_int($payload, 'sequence_no');
    if ($isCreate && $sequenceNo <= 0) {
        $sequenceNo = bugcatcher_checklist_next_sequence($conn, (int) $batch['id']);
    }
    if (!$isCreate && $sequenceNo <= 0) {
        $sequenceNo = (int) ($payload['sequence_no'] ?? 0);
    }

    $title = trim((string) ($payload['title'] ?? ''));
    $moduleName = trim((string) ($payload['module_name'] ?? ($batch['module_name'] ?? '')));
    $submoduleName = trim((string) ($payload['submodule_name'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $requiredRole = bugcatcher_checklist_normalize_enum(
        (string) ($payload['required_role'] ?? 'QA Tester'),
        BUGCATCHER_CHECKLIST_ALLOWED_REQUIRED_ROLES,
        'QA Tester'
    );
    $priority = bugcatcher_checklist_normalize_enum(
        (string) ($payload['priority'] ?? 'medium'),
        BUGCATCHER_CHECKLIST_PRIORITIES,
        'medium'
    );
    $assignedToUserId = checklist_api_get_int($payload, 'assigned_to_user_id');

    if ($title === '' || $moduleName === '') {
        checklist_api_json_error(422, 'validation_error', 'title and module_name are required.');
    }
    if (
        $assignedToUserId > 0 &&
        !bugcatcher_checklist_member_has_role($conn, (int) $context['org_id'], $assignedToUserId, ['QA Tester'])
    ) {
        checklist_api_json_error(422, 'invalid_assignee', 'assigned_to_user_id must belong to a QA Tester in the active organization.');
    }

    return [
        'sequence_no' => $sequenceNo,
        'title' => $title,
        'module_name' => $moduleName,
        'submodule_name' => $submoduleName,
        'description' => $description,
        'required_role' => $requiredRole,
        'priority' => $priority,
        'assigned_to_user_id' => $assignedToUserId,
        'full_title' => bugcatcher_checklist_full_title($moduleName, $submoduleName, $title),
    ];
}

function checklist_api_create_item(mysqli $conn, array $context, array $payload): array
{
    $batchId = checklist_api_get_int($payload, 'batch_id');
    if ($batchId <= 0) {
        checklist_api_json_error(422, 'invalid_batch', 'batch_id is required.');
    }

    $batch = checklist_api_find_batch_or_404($conn, (int) $context['org_id'], $batchId);
    $validated = checklist_api_validate_item_payload($conn, $context, $batch, $payload, true);

    try {
        $stmt = $conn->prepare("
            INSERT INTO checklist_items
                (batch_id, org_id, project_id, sequence_no, title, module_name, submodule_name, full_title, description,
                 status, priority, required_role, assigned_to_user_id, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), 'open', ?, ?, NULLIF(?, 0), ?, ?)
        ");
        $stmt->bind_param(
            'iiiisssssssiii',
            $batchId,
            $context['org_id'],
            $batch['project_id'],
            $validated['sequence_no'],
            $validated['title'],
            $validated['module_name'],
            $validated['submodule_name'],
            $validated['full_title'],
            $validated['description'],
            $validated['priority'],
            $validated['required_role'],
            $validated['assigned_to_user_id'],
            $context['current_user_id'],
            $context['current_user_id']
        );
        $stmt->execute();
        $itemId = (int) $conn->insert_id;
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            checklist_api_json_error(409, 'conflict', 'sequence_no already exists in this batch.');
        }
        throw $e;
    }

    $item = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
    $managerRecipients = array_values(array_diff(
        array_unique(bugcatcher_notification_org_manager_ids($conn, (int) $context['org_id'])),
        [(int) $context['current_user_id']]
    ));
    checklist_api_notify_item($conn, $item, [
        'event_key' => 'checklist_item_created',
        'title' => 'Checklist item created',
        'body' => (string) $item['title'],
        'severity' => 'default',
        'actor_user_id' => (int) $context['current_user_id'],
    ], $managerRecipients);

    $assignedToUserId = (int) ($item['assigned_to_user_id'] ?? 0);
    if ($assignedToUserId > 0 && $assignedToUserId !== (int) $context['current_user_id']) {
        checklist_api_notify_item($conn, $item, [
            'event_key' => 'checklist_item_assigned',
            'title' => 'Checklist item assigned',
            'body' => 'You were assigned checklist item "' . (string) $item['title'] . '".',
            'severity' => 'success',
            'actor_user_id' => (int) $context['current_user_id'],
            'meta' => ['assignment' => true],
        ], [$assignedToUserId]);
    }
    return $item;
}

function checklist_api_update_item(mysqli $conn, array $context, int $itemId, array $payload): array
{
    $item = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
    $previousAssignedToUserId = (int) ($item['assigned_to_user_id'] ?? 0);
    $batch = checklist_api_find_batch_or_404($conn, (int) $context['org_id'], (int) $item['batch_id']);
    $payload = array_merge([
        'sequence_no' => (int) $item['sequence_no'],
        'title' => (string) $item['title'],
        'module_name' => (string) $item['module_name'],
        'submodule_name' => (string) ($item['submodule_name'] ?? ''),
        'description' => (string) ($item['description'] ?? ''),
        'required_role' => (string) ($item['required_role'] ?? 'QA Tester'),
        'priority' => (string) ($item['priority'] ?? 'medium'),
        'assigned_to_user_id' => (int) ($item['assigned_to_user_id'] ?? 0),
    ], $payload);
    $validated = checklist_api_validate_item_payload($conn, $context, $batch, $payload, false);

    $stmt = $conn->prepare("
        UPDATE checklist_items
        SET sequence_no = ?, title = ?, module_name = ?, submodule_name = NULLIF(?, ''), full_title = ?,
            description = NULLIF(?, ''), priority = ?, required_role = ?, assigned_to_user_id = NULLIF(?, 0),
            updated_by = ?
        WHERE id = ? AND org_id = ?
    ");
    $stmt->bind_param(
        'isssssssiiii',
        $validated['sequence_no'],
        $validated['title'],
        $validated['module_name'],
        $validated['submodule_name'],
        $validated['full_title'],
        $validated['description'],
        $validated['priority'],
        $validated['required_role'],
        $validated['assigned_to_user_id'],
        $context['current_user_id'],
        $itemId,
        $context['org_id']
    );
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        if ((int) $e->getCode() === 1062) {
            checklist_api_json_error(409, 'conflict', 'sequence_no already exists in this batch.');
        }
        throw $e;
    }
    $stmt->close();

    $updated = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
    checklist_api_auto_create_issue_if_needed($conn, $context, $updated);
    $updated = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
    $managerRecipients = array_values(array_diff(
        array_unique(bugcatcher_notification_org_manager_ids($conn, (int) $context['org_id'])),
        [(int) $context['current_user_id']]
    ));
    checklist_api_notify_item($conn, $updated, [
        'event_key' => 'checklist_item_updated',
        'title' => 'Checklist item updated',
        'body' => (string) $updated['title'],
        'severity' => 'default',
        'actor_user_id' => (int) $context['current_user_id'],
    ], $managerRecipients);

    $assignedToUserId = (int) ($updated['assigned_to_user_id'] ?? 0);
    if (
        $assignedToUserId > 0 &&
        $assignedToUserId !== $previousAssignedToUserId &&
        $assignedToUserId !== (int) $context['current_user_id']
    ) {
        checklist_api_notify_item($conn, $updated, [
            'event_key' => 'checklist_item_assigned',
            'title' => 'Checklist item assigned',
            'body' => 'You were assigned checklist item "' . (string) $updated['title'] . '".',
            'severity' => 'success',
            'actor_user_id' => (int) $context['current_user_id'],
            'meta' => ['assignment' => true],
        ], [$assignedToUserId]);
    }
    return $updated;
}

function checklist_api_patch_item(mysqli $conn, array $context, int $itemId, array $payload): array
{
    if (bugcatcher_checklist_is_manager_role((string) $context['org_role'])) {
        return checklist_api_update_item($conn, $context, $itemId, $payload);
    }

    $item = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
    if (!bugcatcher_checklist_user_can_work_item($context, $item)) {
        checklist_api_json_error(403, 'forbidden', 'You cannot update this item.');
    }

    if (count($payload) !== 1 || !array_key_exists('status', $payload)) {
        checklist_api_json_error(403, 'forbidden', 'Only checklist managers can edit item definitions.');
    }

    return checklist_api_change_item_status($conn, $context, $itemId, (string) ($payload['status'] ?? ''));
}

function checklist_api_auto_create_issue_if_needed(mysqli $conn, array $context, array $item): void
{
    if (in_array((string) $item['status'], ['failed', 'blocked'], true) && (int) ($item['issue_id'] ?? 0) <= 0) {
        bugcatcher_checklist_create_issue_for_item($conn, $item, (int) $context['current_user_id']);
    }
}

function checklist_api_change_item_status(mysqli $conn, array $context, int $itemId, string $newStatus): array
{
    $item = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);

    if (!bugcatcher_checklist_user_can_work_item($context, $item)) {
        checklist_api_json_error(403, 'forbidden', 'You cannot update this item.');
    }

    $normalizedStatus = bugcatcher_checklist_normalize_enum($newStatus, BUGCATCHER_CHECKLIST_STATUSES, (string) $item['status']);
    $allowed = false;
    if (
        in_array((string) $item['status'], ['open', 'in_progress'], true) &&
        in_array($normalizedStatus, ['in_progress', 'passed', 'failed', 'blocked'], true)
    ) {
        $allowed = true;
    }
    if (
        in_array((string) $item['status'], ['failed', 'blocked'], true) &&
        in_array($normalizedStatus, ['in_progress', 'passed'], true) &&
        bugcatcher_checklist_is_manager_role((string) $context['org_role'])
    ) {
        $allowed = true;
    }
    if ($normalizedStatus === (string) $item['status']) {
        $allowed = true;
    }

    if (!$allowed) {
        checklist_api_json_error(422, 'invalid_transition', 'That status transition is not allowed.');
    }

    $startedAt = $item['started_at'];
    $completedAt = $item['completed_at'];
    if ($normalizedStatus === 'in_progress' && !$startedAt) {
        $startedAt = date('Y-m-d H:i:s');
    }
    if (in_array($normalizedStatus, ['passed', 'failed', 'blocked'], true)) {
        $completedAt = date('Y-m-d H:i:s');
    }
    if ($normalizedStatus === 'in_progress') {
        $completedAt = null;
    }

    $stmt = $conn->prepare("
        UPDATE checklist_items
        SET status = ?, started_at = ?, completed_at = ?, updated_by = ?
        WHERE id = ? AND org_id = ?
    ");
    $stmt->bind_param(
        'sssiii',
        $normalizedStatus,
        $startedAt,
        $completedAt,
        $context['current_user_id'],
        $itemId,
        $context['org_id']
    );
    $stmt->execute();
    $stmt->close();

    $updated = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
    checklist_api_auto_create_issue_if_needed($conn, $context, $updated);
    $updated = checklist_api_find_item_or_404($conn, (int) $context['org_id'], $itemId);
    $severity = in_array((string) $updated['status'], ['failed', 'blocked'], true) ? 'alert' : ((string) $updated['status'] === 'passed' ? 'success' : 'default');
    $recipients = array_values(array_diff(array_unique(array_filter([
        (int) ($updated['assigned_to_user_id'] ?? 0),
        ...bugcatcher_notification_org_manager_ids($conn, (int) $context['org_id']),
    ])), [(int) $context['current_user_id']]));
    checklist_api_notify_item($conn, $updated, [
        'event_key' => 'checklist_item_status_changed',
        'title' => 'Checklist item status updated',
        'body' => (string) $updated['title'] . ' is now ' . (string) $updated['status'] . '.',
        'severity' => $severity,
        'actor_user_id' => (int) $context['current_user_id'],
        'meta' => ['status' => (string) $updated['status']],
    ], $recipients);
    return $updated;
}

function checklist_api_delete_item(mysqli $conn, int $orgId, int $itemId): void
{
    bugcatcher_file_storage_ensure_schema($conn);
    $item = checklist_api_find_item_or_404($conn, $orgId, $itemId);
    $attachments = bugcatcher_checklist_fetch_item_attachments($conn, (int) $item['id']);
    $remoteFiles = [];
    $legacyPaths = [];
    foreach ($attachments as $attachment) {
        $storageKey = (string) ($attachment['storage_key'] ?? '');
        if ($storageKey !== '') {
            $remoteFiles[] = $attachment;
        } else {
            $legacyPaths[] = bugcatcher_checklist_upload_absolute_path((string) $attachment['file_path']);
        }
    }

    $stmt = $conn->prepare("DELETE FROM checklist_items WHERE id = ? AND org_id = ?");
    $stmt->bind_param('ii', $itemId, $orgId);
    $stmt->execute();
    $stmt->close();

    $deletedRemote = [];
    foreach ($remoteFiles as $remoteFile) {
        $storageKey = (string) ($remoteFile['storage_key'] ?? '');
        if ($storageKey === '') {
            continue;
        }

        $provider = bugcatcher_file_storage_provider_from_row($remoteFile);
        $deleteKey = $provider . '|' . $storageKey;
        if (isset($deletedRemote[$deleteKey])) {
            continue;
        }

        bugcatcher_file_storage_delete_if_unreferenced(
            $conn,
            $storageKey,
            null,
            null,
            (string) ($remoteFile['file_path'] ?? ''),
            $provider,
            (string) ($remoteFile['mime_type'] ?? '')
        );
        $deletedRemote[$deleteKey] = true;
    }
    foreach ($legacyPaths as $legacyPath) {
        bugcatcher_file_storage_delete_legacy_local($legacyPath);
    }
}

function checklist_api_delete_batch(mysqli $conn, int $orgId, int $batchId): void
{
    bugcatcher_file_storage_ensure_schema($conn);
    checklist_api_find_batch_or_404($conn, $orgId, $batchId);

    $batchAttachments = bugcatcher_openclaw_fetch_batch_attachments($conn, $batchId);
    $remoteFiles = [];
    $legacyPaths = [];
    foreach ($batchAttachments as $attachment) {
        $storageKey = (string) ($attachment['storage_key'] ?? '');
        if ($storageKey !== '') {
            $remoteFiles[] = $attachment;
        } else {
            $legacyPaths[] = bugcatcher_checklist_upload_absolute_path((string) $attachment['file_path']);
        }
    }

    $stmt = $conn->prepare("
        SELECT ca.file_path, ca.storage_key, ca.storage_provider, ca.mime_type
        FROM checklist_attachments ca
        JOIN checklist_items ci ON ci.id = ca.checklist_item_id
        WHERE ci.batch_id = ?
    ");
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $storageKey = (string) ($row['storage_key'] ?? '');
        if ($storageKey !== '') {
            $remoteFiles[] = $row;
        } else {
            $legacyPaths[] = bugcatcher_checklist_upload_absolute_path((string) ($row['file_path'] ?? ''));
        }
    }

    $stmt = $conn->prepare("DELETE FROM checklist_batch_attachments WHERE checklist_batch_id = ?");
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM checklist_batches WHERE id = ? AND org_id = ?");
    $stmt->bind_param('ii', $batchId, $orgId);
    $stmt->execute();
    $stmt->close();

    $deletedRemote = [];
    foreach ($remoteFiles as $remoteFile) {
        $storageKey = (string) ($remoteFile['storage_key'] ?? '');
        if ($storageKey === '') {
            continue;
        }

        $provider = bugcatcher_file_storage_provider_from_row($remoteFile);
        $deleteKey = $provider . '|' . $storageKey;
        if (isset($deletedRemote[$deleteKey])) {
            continue;
        }

        bugcatcher_file_storage_delete_if_unreferenced(
            $conn,
            $storageKey,
            null,
            null,
            (string) ($remoteFile['file_path'] ?? ''),
            $provider,
            (string) ($remoteFile['mime_type'] ?? '')
        );
        $deletedRemote[$deleteKey] = true;
    }
    foreach ($legacyPaths as $legacyPath) {
        bugcatcher_file_storage_delete_legacy_local($legacyPath);
    }
}

function checklist_api_store_uploaded_batch_file(
    mysqli $conn,
    int $batchId,
    string $tmpPath,
    string $originalName,
    int $size,
    int $uploadedBy
): bool {
    bugcatcher_file_storage_ensure_schema($conn);
    $allowed = bugcatcher_checklist_allowed_mime_map();
    if ($size <= 0 || !is_uploaded_file($tmpPath)) {
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        return false;
    }
    if ($size > $allowed[$mime]['max']) {
        return false;
    }

    $safeOrig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    try {
        $stored = bugcatcher_file_storage_upload_file($tmpPath, $safeOrig, $mime, $size, 'checklist-batch');
    } catch (Throwable $e) {
        return false;
    }
    $filePath = (string) $stored['file_path'];
    $storageKey = (string) ($stored['storage_key'] ?? '');
    $storageProvider = (string) ($stored['storage_provider'] ?? '');
    $storedName = (string) ($stored['original_name'] ?? $safeOrig);
    $storedMime = (string) ($stored['mime_type'] ?? $mime);
    $storedSize = (int) ($stored['file_size'] ?? $size);

    $sourceType = 'manual';
    $stmt = $conn->prepare("
        INSERT INTO checklist_batch_attachments
            (checklist_batch_id, file_path, storage_key, storage_provider, original_name, mime_type, file_size, uploaded_by, source_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isssssiis', $batchId, $filePath, $storageKey, $storageProvider, $storedName, $storedMime, $storedSize, $uploadedBy, $sourceType);
    $stmt->execute();
    $stmt->close();

    return true;
}

function checklist_api_delete_batch_attachment(mysqli $conn, array $attachment): void
{
    bugcatcher_file_storage_ensure_schema($conn);
    $storageKey = (string) ($attachment['storage_key'] ?? '');
    $storageProvider = bugcatcher_file_storage_provider_from_row($attachment);
    $legacyPath = $storageKey === '' ? bugcatcher_checklist_upload_absolute_path((string) $attachment['file_path']) : null;

    $attachmentId = (int) $attachment['id'];
    $stmt = $conn->prepare("DELETE FROM checklist_batch_attachments WHERE id = ?");
    $stmt->bind_param('i', $attachmentId);
    $stmt->execute();
    $stmt->close();

    if ($storageKey !== '') {
        bugcatcher_file_storage_delete_if_unreferenced(
            $conn,
            $storageKey,
            null,
            null,
            (string) ($attachment['file_path'] ?? ''),
            $storageProvider,
            (string) ($attachment['mime_type'] ?? '')
        );
    } else {
        bugcatcher_file_storage_delete_legacy_local($legacyPath);
    }
}
