<?php

require_once __DIR__ . '/bootstrap.php';

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
            <strong><?= htmlspecialchars($currentUsername) ?></strong>
            <span>(<?= htmlspecialchars($currentRole) ?><?= $orgRole ? ' / ' . htmlspecialchars($orgRole) : '' ?>)</span>
            <?php if ($orgName): ?>
                <small><?= htmlspecialchars($orgName) ?></small>
            <?php endif; ?>
        </div>
    </aside>
    <?php
}
