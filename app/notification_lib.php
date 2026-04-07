<?php

declare(strict_types=1);

function webtest_notification_stmt_bind_params(mysqli_stmt $stmt, string $types, array $params): void
{
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function webtest_notification_normalize_path(string $path): string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return '/app/notifications';
    }

    return '/' . ltrim($trimmed, '/');
}

function webtest_notification_meta_json(?array $meta): ?string
{
    if (!$meta) {
        return null;
    }

    return json_encode($meta, JSON_UNESCAPED_SLASHES);
}

function webtest_notification_parse_meta(?string $metaJson): ?array
{
    if (!is_string($metaJson) || trim($metaJson) === '') {
        return null;
    }

    $decoded = json_decode($metaJson, true);
    return is_array($decoded) ? $decoded : null;
}

function webtest_notification_shape(array $row): array
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
        'meta' => webtest_notification_parse_meta($row['meta_json'] ?? null),
    ];
}

function webtest_notification_recipient_can_manage_checklist(mysqli $conn, int $userId, int $orgId): bool
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

function webtest_notification_resolve_link_path(mysqli $conn, int $userId, array $row): string
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
    if (webtest_notification_recipient_can_manage_checklist($conn, $userId, $orgId)) {
        return $linkPath;
    }

    $batchId = (int) ($item['batch_id'] ?? 0);
    if ($batchId > 0 && webtest_notification_recipient_can_manage_checklist($conn, $userId, $orgId)) {
        return '/app/checklist/batches/' . $batchId;
    }

    return '/app/notifications';
}

function webtest_notification_shape_for_user(mysqli $conn, int $userId, array $row): array
{
    $shape = webtest_notification_shape($row);
    $shape['link_path'] = webtest_notification_resolve_link_path($conn, $userId, $row);
    $shape['legacy_path'] = webtest_notification_legacy_destination(
        $shape['link_path'],
        isset($shape['org_id']) ? (int) $shape['org_id'] : 0
    );
    return $shape;
}

function webtest_notification_legacy_fallback_path(): string
{
    return webtest_path('app/notifications.php');
}

function webtest_notification_legacy_destination(string $linkPath, int $orgId = 0): string
{
    $normalized = webtest_notification_normalize_path($linkPath);
    $path = (string) (parse_url($normalized, PHP_URL_PATH) ?? '');
    $query = (string) (parse_url($normalized, PHP_URL_QUERY) ?? '');
    $queryParts = [];
    parse_str($query, $queryParts);
    if ($orgId > 0 && !array_key_exists('org_id', $queryParts)) {
        $queryParts['org_id'] = $orgId;
    }

    $suffix = $queryParts ? ('?' . http_build_query($queryParts)) : '';

    if ($path === '/app/notifications') {
        $target = webtest_notification_legacy_fallback_path();
        return $target . $suffix;
    }

    if ($path === '/app/organizations') {
        return webtest_path('zen/organization.php') . $suffix;
    }

    if ($path === '/app/projects') {
        return webtest_path('melvin/project_list.php') . $suffix;
    }

    if ($path === '/app/checklist') {
        return webtest_path('melvin/checklist_list.php') . $suffix;
    }

    if ($path === '/app/reports') {
        $issueQuery = array_merge([
            'page' => 'issues',
            'view' => 'kanban',
            'status' => 'all',
        ], $queryParts);

        return webtest_path('zen/dashboard.php?' . http_build_query($issueQuery));
    }

    if (preg_match('#^/app/reports/(\d+)$#', $path, $matches)) {
        $issueQuery = array_merge(['id' => (int) $matches[1]], $queryParts);
        return webtest_path('zen/issue_detail.php?' . http_build_query($issueQuery));
    }

    if (preg_match('#^/app/projects/(\d+)$#', $path, $matches)) {
        $projectQuery = array_merge(['id' => (int) $matches[1]], $queryParts);
        return webtest_path('melvin/project_detail.php?' . http_build_query($projectQuery));
    }

    if (preg_match('#^/app/checklist/batches/(\d+)$#', $path, $matches)) {
        $batchQuery = array_merge(['id' => (int) $matches[1]], $queryParts);
        return webtest_path('melvin/checklist_batch.php?' . http_build_query($batchQuery));
    }

    if (preg_match('#^/app/checklist/items/(\d+)$#', $path, $matches)) {
        $itemQuery = array_merge(['id' => (int) $matches[1]], $queryParts);
        return webtest_path('melvin/checklist_item.php?' . http_build_query($itemQuery));
    }

    return webtest_notification_legacy_fallback_path();
}

function webtest_notifications_bootstrap(
    mysqli $conn,
    int $userId,
    int $limit = 10,
    string $state = 'all'
): array {
    $data = webtest_notifications_list($conn, $userId, $state, $limit);

    return [
        'items' => array_map(static function (array $item): array {
            return webtest_augment_datetime_iso_fields($item);
        }, $data['items']),
        'unread_count' => (int) ($data['unread_count'] ?? 0),
        'total_count' => (int) ($data['total_count'] ?? 0),
        'state' => $state,
        'limit' => max(1, min(100, $limit)),
    ];
}

function webtest_realtime_notifications_enabled(): bool
{
    return (bool) webtest_config('REALTIME_NOTIFICATIONS_ENABLED', true);
}

function webtest_realtime_notifications_host(): string
{
    return trim((string) webtest_config('REALTIME_NOTIFICATIONS_HOST', '127.0.0.1'));
}

function webtest_realtime_notifications_port(): int
{
    return max(1, (int) webtest_config('REALTIME_NOTIFICATIONS_PORT', 8090));
}

function webtest_realtime_notifications_internal_shared_secret(): string
{
    $secret = trim((string) webtest_config('REALTIME_NOTIFICATIONS_INTERNAL_SHARED_SECRET', ''));
    if ($secret === '') {
        $secret = trim((string) webtest_config('OPENCLAW_INTERNAL_SHARED_SECRET', ''));
    }
    if ($secret === '' || $secret === 'replace-me-too') {
        $secret = 'webtest-realtime-dev-secret';
    }

    return $secret;
}

function webtest_realtime_notifications_publish_url(): string
{
    $host = webtest_realtime_notifications_host();
    $port = webtest_realtime_notifications_port();
    if ($host === '' || $port <= 0) {
        return '';
    }

    return sprintf('http://%s:%d/internal/publish', $host, $port);
}

function webtest_notification_log(string $message): void
{
    error_log('[webtest-realtime] ' . $message);
}

function webtest_notification_fetch(mysqli $conn, int $userId, int $notificationId): ?array
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

    return $row ? webtest_notification_shape_for_user($conn, $userId, $row) : null;
}

function webtest_notification_counts(mysqli $conn, int $userId): array
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

function webtest_notification_realtime_publish(array $payload): void
{
    static $suppressUntil = 0.0;

    if (!webtest_realtime_notifications_enabled()) {
        return;
    }

    if ($suppressUntil > microtime(true)) {
        return;
    }

    $url = webtest_realtime_notifications_publish_url();
    $secret = webtest_realtime_notifications_internal_shared_secret();
    if ($url === '' || $secret === '') {
        return;
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        webtest_notification_log('Unable to encode realtime payload.');
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
            'timeout' => 0.25,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($result === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        $suppressUntil = microtime(true) + 5.0;
        webtest_notification_log('Publish failed for ' . ($payload['type'] ?? 'unknown') . ' (' . $statusLine . ')');
        return;
    }

    $suppressUntil = 0.0;
}

function webtest_notification_dispatch_realtime(
    mysqli $conn,
    int $userId,
    string $type,
    ?array $notification = null,
    array $extra = []
): void {
    if ($userId <= 0) {
        return;
    }

    $counts = webtest_notification_counts($conn, $userId);
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

    webtest_notification_realtime_publish($payload);
}

function webtest_notification_create(mysqli $conn, array $payload): void
{
    $recipientUserId = (int) ($payload['recipient_user_id'] ?? 0);
    if ($recipientUserId <= 0) {
        return;
    }

    $type = trim((string) ($payload['type'] ?? 'system'));
    $eventKey = trim((string) ($payload['event_key'] ?? 'notification'));
    $title = trim((string) ($payload['title'] ?? 'Notification'));
    $body = trim((string) ($payload['body'] ?? ''));
    $linkPath = webtest_notification_normalize_path((string) ($payload['link_path'] ?? '/app/notifications'));
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
    $metaJson = webtest_notification_meta_json($payload['meta'] ?? null);

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

    $notification = webtest_notification_fetch($conn, $recipientUserId, $notificationId);
    webtest_notification_dispatch_realtime($conn, $recipientUserId, 'notification.created', $notification);
}

function webtest_notifications_send(mysqli $conn, array $recipientUserIds, array $payload): void
{
    $unique = [];
    foreach ($recipientUserIds as $recipientUserId) {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId > 0) {
            $unique[$recipientUserId] = true;
        }
    }

    foreach (array_keys($unique) as $recipientUserId) {
        webtest_notification_create($conn, array_merge($payload, [
            'recipient_user_id' => $recipientUserId,
        ]));
    }
}

function webtest_notification_user_ids_for_org_roles(mysqli $conn, int $orgId, array $roles): array
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
    webtest_notification_stmt_bind_params($stmt, $types, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_values(array_map(static function (array $row): int {
        return (int) $row['user_id'];
    }, $rows));
}

function webtest_notification_org_owner_ids(mysqli $conn, int $orgId): array
{
    return webtest_notification_user_ids_for_org_roles($conn, $orgId, ['owner']);
}

function webtest_notification_org_manager_ids(mysqli $conn, int $orgId): array
{
    return webtest_notification_user_ids_for_org_roles($conn, $orgId, ['owner', 'Project Manager', 'QA Lead']);
}

function webtest_notifications_list(
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

    $counts = webtest_notification_counts($conn, $userId);

    return [
        'items' => array_map(static function (array $row) use ($conn, $userId): array {
            return webtest_notification_shape_for_user($conn, $userId, $row);
        }, $rows),
        'unread_count' => $counts['unread_count'],
        'total_count' => $counts['total_count'],
    ];
}

function webtest_notification_mark_read(mysqli $conn, int $userId, int $notificationId): ?array
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

    $notification = webtest_notification_fetch($conn, $userId, $notificationId);
    if ($notification) {
        webtest_notification_dispatch_realtime($conn, $userId, 'notification.read', $notification);
    }

    return $notification;
}

function webtest_notifications_mark_all_read(mysqli $conn, int $userId): int
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

    webtest_notification_dispatch_realtime($conn, $userId, 'notification.read_all', null, [
        'updated' => $affected,
    ]);

    return $affected;
}
