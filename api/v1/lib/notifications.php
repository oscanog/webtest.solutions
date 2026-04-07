<?php

declare(strict_types=1);

function bc_v1_notifications_get(mysqli $conn, array $params): void
{
    bc_v1_require_method(['GET']);
    $actor = bc_v1_actor($conn, true);
    $state = trim((string) ($_GET['state'] ?? 'all'));
    if (!in_array($state, ['all', 'read', 'unread'], true)) {
        $state = 'all';
    }

    $limit = bc_v1_get_int($_GET, 'limit', 25);
    $data = webtest_notifications_list($conn, (int) $actor['user']['id'], $state, $limit);

    bc_v1_json_success($data);
}

function bc_v1_notifications_read_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $notificationId = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    if ($notificationId <= 0) {
        bc_v1_json_error(422, 'invalid_notification', 'Notification id is invalid.');
    }

    $notification = webtest_notification_mark_read($conn, (int) $actor['user']['id'], $notificationId);
    if (!$notification) {
        bc_v1_json_error(404, 'notification_not_found', 'Notification not found.');
    }

    bc_v1_json_success([
        'updated' => true,
        'notification' => $notification,
    ]);
}

function bc_v1_notifications_read_all_post(mysqli $conn, array $params): void
{
    bc_v1_require_method(['POST']);
    $actor = bc_v1_actor($conn, true);
    $updated = webtest_notifications_mark_all_read($conn, (int) $actor['user']['id']);

    bc_v1_json_success([
        'updated' => $updated,
    ]);
}
