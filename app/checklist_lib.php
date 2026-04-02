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

function bugcatcher_checklist_is_project_manager_role(string $orgRole): bool
{
    return $orgRole === 'Project Manager';
}

function bugcatcher_checklist_can_transition_status(string $currentStatus, string $nextStatus, string $orgRole): bool
{
    if ($nextStatus === $currentStatus) {
        return true;
    }

    if (bugcatcher_checklist_is_project_manager_role($orgRole)) {
        return in_array($nextStatus, BUGCATCHER_CHECKLIST_STATUSES, true);
    }

    if (
        in_array($currentStatus, ['open', 'in_progress'], true) &&
        in_array($nextStatus, ['in_progress', 'passed', 'failed', 'blocked'], true)
    ) {
        return true;
    }

    if (
        in_array($currentStatus, ['failed', 'blocked'], true) &&
        in_array($nextStatus, ['in_progress', 'passed'], true) &&
        bugcatcher_checklist_is_manager_role($orgRole)
    ) {
        return true;
    }

    return false;
}

function bugcatcher_checklist_resolve_status_timestamps(string $nextStatus, ?string $startedAt, ?string $completedAt): array
{
    $normalizedStartedAt = trim((string) $startedAt);
    $normalizedCompletedAt = trim((string) $completedAt);
    $timestamp = date('Y-m-d H:i:s');

    if ($nextStatus === 'open') {
        return [
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    if ($nextStatus === 'in_progress') {
        return [
            'started_at' => $normalizedStartedAt !== '' ? $normalizedStartedAt : $timestamp,
            'completed_at' => null,
        ];
    }

    if (in_array($nextStatus, ['passed', 'failed', 'blocked'], true)) {
        return [
            'started_at' => $normalizedStartedAt !== '' ? $normalizedStartedAt : null,
            'completed_at' => $timestamp,
        ];
    }

    return [
        'started_at' => $normalizedStartedAt !== '' ? $normalizedStartedAt : null,
        'completed_at' => $normalizedCompletedAt !== '' ? $normalizedCompletedAt : null,
    ];
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

function bugcatcher_checklist_batch_source_mode(array $batch): ?string
{
    if ((string) ($batch['source_type'] ?? '') !== 'bot' || (string) ($batch['source_channel'] ?? '') !== 'api') {
        return null;
    }

    if (preg_match('/^ai-chat:\d+:(screenshot|link)$/', (string) ($batch['source_reference'] ?? ''), $matches) === 1) {
        return $matches[1];
    }

    return null;
}

function bugcatcher_checklist_enrich_batch_row(array $batch): array
{
    $batch['source_mode'] = bugcatcher_checklist_batch_source_mode($batch);
    return $batch;
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

    return array_map('bugcatcher_checklist_enrich_batch_row', $rows);
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
    return $row ? bugcatcher_checklist_enrich_batch_row($row) : null;
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

function bugcatcher_checklist_fetch_batch_options(mysqli $conn, int $orgId): array
{
    bugcatcher_checklist_ensure_schema($conn);

    $stmt = $conn->prepare("
        SELECT cb.id,
               cb.title,
               cb.module_name,
               cb.submodule_name,
               cb.status,
               p.name AS project_name
        FROM checklist_batches cb
        JOIN projects p ON p.id = cb.project_id
        WHERE cb.org_id = ?
        ORDER BY cb.created_at DESC, cb.id DESC
    ");
    $stmt->bind_param("i", $orgId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

function bugcatcher_checklist_normalize_item_filters(array $filters): array
{
    $assignment = (string) ($filters['assignment'] ?? '');
    $issue = (string) ($filters['issue'] ?? '');

    return [
        'q' => trim((string) ($filters['q'] ?? '')),
        'project_id' => max(0, (int) ($filters['project_id'] ?? 0)),
        'batch_id' => max(0, (int) ($filters['batch_id'] ?? 0)),
        'status' => in_array((string) ($filters['status'] ?? ''), BUGCATCHER_CHECKLIST_STATUSES, true)
            ? (string) $filters['status']
            : '',
        'assignment' => in_array($assignment, ['', 'assigned', 'unassigned'], true)
            ? $assignment
            : '',
        'priority' => in_array((string) ($filters['priority'] ?? ''), BUGCATCHER_CHECKLIST_PRIORITIES, true)
            ? (string) $filters['priority']
            : '',
        'issue' => in_array($issue, ['', 'with_issue', 'without_issue'], true)
            ? $issue
            : '',
    ];
}

function bugcatcher_checklist_build_org_item_filter_sql(array $filters, string &$types, array &$params): string
{
    $filters = bugcatcher_checklist_normalize_item_filters($filters);
    $sql = '';

    if ((int) $filters['project_id'] > 0) {
        $sql .= " AND ci.project_id = ?";
        $types .= "i";
        $params[] = (int) $filters['project_id'];
    }

    if ((int) $filters['batch_id'] > 0) {
        $sql .= " AND ci.batch_id = ?";
        $types .= "i";
        $params[] = (int) $filters['batch_id'];
    }

    if ((string) $filters['status'] !== '') {
        $sql .= " AND ci.status = ?";
        $types .= "s";
        $params[] = (string) $filters['status'];
    }

    if ((string) $filters['priority'] !== '') {
        $sql .= " AND ci.priority = ?";
        $types .= "s";
        $params[] = (string) $filters['priority'];
    }

    if ((string) $filters['assignment'] === 'assigned') {
        $sql .= " AND ci.assigned_to_user_id IS NOT NULL AND ci.assigned_to_user_id > 0";
    } elseif ((string) $filters['assignment'] === 'unassigned') {
        $sql .= " AND (ci.assigned_to_user_id IS NULL OR ci.assigned_to_user_id = 0)";
    }

    if ((string) $filters['issue'] === 'with_issue') {
        $sql .= " AND ci.issue_id IS NOT NULL AND ci.issue_id > 0";
    } elseif ((string) $filters['issue'] === 'without_issue') {
        $sql .= " AND (ci.issue_id IS NULL OR ci.issue_id = 0)";
    }

    if ((string) $filters['q'] !== '') {
        $needle = '%' . (string) $filters['q'] . '%';
        $sql .= "
            AND (
                ci.title LIKE ?
                OR ci.full_title LIKE ?
                OR ci.description LIKE ?
                OR ci.required_role LIKE ?
                OR assignee.username LIKE ?
                OR cb.title LIKE ?
                OR cb.module_name LIKE ?
                OR cb.submodule_name LIKE ?
                OR p.name LIKE ?
            )
        ";
        $types .= "sssssssss";
        array_push($params, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
    }

    return $sql;
}

function bugcatcher_checklist_fetch_org_items_overview(
    mysqli $conn,
    int $orgId,
    array $filters = [],
    int $page = 1,
    int $perPage = 25
): array {
    $filters = bugcatcher_checklist_normalize_item_filters($filters);
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));

    $baseFrom = "
        FROM checklist_items ci
        JOIN checklist_batches cb ON cb.id = ci.batch_id
        JOIN projects p ON p.id = ci.project_id
        LEFT JOIN users assignee ON assignee.id = ci.assigned_to_user_id
        WHERE ci.org_id = ?
    ";

    $totalStmt = $conn->prepare("SELECT COUNT(*) AS total_count FROM checklist_items WHERE org_id = ?");
    $totalStmt->bind_param("i", $orgId);
    $totalStmt->execute();
    $totalRow = $totalStmt->get_result()->fetch_assoc();
    $totalStmt->close();
    $totalCount = (int) ($totalRow['total_count'] ?? 0);

    $countTypes = "i";
    $countParams = [$orgId];
    $filterSql = bugcatcher_checklist_build_org_item_filter_sql($filters, $countTypes, $countParams);

    $summaryStmt = $conn->prepare("
        SELECT COUNT(*) AS visible_total,
               SUM(CASE WHEN ci.assigned_to_user_id IS NOT NULL AND ci.assigned_to_user_id > 0 THEN 1 ELSE 0 END) AS assigned_count,
               SUM(CASE WHEN ci.assigned_to_user_id IS NULL OR ci.assigned_to_user_id = 0 THEN 1 ELSE 0 END) AS unassigned_count,
               SUM(CASE WHEN ci.status = 'open' THEN 1 ELSE 0 END) AS open_count
        {$baseFrom}
        {$filterSql}
    ");
    bugcatcher_stmt_bind_params($summaryStmt, $countTypes, $countParams);
    $summaryStmt->execute();
    $summaryRow = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    $visibleTotal = (int) ($summaryRow['visible_total'] ?? 0);
    $pageCount = max(1, (int) ceil($visibleTotal / $perPage));
    if ($page > $pageCount) {
        $page = $pageCount;
    }
    $offset = ($page - 1) * $perPage;

    $listTypes = "i";
    $listParams = [$orgId];
    $listFilterSql = bugcatcher_checklist_build_org_item_filter_sql($filters, $listTypes, $listParams);
    $listTypes .= "ii";
    $listParams[] = $perPage;
    $listParams[] = $offset;

    $listStmt = $conn->prepare("
        SELECT ci.*,
               cb.title AS batch_title,
               cb.status AS batch_status,
               cb.module_name AS batch_module_name,
               cb.submodule_name AS batch_submodule_name,
               p.name AS project_name,
               assignee.username AS assigned_to_name
        {$baseFrom}
        {$listFilterSql}
        ORDER BY COALESCE(ci.updated_at, ci.created_at) DESC, ci.id DESC
        LIMIT ? OFFSET ?
    ");
    bugcatcher_stmt_bind_params($listStmt, $listTypes, $listParams);
    $listStmt->execute();
    $result = $listStmt->get_result();
    $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $listStmt->close();

    return [
        'filters' => $filters,
        'items' => $items,
        'summary' => [
            'total' => $totalCount,
            'visible' => $visibleTotal,
            'assigned' => (int) ($summaryRow['assigned_count'] ?? 0),
            'unassigned' => (int) ($summaryRow['unassigned_count'] ?? 0),
            'open' => (int) ($summaryRow['open_count'] ?? 0),
        ],
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'page_count' => $pageCount,
            'total' => $visibleTotal,
            'offset' => $offset,
        ],
    ];
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
    return $row ? bugcatcher_checklist_enrich_batch_row($row) : null;
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

function bugcatcher_attachment_public_url(string $storedPath): string
{
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '') {
        return '';
    }

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $storedPath)) {
        return $storedPath;
    }

    $normalizedPath = ltrim($storedPath, '/');
    $baseUrl = bugcatcher_base_url();
    if ($baseUrl !== '') {
        return $baseUrl . '/' . $normalizedPath;
    }

    return '/' . $normalizedPath;
}

function bugcatcher_checklist_shape_attachment(array $attachment): array
{
    $shaped = $attachment;
    $shaped['file_url'] = bugcatcher_attachment_public_url((string) ($attachment['file_path'] ?? ''));
    return $shaped;
}

function bugcatcher_checklist_shape_attachments(array $attachments): array
{
    return array_map(
        static fn(array $attachment): array => bugcatcher_checklist_shape_attachment($attachment),
        $attachments
    );
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
    if (bugcatcher_checklist_is_manager_role((string) ($context['org_role'] ?? ''))) {
        return true;
    }

    $contextUserId = isset($context['current_user_id'])
        ? (int) $context['current_user_id']
        : (int) ($context['user_id'] ?? 0);

    return $contextUserId > 0 && (int) ($item['assigned_to_user_id'] ?? 0) === $contextUserId;
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
        INSERT INTO issues (title, description, author_id, org_id, project_id, workflow_status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $workflowStatus = bugcatcher_issue_workflow_default();
    $projectId = (int) ($item['project_id'] ?? 0);
    $stmt->bind_param("ssiiis", $item['full_title'], $issueDescription, $authorId, $item['org_id'], $projectId, $workflowStatus);
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
    $storageProvider = (string) ($stored['storage_provider'] ?? '');
    $storedName = (string) ($stored['original_name'] ?? $safeOrig);
    $storedMime = (string) ($stored['mime_type'] ?? $mime);
    $storedSize = (int) ($stored['file_size'] ?? $size);

    $stmt = $conn->prepare("
        INSERT INTO checklist_attachments
            (checklist_item_id, file_path, storage_key, storage_provider, original_name, mime_type, file_size, uploaded_by, source_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssssiis", $itemId, $filePath, $storageKey, $storageProvider, $storedName, $storedMime, $storedSize, $uploadedBy, $sourceType);
    $stmt->execute();
    $stmt->close();

    return true;
}

function bugcatcher_checklist_delete_attachment(mysqli $conn, array $attachment): void
{
    bugcatcher_file_storage_ensure_schema($conn);
    $storageKey = (string) ($attachment['storage_key'] ?? '');
    $storageProvider = bugcatcher_file_storage_provider_from_row($attachment);
    $legacyPath = $storageKey === '' ? bugcatcher_checklist_upload_absolute_path($attachment['file_path']) : null;

    $stmt = $conn->prepare("DELETE FROM checklist_attachments WHERE id = ?");
    $attachmentId = (int) $attachment['id'];
    $stmt->bind_param("i", $attachmentId);
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
