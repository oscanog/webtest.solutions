<?php

function bugcatcher_theme_storage_key(): string
{
    return 'bugcatcher-theme';
}

function bugcatcher_render_theme_bootstrap(): void
{
    $storageKey = json_encode(bugcatcher_theme_storage_key(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>
    <script>
        (function () {
            var storageKey = <?= $storageKey ?>;
            var theme = "light";

            try {
                var stored = window.localStorage.getItem(storageKey);
                if (stored === "light" || stored === "dark") {
                    theme = stored;
                }
            } catch (error) {
                theme = "light";
            }

            var root = document.documentElement;
            root.setAttribute("data-theme", theme);
            root.style.colorScheme = theme;
        })();
    </script>
    <?php
}

function bugcatcher_render_theme_toggle(): void
{
    ?>
    <button
        type="button"
        class="bc-theme-toggle"
        data-theme-toggle
        aria-label="Switch theme"
        title="Switch theme"
    >
        <span class="bc-theme-toggle__track" aria-hidden="true">
            <span class="bc-theme-toggle__thumb">
                <svg
                    class="bc-theme-toggle__icon bc-theme-toggle__icon--sun"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <circle cx="12" cy="12" r="4"></circle>
                    <path d="M12 2.5v2.5M12 19v2.5M21.5 12H19M5 12H2.5M18.7 5.3l-1.8 1.8M7.1 16.9l-1.8 1.8M18.7 18.7l-1.8-1.8M7.1 7.1 5.3 5.3"></path>
                </svg>
                <svg
                    class="bc-theme-toggle__icon bc-theme-toggle__icon--moon"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M20 14.5A7.5 7.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5Z"></path>
                </svg>
            </span>
        </span>
        <span class="bc-theme-toggle__text" data-theme-toggle-label>Light</span>
    </button>
    <?php
}

function bugcatcher_render_legacy_ui_script(): void
{
    ?>
    <script src="<?= htmlspecialchars(bugcatcher_asset_path('app/legacy_ui.js')) ?>"></script>
    <?php
}
