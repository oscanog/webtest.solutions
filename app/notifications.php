<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/auth_org.php';
require_once __DIR__ . '/checklist_shell.php';

$userStmt = $conn->prepare('SELECT username, email, role FROM users WHERE id = ? LIMIT 1');
$userStmt->bind_param('i', $current_user_id);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    http_response_code(404);
    die('User account was not found.');
}

$activeOrgId = (int) ($_SESSION['active_org_id'] ?? 0);
$membership = $activeOrgId > 0 ? bugcatcher_fetch_org_membership($conn, $activeOrgId, $current_user_id) : null;
$orgRole = is_array($membership) ? (string) ($membership['role'] ?? '') : null;
$orgName = is_array($membership) ? (string) ($membership['org_name'] ?? '') : null;
$pageData = bugcatcher_notifications_bootstrap($conn, $current_user_id, 50, 'all');
$items = is_array($pageData['items'] ?? null) ? $pageData['items'] : [];
$unreadCount = (int) ($pageData['unread_count'] ?? 0);
$readCount = max(0, count($items) - $unreadCount);
$priorityCount = count(array_filter($items, static function (array $item): bool {
    return empty($item['read_at']) && (string) ($item['severity'] ?? 'default') === 'alert';
}));

$context = [
    'current_username' => (string) $user['username'],
    'current_role' => $current_role,
    'org_role' => $orgRole,
    'org_name' => $orgName,
];

function bugcatcher_render_notifications_page_rows(array $items): void
{
    if (!$items) {
        ?>
        <div class="bc-card bc-notifications-empty-state">
            <strong>Inbox is clear.</strong>
            <p>There are no notifications to review right now.</p>
        </div>
        <?php
        return;
    }

    foreach ($items as $item):
        $notificationId = (int) ($item['id'] ?? 0);
        $destination = trim((string) ($item['legacy_path'] ?? bugcatcher_notification_legacy_fallback_path()));
        $isUnread = empty($item['read_at']);
        $severity = (string) ($item['severity'] ?? 'default');
        ?>
        <a
            href="<?= htmlspecialchars($destination) ?>"
            class="bc-notification-row <?= $isUnread ? 'is-unread' : 'is-read' ?>"
            data-notification-item
            data-notification-id="<?= $notificationId ?>"
            data-notification-destination="<?= htmlspecialchars($destination) ?>"
        >
            <div class="bc-notification-row__main">
                <div class="bc-notification-row__topline">
                    <span class="bc-notification-tag severity-<?= htmlspecialchars($severity) ?>">
                        <?= htmlspecialchars(bugcatcher_notification_severity_label($severity)) ?>
                    </span>
                    <span class="bc-notification-state <?= $isUnread ? 'is-unread' : 'is-read' ?>">
                        <?= $isUnread ? 'Unread' : 'Read' ?>
                    </span>
                    <span class="bc-notification-time" data-notification-created-at="<?= htmlspecialchars((string) ($item['created_at'] ?? '')) ?>">
                        <?= htmlspecialchars(bugcatcher_notification_time_label((string) ($item['created_at'] ?? ''))) ?>
                    </span>
                </div>
                <strong class="bc-notification-row__title"><?= htmlspecialchars((string) ($item['title'] ?? 'Notification')) ?></strong>
                <?php if (trim((string) ($item['body'] ?? '')) !== ''): ?>
                    <p class="bc-notification-row__body"><?= htmlspecialchars((string) $item['body']) ?></p>
                <?php endif; ?>
            </div>
            <span class="bc-notification-row__cta">Open</span>
        </a>
    <?php
    endforeach;
}

bugcatcher_shell_start(
    'Notifications',
    'notifications',
    $context,
    null,
    ['/app/legacy_notifications.css?v=1']
);
?>

<div
    class="bc-notifications-page"
    data-notifications-page
    data-notifications-initial="<?= bugcatcher_json_attr($pageData) ?>"
>
    <section class="bc-card bc-notifications-hero">
        <div class="bc-notifications-hero__copy">
            <span class="bc-notifications-eyebrow">In-App Inbox</span>
            <h2>Stay on top of project movement</h2>
            <p>The latest alerts across issues, projects, and checklist work land here first. Open a notification to jump straight into the legacy workflow it belongs to.</p>
        </div>
        <div class="bc-notifications-hero__actions">
            <button type="button" class="bc-btn secondary" data-notifications-mark-all-page<?= $unreadCount <= 0 ? ' disabled' : '' ?>>
                Mark all read
            </button>
        </div>
    </section>

    <div data-notifications-page-message>
        <div class="bc-alert info">Connecting live notifications...</div>
    </div>

    <section class="bc-grid cols-3 bc-notifications-stats" data-notifications-page-stats>
        <div class="bc-stat bc-stat--alert">
            <span>Unread</span>
            <strong><?= $unreadCount ?></strong>
            <small>new</small>
        </div>
        <div class="bc-stat bc-stat--success">
            <span>Read</span>
            <strong><?= $readCount ?></strong>
            <small>seen</small>
        </div>
        <div class="bc-stat bc-stat--steel">
            <span>Priority</span>
            <strong><?= $priorityCount ?></strong>
            <small>need action</small>
        </div>
    </section>

    <section class="bc-card bc-notifications-inbox">
        <div class="bc-notifications-inbox__head">
            <div>
                <span class="bc-notifications-eyebrow">Recent Activity</span>
                <h2>Latest 50 notifications</h2>
                <p>Unread items stay highlighted so the queue is easy to triage at a glance.</p>
            </div>
        </div>
        <div class="bc-notifications-list-page" data-notifications-page-list>
            <?php bugcatcher_render_notifications_page_rows($items); ?>
        </div>
    </section>
</div>

<?php bugcatcher_shell_end(); ?>
