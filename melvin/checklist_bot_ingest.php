<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/checklist_lib.php';

bugcatcher_start_session();

header('Content-Type: application/json');

try {
    $conn = bugcatcher_db_connection();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

function respond_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function ingest_header_token(): string
{
    return (string) ($_SERVER['HTTP_X_BUGCRAWLER_TOKEN'] ?? '');
}

function ingest_resolve_temp_file(string $token): ?string
{
    $token = preg_replace('/[^a-zA-Z0-9._-]/', '', $token);
    if ($token === '') {
        return null;
    }

    $candidates = [
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bugcatcher-checklist-bot' . DIRECTORY_SEPARATOR . $token,
        bugcatcher_checklist_uploads_dir() . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $token,
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

$expectedToken = bugcatcher_config('CHECKLIST_BOT_SHARED_SECRET', '');
if ($expectedToken === '' || !hash_equals($expectedToken, ingest_header_token())) {
    respond_json(401, ['error' => 'Invalid or missing bot token.']);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    respond_json(400, ['error' => 'Invalid JSON payload.']);
}

$orgId = ctype_digit((string) ($payload['org_id'] ?? '')) ? (int) $payload['org_id'] : 0;
$projectId = ctype_digit((string) ($payload['project_id'] ?? '')) ? (int) $payload['project_id'] : 0;
$batchPayload = is_array($payload['batch'] ?? null) ? $payload['batch'] : [];
$itemsPayload = is_array($payload['items'] ?? null) ? $payload['items'] : [];
$attachmentsPayload = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];

if ($orgId <= 0 || $projectId <= 0 || !$batchPayload || !$itemsPayload) {
    respond_json(422, ['error' => 'org_id, project_id, batch, and items are required.']);
}
if (count($itemsPayload) > 200) {
    respond_json(422, ['error' => 'Max batch size is 200 items.']);
}

$project = bugcatcher_checklist_fetch_project($conn, $orgId, $projectId);
if (!$project) {
    respond_json(404, ['error' => 'Project not found in the provided organization.']);
}

$assignedQaLeadId = ctype_digit((string) ($batchPayload['assigned_qa_lead_id'] ?? '')) ? (int) $batchPayload['assigned_qa_lead_id'] : 0;
if ($assignedQaLeadId > 0 && !bugcatcher_checklist_member_has_role($conn, $orgId, $assignedQaLeadId, ['QA Lead'])) {
    respond_json(422, ['error' => 'assigned_qa_lead_id must belong to a QA Lead in this organization.']);
}

$title = trim((string) ($batchPayload['title'] ?? ''));
$moduleName = trim((string) ($batchPayload['module_name'] ?? ''));
$submoduleName = trim((string) ($batchPayload['submodule_name'] ?? ''));
$sourceType = bugcatcher_checklist_normalize_enum((string) ($batchPayload['source_type'] ?? 'bot'), ['manual', 'bot'], 'bot');
$sourceChannel = bugcatcher_checklist_normalize_enum((string) ($batchPayload['source_channel'] ?? 'api'), ['web', 'telegram', 'legacy_chat', 'api'], 'api');
$sourceReference = trim((string) ($batchPayload['source_reference'] ?? ''));
$notes = trim((string) ($batchPayload['notes'] ?? ''));

if ($title === '' || $moduleName === '') {
    respond_json(422, ['error' => 'Batch title and module_name are required.']);
}

$createdBy = $project['created_by'];
$itemIds = [];
$itemIdBySequence = [];
$deferredNotes = [];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("
        INSERT INTO checklist_batches
            (org_id, project_id, title, module_name, submodule_name, source_type, source_channel, source_reference,
             status, created_by, updated_by, assigned_qa_lead_id, notes)
        VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), 'open', ?, ?, NULLIF(?, 0), NULLIF(?, ''))
    ");
    $stmt->bind_param(
        "iissssssiiis",
        $orgId,
        $projectId,
        $title,
        $moduleName,
        $submoduleName,
        $sourceType,
        $sourceChannel,
        $sourceReference,
        $createdBy,
        $createdBy,
        $assignedQaLeadId,
        $notes
    );
    $stmt->execute();
    $batchId = (int) $conn->insert_id;
    $stmt->close();

    foreach ($itemsPayload as $index => $itemPayload) {
        if (!is_array($itemPayload)) {
            throw new RuntimeException('Each item must be an object.');
        }

        $sequenceNo = ctype_digit((string) ($itemPayload['sequence_no'] ?? '')) ? (int) $itemPayload['sequence_no'] : ($index + 1);
        $itemTitle = trim((string) ($itemPayload['title'] ?? ''));
        $itemModuleName = trim((string) ($itemPayload['module_name'] ?? $moduleName));
        $itemSubmodule = trim((string) ($itemPayload['submodule_name'] ?? $submoduleName));
        $description = trim((string) ($itemPayload['description'] ?? ''));
        $requiredRole = bugcatcher_checklist_normalize_enum((string) ($itemPayload['required_role'] ?? 'QA Tester'), BUGCATCHER_CHECKLIST_ALLOWED_REQUIRED_ROLES, 'QA Tester');
        $priority = bugcatcher_checklist_normalize_enum((string) ($itemPayload['priority'] ?? 'medium'), BUGCATCHER_CHECKLIST_PRIORITIES, 'medium');
        $assignedToUserId = ctype_digit((string) ($itemPayload['assigned_to_user_id'] ?? '')) ? (int) $itemPayload['assigned_to_user_id'] : 0;

        if ($itemTitle === '' || $itemModuleName === '') {
            throw new RuntimeException('Each item requires title and module_name.');
        }
        if ($assignedToUserId > 0 && bugcatcher_checklist_fetch_member_role($conn, $orgId, $assignedToUserId) === null) {
            throw new RuntimeException("assigned_to_user_id {$assignedToUserId} is not a member of org {$orgId}.");
        }

        $fullTitle = bugcatcher_checklist_full_title($itemModuleName, $itemSubmodule, $itemTitle);
        $stmt = $conn->prepare("
            INSERT INTO checklist_items
                (batch_id, org_id, project_id, sequence_no, title, module_name, submodule_name, full_title, description,
                 status, priority, required_role, assigned_to_user_id, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), 'open', ?, ?, NULLIF(?, 0), ?, ?)
        ");
        $stmt->bind_param(
            "iiiisssssssiii",
            $batchId,
            $orgId,
            $projectId,
            $sequenceNo,
            $itemTitle,
            $itemModuleName,
            $itemSubmodule,
            $fullTitle,
            $description,
            $priority,
            $requiredRole,
            $assignedToUserId,
            $createdBy,
            $createdBy
        );
        $stmt->execute();
        $itemId = (int) $conn->insert_id;
        $stmt->close();

        $itemIds[] = $itemId;
        $itemIdBySequence[$sequenceNo] = $itemId;
    }

    foreach ($attachmentsPayload as $attachment) {
        if (!is_array($attachment)) {
            throw new RuntimeException('Each attachment must be an object.');
        }

        $scope = (string) ($attachment['scope'] ?? 'batch');
        $token = (string) ($attachment['temp_file_token'] ?? '');
        $path = $token !== '' ? ingest_resolve_temp_file($token) : '';
        $originalName = (string) ($attachment['original_name'] ?? 'attachment');
        $mimeType = (string) ($attachment['mime_type'] ?? '');
        $itemSequenceNo = ctype_digit((string) ($attachment['item_sequence_no'] ?? '')) ? (int) $attachment['item_sequence_no'] : 0;

        if ($scope === 'item' && $itemSequenceNo > 0 && $path && isset($itemIdBySequence[$itemSequenceNo])) {
            $size = (int) filesize($path);
            if (!bugcatcher_checklist_store_uploaded_file($conn, $itemIdBySequence[$itemSequenceNo], $path, $originalName, $size, false, null, 'bot')) {
                throw new RuntimeException("Invalid attachment for item sequence {$itemSequenceNo}.");
            }
            continue;
        }

        $deferredNotes[] = trim("Attachment reference kept in notes. Scope: {$scope}; name: {$originalName}; mime: {$mimeType}; token: {$token}");
    }

    if ($deferredNotes) {
        $mergedNotes = trim($notes . "\n" . implode("\n", $deferredNotes));
        $stmt = $conn->prepare("UPDATE checklist_batches SET notes = NULLIF(?, '') WHERE id = ?");
        $stmt->bind_param("si", $mergedNotes, $batchId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    respond_json(422, ['error' => $e->getMessage()]);
}

respond_json(201, [
    'batch_id' => $batchId,
    'item_count' => count($itemIds),
    'item_ids' => $itemIds,
]);
