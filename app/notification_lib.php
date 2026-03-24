<?php

declare(strict_types=1);

function bugcatcher_notification_normalize_path(string $path): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return '/app/notifications';
    }

    return '/' . ltrim($trimmed, '/');
}

function bugcatcher_notification_meta_json(?array $meta): ?string
{
    if (!$meta) {
        return null;
    }

    return json_encode($meta, JSON_UNESCAPED_SLASHES);
}

function bugcatcher_notification_parse_meta(?string $metaJson): ?array
{
    if (!is_string($metaJson) || trim($metaJson) === '') {
        return null;
    }

    $decoded = json_decode($metaJson, true);
    return is_array($decoded) ? $decoded : null;
}

function bugcatcher_notification_shape(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'type' => (string) $row['type'],
        'event_key' => (string) $row['event_key'],
        'title' => (string) $row['title'],
        'body' => (string) ($row['body'] ?? ''),
        'severity' => (string) ($row['severity'] ?? 'default'),
        'link_path' => (string) ($row['link_path'] ?? '/app/notifications'),
        'read_at' => $row['read_at'] ?: null,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'org_id' => isset($row['org_id']) ? (int) $row['org_id'] : null,
        'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
        'issue_id' => isset($row['issue_id']) ? (int) $row['issue_id'] : null,
        'checklist_batch_id' => isset($row['checklist_batch_id']) ? (int) $row['checklist_batch_id'] : null,
        'checklist_item_id' => isset($row['checklist_item_id']) ? (int) $row['checklist_item_id'] : null,
        'actor' => [
            'id' => isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : 0,
            'username' => (string) ($row['actor_username'] ?? ''),
        ],
        'meta' => bugcatcher_notification_parse_meta($row['meta_json'] ?? null),
    ];
}

function bugcatcher_notification_recipient_can_manage_checklist(mysqli $conn, int $userId, int $orgId): bool
{
    if ($userId <= 0 || $orgId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT role
        FROM org_members
        WHERE org_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $orgId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $role = (string) ($row['role'] ?? '');
    return in_array($role, ['owner', 'Project Manager', 'QA Lead'], true);
}

function bugcatcher_notification_resolve_link_path(mysqli $conn, int $userId, array $row): string
{
    $linkPath = (string) ($row['link_path'] ?? '/app/notifications');
    $itemId = isset($row['checklist_item_id']) ? (int) $row['checklist_item_id'] : 0;
    if ($itemId <= 0 || strpos($linkPath, '/app/checklist/items/') !== 0) {
        return $linkPath;
    }

    $stmt = $conn->prepare("
        SELECT ci.id,
               ci.batch_id,
               ci.assigned_to_user_id,
               cb.org_id
        FROM checklist_items ci
        JOIN checklist_batches cb ON cb.id = ci.batch_id
        WHERE ci.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) {
        return '/app/notifications';
    }

    if ((int) ($item['assigned_to_user_id'] ?? 0) === $userId) {
        return $linkPath;
    }

    $orgId = (int) ($item['org_id'] ?? 0);
    if (bugcatcher_notification_recipient_can_manage_checklist($conn, $userId, $orgId)) {
        return $linkPath;
    }

    $batchId = (int) ($item['batch_id'] ?? 0);
    if ($batchId > 0 && bugcatcher_notification_recipient_can_manage_checklist($conn, $userId, $orgId)) {
        return '/app/checklist/batches/' . $batchId;
    }

    return '/app/notifications';
}

function bugcatcher_notification_shape_for_user(mysqli $conn, int $userId, array $row): array
{
    $shape = bugcatcher_notification_shape($row);
    $shape['link_path'] = bugcatcher_notification_resolve_link_path($conn, $userId, $row);
    return $shape;
}

function bugcatcher_realtime_notifications_enabled(): bool
{
    return (bool) bugcatcher_config('REALTIME_NOTIFICATIONS_ENABLED', true);
}

function bugcatcher_realtime_notifications_host(): string
{
    return trim((string) bugcatcher_config('REALTIME_NOTIFICATIONS_HOST', '127.0.0.1'));
}

function bugcatcher_realtime_notifications_port(): int
{
    return max(1, (int) bugcatcher_config('REALTIME_NOTIFICATIONS_PORT', 8090));
}

function bugcatcher_realtime_notifications_internal_shared_secret(): string
{
    $secret = trim((string) bugcatcher_config('REALTIME_NOTIFICATIONS_INTERNAL_SHARED_SECRET', ''));
    if ($secret === '') {
        $secret = trim((string) bugcatcher_config('OPENCLAW_INTERNAL_SHARED_SECRET', ''));
    }
    if ($secret === '' || $secret === 'replace-me-too') {
        $secret = 'bugcatcher-realtime-dev-secret';
    }

    return $secret;
}

function bugcatcher_realtime_notifications_publish_url(): string
{
    $host = bugcatcher_realtime_notifications_host();
    $port = bugcatcher_realtime_notifications_port();
    if ($host === '' || $port <= 0) {
        return '';
    }

    return sprintf('http://%s:%d/internal/publish', $host, $port);
}

function bugcatcher_notification_log(string $message): void
{
    error_log('[bugcatcher-realtime] ' . $message);
}

function bugcatcher_notification_fetch(mysqli $conn, int $userId, int $notificationId): ?array
{
    if ($notificationId <= 0 || $userId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT n.*, actor.username AS actor_username
        FROM notifications n
        LEFT JOIN users actor ON actor.id = n.actor_user_id
        WHERE n.id = ? AND n.recipient_user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $notificationId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? bugcatcher_notification_shape_for_user($conn, $userId, $row) : null;
}

function bugcatcher_notification_counts(mysqli $conn, int $userId): array
{
    $countStmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread_count,
            COUNT(*) AS total_count
        FROM notifications
        WHERE recipient_user_id = ?
    ");
    $countStmt->bind_param('i', $userId);
    $countStmt->execute();
    $counts = $countStmt->get_result()->fetch_assoc() ?: ['unread_count' => 0, 'total_count' => 0];
    $countStmt->close();

    return [
        'unread_count' => (int) ($counts['unread_count'] ?? 0),
        'total_count' => (int) ($counts['total_count'] ?? 0),
    ];
}

function bugcatcher_notification_realtime_publish(array $payload): void
{
    if (!bugcatcher_realtime_notifications_enabled()) {
        return;
    }

    $url = bugcatcher_realtime_notifications_publish_url();
    $secret = bugcatcher_realtime_notifications_internal_shared_secret();
    if ($url === '' || $secret === '') {
        return;
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        bugcatcher_notification_log('Unable to encode realtime payload.');
        return;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $secret,
                'Connection: close',
            ]) . "\r\n",
            'content' => $json,
            'timeout' => 1.5,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($result === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        bugcatcher_notification_log('Publish failed for ' . ($payload['type'] ?? 'unknown') . ' (' . $statusLine . ')');
    }
}

function bugcatcher_notification_dispatch_realtime(
    mysqli $conn,
    int $userId,
    string $type,
    ?array $notification = null,
    array $extra = []
): void {
    if ($userId <= 0) {
        return;
    }

    $counts = bugcatcher_notification_counts($conn, $userId);
    $payload = array_merge([
        'type' => $type,
        'recipient_user_id' => $userId,
        'unread_count' => $counts['unread_count'],
        'total_count' => $counts['total_count'],
        'timestamp' => gmdate('c'),
    ], $extra);

    if ($notification !== null) {
        $payload['notification'] = $notification;
    }

    bugcatcher_notification_realtime_publish($payload);
}

function bugcatcher_notification_create(mysqli $conn, array $payload): void
{
    $recipientUserId = (int) ($payload['recipient_user_id'] ?? 0);
    if ($recipientUserId <= 0) {
        return;
    }

    $type = trim((string) ($payload['type'] ?? 'system'));
    $eventKey = trim((string) ($payload['event_key'] ?? 'notification'));
    $title = trim((string) ($payload['title'] ?? 'Notification'));
    $body = trim((string) ($payload['body'] ?? ''));
    $linkPath = bugcatcher_notification_normalize_path((string) ($payload['link_path'] ?? '/app/notifications'));
    $severity = trim((string) ($payload['severity'] ?? 'default'));
    if (!in_array($severity, ['default', 'success', 'alert'], true)) {
        $severity = 'default';
    }

    $actorUserId = max(0, (int) ($payload['actor_user_id'] ?? 0));
    $orgId = max(0, (int) ($payload['org_id'] ?? 0));
    $projectId = max(0, (int) ($payload['project_id'] ?? 0));
    $issueId = max(0, (int) ($payload['issue_id'] ?? 0));
    $checklistBatchId = max(0, (int) ($payload['checklist_batch_id'] ?? 0));
    $checklistItemId = max(0, (int) ($payload['checklist_item_id'] ?? 0));
    $metaJson = bugcatcher_notification_meta_json($payload['meta'] ?? null);

    $stmt = $conn->prepare("
        INSERT INTO notifications
            (recipient_user_id, actor_user_id, org_id, project_id, issue_id, checklist_batch_id, checklist_item_id,
             type, event_key, title, body, link_path, severity, meta_json)
        VALUES
            (?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0),
             ?, ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''))
    ");
    $stmt->bind_param(
        'iiiiiiisssssss',
        $recipientUserId,
        $actorUserId,
        $orgId,
        $projectId,
        $issueId,
        $checklistBatchId,
        $checklistItemId,
        $type,
        $eventKey,
        $title,
        $body,
        $linkPath,
        $severity,
        $metaJson
    );
    $stmt->execute();
    $notificationId = (int) $conn->insert_id;
    $stmt->close();

    $notification = bugcatcher_notification_fetch($conn, $recipientUserId, $notificationId);
    bugcatcher_notification_dispatch_realtime($conn, $recipientUserId, 'notification.created', $notification);
}

function bugcatcher_notifications_send(mysqli $conn, array $recipientUserIds, array $payload): void
{
    $unique = [];
    foreach ($recipientUserIds as $recipientUserId) {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId > 0) {
            $unique[$recipientUserId] = true;
        }
    }

    foreach (array_keys($unique) as $recipientUserId) {
        bugcatcher_notification_create($conn, array_merge($payload, [
            'recipient_user_id' => $recipientUserId,
        ]));
    }
}

function bugcatcher_notification_user_ids_for_org_roles(mysqli $conn, int $orgId, array $roles): array
{
    if ($orgId <= 0 || !$roles) {
        return [];
    }

    $roles = array_values(array_unique(array_filter(array_map('strval', $roles))));
    if (!$roles) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $types = 'i' . str_repeat('s', count($roles));
    $params = array_merge([$orgId], $roles);
    $stmt = $conn->prepare("
        SELECT user_id
        FROM org_members
        WHERE org_id = ? AND role IN ({$placeholders})
    ");
    bc_v1_stmt_bind($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_values(array_map(static function (array $row): int {
        return (int) $row['user_id'];
    }, $rows));
}

function bugcatcher_notification_org_owner_ids(mysqli $conn, int $orgId): array
{
    return bugcatcher_notification_user_ids_for_org_roles($conn, $orgId, ['owner']);
}

function bugcatcher_notification_org_manager_ids(mysqli $conn, int $orgId): array
{
    return bugcatcher_notification_user_ids_for_org_roles($conn, $orgId, ['owner', 'Project Manager', 'QA Lead']);
}

function bugcatcher_notifications_list(
    mysqli $conn,
    int $userId,
    string $state = 'all',
    int $limit = 25
): array {
    $limit = max(1, min(100, $limit));
    $readClause = '';
    if ($state === 'read') {
        $readClause = ' AND n.read_at IS NOT NULL';
    } elseif ($state === 'unread') {
        $readClause = ' AND n.read_at IS NULL';
    }

    $stmt = $conn->prepare("
        SELECT n.*,
               actor.username AS actor_username
        FROM notifications n
        LEFT JOIN users actor ON actor.id = n.actor_user_id
        WHERE n.recipient_user_id = ?{$readClause}
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT ?
    ");
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $counts = bugcatcher_notification_counts($conn, $userId);

    return [
        'items' => array_map(static function (array $row) use ($conn, $userId): array {
            return bugcatcher_notification_shape_for_user($conn, $userId, $row);
        }, $rows),
        'unread_count' => $counts['unread_count'],
        'total_count' => $counts['total_count'],
    ];
}

function bugcatcher_notification_mark_read(mysqli $conn, int $userId, int $notificationId): ?array
{
    if ($notificationId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        UPDATE notifications
        SET read_at = COALESCE(read_at, NOW())
        WHERE id = ? AND recipient_user_id = ?
    ");
    $stmt->bind_param('ii', $notificationId, $userId);
    $stmt->execute();
    $stmt->close();

    $notification = bugcatcher_notification_fetch($conn, $userId, $notificationId);
    if ($notification) {
        bugcatcher_notification_dispatch_realtime($conn, $userId, 'notification.read', $notification);
    }

    return $notification;
}

function bugcatcher_notifications_mark_all_read(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("
        UPDATE notifications
        SET read_at = COALESCE(read_at, NOW())
        WHERE recipient_user_id = ? AND read_at IS NULL
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $affected = (int) $stmt->affected_rows;
    $stmt->close();

    bugcatcher_notification_dispatch_realtime($conn, $userId, 'notification.read_all', null, [
        'updated' => $affected,
    ]);

    return $affected;
}
