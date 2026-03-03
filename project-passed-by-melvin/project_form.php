<?php

require_once dirname(__DIR__) . '/app/auth_org.php';
require_once dirname(__DIR__) . '/app/checklist_lib.php';
require_once dirname(__DIR__) . '/app/checklist_shell.php';

$context = bugcatcher_require_org_context($conn);
bugcatcher_checklist_require_manager($context);

$projectId = bugcatcher_get_int('id');
$project = $projectId > 0 ? bugcatcher_checklist_fetch_project($conn, $context['org_id'], $projectId) : null;
if ($projectId > 0 && !$project) {
    die('Project not found.');
}

$error = '';
$name = $project['name'] ?? '';
$code = $project['code'] ?? '';
$description = $project['description'] ?? '';
$status = $project['status'] ?? 'active';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = bugcatcher_checklist_normalize_enum($_POST['status'] ?? 'active', ['active', 'archived'], 'active');

    if ($name === '') {
        $error = 'Project name is required.';
    } else {
        if ($project) {
            $stmt = $conn->prepare("
                UPDATE projects
                SET name = ?, code = NULLIF(?, ''), description = NULLIF(?, ''), status = ?, updated_by = ?
                WHERE id = ? AND org_id = ?
            ");
            $stmt->bind_param(
                "ssssiii",
                $name,
                $code,
                $description,
                $status,
                $context['current_user_id'],
                $projectId,
                $context['org_id']
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO projects (org_id, name, code, description, status, created_by, updated_by)
                VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?)
            ");
            $stmt->bind_param(
                "issssii",
                $context['org_id'],
                $name,
                $code,
                $description,
                $status,
                $context['current_user_id'],
                $context['current_user_id']
            );
        }

        $ok = $stmt->execute();
        if (!$ok) {
            $error = str_contains(strtolower($stmt->error), 'duplicate')
                ? 'Project name or code already exists in this organization.'
                : 'Failed to save project: ' . $stmt->error;
        }
        $newId = $project ? $projectId : (int) $conn->insert_id;
        $stmt->close();

        if ($ok) {
            header('Location: /project-passed-by-melvin/project_detail.php?id=' . $newId);
            exit;
        }
    }
}

bugcatcher_shell_start($project ? 'Edit Project' : 'New Project', 'projects', $context, [
    ['href' => '/project-passed-by-melvin/project_list.php', 'label' => 'Back to Projects', 'variant' => 'secondary'],
]);
?>

<?php if ($error): ?>
    <div class="bc-alert error"><?= bugcatcher_html($error) ?></div>
<?php endif; ?>

<div class="bc-panel">
    <form method="post" class="bc-form-grid">
        <div class="bc-field">
            <label for="name">Project name</label>
            <input class="bc-input" id="name" name="name" required value="<?= bugcatcher_html($name) ?>">
        </div>
        <div class="bc-field">
            <label for="code">Project code</label>
            <input class="bc-input" id="code" name="code" value="<?= bugcatcher_html($code) ?>" placeholder="Optional short code">
        </div>
        <div class="bc-field full">
            <label for="description">Description</label>
            <textarea class="bc-textarea" id="description" name="description"><?= bugcatcher_html($description) ?></textarea>
        </div>
        <div class="bc-field">
            <label for="status">Status</label>
            <select class="bc-select" id="status" name="status">
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option>
            </select>
        </div>
        <div class="bc-field full">
            <button type="submit" class="bc-btn"><?= $project ? 'Save Project' : 'Create Project' ?></button>
        </div>
    </form>
</div>

<?php bugcatcher_shell_end(); ?>
