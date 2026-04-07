<?php

require_once dirname(__DIR__) . '/app/auth_org.php';
require_once dirname(__DIR__) . '/app/checklist_lib.php';
require_once dirname(__DIR__) . '/app/checklist_shell.php';

$context = webtest_require_org_context($conn);
$projectId = webtest_get_int('id');
$project = $projectId > 0 ? webtest_checklist_fetch_project($conn, $context['org_id'], $projectId) : null;
if (!$project) {
    die('Project not found.');
}

$batches = webtest_checklist_fetch_batches($conn, $context['org_id'], $projectId);

webtest_shell_start('Project Detail', 'projects', $context, [
    ['href' => '/melvin/checklist_list.php?project_id=' . $projectId, 'label' => 'Open Checklist'],
    ['href' => '/melvin/project_form.php?id=' . $projectId, 'label' => 'Edit', 'variant' => 'secondary'],
]);
?>

<div class="bc-grid cols-3">
    <div class="bc-stat">
        <span>Status</span>
        <strong><?= webtest_html($project['status']) ?></strong>
    </div>
    <div class="bc-stat">
        <span>Project code</span>
        <strong><?= webtest_html($project['code'] ?: 'N/A') ?></strong>
    </div>
    <div class="bc-stat">
        <span>Created by</span>
        <strong><?= webtest_html($project['created_by_name'] ?: 'Unknown') ?></strong>
    </div>
</div>

<div class="bc-card">
    <h2><?= webtest_html($project['name']) ?></h2>
    <p class="bc-meta">Created <?= webtest_html(webtest_checklist_format_datetime($project['created_at'])) ?></p>
    <?php if (!empty($project['description'])): ?>
        <p><?= nl2br(webtest_html($project['description'])) ?></p>
    <?php else: ?>
        <p class="bc-meta">No description added for this project yet.</p>
    <?php endif; ?>
</div>

<div class="bc-card">
    <div class="bc-list-head">
        <div>
            <h2>Checklist batches</h2>
            <p class="bc-meta">Recent checklist runs under this project.</p>
        </div>
        <a class="bc-btn" href="<?= webtest_html(webtest_path('melvin/checklist_batch.php?project_id=' . $projectId)) ?>">New Batch</a>
    </div>
    <div class="bc-list">
        <?php if (!$batches): ?>
            <div class="bc-empty">No checklist batches created for this project yet.</div>
        <?php else: ?>
            <?php foreach ($batches as $batch): ?>
                <div class="bc-list-item">
                    <div class="bc-list-head">
                        <div>
                            <strong><?= webtest_html($batch['title']) ?></strong>
                            <div class="bc-meta">
                                <?= webtest_html($batch['module_name']) ?>
                                <?php if (!empty($batch['submodule_name'])): ?>
                                    / <?= webtest_html($batch['submodule_name']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="<?= webtest_html(webtest_path('melvin/checklist_batch.php?id=' . (int) $batch['id'])) ?>">Open</a>
                    </div>
                    <div class="bc-inline">
                        <span class="bc-badge"><?= webtest_html($batch['status']) ?></span>
                        <span class="bc-badge"><?= (int) $batch['total_items'] ?> items</span>
                        <span class="bc-badge"><?= webtest_html($batch['source_type']) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php webtest_shell_end(); ?>
