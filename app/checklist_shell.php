<?php

require_once __DIR__ . '/sidebar.php';

function bugcatcher_shell_start(
    string $pageTitle,
    string $activePage,
    array $context,
    ?array $actions = null,
    array $extraStyles = []
): void {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
        <title><?= htmlspecialchars($pageTitle) ?> | BugCatcher</title>
        <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('app/legacy_theme.css?v=5')) ?>">
        <?php foreach ($extraStyles as $href): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_href((string) $href)) ?>">
        <?php endforeach; ?>
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
        <?php bugcatcher_render_page_header(
            $pageTitle,
            $context['current_username'],
            $context['current_role'],
            $context['org_role'],
            $context['org_name'],
            $actions
        ); ?>
        <section class="bc-content">
    <?php
}

function bugcatcher_shell_end(array $extraScripts = []): void
{
    ?>
        </section>
    </main>
    <script src="<?= htmlspecialchars(bugcatcher_path('app/mobile_nav.js?v=3')) ?>"></script>
    <script src="<?= htmlspecialchars(bugcatcher_path('app/notifications_ui.js?v=1')) ?>"></script>
    <?php foreach ($extraScripts as $src): ?>
        <script src="<?= htmlspecialchars(bugcatcher_href((string) $src)) ?>"></script>
    <?php endforeach; ?>
    </body>
    </html>
    <?php
}
