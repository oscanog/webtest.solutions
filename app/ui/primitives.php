<?php

function bugcatcher_ui_button_variant(?string $variant = null): string
{
    $normalized = trim((string) $variant);
    if ($normalized === '') {
        return 'primary';
    }

    return match ($normalized) {
        'secondary', 'ghost', 'danger', 'quiet' => $normalized,
        default => 'primary',
    };
}

function bugcatcher_ui_button_classes(?string $variant = null, string $extraClasses = ''): string
{
    $classes = ['bc-btn', 'bc-btn--' . bugcatcher_ui_button_variant($variant)];
    $extraClasses = trim($extraClasses);
    if ($extraClasses !== '') {
        $classes[] = $extraClasses;
    }

    return implode(' ', $classes);
}

function bugcatcher_render_header_actions(array $actions): void
{
    foreach ($actions as $action):
        $href = (string) ($action['href'] ?? '#');
        $label = (string) ($action['label'] ?? 'Action');
        $variant = (string) ($action['variant'] ?? 'primary');
        ?>
        <a
            class="<?= htmlspecialchars(bugcatcher_ui_button_classes($variant)) ?>"
            href="<?= htmlspecialchars(bugcatcher_href($href)) ?>"
        >
            <?= htmlspecialchars($label) ?>
        </a>
        <?php
    endforeach;
}
