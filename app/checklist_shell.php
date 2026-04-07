<?php

require_once __DIR__ . '/sidebar.php';

function webtest_shell_start(
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
        <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(webtest_path('favicon.svg')) ?>">
        <title><?= htmlspecialchars($pageTitle) ?> | WebTest</title>
        <?php webtest_render_theme_bootstrap(); ?>
        <link rel="stylesheet" href="<?= htmlspecialchars(webtest_asset_path('app/legacy_theme.css')) ?>">
        <?php foreach ($extraStyles as $href): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars(webtest_href((string) $href)) ?>">
        <?php endforeach; ?>
    </head>
    <body>
    <?php webtest_render_sidebar(
        $activePage,
        $context['current_username'],
        $context['current_role'],
        $context['org_role'],
        $context['org_name']
    ); ?>
    <main class="bc-main">
        <?php webtest_render_page_header(
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

function webtest_shell_end(array $extraScripts = []): void
{
    ?>
        </section>
    </main>
    <script src="<?= htmlspecialchars(webtest_asset_path('app/toast.js')) ?>"></script>
    <?php webtest_render_legacy_ui_script(); ?>
    <script src="<?= htmlspecialchars(webtest_asset_path('app/mobile_nav.js')) ?>"></script>
    <script src="<?= htmlspecialchars(webtest_asset_path('app/notifications_ui.js')) ?>"></script>
    <?php foreach ($extraScripts as $src): ?>
        <script src="<?= htmlspecialchars(webtest_href((string) $src)) ?>"></script>
    <?php endforeach; ?>
    </body>
    </html>
    <?php
}
