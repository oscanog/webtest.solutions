<?php

declare(strict_types=1);

function webtest_issue_find_membership(mysqli $conn, int $orgId, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT role FROM org_members WHERE org_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $orgId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function webtest_issue_label_catalog(mysqli $conn): array
{
    $result = $conn->query("SELECT id, name, color FROM labels ORDER BY name ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function webtest_issue_project_catalog(mysqli $conn, int $orgId): array
{
    $stmt = $conn->prepare("
        SELECT id, name, code
        FROM projects
        WHERE org_id = ? AND status = 'active'
        ORDER BY name ASC, id ASC
    ");
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function webtest_issue_project_for_org(mysqli $conn, int $orgId, int $projectId): ?array
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

function webtest_issue_uploaded_images_array(array $files): ?array
{
    foreach (['images', 'images[]'] as $field) {
        $bucket = $files[$field] ?? null;
        if (!is_array($bucket)) {
            continue;
        }
        if (is_array($bucket['name'] ?? null)) {
            return $bucket;
        }

        $singleName = trim((string) ($bucket['name'] ?? ''));
        if ($singleName !== '') {
            return [
                'name' => [$singleName],
                'type' => [(string) ($bucket['type'] ?? '')],
                'tmp_name' => [(string) ($bucket['tmp_name'] ?? '')],
                'error' => [(int) ($bucket['error'] ?? UPLOAD_ERR_NO_FILE)],
                'size' => [(int) ($bucket['size'] ?? 0)],
            ];
        }
    }

    return null;
}

function webtest_issue_create_from_form(mysqli $conn, int $orgId, int $authorId, array $post, array $files): int
{
    $title = trim((string) ($post['title'] ?? ''));
    $description = trim((string) ($post['description'] ?? ''));
    $projectId = ctype_digit((string) ($post['project_id'] ?? '')) ? (int) $post['project_id'] : 0;
    $labelIds = array_values(array_unique(array_filter(array_map(static function ($value): int {
        return ctype_digit((string) $value) ? (int) $value : 0;
    }, $post['labels'] ?? []))));

    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    if ($projectId <= 0) {
        throw new RuntimeException('Please choose a project.');
    }
    if (!$labelIds) {
        throw new RuntimeException('Please select at least one label.');
    }

    $project = webtest_issue_project_for_org($conn, $orgId, $projectId);
    if (!$project || (string) ($project['status'] ?? 'archived') !== 'active') {
        throw new RuntimeException('Please choose a valid active project.');
    }

    $placeholders = implode(',', array_fill(0, count($labelIds), '?'));
    $stmt = $conn->prepare("SELECT id FROM labels WHERE id IN ({$placeholders})");
    $types = str_repeat('i', count($labelIds));
    $refs = [];
    $refs[] = &$types;
    foreach ($labelIds as $index => $labelId) {
        $refs[] = &$labelIds[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $validRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $validIds = array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $validRows);
    sort($validIds);
    $sortedLabelIds = $labelIds;
    sort($sortedLabelIds);
    if ($validIds !== $sortedLabelIds) {
        throw new RuntimeException('Please choose only valid labels.');
    }

    webtest_file_storage_ensure_schema($conn);
    $allowed = [
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
        $workflowStatus = webtest_issue_workflow_default();
        $stmt->bind_param('ssiiis', $title, $description, $authorId, $orgId, $projectId, $workflowStatus);
        $stmt->execute();
        $issueId = (int) $conn->insert_id;
        $stmt->close();

        $imageUploads = webtest_issue_uploaded_images_array($files);
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
                if (!isset($allowed[$mime])) {
                    continue;
                }

                $ext = $allowed[$mime];
                $origName = (string) ($imageUploads['name'][$i] ?? ('image.' . $ext));
                $safeOrig = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
                $stored = webtest_file_storage_upload_file($tmp, $safeOrig, $mime, $size, 'issues');
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

        $stmtLabel = $conn->prepare("
            INSERT INTO issue_labels (issue_id, label_id)
            VALUES (?, ?)
        ");
        foreach ($labelIds as $labelId) {
            $stmtLabel->bind_param('ii', $issueId, $labelId);
            $stmtLabel->execute();
        }
        $stmtLabel->close();

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        foreach ($uploadedKeys as $uploadedKey) {
            try {
                webtest_file_storage_delete($uploadedKey);
            } catch (Throwable $deleteError) {
                // Ignore cleanup errors after rollback.
            }
        }
        throw new RuntimeException('Failed to create issue.', 0, $exception);
    }

    return $issueId;
}
