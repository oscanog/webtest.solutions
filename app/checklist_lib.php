<?php

require_once __DIR__ . '/bootstrap.php';

const BUGCATCHER_CHECKLIST_MANAGER_ROLES = ['owner', 'Project Manager', 'QA Lead'];
const BUGCATCHER_CHECKLIST_ALLOWED_REQUIRED_ROLES = [
    'QA Lead',
    'Senior QA',
    'QA Tester',
    'Project Manager',
    'Senior Developer',
    'Junior Developer',
    'member',
    'owner',
];
const BUGCATCHER_CHECKLIST_STATUSES = ['open', 'in_progress', 'passed', 'failed', 'blocked'];
const BUGCATCHER_BATCH_STATUSES = ['draft', 'open', 'completed', 'archived'];
const BUGCATCHER_CHECKLIST_PRIORITIES = ['low', 'medium', 'high'];

function bugcatcher_checklist_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!bugcatcher_db_has_column($conn, 'checklist_batches', 'page_url')) {
        $conn->query("ALTER TABLE checklist_batches ADD COLUMN page_url VARCHAR(2048) DEFAULT NULL AFTER notes");
    }

    $done = true;
}

function bugcatcher_html(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bugcatcher_stmt_bind_params(mysqli_stmt $stmt, string $types, array $params): void
{
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function bugcatcher_checklist_is_manager_role(string $orgRole): bool
{
    return in_array($orgRole, BUGCATCHER_CHECKLIST_MANAGER_ROLES, true);
}

function bugcatcher_checklist_require_manager(array $context): void
{
    if (!bugcatcher_checklist_is_manager_role($context['org_role'])) {
        http_response_code(403);
        die("Only organization owners, Project Managers, and QA Leads can manage this area.");
    }
}

function bugcatcher_checklist_normalize_enum(string $value, array $allowed, string $default): string
{
    return in_array($value, $allowed, true) ? $value : $default;
}

function bugcatcher_checklist_normalize_page_url(?string $value): string
{
    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return '';
    }

    if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
        return '';
    }

    $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return $trimmed;
}

function bugcatcher_checklist_full_title(string $moduleName, ?string $submoduleName, string $title): string
{
    $parts = [$moduleName];
    if ($submoduleName !== null && $submoduleName !== '') {
        $parts[] = $submoduleName;
    }
    $parts[] = $title;
    return implode(' | ', $parts);
}

function bugcatcher_checklist_fetch_projects(mysqli $conn, int $orgId, bool $includeArchived = false): array
{
    $sql = "
        SELECT p.*,
               COALESCE(batch_stats.batch_count, 0) AS batch_count,
               COALESCE(item_stats.open_item_count, 0) AS open_item_count
        FROM projects p
        LEFT JOIN (
            SELECT project_id, COUNT(*) AS batch_count
            FROM checklist_batches
            GROUP BY project_id
        ) batch_stats ON batch_stats.project_id = p.id
        LEFT JOIN (
            SELECT project_id, COUNT(*) AS open_item_count
            FROM checklist_items
            WHERE status IN ('open', 'in_progress', 'blocked', 'failed')
            GROUP BY project_id
        ) item_stats ON item_stats.project_id = p.id
        WHERE p.org_id = ?
    ";
    if (!$includeArchived) {
        $sql .= " AND p.status = 'active'";
    }
    $sql .= " ORDER BY p.status ASC, p.name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $orgId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function bugcatcher_checklist_fetch_project(mysqli $conn, int $orgId, int $projectId): ?array
{
    $stmt = $conn->prepare("
        SELECT p.*,
               u.username AS created_by_name,
               uu.username AS updated_by_name
        FROM projects p
        LEFT JOIN users u ON u.id = p.created_by
        LEFT JOIN users uu ON uu.id = p.updated_by
        WHERE p.org_id = ? AND p.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $orgId, $projectId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bugcatcher_checklist_fetch_org_members(mysqli $conn, int $orgId, ?array $roles = null): array
{
    $sql = "
        SELECT u.id, u.username, om.role
        FROM org_members om
        JOIN users u ON u.id = om.user_id
        WHERE om.org_id = ?
    ";
    $types = "i";
    $params = [$orgId];
    if ($roles) {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $sql .= " AND om.role IN ({$placeholders})";
        $types .= str_repeat('s', count($roles));
        foreach ($roles as $role) {
            $params[] = $role;
        }
    }
    $sql .= " ORDER BY u.username ASC";

    $stmt = $conn->prepare($sql);
    bugcatcher_stmt_bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function bugcatcher_checklist_fetch_member_role(mysqli $conn, int $orgId, int $userId): ?string
{
    $stmt = $conn->prepare("SELECT role FROM org_members WHERE org_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $orgId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['role'] ?? null;
}

function bugcatcher_checklist_member_has_role(mysqli $conn, int $orgId, int $userId, array $roles): bool
{
    $role = bugcatcher_checklist_fetch_member_role($conn, $orgId, $userId);
    return $role !== null && in_array($role, $roles, true);
}

function bugcatcher_checklist_fetch_batches(
    mysqli $conn,
    int $orgId,
    int $projectId = 0,
    string $status = '',
    string $search = ''
): array {
    bugcatcher_checklist_ensure_schema($conn);

    $sql = "
        SELECT cb.*,
               p.name AS project_name,
               qa.username AS qa_lead_name,
               creator.username AS created_by_name,
               COUNT(ci.id) AS total_items,
               SUM(ci.status = 'open') AS open_items,
               SUM(ci.status = 'in_progress') AS in_progress_items,
               SUM(ci.status = 'passed') AS passed_items,
               SUM(ci.status = 'failed') AS failed_items,
               SUM(ci.status = 'blocked') AS blocked_items
        FROM checklist_batches cb
        JOIN projects p ON p.id = cb.project_id
        LEFT JOIN users qa ON qa.id = cb.assigned_qa_lead_id
        LEFT JOIN users creator ON creator.id = cb.created_by
        LEFT JOIN checklist_items ci ON ci.batch_id = cb.id
        WHERE cb.org_id = ?
    ";
    $types = "i";
    $params = [$orgId];
    if ($projectId > 0) {
        $sql .= " AND cb.project_id = ?";
        $types .= "i";
        $params[] = $projectId;
    }
    if ($status !== '' && in_array($status, BUGCATCHER_BATCH_STATUSES, true)) {
        $sql .= " AND cb.status = ?";
        $types .= "s";
        $params[] = $status;
    }
    if ($search !== '') {
        $needle = '%' . $search . '%';
        $sql .= " AND (cb.title LIKE ? OR cb.module_name LIKE ? OR cb.submodule_name LIKE ? OR p.name LIKE ?)";
        $types .= "ssss";
        array_push($params, $needle, $needle, $needle, $needle);
    }
    $sql .= "
        GROUP BY cb.id
        ORDER BY cb.created_at DESC, cb.id DESC
    ";

    $stmt = $conn->prepare($sql);
    bugcatcher_stmt_bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function bugcatcher_checklist_fetch_batch(mysqli $conn, int $orgId, int $batchId): ?array
{
    bugcatcher_checklist_ensure_schema($conn);

    $stmt = $conn->prepare("
        SELECT cb.*,
               p.name AS project_name,
               qa.username AS qa_lead_name,
               cu.username AS created_by_name,
               uu.username AS updated_by_name
        FROM checklist_batches cb
        JOIN projects p ON p.id = cb.project_id
        LEFT JOIN users qa ON qa.id = cb.assigned_qa_lead_id
        LEFT JOIN users cu ON cu.id = cb.created_by
        LEFT JOIN users uu ON uu.id = cb.updated_by
        WHERE cb.org_id = ? AND cb.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $orgId, $batchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bugcatcher_checklist_find_batch_by_exact_target(
    mysqli $conn,
    int $orgId,
    int $projectId,
    string $title,
    string $moduleName,
    string $submoduleName = ''
): ?array {
    bugcatcher_checklist_ensure_schema($conn);

    $normalize = static function (string $value): string {
        $trimmed = trim($value);
        return function_exists('mb_strtolower')
            ? mb_strtolower($trimmed, 'UTF-8')
            : strtolower($trimmed);
    };

    $normalizedTitle = $normalize($title);
    $normalizedModule = $normalize($moduleName);
    $normalizedSubmodule = $normalize($submoduleName);

    $stmt = $conn->prepare("
        SELECT cb.*,
               p.name AS project_name,
               qa.username AS qa_lead_name,
               cu.username AS created_by_name,
               uu.username AS updated_by_name
        FROM checklist_batches cb
        JOIN projects p ON p.id = cb.project_id
        LEFT JOIN users qa ON qa.id = cb.assigned_qa_lead_id
        LEFT JOIN users cu ON cu.id = cb.created_by
        LEFT JOIN users uu ON uu.id = cb.updated_by
        WHERE cb.org_id = ?
          AND cb.project_id = ?
          AND LOWER(TRIM(cb.title)) = ?
          AND LOWER(TRIM(cb.module_name)) = ?
          AND LOWER(TRIM(COALESCE(cb.submodule_name, ''))) = ?
        ORDER BY cb.id DESC
        LIMIT 1
    ");
    $stmt->bind_param('iisss', $orgId, $projectId, $normalizedTitle, $normalizedModule, $normalizedSubmodule);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function bugcatcher_checklist_fetch_items_for_batch(mysqli $conn, int $batchId): array
{
    $stmt = $conn->prepare("
        SELECT ci.*,
               assignee.username AS assigned_to_name,
               creator.username AS created_by_name,
               updater.username AS updated_by_name
        FROM checklist_items ci
        LEFT JOIN users assignee ON assignee.id = ci.assigned_to_user_id
        LEFT JOIN users creator ON creator.id = ci.created_by
        LEFT JOIN users updater ON updater.id = ci.updated_by
        WHERE ci.batch_id = ?
        ORDER BY ci.sequence_no ASC, ci.id ASC
    ");
    $stmt->bind_param("i", $batchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function bugcatcher_checklist_fetch_item(mysqli $conn, int $orgId, int $itemId): ?array
{
    bugcatcher_checklist_ensure_schema($conn);

    $stmt = $conn->prepare("
        SELECT ci.*,
               cb.title AS batch_title,
               cb.status AS batch_status,
               cb.page_url AS batch_page_url,
               p.name AS project_name,
               assignee.username AS assigned_to_name,
               creator.username AS created_by_name,
               updater.username AS updated_by_name
        FROM checklist_items ci
        JOIN checklist_batches cb ON cb.id = ci.batch_id
        JOIN projects p ON p.id = ci.project_id
        LEFT JOIN users assignee ON assignee.id = ci.assigned_to_user_id
        LEFT JOIN users creator ON creator.id = ci.created_by
        LEFT JOIN users updater ON updater.id = ci.updated_by
        WHERE ci.org_id = ? AND ci.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $orgId, $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bugcatcher_checklist_fetch_item_attachments(mysqli $conn, int $itemId): array
{
    $stmt = $conn->prepare("
        SELECT ca.*, u.username AS uploaded_by_name
        FROM checklist_attachments ca
        LEFT JOIN users u ON u.id = ca.uploaded_by
        WHERE ca.checklist_item_id = ?
        ORDER BY ca.created_at DESC, ca.id DESC
    ");
    $stmt->bind_param("i", $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function bugcatcher_checklist_fetch_attachment(mysqli $conn, int $attachmentId, int $itemId): ?array
{
    $stmt = $conn->prepare("
        SELECT *
        FROM checklist_attachments
        WHERE id = ? AND checklist_item_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $attachmentId, $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bugcatcher_checklist_next_sequence(mysqli $conn, int $batchId): int
{
    $stmt = $conn->prepare("SELECT COALESCE(MAX(sequence_no), 0) + 1 AS next_sequence FROM checklist_items WHERE batch_id = ?");
    $stmt->bind_param("i", $batchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['next_sequence'] ?? 1);
}

function bugcatcher_checklist_user_can_work_item(array $context, array $item): bool
{
    if (bugcatcher_checklist_is_manager_role($context['org_role'])) {
        return true;
    }

    return (int) ($item['assigned_to_user_id'] ?? 0) === (int) $context['current_user_id'];
}

function bugcatcher_checklist_detect_issue_label_id(mysqli $conn): int
{
    static $labelId = null;
    if ($labelId !== null) {
        return $labelId;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM labels
        WHERE LOWER(name) IN ('bug', 'defect', 'issue')
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $labelId = (int) ($row['id'] ?? 0);

    return $labelId;
}

function bugcatcher_checklist_create_issue_for_item(mysqli $conn, array $item, int $actorUserId): int
{
    $existingIssueId = (int) ($item['issue_id'] ?? 0);
    if ($existingIssueId > 0) {
        return $existingIssueId;
    }

    $status = $item['status'];
    $description = trim((string) ($item['description'] ?? ''));
    $preface = "Checklist item {$item['id']} moved to {$status}.\nBatch: {$item['batch_title']}\nProject: {$item['project_name']}\n";
    $issueDescription = trim($preface . "\n" . $description);
    $authorId = $actorUserId > 0 ? $actorUserId : (int) $item['created_by'];

    $stmt = $conn->prepare("
        INSERT INTO issues (title, description, author_id, org_id, status, assign_status)
        VALUES (?, ?, ?, ?, 'open', 'unassigned')
    ");
    $stmt->bind_param("ssii", $item['full_title'], $issueDescription, $authorId, $item['org_id']);
    $stmt->execute();
    $issueId = (int) $conn->insert_id;
    $stmt->close();

    $labelId = bugcatcher_checklist_detect_issue_label_id($conn);
    if ($labelId > 0) {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO issue_labels (issue_id, label_id)
            VALUES (?, ?)
        ");
        $stmt->bind_param("ii", $issueId, $labelId);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE checklist_items SET issue_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $issueId, $item['id']);
    $stmt->execute();
    $stmt->close();

    return $issueId;
}

function bugcatcher_checklist_ensure_upload_dir(): string
{
    $uploadDir = bugcatcher_checklist_uploads_dir();
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 02775, true);
    }
    return $uploadDir;
}

function bugcatcher_checklist_move_server_file(string $sourcePath, string $destinationPath): bool
{
    if (@rename($sourcePath, $destinationPath)) {
        @chmod($destinationPath, 0664);
        return true;
    }

    if (!@copy($sourcePath, $destinationPath)) {
        return false;
    }

    if (!@unlink($sourcePath)) {
        @unlink($destinationPath);
        return false;
    }

    @chmod($destinationPath, 0664);
    return true;
}

function bugcatcher_checklist_allowed_mime_map(): array
{
    return [
        'image/jpeg' => ['ext' => 'jpg', 'max' => 10 * 1024 * 1024],
        'image/png' => ['ext' => 'png', 'max' => 10 * 1024 * 1024],
        'image/gif' => ['ext' => 'gif', 'max' => 10 * 1024 * 1024],
        'image/webp' => ['ext' => 'webp', 'max' => 10 * 1024 * 1024],
        'video/mp4' => ['ext' => 'mp4', 'max' => 50 * 1024 * 1024],
        'video/webm' => ['ext' => 'webm', 'max' => 50 * 1024 * 1024],
        'video/quicktime' => ['ext' => 'mov', 'max' => 50 * 1024 * 1024],
    ];
}

function bugcatcher_checklist_store_uploaded_file(
    mysqli $conn,
    int $itemId,
    string $tmpPath,
    string $originalName,
    int $size,
    bool $isUploadedFile,
    ?int $uploadedBy,
    string $sourceType = 'manual'
): bool {
    bugcatcher_file_storage_ensure_schema($conn);
    $allowed = bugcatcher_checklist_allowed_mime_map();
    if ($size <= 0) {
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

    if ($isUploadedFile && !is_uploaded_file($tmpPath)) {
        return false;
    }
    if (!$isUploadedFile && !is_file($tmpPath)) {
        return false;
    }

    $safeOrig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    try {
        $stored = bugcatcher_file_storage_upload_file($tmpPath, $safeOrig, $mime, $size, 'checklist-item');
    } catch (Throwable $e) {
        return false;
    }
    $filePath = (string) $stored['file_path'];
    $storageKey = (string) ($stored['storage_key'] ?? '');
    $storedName = (string) ($stored['original_name'] ?? $safeOrig);
    $storedMime = (string) ($stored['mime_type'] ?? $mime);
    $storedSize = (int) ($stored['file_size'] ?? $size);

    $stmt = $conn->prepare("
        INSERT INTO checklist_attachments
            (checklist_item_id, file_path, storage_key, original_name, mime_type, file_size, uploaded_by, source_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issssiis", $itemId, $filePath, $storageKey, $storedName, $storedMime, $storedSize, $uploadedBy, $sourceType);
    $stmt->execute();
    $stmt->close();

    return true;
}

function bugcatcher_checklist_delete_attachment(mysqli $conn, array $attachment): void
{
    bugcatcher_file_storage_ensure_schema($conn);
    $storageKey = (string) ($attachment['storage_key'] ?? '');
    $legacyPath = $storageKey === '' ? bugcatcher_checklist_upload_absolute_path($attachment['file_path']) : null;

    $stmt = $conn->prepare("DELETE FROM checklist_attachments WHERE id = ?");
    $attachmentId = (int) $attachment['id'];
    $stmt->bind_param("i", $attachmentId);
    $stmt->execute();
    $stmt->close();

    if ($storageKey !== '') {
        bugcatcher_file_storage_delete_if_unreferenced($conn, $storageKey);
    } else {
        bugcatcher_file_storage_delete_legacy_local($legacyPath);
    }
}

function bugcatcher_checklist_status_badge_class(string $status): string
{
    return 'status-' . str_replace('_', '-', $status);
}

function bugcatcher_checklist_format_datetime(?string $value): string
{
    if (!$value) {
        return 'N/A';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }
    return date('Y-m-d H:i', $timestamp);
}
