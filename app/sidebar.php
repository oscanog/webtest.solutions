<?php

function bugcatcher_sidebar_href(string $activePage): string
{
    switch ($activePage) {
        case 'projects':
            return '/project-passed-by-melvin/project_list.php';
        case 'checklist':
            return '/checklist-passed-by-melvin/checklist_list.php';
        case 'organization':
            return '/organization.php';
        case 'dashboard':
        default:
            return '/dashboard.php?page=dashboard';
    }
}

function bugcatcher_render_sidebar(
    string $activePage,
    string $currentUsername,
    string $currentRole,
    ?string $orgRole = null,
    ?string $orgName = null
): void {
    $nav = [
        'dashboard' => ['label' => 'Dashboard', 'href' => '/dashboard.php?page=dashboard'],
        'organization' => ['label' => 'Organization', 'href' => '/organization.php'],
        'projects' => ['label' => 'Projects', 'href' => '/project-passed-by-melvin/project_list.php'],
        'checklist' => ['label' => 'Checklist', 'href' => '/checklist-passed-by-melvin/checklist_list.php'],
    ];
    ?>
    <aside class="bc-sidebar">
        <div class="bc-logo">BugCatcher</div>
        <nav class="bc-nav">
            <?php foreach ($nav as $key => $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $activePage === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            <?php endforeach; ?>
            <a href="/register-passed-by-maglaque/logout.php" class="logout">Logout</a>
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
