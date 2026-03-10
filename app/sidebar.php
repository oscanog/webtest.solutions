<?php

require_once __DIR__ . '/bootstrap.php';

function bugcatcher_sidebar_href(string $activePage): string
{
    switch ($activePage) {
        case 'discord_link':
            return '/discord-link.php';
        case 'super_admin':
            return '/super-admin/openclaw.php';
        case 'projects':
            return '/melvin/project_list.php';
        case 'checklist':
            return '/melvin/checklist_list.php';
        case 'organization':
            return '/zen/organization.php';
        case 'dashboard':
        default:
            return '/zen/dashboard.php?page=dashboard';
    }
}

function bugcatcher_render_sidebar(
    string $activePage,
    string $currentUsername,
    string $currentRole,
    ?string $orgRole = null,
    ?string $orgName = null
): void {
    $sidebarId = 'bc-sidebar';
    $nav = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/zen/dashboard.php?page=dashboard'],
        'organization' => ['label' => 'Organization', 'href' => '/zen/organization.php'],
        'projects' => ['label' => 'Projects', 'href' => '/melvin/project_list.php'],
        'checklist' => ['label' => 'Checklist', 'href' => '/melvin/checklist_list.php'],
        'discord_link' => ['label' => 'Discord Link', 'href' => '/discord-link.php'],
    ];
    if (bugcatcher_is_super_admin_role($currentRole)) {
        $nav['super_admin'] = ['label' => 'Super Admin', 'href' => '/super-admin/openclaw.php'];
    }
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
            <?php foreach ($nav as $key => $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $activePage === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            <?php endforeach; ?>
            <a href="/rainier/logout.php" class="logout">Logout</a>
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
