<?php

declare(strict_types=1);

function bc_v1_issue_org_member_has_role(mysqli $conn, int $orgId, int $userId, string $role): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM org_members
        WHERE org_id = ? AND user_id = ? AND role = ?
        LIMIT 1
    ");
    $stmt->bind_param('iis', $orgId, $userId, $role);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool) $row;
}

function bc_v1_issue_project_fetch(mysqli $conn, int $orgId, int $projectId): ?array
{
    $stmt = $conn->prepare("
        SELECT id, org_id, name, code, status
        FROM projects
        WHERE id = ? AND org_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $projectId, $orgId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bc_v1_issue_fetch(mysqli $conn, int $orgId, int $issueId): ?array
{
    $stmt = $conn->prepare("
        SELECT i.*, u.username AS author_username, o.name AS org_name, p.name AS project_name, p.code AS project_code
        FROM issues i
        LEFT JOIN users u ON u.id = i.author_id
        JOIN organizations o ON o.id = i.org_id
        JOIN projects p ON p.id = i.project_id
        WHERE i.id = ? AND i.org_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $issueId, $orgId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bc_v1_issue_labels_map(mysqli $conn, array $issueIds): array
{
    if (!$issueIds) {
        return [];
    }

    $issueIds = array_values(array_unique(array_map('intval', $issueIds)));
    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $types = str_repeat('i', count($issueIds));
    $stmt = $conn->prepare("
        SELECT il.issue_id, l.id, l.name, l.description, l.color
        FROM issue_labels il
        JOIN labels l ON l.id = il.label_id
        WHERE il.issue_id IN ({$placeholders})
        ORDER BY l.name ASC
    ");
    bc_v1_stmt_bind($stmt, $types, $issueIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $map = [];
    foreach ($rows as $row) {
        $id = (int) $row['issue_id'];
        $map[$id][] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'color' => (string) ($row['color'] ?? ''),
        ];
    }
    return $map;
}

function bc_v1_issue_attachments_map(mysqli $conn, array $issueIds): array
{
    bugcatcher_file_storage_ensure_schema($conn);
    if (!$issueIds) {
        return [];
    }

    $issueIds = array_values(array_unique(array_map('intval', $issueIds)));
    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $types = str_repeat('i', count($issueIds));
    $stmt = $conn->prepare("
        SELECT id, issue_id, file_path, original_name, mime_type, file_size, uploaded_at
        FROM issue_attachments
        WHERE issue_id IN ({$placeholders})
        ORDER BY id ASC
    ");
    bc_v1_stmt_bind($stmt, $types, $issueIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $map = [];
    foreach ($rows as $row) {
        $id = (int) $row['issue_id'];
        $map[$id][] = [
            'id' => (int) $row['id'],
            'file_path' => (string) $row['file_path'],
            'original_name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'],
            'file_size' => (int) $row['file_size'],
            'uploaded_at' => (string) $row['uploaded_at'],
        ];
    }
    return $map;
}

function bc_v1_issue_shape(array $row, array $labels = [], array $attachments = []): array
{
    $workflowStatus = bugcatcher_issue_workflow_normalize(
        (string) ($row['workflow_status'] ?? $row['assign_status'] ?? bugcatcher_issue_workflow_default())
    );

    return [
        'id' => (int) $row['id'],
        'org_id' => (int) $row['org_id'],
        'org_name' => (string) ($row['org_name'] ?? ''),
        'project_id' => (int) ($row['project_id'] ?? 0),
        'project_name' => (string) ($row['project_name'] ?? ''),
        'project_code' => isset($row['project_code']) && $row['project_code'] !== null ? (string) $row['project_code'] : null,
        'title' => (string) $row['title'],
        'description' => (string) ($row['description'] ?? ''),
        'workflow_status' => $workflowStatus,
        'status' => bugcatcher_issue_workflow_status_alias($workflowStatus),
        'assign_status' => bugcatcher_issue_workflow_assign_status_alias($workflowStatus),
        'author_id' => isset($row['author_id']) ? (int) $row['author_id'] : 0,
        'author_username' => (string) ($row['author_username'] ?? ''),
        'pm_id' => isset($row['pm_id']) ? (int) $row['pm_id'] : 0,
        'assigned_dev_id' => isset($row['assigned_dev_id']) ? (int) $row['assigned_dev_id'] : 0,
        'assigned_junior_id' => isset($row['assigned_junior_id']) ? (int) $row['assigned_junior_id'] : 0,
        'assigned_qa_id' => isset($row['assigned_qa_id']) ? (int) $row['assigned_qa_id'] : 0,
        'assigned_senior_qa_id' => isset($row['assigned_senior_qa_id']) ? (int) $row['assigned_senior_qa_id'] : 0,
        'assigned_qa_lead_id' => isset($row['assigned_qa_lead_id']) ? (int) $row['assigned_qa_lead_id'] : 0,
        'assigned_at' => (string) ($row['assigned_at'] ?? ''),
        'junior_assigned_at' => (string) ($row['junior_assigned_at'] ?? ''),
        'junior_done_at' => (string) ($row['junior_done_at'] ?? ''),
        'qa_assigned_at' => (string) ($row['qa_assigned_at'] ?? ''),
        'senior_qa_assigned_at' => (string) ($row['senior_qa_assigned_at'] ?? ''),
        'qa_lead_assigned_at' => (string) ($row['qa_lead_assigned_at'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'labels' => $labels,
        'attachments' => $attachments,
    ];
}

function bc_v1_issue_visibility_scope(array $orgContext): string
{
    if (bugcatcher_is_system_admin_role((string) ($orgContext['system_role'] ?? 'user'))) {
        return 'admin';
    }
    if (!empty($orgContext['is_org_owner'])) {
        return 'owner';
    }

    $role = (string) ($orgContext['org_role'] ?? '');
    if ($role === 'Project Manager') {
        return 'pm';
    }
    if ($role === 'Senior Developer') {
        return 'senior';
    }
    if ($role === 'Junior Developer') {
        return 'junior';
    }
    if ($role === 'QA Tester') {
        return 'qa';
    }
    if ($role === 'Senior QA') {
        return 'senior_qa';
    }
    if ($role === 'QA Lead') {
        return 'qa_lead';
    }
    return 'regular';
}

function bc_v1_issue_count(mysqli $conn, int $orgId, string $status): int
{
    $filterSql = bugcatcher_issue_workflow_filter_sql('workflow_status', $status);
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM issues
        WHERE org_id = ? AND {$filterSql}
    ");
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
}

function bc_v1_issue_count_for_org_ids(mysqli $conn, array $orgIds, string $status): int
{
    if (!$orgIds) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
    $filterSql = bugcatcher_issue_workflow_filter_sql('workflow_status', $status);
    $params = $orgIds;
    $types = str_repeat('i', count($orgIds));
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM issues
        WHERE {$filterSql} AND org_id IN ({$placeholders})
    ");
    bc_v1_stmt_bind($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
}

function bc_v1_issue_fetch_for_actor(mysqli $conn, array $actor, int $issueId): ?array
{
    $userId = (int) ($actor['user']['id'] ?? 0);
    $stmt = $conn->prepare("
        SELECT i.*, u.username AS author_username, o.name AS org_name, p.name AS project_name, p.code AS project_code
        FROM issues i
        JOIN org_members om
            ON om.org_id = i.org_id
           AND om.user_id = ?
        JOIN organizations o ON o.id = i.org_id
        JOIN projects p ON p.id = i.project_id
        LEFT JOIN users u ON u.id = i.author_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $userId, $issueId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function bc_v1_issue_parse_label_ids(array $payload): array
{
    $raw = $payload['labels'] ?? $payload['label_ids'] ?? [];
    if (!is_array($raw)) {
        $text = trim((string) $raw);
        $raw = $text === '' ? [] : explode(',', $text);
    }

    $ids = [];
    foreach ($raw as $value) {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_numeric($value) && ctype_digit((string) $value)) {
            $id = (int) $value;
        } else {
            continue;
        }
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function bc_v1_issue_validate_labels(mysqli $conn, array $labelIds): array
{
    if (!$labelIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($labelIds), '?'));
    $types = str_repeat('i', count($labelIds));
    $stmt = $conn->prepare("SELECT id FROM labels WHERE id IN ({$placeholders})");
    bc_v1_stmt_bind($stmt, $types, $labelIds);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $valid = array_map(static function (array $row): int {
        return (int) $row['id'];
    }, $rows);
    sort($valid);
    sort($labelIds);
    if ($valid !== $labelIds) {
        bc_v1_json_error(422, 'invalid_labels', 'One or more labels are invalid.');
    }
    return $valid;
}

function bc_v1_issue_hydrated_by_id(mysqli $conn, int $orgId, int $issueId): array
{
    $row = bc_v1_issue_fetch($conn, $orgId, $issueId);
    if (!$row) {
        bc_v1_json_error(404, 'issue_not_found', 'Issue not found in this organization.');
    }
    $labelsMap = bc_v1_issue_labels_map($conn, [$issueId]);
    $attachmentsMap = bc_v1_issue_attachments_map($conn, [$issueId]);
    return bc_v1_issue_shape($row, $labelsMap[$issueId] ?? [], $attachmentsMap[$issueId] ?? []);
}

function bc_v1_issue_link_path(int $issueId): string
{
    return '/app/reports/' . $issueId;
}

function bc_v1_issue_uploaded_images(): ?array
{
    foreach (['images', 'images[]'] as $field) {
        $files = $_FILES[$field] ?? null;
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

function bc_v1_issue_is_visible_to_actor(array $issue, array $org): bool
{
    return (int) ($issue['org_id'] ?? 0) === (int) ($org['org_id'] ?? 0);
}

function bc_v1_issue_notify(mysqli $conn, array $issue, array $payload, array $recipientUserIds): void
{
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? $issue['assign_status'] ?? ''));
    bugcatcher_notifications_send($conn, $recipientUserIds, [
        'type' => 'issue',
        'event_key' => (string) ($payload['event_key'] ?? 'issue_updated'),
        'title' => (string) ($payload['title'] ?? 'Issue updated'),
        'body' => (string) ($payload['body'] ?? ''),
        'severity' => (string) ($payload['severity'] ?? 'default'),
        'link_path' => bc_v1_issue_link_path((int) $issue['id']),
        'actor_user_id' => (int) ($payload['actor_user_id'] ?? 0),
        'org_id' => (int) ($issue['org_id'] ?? 0),
        'project_id' => (int) ($issue['project_id'] ?? 0),
        'issue_id' => (int) ($issue['id'] ?? 0),
        'meta' => $payload['meta'] ?? [
            'project_id' => (int) ($issue['project_id'] ?? 0),
            'workflow_status' => $workflowStatus,
            'assign_status' => bugcatcher_issue_workflow_assign_status_alias($workflowStatus),
            'status' => bugcatcher_issue_workflow_status_alias($workflowStatus),
        ],
    ]);
}

function bc_v1_issues_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $requestedOrgId = bc_v1_get_int($_GET, 'org_id', 0);

    $status = bugcatcher_issue_workflow_filter((string) ($_GET['status'] ?? 'open'));
    $labelId = bc_v1_get_int($_GET, 'label', 0);
    $author = bc_v1_get_int($_GET, 'author', 0);
    $isAllScope = bc_v1_actor_is_all_scope($actor) && $requestedOrgId <= 0;

    if ($isAllScope) {
        $orgIds = bc_v1_user_org_ids($conn, (int) $actor['user']['id']);
        $scope = 'all';
        $isSystemAdmin = true;

        if ($author > 0 || $labelId > 0) {
            // Keep the existing admin-only author/label filtering behavior in all-scope.
            $author = max(0, $author);
            $labelId = max(0, $labelId);
        }

        if (!$orgIds) {
            bc_v1_json_success([
                'org' => bc_v1_all_org_context($actor),
                'scope' => $scope,
                'status' => $status,
                'filters' => [
                    'author' => $author > 0 ? $author : null,
                    'label' => $labelId > 0 ? $labelId : null,
                ],
                'counts' => [
                    'open' => 0,
                    'closed' => 0,
                ],
                'issues' => [],
            ]);
        }

        $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
        $statusSql = bugcatcher_issue_workflow_filter_sql('i.workflow_status', $status);
        $sql = "
            SELECT i.*, u.username AS author_username, o.name AS org_name, p.name AS project_name, p.code AS project_code
            FROM issues i
            JOIN organizations o ON o.id = i.org_id
            JOIN projects p ON p.id = i.project_id
            LEFT JOIN users u ON u.id = i.author_id
            WHERE {$statusSql} AND i.org_id IN ({$placeholders})
        ";
        $types = str_repeat('i', count($orgIds));
        $queryParams = $orgIds;

        if ($author > 0) {
            $sql .= " AND i.author_id = ?";
            $types .= 'i';
            $queryParams[] = $author;
        }
        if ($labelId > 0) {
            $sql .= " AND i.id IN (SELECT issue_id FROM issue_labels WHERE label_id = ?)";
            $types .= 'i';
            $queryParams[] = $labelId;
        }

        $sql .= " ORDER BY i.created_at DESC";

        $stmt = $conn->prepare($sql);
        bc_v1_stmt_bind($stmt, $types, $queryParams);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $issueIds = array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $rows);
        $labelsMap = bc_v1_issue_labels_map($conn, $issueIds);
        $attachmentsMap = bc_v1_issue_attachments_map($conn, $issueIds);

        $issues = [];
        foreach ($rows as $row) {
            $issueId = (int) $row['id'];
            $issues[] = bc_v1_issue_shape($row, $labelsMap[$issueId] ?? [], $attachmentsMap[$issueId] ?? []);
        }

        bc_v1_json_success([
            'org' => bc_v1_all_org_context($actor),
            'scope' => $scope,
            'status' => $status,
            'filters' => [
                'author' => $author > 0 ? $author : null,
                'label' => $labelId > 0 ? $labelId : null,
            ],
            'counts' => [
                'open' => bc_v1_issue_count_for_org_ids($conn, $orgIds, 'open'),
                'closed' => bc_v1_issue_count_for_org_ids($conn, $orgIds, 'closed'),
            ],
            'issues' => $issues,
        ]);
    }

    $org = bc_v1_org_context($conn, $actor, $requestedOrgId);
    $scope = bc_v1_issue_visibility_scope($org);
    $isSystemAdmin = bugcatcher_is_system_admin_role((string) ($org['system_role'] ?? 'user'));

    if (!$isSystemAdmin) {
        $author = 0;
    }

    $statusSql = bugcatcher_issue_workflow_filter_sql('i.workflow_status', $status);
    $sql = "
        SELECT i.*, u.username AS author_username, o.name AS org_name, p.name AS project_name, p.code AS project_code
        FROM issues i
        JOIN organizations o ON o.id = i.org_id
        JOIN projects p ON p.id = i.project_id
        LEFT JOIN users u ON u.id = i.author_id
        WHERE {$statusSql} AND i.org_id = ?
    ";
    $types = 'i';
    $queryParams = [(int) $org['org_id']];

    if ($author > 0 && $isSystemAdmin) {
        $sql .= " AND i.author_id = ?";
        $types .= 'i';
        $queryParams[] = $author;
    }
    if ($labelId > 0) {
        $sql .= " AND i.id IN (SELECT issue_id FROM issue_labels WHERE label_id = ?)";
        $types .= 'i';
        $queryParams[] = $labelId;
    }

    $sql .= " ORDER BY i.created_at DESC";

    $stmt = $conn->prepare($sql);
    bc_v1_stmt_bind($stmt, $types, $queryParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $issueIds = array_map(static function (array $row): int {
        return (int) $row['id'];
    }, $rows);
    $labelsMap = bc_v1_issue_labels_map($conn, $issueIds);
    $attachmentsMap = bc_v1_issue_attachments_map($conn, $issueIds);

    $issues = [];
    foreach ($rows as $row) {
        $issueId = (int) $row['id'];
        $issues[] = bc_v1_issue_shape($row, $labelsMap[$issueId] ?? [], $attachmentsMap[$issueId] ?? []);
    }

    $openCount = bc_v1_issue_count($conn, (int) $org['org_id'], 'open');
    $closedCount = bc_v1_issue_count($conn, (int) $org['org_id'], 'closed');

    bc_v1_json_success([
        'org' => $org,
        'scope' => $scope,
        'status' => $status,
        'filters' => [
            'author' => $author > 0 ? $author : null,
            'label' => $labelId > 0 ? $labelId : null,
        ],
        'counts' => [
            'open' => $openCount,
            'closed' => $closedCount,
        ],
        'issues' => $issues,
    ]);
}

function bc_v1_issues_id_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $issueId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($issueId <= 0) {
        bc_v1_json_error(422, 'invalid_issue', 'Issue id is invalid.');
    }

    $requestedOrgId = bc_v1_get_int($_GET, 'org_id', 0);
    if ($requestedOrgId > 0 || !bc_v1_actor_is_all_scope($actor)) {
        $org = bc_v1_org_context($conn, $actor, $requestedOrgId);
        $issue = bc_v1_issue_fetch($conn, (int) $org['org_id'], $issueId);
    } else {
        $issue = bc_v1_issue_fetch_for_actor($conn, $actor, $issueId);
        $org = $issue ? bc_v1_org_context($conn, $actor, (int) $issue['org_id']) : null;
    }
    if (!$issue) {
        bc_v1_json_error(404, 'issue_not_found', 'Issue not found in this organization.');
    }
    if (!$org) {
        bc_v1_json_error(404, 'org_not_found', 'Issue organization could not be resolved.');
    }
    if (!bc_v1_issue_is_visible_to_actor($issue, $org)) {
        bc_v1_json_error(403, 'forbidden', 'You do not have access to this issue.');
    }

    bc_v1_json_success([
        'org' => $org,
        'issue' => bc_v1_issue_hydrated_by_id($conn, (int) $org['org_id'], $issueId),
    ]);
}

function bc_v1_issues_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($payload, 'org_id', 0));

    $title = trim((string) ($payload['title'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $projectId = bc_v1_get_int($payload, 'project_id', 0);
    $labelIds = bc_v1_issue_parse_label_ids($payload);
    if ($title === '') {
        bc_v1_json_error(422, 'validation_error', 'title is required.');
    }
    if ($projectId <= 0) {
        bc_v1_json_error(422, 'validation_error', 'project_id is required.');
    }
    if (!$labelIds) {
        bc_v1_json_error(422, 'validation_error', 'At least one label is required.');
    }
    $labelIds = bc_v1_issue_validate_labels($conn, $labelIds);
    $project = bc_v1_issue_project_fetch($conn, (int) $org['org_id'], $projectId);
    if (!$project || (string) ($project['status'] ?? 'archived') !== 'active') {
        bc_v1_json_error(422, 'validation_error', 'project_id must reference an active project in this organization.');
    }
    bugcatcher_file_storage_ensure_schema($conn);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $maxBytes = 10 * 1024 * 1024;
    $uploadedKeys = [];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO issues (title, description, author_id, org_id, project_id, workflow_status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $workflowStatus = bugcatcher_issue_workflow_default();
        $stmt->bind_param('ssiiis', $title, $description, $org['user_id'], $org['org_id'], $projectId, $workflowStatus);
        $stmt->execute();
        $issueId = (int) $conn->insert_id;
        $stmt->close();

        $stmtLabel = $conn->prepare("INSERT INTO issue_labels (issue_id, label_id) VALUES (?, ?)");
        foreach ($labelIds as $labelId) {
            $stmtLabel->bind_param('ii', $issueId, $labelId);
            $stmtLabel->execute();
        }
        $stmtLabel->close();

        $imageUploads = bc_v1_issue_uploaded_images();
        if ($imageUploads !== null) {
            $stmtAtt = $conn->prepare("
                INSERT INTO issue_attachments (issue_id, file_path, storage_key, storage_provider, original_name, mime_type, file_size)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $count = count($imageUploads['name']);
            for ($i = 0; $i < $count; $i++) {
                $err = (int) ($imageUploads['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE || $err !== UPLOAD_ERR_OK) {
                    continue;
                }

                $tmp = (string) ($imageUploads['tmp_name'][$i] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    continue;
                }

                $size = (int) ($imageUploads['size'][$i] ?? 0);
                if ($size <= 0 || $size > $maxBytes) {
                    continue;
                }

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo !== false ? (string) finfo_file($finfo, $tmp) : '';
                if ($finfo !== false) {
                    finfo_close($finfo);
                }
                if (!isset($allowedMimes[$mime])) {
                    continue;
                }

                $ext = $allowedMimes[$mime];
                $origName = (string) ($imageUploads['name'][$i] ?? ('image.' . $ext));
                $safeOrig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
                $stored = bugcatcher_file_storage_upload_file($tmp, $safeOrig, $mime, $size, 'issues');
                $filePath = (string) $stored['file_path'];
                $storageKey = (string) ($stored['storage_key'] ?? '');
                $storageProvider = (string) ($stored['storage_provider'] ?? '');
                $storedName = (string) ($stored['original_name'] ?? $safeOrig);
                $storedMime = (string) ($stored['mime_type'] ?? $mime);
                $storedSize = (int) ($stored['file_size'] ?? $size);
                if ($storageKey !== '') {
                    $uploadedKeys[] = $storageKey;
                }

                $stmtAtt->bind_param('isssssi', $issueId, $filePath, $storageKey, $storageProvider, $storedName, $storedMime, $storedSize);
                $stmtAtt->execute();
            }

            $stmtAtt->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        foreach ($uploadedKeys as $uploadedKey) {
            try {
                bugcatcher_file_storage_delete($uploadedKey);
            } catch (Throwable $deleteError) {
                // Ignore cleanup errors after rollback.
            }
        }
        bc_v1_json_error(500, 'issue_create_failed', 'Failed to create issue.', $e->getMessage());
    }

    $hydratedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $org['org_id'], $issueId);
    $managerRecipients = array_values(array_diff(
        bugcatcher_notification_org_manager_ids($conn, (int) $org['org_id']),
        [(int) $org['user_id']]
    ));
    bc_v1_issue_notify($conn, $hydratedIssue, [
        'event_key' => 'issue_created',
        'title' => 'New issue created',
        'body' => $title . ' needs review in ' . ((string) ($org['org_name'] ?? 'your organization')) . '.',
        'severity' => 'alert',
        'actor_user_id' => (int) $org['user_id'],
    ], $managerRecipients);

    bc_v1_json_success([
        'issue' => $hydratedIssue,
    ], 201);
}

function bc_v1_issues_delete(mysqli $conn, array $params): void
{
    bc_v1_require_method(['DELETE']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $issueId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($issueId <= 0) {
        bc_v1_json_error(422, 'invalid_issue', 'Issue id is invalid.');
    }

    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($payload, 'org_id', 0));
    $issue = bc_v1_issue_fetch($conn, (int) $org['org_id'], $issueId);
    if (!$issue) {
        bc_v1_json_error(404, 'issue_not_found', 'Issue not found in this organization.');
    }

    $isSystemAdmin = bugcatcher_is_system_admin_role((string) ($actor['user']['role'] ?? 'user'));
    $isOrgOwner = (bool) ($org['is_org_owner'] ?? false);
    if (!$isSystemAdmin && !$isOrgOwner) {
        bc_v1_json_error(403, 'forbidden', 'Only organization owners or system admins can delete issues.');
    }
    if (!$isSystemAdmin && $isOrgOwner && bugcatcher_issue_workflow_is_closed((string) ($issue['workflow_status'] ?? ''))) {
        bc_v1_json_error(422, 'forbidden_closed_issue', 'Closed issues cannot be deleted by the organization owner.');
    }
    bugcatcher_file_storage_ensure_schema($conn);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT file_path, storage_key, storage_provider, mime_type FROM issue_attachments WHERE issue_id = ?");
        $stmt->bind_param('i', $issueId);
        $stmt->execute();
        $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $remoteFiles = [];
        $legacyPaths = [];
        foreach ($files as $file) {
            $storageKey = (string) ($file['storage_key'] ?? '');
            $storedPath = (string) ($file['file_path'] ?? '');
            if ($storageKey !== '') {
                $remoteFiles[] = $file;
                continue;
            }
            $legacyPaths[] = bugcatcher_upload_absolute_path($storedPath);
        }

        $stmt = $conn->prepare("DELETE FROM issue_attachments WHERE issue_id = ?");
        $stmt->bind_param('i', $issueId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM issue_labels WHERE issue_id = ?");
        $stmt->bind_param('i', $issueId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM issues WHERE id = ? AND org_id = ?");
        $stmt->bind_param('ii', $issueId, $org['org_id']);
        $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new RuntimeException('Failed to delete issue row.');
        }

        $conn->commit();

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
    } catch (Throwable $e) {
        $conn->rollback();
        bc_v1_json_error(500, 'issue_delete_failed', 'Failed to delete issue.', $e->getMessage());
    }

    bc_v1_json_success([
        'deleted' => true,
        'issue_id' => $issueId,
    ]);
}

function bc_v1_issue_action_context(mysqli $conn, array $params): array
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $payload = bc_v1_request_data();
    $issueId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($issueId <= 0) {
        bc_v1_json_error(422, 'invalid_issue', 'Issue id is invalid.');
    }

    $org = bc_v1_org_context($conn, $actor, bc_v1_get_int($payload, 'org_id', 0));
    $issue = bc_v1_issue_fetch($conn, (int) $org['org_id'], $issueId);
    if (!$issue) {
        bc_v1_json_error(404, 'issue_not_found', 'Issue not found in this organization.');
    }

    return [
        'actor' => $actor,
        'payload' => $payload,
        'org' => $org,
        'issue' => $issue,
        'issue_id' => $issueId,
        'user_id' => (int) $actor['user']['id'],
    ];
}

function bc_v1_issue_require_org_role(array $org, string $requiredRole, string $message): void
{
    if ((string) ($org['org_role'] ?? '') !== $requiredRole) {
        bc_v1_json_error(403, 'forbidden', $message);
    }
}

function bc_v1_issues_assign_dev_post(mysqli $conn, array $params): void
{
    $ctx = bc_v1_issue_action_context($conn, $params);
    bc_v1_issue_require_org_role($ctx['org'], 'Project Manager', 'Only Project Managers can assign issues.');

    $devId = bc_v1_get_int($ctx['payload'], 'dev_id', 0);
    if ($devId <= 0) {
        bc_v1_json_error(422, 'validation_error', 'dev_id is required.');
    }

    $issue = $ctx['issue'];
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
    if (!bugcatcher_issue_workflow_can_assign_dev($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Issue is not ready for PM assignment.');
    }
    if (!bugcatcher_issue_workflow_is_active($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Only open issues can be assigned.');
    }
    if (!empty($issue['pm_id']) && (int) $issue['pm_id'] !== $ctx['user_id']) {
        bc_v1_json_error(403, 'forbidden', 'Only the original Project Manager can reassign this rejected issue.');
    }
    if (!bc_v1_issue_org_member_has_role($conn, (int) $ctx['org']['org_id'], $devId, 'Senior Developer')) {
        bc_v1_json_error(422, 'invalid_assignee', 'Selected user is not a Senior Developer in this organization.');
    }

    $stmt = $conn->prepare("
        UPDATE issues
        SET pm_id = IFNULL(pm_id, ?),
            assigned_dev_id = ?,
            assigned_junior_id = NULL,
            assigned_qa_id = NULL,
            assigned_senior_qa_id = NULL,
            assigned_qa_lead_id = NULL,
            junior_assigned_at = NULL,
            qa_assigned_at = NULL,
            senior_qa_assigned_at = NULL,
            qa_lead_assigned_at = NULL,
            junior_done_at = NULL,
            workflow_status = 'with_senior',
            assigned_at = NOW()
        WHERE id = ? AND org_id = ? AND workflow_status IN ('unassigned', 'rejected')
    ");
    $stmt->bind_param('iiii', $ctx['user_id'], $devId, $ctx['issue_id'], $ctx['org']['org_id']);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        bc_v1_json_error(409, 'assign_failed', 'Failed to assign issue to Senior Developer.');
    }

    $updatedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $ctx['org']['org_id'], $ctx['issue_id']);
    bc_v1_issue_notify($conn, $updatedIssue, [
        'event_key' => 'issue_assigned_dev',
        'title' => 'Issue assigned to Senior Developer',
        'body' => $updatedIssue['title'] . ' is now assigned to you.',
        'severity' => 'alert',
        'actor_user_id' => (int) $ctx['user_id'],
    ], [$devId, (int) ($updatedIssue['author_id'] ?? 0)]);

    bc_v1_json_success([
        'issue' => $updatedIssue,
    ]);
}

function bc_v1_issues_assign_junior_post(mysqli $conn, array $params): void
{
    $ctx = bc_v1_issue_action_context($conn, $params);
    bc_v1_issue_require_org_role($ctx['org'], 'Senior Developer', 'Only Senior Developers can assign Junior Developers.');

    $juniorId = bc_v1_get_int($ctx['payload'], 'junior_id', 0);
    if ($juniorId <= 0) {
        bc_v1_json_error(422, 'validation_error', 'junior_id is required.');
    }

    $issue = $ctx['issue'];
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
    if ((int) ($issue['assigned_dev_id'] ?? 0) !== $ctx['user_id']) {
        bc_v1_json_error(403, 'forbidden', 'You can only assign issues that are assigned to you.');
    }
    if (!bugcatcher_issue_workflow_can_assign_junior($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Issue is not currently with a Senior Developer.');
    }
    if (!empty($issue['assigned_junior_id'])) {
        bc_v1_json_error(422, 'invalid_state', 'Issue already has a Junior Developer assigned.');
    }
    if (!bc_v1_issue_org_member_has_role($conn, (int) $ctx['org']['org_id'], $juniorId, 'Junior Developer')) {
        bc_v1_json_error(422, 'invalid_assignee', 'Selected user is not a Junior Developer in this organization.');
    }

    $stmt = $conn->prepare("
        UPDATE issues
        SET assigned_junior_id = ?, junior_assigned_at = NOW(), workflow_status = 'with_junior'
        WHERE id = ? AND org_id = ? AND assigned_dev_id = ? AND assigned_junior_id IS NULL AND workflow_status = 'with_senior'
    ");
    $stmt->bind_param('iiii', $juniorId, $ctx['issue_id'], $ctx['org']['org_id'], $ctx['user_id']);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        bc_v1_json_error(409, 'assign_failed', 'Failed to assign Junior Developer.');
    }

    $updatedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $ctx['org']['org_id'], $ctx['issue_id']);
    bc_v1_issue_notify($conn, $updatedIssue, [
        'event_key' => 'issue_assigned_junior',
        'title' => 'Issue assigned to Junior Developer',
        'body' => $updatedIssue['title'] . ' is ready for your implementation.',
        'severity' => 'alert',
        'actor_user_id' => (int) $ctx['user_id'],
    ], [$juniorId, (int) ($updatedIssue['author_id'] ?? 0)]);

    bc_v1_json_success([
        'issue' => $updatedIssue,
    ]);
}

function bc_v1_issues_junior_done_post(mysqli $conn, array $params): void
{
    $ctx = bc_v1_issue_action_context($conn, $params);
    bc_v1_issue_require_org_role($ctx['org'], 'Junior Developer', 'Only Junior Developers can mark DONE.');

    $issue = $ctx['issue'];
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
    if (!bugcatcher_issue_workflow_is_active($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Only open issues can be marked DONE.');
    }
    if ((int) ($issue['assigned_junior_id'] ?? 0) !== $ctx['user_id']) {
        bc_v1_json_error(403, 'forbidden', 'You can only mark DONE for issues assigned to you.');
    }
    if (!bugcatcher_issue_workflow_can_mark_junior_done($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Issue is not currently with a Junior Developer.');
    }

    $stmt = $conn->prepare("
        UPDATE issues
        SET workflow_status = 'done_by_junior', junior_done_at = NOW()
        WHERE id = ? AND org_id = ? AND assigned_junior_id = ? AND workflow_status = 'with_junior'
    ");
    $stmt->bind_param('iii', $ctx['issue_id'], $ctx['org']['org_id'], $ctx['user_id']);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        bc_v1_json_error(409, 'update_failed', 'Failed to mark issue as done by Junior Developer.');
    }

    $updatedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $ctx['org']['org_id'], $ctx['issue_id']);
    bc_v1_issue_notify($conn, $updatedIssue, [
        'event_key' => 'issue_done_by_junior',
        'title' => 'Junior Developer marked issue done',
        'body' => $updatedIssue['title'] . ' is ready for QA assignment.',
        'severity' => 'success',
        'actor_user_id' => (int) $ctx['user_id'],
    ], [(int) ($updatedIssue['assigned_dev_id'] ?? 0), (int) ($updatedIssue['author_id'] ?? 0)]);

    bc_v1_json_success([
        'issue' => $updatedIssue,
    ]);
}

function bc_v1_issues_assign_qa_post(mysqli $conn, array $params): void
{
    $ctx = bc_v1_issue_action_context($conn, $params);
    bc_v1_issue_require_org_role($ctx['org'], 'Senior Developer', 'Only Senior Developers can assign QA Testers.');

    $qaId = bc_v1_get_int($ctx['payload'], 'qa_id', 0);
    if ($qaId <= 0) {
        bc_v1_json_error(422, 'validation_error', 'qa_id is required.');
    }

    $issue = $ctx['issue'];
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
    if (!bugcatcher_issue_workflow_is_active($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Only open issues can be analyzed.');
    }
    if ((int) ($issue['assigned_dev_id'] ?? 0) !== $ctx['user_id']) {
        bc_v1_json_error(403, 'forbidden', 'You can only analyze issues assigned to you.');
    }
    if (!bugcatcher_issue_workflow_can_assign_qa($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Issue is not ready for QA.');
    }
    if (!empty($issue['assigned_qa_id'])) {
        bc_v1_json_error(422, 'invalid_state', 'Issue already has a QA Tester assigned.');
    }
    if (!bc_v1_issue_org_member_has_role($conn, (int) $ctx['org']['org_id'], $qaId, 'QA Tester')) {
        bc_v1_json_error(422, 'invalid_assignee', 'Selected user is not a QA Tester in this organization.');
    }

    $stmt = $conn->prepare("
        UPDATE issues
        SET assigned_qa_id = ?, qa_assigned_at = NOW(), workflow_status = 'with_qa'
        WHERE id = ? AND org_id = ? AND assigned_dev_id = ? AND assigned_qa_id IS NULL AND workflow_status = 'done_by_junior'
    ");
    $stmt->bind_param('iiii', $qaId, $ctx['issue_id'], $ctx['org']['org_id'], $ctx['user_id']);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        bc_v1_json_error(409, 'assign_failed', 'Failed to assign QA Tester.');
    }

    $updatedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $ctx['org']['org_id'], $ctx['issue_id']);
    bc_v1_issue_notify($conn, $updatedIssue, [
        'event_key' => 'issue_assigned_qa',
        'title' => 'Issue assigned to QA Tester',
        'body' => $updatedIssue['title'] . ' is ready for verification.',
        'severity' => 'alert',
        'actor_user_id' => (int) $ctx['user_id'],
    ], [$qaId, (int) ($updatedIssue['author_id'] ?? 0)]);

    bc_v1_json_success([
        'issue' => $updatedIssue,
    ]);
}

function bc_v1_issues_report_senior_qa_post(mysqli $conn, array $params): void
{
    $ctx = bc_v1_issue_action_context($conn, $params);
    bc_v1_issue_require_org_role($ctx['org'], 'QA Tester', 'Only QA Testers can report issues to Senior QA.');

    $seniorQaId = bc_v1_get_int($ctx['payload'], 'senior_qa_id', 0);
    if ($seniorQaId <= 0) {
        bc_v1_json_error(422, 'validation_error', 'senior_qa_id is required.');
    }

    $issue = $ctx['issue'];
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
    if (!bugcatcher_issue_workflow_is_active($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Only open issues can be reported.');
    }
    if ((int) ($issue['assigned_qa_id'] ?? 0) !== $ctx['user_id']) {
        bc_v1_json_error(403, 'forbidden', 'You can only report issues assigned to you.');
    }
    if (!bugcatcher_issue_workflow_can_report_senior_qa($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Issue is not currently with QA.');
    }
    if (!empty($issue['assigned_senior_qa_id'])) {
        bc_v1_json_error(422, 'invalid_state', 'Issue already has a Senior QA assigned.');
    }
    if (!bc_v1_issue_org_member_has_role($conn, (int) $ctx['org']['org_id'], $seniorQaId, 'Senior QA')) {
        bc_v1_json_error(422, 'invalid_assignee', 'Selected user is not a Senior QA in this organization.');
    }

    $stmt = $conn->prepare("
        UPDATE issues
        SET assigned_senior_qa_id = ?, senior_qa_assigned_at = NOW(), workflow_status = 'with_senior_qa'
        WHERE id = ? AND org_id = ? AND assigned_qa_id = ? AND assigned_senior_qa_id IS NULL AND workflow_status = 'with_qa'
    ");
    $stmt->bind_param('iiii', $seniorQaId, $ctx['issue_id'], $ctx['org']['org_id'], $ctx['user_id']);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        bc_v1_json_error(409, 'assign_failed', 'Failed to report issue to Senior QA.');
    }

    $updatedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $ctx['org']['org_id'], $ctx['issue_id']);
    bc_v1_issue_notify($conn, $updatedIssue, [
        'event_key' => 'issue_reported_senior_qa',
        'title' => 'Issue reported to Senior QA',
        'body' => $updatedIssue['title'] . ' is waiting for your review.',
        'severity' => 'alert',
        'actor_user_id' => (int) $ctx['user_id'],
    ], [$seniorQaId, (int) ($updatedIssue['author_id'] ?? 0)]);

    bc_v1_json_success([
        'issue' => $updatedIssue,
    ]);
}

function bc_v1_issues_report_qa_lead_post(mysqli $conn, array $params): void
{
    $ctx = bc_v1_issue_action_context($conn, $params);
    bc_v1_issue_require_org_role($ctx['org'], 'Senior QA', 'Only Senior QA can report issues to QA Lead.');

    $qaLeadId = bc_v1_get_int($ctx['payload'], 'qa_lead_id', 0);
    if ($qaLeadId <= 0) {
        bc_v1_json_error(422, 'validation_error', 'qa_lead_id is required.');
    }

    $issue = $ctx['issue'];
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
    if (!bugcatcher_issue_workflow_is_active($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Only open issues can be reported.');
    }
    if ((int) ($issue['assigned_senior_qa_id'] ?? 0) !== $ctx['user_id']) {
        bc_v1_json_error(403, 'forbidden', 'You can only report issues assigned to you.');
    }
    if (!bugcatcher_issue_workflow_can_report_qa_lead($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Issue is not currently with Senior QA.');
    }
    if (!empty($issue['assigned_qa_lead_id'])) {
        bc_v1_json_error(422, 'invalid_state', 'Issue already has a QA Lead assigned.');
    }
    if (!bc_v1_issue_org_member_has_role($conn, (int) $ctx['org']['org_id'], $qaLeadId, 'QA Lead')) {
        bc_v1_json_error(422, 'invalid_assignee', 'Selected user is not a QA Lead in this organization.');
    }

    $stmt = $conn->prepare("
        UPDATE issues
        SET assigned_qa_lead_id = ?, qa_lead_assigned_at = NOW(), workflow_status = 'with_qa_lead'
        WHERE id = ? AND org_id = ? AND assigned_senior_qa_id = ? AND assigned_qa_lead_id IS NULL AND workflow_status = 'with_senior_qa'
    ");
    $stmt->bind_param('iiii', $qaLeadId, $ctx['issue_id'], $ctx['org']['org_id'], $ctx['user_id']);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        bc_v1_json_error(409, 'assign_failed', 'Failed to report issue to QA Lead.');
    }

    $updatedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $ctx['org']['org_id'], $ctx['issue_id']);
    bc_v1_issue_notify($conn, $updatedIssue, [
        'event_key' => 'issue_reported_qa_lead',
        'title' => 'Issue reported to QA Lead',
        'body' => $updatedIssue['title'] . ' needs your approval.',
        'severity' => 'alert',
        'actor_user_id' => (int) $ctx['user_id'],
    ], [$qaLeadId, (int) ($updatedIssue['author_id'] ?? 0)]);

    bc_v1_json_success([
        'issue' => $updatedIssue,
    ]);
}

function bc_v1_issues_qa_lead_approve_post(mysqli $conn, array $params): void
{
    $ctx = bc_v1_issue_action_context($conn, $params);
    bc_v1_issue_require_org_role($ctx['org'], 'QA Lead', 'Only QA Lead can approve.');

    $issue = $ctx['issue'];
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
    if (!bugcatcher_issue_workflow_is_active($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Only open issues can be approved.');
    }
    if ((int) ($issue['assigned_qa_lead_id'] ?? 0) !== $ctx['user_id']) {
        bc_v1_json_error(403, 'forbidden', 'Issue is not assigned to you.');
    }
    if (!bugcatcher_issue_workflow_can_qa_lead_decide($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Issue is not currently with QA Lead.');
    }

    $stmt = $conn->prepare("
        UPDATE issues
        SET workflow_status = 'approved'
        WHERE id = ? AND org_id = ? AND assigned_qa_lead_id = ? AND workflow_status = 'with_qa_lead'
    ");
    $stmt->bind_param('iii', $ctx['issue_id'], $ctx['org']['org_id'], $ctx['user_id']);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        bc_v1_json_error(409, 'approve_failed', 'Failed to approve issue.');
    }

    $updatedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $ctx['org']['org_id'], $ctx['issue_id']);
    bc_v1_issue_notify($conn, $updatedIssue, [
        'event_key' => 'issue_approved',
        'title' => 'Issue approved by QA Lead',
        'body' => $updatedIssue['title'] . ' is ready for Project Manager closure.',
        'severity' => 'success',
        'actor_user_id' => (int) $ctx['user_id'],
    ], [(int) ($updatedIssue['pm_id'] ?? 0), (int) ($updatedIssue['author_id'] ?? 0)]);

    bc_v1_json_success([
        'issue' => $updatedIssue,
    ]);
}

function bc_v1_issues_qa_lead_reject_post(mysqli $conn, array $params): void
{
    $ctx = bc_v1_issue_action_context($conn, $params);
    bc_v1_issue_require_org_role($ctx['org'], 'QA Lead', 'Only QA Lead can reject.');

    $issue = $ctx['issue'];
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
    if (!bugcatcher_issue_workflow_is_active($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Only open issues can be rejected.');
    }
    if ((int) ($issue['assigned_qa_lead_id'] ?? 0) !== $ctx['user_id']) {
        bc_v1_json_error(403, 'forbidden', 'Issue is not assigned to you.');
    }
    if (!bugcatcher_issue_workflow_can_qa_lead_decide($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Issue is not currently with QA Lead.');
    }

    $stmt = $conn->prepare("
        UPDATE issues
        SET workflow_status = 'rejected',
            assigned_dev_id = NULL,
            assigned_junior_id = NULL,
            assigned_qa_id = NULL,
            assigned_senior_qa_id = NULL,
            assigned_qa_lead_id = NULL,
            assigned_at = NULL,
            junior_assigned_at = NULL,
            junior_done_at = NULL,
            qa_assigned_at = NULL,
            senior_qa_assigned_at = NULL,
            qa_lead_assigned_at = NULL
        WHERE id = ? AND org_id = ? AND assigned_qa_lead_id = ? AND workflow_status = 'with_qa_lead'
    ");
    $stmt->bind_param('iii', $ctx['issue_id'], $ctx['org']['org_id'], $ctx['user_id']);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        bc_v1_json_error(409, 'reject_failed', 'Failed to reject issue.');
    }

    $updatedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $ctx['org']['org_id'], $ctx['issue_id']);
    bc_v1_issue_notify($conn, $updatedIssue, [
        'event_key' => 'issue_rejected',
        'title' => 'Issue rejected by QA Lead',
        'body' => $updatedIssue['title'] . ' needs reassignment.',
        'severity' => 'alert',
        'actor_user_id' => (int) $ctx['user_id'],
    ], [(int) ($updatedIssue['pm_id'] ?? 0), (int) ($updatedIssue['author_id'] ?? 0)]);

    bc_v1_json_success([
        'issue' => $updatedIssue,
    ]);
}

function bc_v1_issues_pm_close_post(mysqli $conn, array $params): void
{
    $ctx = bc_v1_issue_action_context($conn, $params);
    bc_v1_issue_require_org_role($ctx['org'], 'Project Manager', 'Only Project Managers can close issues.');

    $issue = $ctx['issue'];
    $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
    if (bugcatcher_issue_workflow_is_closed($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Issue is already closed.');
    }
    if (!bugcatcher_issue_workflow_can_pm_close($workflowStatus)) {
        bc_v1_json_error(422, 'invalid_state', 'Only approved issues can be closed.');
    }

    $stmt = $conn->prepare("
        UPDATE issues
        SET workflow_status = 'closed'
        WHERE id = ? AND org_id = ? AND workflow_status = 'approved'
    ");
    $stmt->bind_param('ii', $ctx['issue_id'], $ctx['org']['org_id']);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();
    if ($affected !== 1) {
        bc_v1_json_error(409, 'close_failed', 'Failed to close issue.');
    }

    $updatedIssue = bc_v1_issue_hydrated_by_id($conn, (int) $ctx['org']['org_id'], $ctx['issue_id']);
    bc_v1_issue_notify($conn, $updatedIssue, [
        'event_key' => 'issue_closed',
        'title' => 'Issue closed',
        'body' => $updatedIssue['title'] . ' has been closed.',
        'severity' => 'success',
        'actor_user_id' => (int) $ctx['user_id'],
    ], [(int) ($updatedIssue['author_id'] ?? 0)]);

    bc_v1_json_success([
        'issue' => $updatedIssue,
    ]);
}
