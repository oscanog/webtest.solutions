<?php

require_once dirname(__DIR__) . '/app/auth_org.php';
require_once dirname(__DIR__) . '/app/checklist_lib.php';
require_once dirname(__DIR__) . '/app/checklist_shell.php';

$context = bugcatcher_require_org_context($conn);
$projectId = bugcatcher_get_int('project_id');
$status = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

if (!in_array($status, array_merge([''], BUGCATCHER_BATCH_STATUSES), true)) {
    $status = '';
}

$projects = bugcatcher_checklist_fetch_projects($conn, $context['org_id'], true);
$batches = bugcatcher_checklist_fetch_batches($conn, $context['org_id'], $projectId, $status, $search);

bugcatcher_shell_start('Checklist', 'checklist', $context, [
    ['href' => '/melvin/checklist_batch.php', 'label' => 'New Batch'],
    ['href' => '/melvin/project_list.php', 'label' => 'Projects', 'variant' => 'secondary'],
]);
?>

<div class="bc-panel">
    <form method="get" class="bc-form-grid">
        <div class="bc-field">
            <label for="project_id">Project</label>
            <select class="bc-select" id="project_id" name="project_id">
                <option value="">All projects</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>" <?= $projectId === (int) $project['id'] ? 'selected' : '' ?>>
                        <?= bugcatcher_html($project['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bc-field">
            <label for="status">Batch status</label>
            <select class="bc-select" id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach (BUGCATCHER_BATCH_STATUSES as $value): ?>
                    <option value="<?= bugcatcher_html($value) ?>" <?= $status === $value ? 'selected' : '' ?>>
                        <?= bugcatcher_html(ucfirst($value)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bc-field full">
            <label for="q">Search</label>
            <input class="bc-input" id="q" name="q" value="<?= bugcatcher_html($search) ?>" placeholder="Search title, module, or project">
        </div>
        <div class="bc-field full">
            <button type="submit" class="bc-btn">Apply Filters</button>
        </div>
    </form>
</div>

<div class="bc-list">
    <?php if (!$batches): ?>
        <div class="bc-card bc-empty">No checklist batches matched the current filter.</div>
    <?php else: ?>
        <?php foreach ($batches as $batch): ?>
            <article class="bc-list-item">
                <div class="bc-list-head">
                    <div>
                        <strong><?= bugcatcher_html($batch['title']) ?></strong>
                        <div class="bc-meta">
                            <?= bugcatcher_html($batch['project_name']) ?> |
                            <?= bugcatcher_html($batch['module_name']) ?>
                            <?php if (!empty($batch['submodule_name'])): ?>
                                / <?= bugcatcher_html($batch['submodule_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="<?= bugcatcher_html(bugcatcher_path('melvin/checklist_batch.php?id=' . (int) $batch['id'])) ?>">Open Batch</a>
                </div>
                <div class="bc-inline">
                    <span class="bc-badge"><?= bugcatcher_html($batch['status']) ?></span>
                    <span class="bc-badge"><?= bugcatcher_html($batch['source_type']) ?>/<?= bugcatcher_html($batch['source_channel']) ?></span>
                    <span class="bc-badge"><?= (int) $batch['total_items'] ?> items</span>
                    <span class="bc-badge">QA Lead: <?= bugcatcher_html($batch['qa_lead_name'] ?: 'Unassigned') ?></span>
                </div>
                <div class="bc-grid cols-3">
                    <div class="bc-stat"><span>Open</span><strong><?= (int) $batch['open_items'] ?></strong></div>
                    <div class="bc-stat"><span>In progress</span><strong><?= (int) $batch['in_progress_items'] ?></strong></div>
                    <div class="bc-stat"><span>Passed / Failed / Blocked</span><strong><?= (int) $batch['passed_items'] ?>/<?= (int) $batch['failed_items'] ?>/<?= (int) $batch['blocked_items'] ?></strong></div>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php bugcatcher_shell_end(); ?>
