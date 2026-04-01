<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/notification_lib.php';

function bugcatcher_sidebar_definitions(): array
{
    return [
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'href' => bugcatcher_path('zen/dashboard.php?page=dashboard'),
            'roles' => null,
        ],
        [
            'key' => 'issues',
            'label' => 'Issues',
            'href' => bugcatcher_path('zen/dashboard.php?page=issues&view=kanban&status=all'),
            'roles' => null,
        ],
        [
            'key' => 'organization',
            'label' => 'Organization',
            'href' => bugcatcher_path('zen/organization.php'),
            'roles' => null,
        ],
        [
            'key' => 'projects',
            'label' => 'Projects',
            'href' => bugcatcher_path('melvin/project_list.php'),
            'roles' => null,
        ],
        [
            'key' => 'checklist',
            'label' => 'Checklist',
            'href' => bugcatcher_path('melvin/checklist_list.php'),
            'roles' => null,
        ],
        [
            'key' => 'super_admin',
            'label' => 'AI Admin',
            'href' => bugcatcher_path('super-admin/ai.php'),
            'roles' => ['super_admin'],
        ],
    ];
}

function bugcatcher_sidebar_items(string $currentRole): array
{
    $normalizedRole = bugcatcher_normalize_system_role($currentRole);

    return array_values(array_filter(
        bugcatcher_sidebar_definitions(),
        static function (array $item) use ($normalizedRole): bool {
            $roles = $item['roles'] ?? null;
            return $roles === null || in_array($normalizedRole, $roles, true);
        }
    ));
}

function bugcatcher_sidebar_href(string $activePage): string
{
    foreach (bugcatcher_sidebar_definitions() as $item) {
        if (($item['key'] ?? '') === $activePage) {
            return (string) ($item['href'] ?? bugcatcher_path('zen/dashboard.php?page=dashboard'));
        }
    }

    return bugcatcher_path('zen/dashboard.php?page=dashboard');
}

function bugcatcher_display_role_label(string $currentRole, ?string $orgRole = null): string
{
    $orgRole = trim((string) $orgRole);
    if ($orgRole !== '') {
        return $orgRole;
    }

    return $currentRole;
}

function bugcatcher_user_initials(string $username): string
{
    $username = trim($username);
    if ($username === '') {
        return 'U';
    }

    $parts = preg_split('/[\s._-]+/', $username) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    if ($initials !== '') {
        return $initials;
    }

    return strtoupper(substr($username, 0, 2));
}

function bugcatcher_json_attr(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        $json = '{}';
    }

    return htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
}

function bugcatcher_notification_header_bootstrap(): array
{
    static $bootstrap = null;
    if ($bootstrap !== null) {
        return $bootstrap;
    }

    $bootstrap = [
        'items' => [],
        'unread_count' => 0,
        'total_count' => 0,
        'limit' => 10,
        'page_path' => bugcatcher_path('app/notifications.php'),
        'notifications_endpoint' => bugcatcher_path('api/v1/notifications'),
        'read_endpoint_template' => bugcatcher_path('api/v1/notifications/__ID__/read'),
        'read_all_endpoint' => bugcatcher_path('api/v1/notifications/read-all'),
        'socket_token_endpoint' => bugcatcher_path('api/v1/realtime/socket-token'),
    ];

    $conn = $GLOBALS['conn'] ?? null;
    $userId = (int) ($GLOBALS['current_user_id'] ?? ($_SESSION['user_id'] ?? 0));
    if (!$conn instanceof mysqli || $userId <= 0) {
        return $bootstrap;
    }

    $data = bugcatcher_notifications_bootstrap($conn, $userId, 10, 'all');
    $bootstrap['items'] = $data['items'];
    $bootstrap['unread_count'] = (int) ($data['unread_count'] ?? 0);
    $bootstrap['total_count'] = (int) ($data['total_count'] ?? 0);

    return $bootstrap;
}

function bugcatcher_notification_count_label(int $count): string
{
    if ($count <= 0) {
        return '0';
    }

    if ($count > 99) {
        return '99+';
    }

    return (string) $count;
}

function bugcatcher_notification_severity_label(string $severity): string
{
    if ($severity === 'alert') {
        return 'Priority';
    }

    if ($severity === 'success') {
        return 'Update';
    }

    return 'Info';
}

function bugcatcher_notification_time_label(string $createdAt): string
{
    $timestamp = strtotime($createdAt);
    if ($timestamp === false) {
        return trim($createdAt) !== '' ? $createdAt : 'Just now';
    }

    return date('M j, Y g:i A', $timestamp);
}

function bugcatcher_render_notification_items(array $items): void
{
    if (!$items) {
        ?>
        <div class="bc-notification-empty" data-notification-empty>
            No notifications yet. New project, issue, and checklist updates will appear here.
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
            class="bc-notification-item <?= $isUnread ? 'is-unread' : 'is-read' ?>"
            data-notification-item
            data-notification-id="<?= $notificationId ?>"
            data-notification-destination="<?= htmlspecialchars($destination) ?>"
        >
            <div class="bc-notification-copy">
                <strong class="bc-notification-title"><?= htmlspecialchars((string) ($item['title'] ?? 'Notification')) ?></strong>
                <?php if (trim((string) ($item['body'] ?? '')) !== ''): ?>
                    <p class="bc-notification-body"><?= htmlspecialchars((string) $item['body']) ?></p>
                <?php endif; ?>
            </div>
            <div class="bc-notification-meta">
                <span class="bc-notification-tag severity-<?= htmlspecialchars($severity) ?>">
                    <?= htmlspecialchars(bugcatcher_notification_severity_label($severity)) ?>
                </span>
                <span class="bc-notification-time" data-notification-created-at="<?= htmlspecialchars((string) ($item['created_at'] ?? '')) ?>">
                    <?= htmlspecialchars(bugcatcher_notification_time_label((string) ($item['created_at'] ?? ''))) ?>
                </span>
            </div>
        </a>
        <?php
    endforeach;
}

function bugcatcher_render_page_header(
    string $title,
    string $currentUsername,
    string $currentRole,
    ?string $orgRole = null,
    ?string $subtitle = null,
    ?array $actions = null
): void {
    static $menuCounter = 0;
    $menuCounter++;
    $displayRole = bugcatcher_display_role_label($currentRole, $orgRole);
    $avatarInitials = bugcatcher_user_initials($currentUsername);
    $menuId = 'bc-user-menu-' . $menuCounter;
    $notificationsId = 'bc-notifications-menu-' . $menuCounter;
    $subtitle = trim((string) $subtitle);
    $actions = $actions ?? [];
    $notificationBootstrap = bugcatcher_notification_header_bootstrap();
    $unreadCount = (int) ($notificationBootstrap['unread_count'] ?? 0);
    ?>
    <header class="bc-page-header">
        <div class="bc-topbar topbar">
            <div class="bc-topbar-copy">
                <h1><?= htmlspecialchars($title) ?></h1>
                <?php if ($subtitle !== ''): ?>
                    <p class="bc-topbar-subtitle"><?= htmlspecialchars($subtitle) ?></p>
                <?php endif; ?>
            </div>
            <div class="bc-session topbar-right">
                <span class="bc-session-copy">
                    Welcome,
                    <span data-session-username><?= htmlspecialchars($currentUsername) ?></span>
                    (<span data-session-role><?= htmlspecialchars($displayRole) ?></span>)
                </span>
                <div
                    class="bc-notifications-menu"
                    data-notifications-root
                    data-notifications-page-url="<?= htmlspecialchars((string) ($notificationBootstrap['page_path'] ?? bugcatcher_path('app/notifications.php'))) ?>"
                    data-notifications-endpoint="<?= htmlspecialchars((string) ($notificationBootstrap['notifications_endpoint'] ?? bugcatcher_path('api/v1/notifications'))) ?>"
                    data-notification-read-template="<?= htmlspecialchars((string) ($notificationBootstrap['read_endpoint_template'] ?? bugcatcher_path('api/v1/notifications/__ID__/read'))) ?>"
                    data-notification-read-all-endpoint="<?= htmlspecialchars((string) ($notificationBootstrap['read_all_endpoint'] ?? bugcatcher_path('api/v1/notifications/read-all'))) ?>"
                    data-notification-socket-endpoint="<?= htmlspecialchars((string) ($notificationBootstrap['socket_token_endpoint'] ?? bugcatcher_path('api/v1/realtime/socket-token'))) ?>"
                    data-notifications-initial="<?= bugcatcher_json_attr($notificationBootstrap) ?>"
                >
                    <button
                        type="button"
                        class="bc-notifications-trigger"
                        data-notification-trigger
                        aria-haspopup="menu"
                        aria-expanded="false"
                        aria-controls="<?= htmlspecialchars($notificationsId) ?>"
                        aria-label="Open notifications"
                    >
                        <span class="bc-notifications-trigger-label">Notifications</span>
                        <span class="bc-notifications-badge<?= $unreadCount > 0 ? '' : ' is-empty' ?>" data-notification-count>
                            <?= htmlspecialchars(bugcatcher_notification_count_label($unreadCount)) ?>
                        </span>
                    </button>
                    <div
                        class="bc-notifications-panel"
                        id="<?= htmlspecialchars($notificationsId) ?>"
                        data-notification-panel
                        role="menu"
                        hidden
                    >
                        <div class="bc-notifications-panel-head">
                            <div>
                                <strong>Notifications</strong>
                                <p>Latest updates across your workspace.</p>
                            </div>
                            <button type="button" class="bc-notifications-link" data-notification-mark-all>
                                Mark all read
                            </button>
                        </div>
                        <div class="bc-notifications-list" data-notification-list>
                            <?php bugcatcher_render_notification_items((array) ($notificationBootstrap['items'] ?? [])); ?>
                        </div>
                        <div class="bc-notifications-panel-foot">
                            <a href="<?= htmlspecialchars((string) ($notificationBootstrap['page_path'] ?? bugcatcher_path('app/notifications.php'))) ?>" class="bc-notifications-link" data-notification-show-more>
                                Show more
                            </a>
                        </div>
                    </div>
                </div>
                <div class="bc-user-menu" data-user-menu>
                    <button
                        type="button"
                        class="bc-user-menu-trigger"
                        data-user-menu-trigger
                        aria-haspopup="menu"
                        aria-expanded="false"
                        aria-controls="<?= htmlspecialchars($menuId) ?>"
                        aria-label="Open user profile menu"
                    >
                        <span class="bc-user-menu-avatar" data-session-avatar><?= htmlspecialchars($avatarInitials) ?></span>
                    </button>
                    <div
                        class="bc-user-menu-panel"
                        id="<?= htmlspecialchars($menuId) ?>"
                        data-user-menu-panel
                        role="menu"
                        hidden
                    >
                        <a href="<?= htmlspecialchars(bugcatcher_path('app/profile.php')) ?>" class="bc-user-menu-item" role="menuitem">
                            Profile
                        </a>
                        <a href="<?= htmlspecialchars(bugcatcher_path('rainier/logout.php')) ?>" class="bc-user-menu-item danger" role="menuitem">
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php if (!empty($actions)): ?>
            <div class="bc-subheader">
                <div class="bc-subheader-actions">
                    <?php foreach ($actions as $action): ?>
                        <a
                            class="bc-btn <?= !empty($action['variant']) ? htmlspecialchars((string) $action['variant']) : '' ?>"
                            href="<?= htmlspecialchars(bugcatcher_href((string) ($action['href'] ?? '#'))) ?>"
                        >
                            <?= htmlspecialchars((string) ($action['label'] ?? 'Action')) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </header>
    <?php
}

function bugcatcher_render_sidebar(
    string $activePage,
    string $currentUsername,
    string $currentRole,
    ?string $orgRole = null,
    ?string $orgName = null
): void {
    $sidebarId = 'bc-sidebar';
    $nav = bugcatcher_sidebar_items($currentRole);
    ?>
    <button
        type="button"
        class="bc-mobile-toggle"
        data-drawer-toggle
        data-drawer-target="<?= htmlspecialchars($sidebarId) ?>"
        aria-controls="<?= htmlspecialchars($sidebarId) ?>"
        aria-expanded="false"
        aria-label="Open navigation menu"
    >
        <span></span>
        <span></span>
        <span></span>
    </button>
    <div class="bc-mobile-backdrop" data-drawer-backdrop hidden></div>
    <aside
        class="bc-sidebar"
        id="<?= htmlspecialchars($sidebarId) ?>"
        data-drawer
        data-drawer-breakpoint="960"
    >
        <div class="bc-logo">BugCatcher</div>
        <nav class="bc-nav">
            <?php foreach ($nav as $item): ?>
                <?php $key = (string) ($item['key'] ?? ''); ?>
                <a href="<?= htmlspecialchars((string) ($item['href'] ?? '#')) ?>" class="<?= $activePage === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars((string) ($item['label'] ?? 'Page')) ?>
                </a>
            <?php endforeach; ?>
            <a href="<?= htmlspecialchars(bugcatcher_path('rainier/logout.php')) ?>" class="logout">Logout</a>
        </nav>
        <div class="bc-userbox">
            <div>Logged in as</div>
            <strong data-session-sidebar-username><?= htmlspecialchars($currentUsername) ?></strong>
            <span>(<?= htmlspecialchars($currentRole) ?><?= $orgRole ? ' / ' . htmlspecialchars($orgRole) : '' ?>)</span>
            <?php if ($orgName): ?>
                <small><?= htmlspecialchars($orgName) ?></small>
            <?php endif; ?>
        </div>
    </aside>
    <?php
}
