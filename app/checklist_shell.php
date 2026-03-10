<?php

require_once __DIR__ . '/sidebar.php';

function bugcatcher_shell_start(
    string $pageTitle,
    string $activePage,
    array $context,
    ?array $actions = null
): void {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <title><?= htmlspecialchars($pageTitle) ?> | BugCatcher</title>
        <link rel="stylesheet" href="/app/checklist_theme.css?v=1">
    </head>
    <body>
    <?php bugcatcher_render_sidebar(
        $activePage,
        $context['current_username'],
        $context['current_role'],
        $context['org_role'],
        $context['org_name']
    ); ?>
    <main class="bc-main">
        <header class="bc-topbar">
            <div>
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                <p><?= htmlspecialchars($context['org_name']) ?></p>
            </div>
            <?php if (!empty($actions)): ?>
                <div class="bc-actions">
                    <?php foreach ($actions as $action): ?>
                        <a class="bc-btn <?= !empty($action['variant']) ? htmlspecialchars($action['variant']) : '' ?>"
                           href="<?= htmlspecialchars($action['href']) ?>">
                            <?= htmlspecialchars($action['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </header>
        <section class="bc-content">
    <?php
}

function bugcatcher_shell_end(): void
{
    ?>
        </section>
    </main>
    <script src="/app/mobile_nav.js?v=1"></script>
    </body>
    </html>
    <?php
}
