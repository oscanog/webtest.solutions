<?php

require_once dirname(__DIR__) . '/app/auth_org.php';
require_once dirname(__DIR__) . '/app/checklist_lib.php';
require_once dirname(__DIR__) . '/app/checklist_shell.php';

$context = webtest_require_org_context($conn);
$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    webtest_checklist_require_manager($context);
    $projectId = webtest_post_int('project_id');
    $action = $_POST['action'] ?? '';

    $project = $projectId > 0 ? webtest_checklist_fetch_project($conn, $context['org_id'], $projectId) : null;
    if (!$project) {
        $error = 'Project not found.';
    } elseif ($action === 'archive' || $action === 'activate') {
        $nextStatus = $action === 'archive' ? 'archived' : 'active';
        $stmt = $conn->prepare("UPDATE projects SET status = ?, updated_by = ? WHERE id = ? AND org_id = ?");
        $stmt->bind_param("siii", $nextStatus, $context['current_user_id'], $projectId, $context['org_id']);
        $stmt->execute();
        $stmt->close();
        $flash = $nextStatus === 'archived' ? 'Project archived.' : 'Project reactivated.';
    }
}

$includeArchived = isset($_GET['show']) && $_GET['show'] === 'all';
$projects = webtest_checklist_fetch_projects($conn, $context['org_id'], $includeArchived);

webtest_shell_start('Projects', 'projects', $context, [
    ['href' => '/melvin/project_form.php', 'label' => 'New Project'],
    ['href' => '/melvin/checklist_list.php', 'label' => 'Open Checklist', 'variant' => 'secondary'],
]);
?>

<?php if ($flash): ?>
    <div class="bc-alert success"><?= webtest_html($flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="bc-alert error"><?= webtest_html($error) ?></div>
<?php endif; ?>

<div class="bc-inline">
    <a class="bc-btn secondary" href="?show=active">Active Projects</a>
    <a class="bc-btn secondary" href="?show=all">Show Archived Too</a>
</div>

<div class="bc-table-wrap">
    <table class="bc-table">
        <thead>
        <tr>
            <th>Project</th>
            <th>Status</th>
            <th>Code</th>
            <th>Batches</th>
            <th>Open Items</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$projects): ?>
            <tr>
                <td colspan="6" class="bc-empty">No projects found for this organization.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($projects as $project): ?>
                <tr>
                    <td>
                        <strong><?= webtest_html($project['name']) ?></strong>
                        <?php if (!empty($project['description'])): ?>
                            <div class="bc-meta"><?= nl2br(webtest_html($project['description'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="bc-badge"><?= webtest_html($project['status']) ?></span></td>
                    <td><?= webtest_html($project['code'] ?: 'N/A') ?></td>
                    <td><?= (int) $project['batch_count'] ?></td>
                    <td><?= (int) $project['open_item_count'] ?></td>
                    <td>
                        <div class="bc-inline">
                            <a href="<?= webtest_html(webtest_path('melvin/project_detail.php?id=' . (int) $project['id'])) ?>">View</a>
                            <?php if (webtest_checklist_is_manager_role($context['org_role'])): ?>
                                <a href="<?= webtest_html(webtest_path('melvin/project_form.php?id=' . (int) $project['id'])) ?>">Edit</a>
                                <form method="post">
                                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                                    <input type="hidden" name="action" value="<?= $project['status'] === 'active' ? 'archive' : 'activate' ?>">
                                    <button type="submit" class="bc-btn secondary">
                                        <?= $project['status'] === 'active' ? 'Archive' : 'Activate' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php webtest_shell_end(); ?>
