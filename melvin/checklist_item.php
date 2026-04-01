<?php

require_once dirname(__DIR__) . '/app/auth_org.php';
require_once dirname(__DIR__) . '/app/checklist_lib.php';
require_once dirname(__DIR__) . '/app/checklist_shell.php';

$context = bugcatcher_require_org_context($conn);
$itemId = bugcatcher_get_int('id');
$item = $itemId > 0 ? bugcatcher_checklist_fetch_item($conn, $context['org_id'], $itemId) : null;
if (!$item) {
    die('Checklist item not found.');
}

$isChecklistManager = bugcatcher_checklist_is_manager_role((string) $context['org_role']);
$qaTesters = bugcatcher_checklist_fetch_org_members($conn, $context['org_id'], ['QA Tester']);
$attachments = bugcatcher_checklist_fetch_item_attachments($conn, $itemId);
$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_item') {
        if (!$isChecklistManager) {
            http_response_code(403);
            die('Only checklist managers can edit item definitions.');
        }

        $title = trim($_POST['title'] ?? '');
        $moduleName = trim($_POST['module_name'] ?? '');
        $submoduleName = trim($_POST['submodule_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = bugcatcher_checklist_normalize_enum($_POST['priority'] ?? 'medium', BUGCATCHER_CHECKLIST_PRIORITIES, 'medium');
        $requiredRole = bugcatcher_checklist_normalize_enum($_POST['required_role'] ?? 'QA Tester', BUGCATCHER_CHECKLIST_ALLOWED_REQUIRED_ROLES, 'QA Tester');
        $assignedToUserId = bugcatcher_post_int('assigned_to_user_id');

        if ($title === '' || $moduleName === '') {
            $error = 'Title and module name are required.';
        } elseif ($assignedToUserId > 0 && !bugcatcher_checklist_member_has_role($conn, $context['org_id'], $assignedToUserId, ['QA Tester'])) {
            $error = 'Assigned tester must be a QA Tester in the active organization.';
        } else {
            $fullTitle = bugcatcher_checklist_full_title($moduleName, $submoduleName, $title);
            $stmt = $conn->prepare("
                UPDATE checklist_items
                SET title = ?, module_name = ?, submodule_name = NULLIF(?, ''), full_title = ?,
                    description = NULLIF(?, ''), priority = ?, required_role = ?, assigned_to_user_id = NULLIF(?, 0),
                    updated_by = ?
                WHERE id = ? AND org_id = ?
            ");
            $stmt->bind_param('sssssssiiii', $title, $moduleName, $submoduleName, $fullTitle, $description, $priority, $requiredRole, $assignedToUserId, $context['current_user_id'], $itemId, $context['org_id']);
            $stmt->execute();
            $stmt->close();
            $flash = 'Checklist item updated.';
        }
    } elseif ($action === 'change_status') {
        if (!bugcatcher_checklist_user_can_work_item($context, $item)) {
            http_response_code(403);
            die('You cannot update this item.');
        }

        $newStatus = bugcatcher_checklist_normalize_enum($_POST['status'] ?? 'open', BUGCATCHER_CHECKLIST_STATUSES, $item['status']);
        $allowed = false;
        if (in_array($item['status'], ['open', 'in_progress'], true) && in_array($newStatus, ['in_progress', 'passed', 'failed', 'blocked'], true)) {
            $allowed = true;
        }
        if (in_array($item['status'], ['failed', 'blocked'], true) && in_array($newStatus, ['in_progress', 'passed'], true) && $isChecklistManager) {
            $allowed = true;
        }
        if ($newStatus === $item['status']) {
            $allowed = true;
        }

        if (!$allowed) {
            $error = 'That status transition is not allowed.';
        } else {
            $startedAt = $item['started_at'];
            $completedAt = $item['completed_at'];
            if ($newStatus === 'in_progress' && !$startedAt) {
                $startedAt = date('Y-m-d H:i:s');
            }
            if (in_array($newStatus, ['passed', 'failed', 'blocked'], true)) {
                $completedAt = date('Y-m-d H:i:s');
            }
            if ($newStatus === 'in_progress') {
                $completedAt = null;
            }

            $stmt = $conn->prepare("
                UPDATE checklist_items
                SET status = ?, started_at = ?, completed_at = ?, updated_by = ?
                WHERE id = ? AND org_id = ?
            ");
            $stmt->bind_param('sssiii', $newStatus, $startedAt, $completedAt, $context['current_user_id'], $itemId, $context['org_id']);
            $stmt->execute();
            $stmt->close();
            $flash = 'Checklist status updated.';
        }
    } elseif ($action === 'upload_attachment') {
        if (!bugcatcher_checklist_user_can_work_item($context, $item)) {
            http_response_code(403);
            die('You cannot upload to this item.');
        }

        if (empty($_FILES['attachments']['name']) || !is_array($_FILES['attachments']['name'])) {
            $error = 'Select at least one file.';
        } else {
            $uploadedCount = 0;
            for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
                $errCode = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($errCode !== UPLOAD_ERR_OK) {
                    continue;
                }
                $tmp = $_FILES['attachments']['tmp_name'][$i] ?? '';
                $name = $_FILES['attachments']['name'][$i] ?? 'attachment';
                $size = (int) ($_FILES['attachments']['size'][$i] ?? 0);
                if (bugcatcher_checklist_store_uploaded_file($conn, $itemId, $tmp, $name, $size, true, $context['current_user_id'])) {
                    $uploadedCount++;
                }
            }
            $flash = $uploadedCount > 0 ? "{$uploadedCount} attachment(s) uploaded." : 'No valid attachments were uploaded.';
        }
    } elseif ($action === 'delete_attachment') {
        if (!bugcatcher_checklist_user_can_work_item($context, $item)) {
            http_response_code(403);
            die('You cannot delete attachments from this item.');
        }

        $attachmentId = bugcatcher_post_int('attachment_id');
        $attachment = $attachmentId > 0 ? bugcatcher_checklist_fetch_attachment($conn, $attachmentId, $itemId) : null;
        if (!$attachment) {
            $error = 'Attachment not found.';
        } else {
            bugcatcher_checklist_delete_attachment($conn, $attachment);
            $flash = 'Attachment deleted.';
        }
    }

    if ($flash !== '' && in_array($action, ['save_item', 'change_status', 'upload_attachment', 'delete_attachment'], true)) {
        $item = bugcatcher_checklist_fetch_item($conn, $context['org_id'], $itemId);
        if (
            in_array($action, ['save_item', 'change_status', 'upload_attachment'], true) &&
            in_array($item['status'], ['failed', 'blocked'], true) &&
            (int) $item['issue_id'] <= 0
        ) {
            bugcatcher_checklist_create_issue_for_item($conn, $item, $context['current_user_id']);
            $item = bugcatcher_checklist_fetch_item($conn, $context['org_id'], $itemId);
        }
        $attachments = bugcatcher_checklist_fetch_item_attachments($conn, $itemId);
    }
}

$canWorkItem = bugcatcher_checklist_user_can_work_item($context, $item);
$descriptionText = trim((string) ($item['description'] ?? ''));

bugcatcher_shell_start('Checklist Item', 'checklist', $context, [
    ['href' => '/melvin/checklist_batch.php?id=' . (int) $item['batch_id'], 'label' => 'Back to Batch', 'variant' => 'secondary'],
]);
?>

<?php if ($flash): ?><div class="bc-alert success"><?= bugcatcher_html($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bc-alert error"><?= bugcatcher_html($error) ?></div><?php endif; ?>

<div class="bc-list-head bc-card">
    <div>
        <h2><?= bugcatcher_html($item['full_title']) ?></h2>
        <p class="bc-meta"><?= bugcatcher_html($item['project_name']) ?> | Batch: <?= bugcatcher_html($item['batch_title']) ?> | Sequence #<?= (int) $item['sequence_no'] ?></p>
        <div class="bc-inline">
            <span class="bc-badge bc-badge-role"><?= bugcatcher_html($item['required_role']) ?></span>
            <span class="bc-badge <?= (int) ($item['assigned_to_user_id'] ?? 0) > 0 ? 'bc-badge-tester' : 'bc-badge-muted' ?>">QA Tester: <?= bugcatcher_html($item['assigned_to_name'] ?: 'Unassigned') ?></span>
        </div>
    </div>
    <span class="bc-badge <?= bugcatcher_html(bugcatcher_checklist_status_badge_class($item['status'])) ?>"><?= bugcatcher_html(ucfirst(str_replace('_', ' ', $item['status']))) ?></span>
</div>

<div class="bc-grid cols-2">
    <div class="bc-panel">
        <div class="bc-section-head">
            <div>
                <h2><?= $isChecklistManager ? 'Definition' : 'Definition Summary' ?></h2>
                <?php if (!$isChecklistManager): ?><p class="bc-meta">Only owners, Project Managers, and QA Leads can edit checklist definitions.</p><?php endif; ?>
            </div>
            <?php if ($isChecklistManager): ?><span class="bc-badge bc-badge-tester">QA Tester assignment only</span><?php endif; ?>
        </div>
        <?php if ($isChecklistManager): ?>
            <form method="post" class="bc-form-grid">
                <input type="hidden" name="action" value="save_item">
                <div class="bc-field"><label for="title">Title</label><input class="bc-input" id="title" name="title" required value="<?= bugcatcher_html($item['title']) ?>"></div>
                <div class="bc-field"><label for="module_name">Module</label><input class="bc-input" id="module_name" name="module_name" required value="<?= bugcatcher_html($item['module_name']) ?>"></div>
                <div class="bc-field"><label for="submodule_name">Submodule</label><input class="bc-input" id="submodule_name" name="submodule_name" value="<?= bugcatcher_html($item['submodule_name']) ?>"></div>
                <div class="bc-field"><label for="priority">Priority</label><select class="bc-select" id="priority" name="priority"><?php foreach (BUGCATCHER_CHECKLIST_PRIORITIES as $priority): ?><option value="<?= bugcatcher_html($priority) ?>" <?= $item['priority'] === $priority ? 'selected' : '' ?>><?= bugcatcher_html(ucfirst($priority)) ?></option><?php endforeach; ?></select></div>
                <div class="bc-field"><label for="required_role">Required role</label><select class="bc-select" id="required_role" name="required_role"><?php foreach (BUGCATCHER_CHECKLIST_ALLOWED_REQUIRED_ROLES as $role): ?><option value="<?= bugcatcher_html($role) ?>" <?= $item['required_role'] === $role ? 'selected' : '' ?>><?= bugcatcher_html($role) ?></option><?php endforeach; ?></select></div>
                <div class="bc-field"><label for="assigned_to_user_id">QA Tester</label><select class="bc-select" id="assigned_to_user_id" name="assigned_to_user_id"><option value="0">Unassigned</option><?php foreach ($qaTesters as $qaTester): ?><option value="<?= (int) $qaTester['id'] ?>" <?= (int) $item['assigned_to_user_id'] === (int) $qaTester['id'] ? 'selected' : '' ?>><?= bugcatcher_html($qaTester['username']) ?> (<?= bugcatcher_html($qaTester['role']) ?>)</option><?php endforeach; ?></select></div>
                <div class="bc-field full"><label for="description">Description</label><textarea class="bc-textarea" id="description" name="description"><?= bugcatcher_html($descriptionText) ?></textarea></div>
                <div class="bc-field full"><button type="submit" class="bc-btn">Save Item</button></div>
            </form>
        <?php else: ?>
            <div class="bc-kv">
                <div><span>Title</span><?= bugcatcher_html($item['title']) ?></div>
                <div><span>Module</span><?= bugcatcher_html($item['module_name']) ?></div>
                <div><span>Submodule</span><?= bugcatcher_html($item['submodule_name'] ?: 'Not set') ?></div>
                <div><span>Priority</span><?= bugcatcher_html(ucfirst((string) $item['priority'])) ?></div>
                <div><span>Required role</span><?= bugcatcher_html($item['required_role']) ?></div>
                <div><span>QA Tester</span><?= bugcatcher_html($item['assigned_to_name'] ?: 'Unassigned') ?></div>
                <div class="bc-kv-span-2"><span>Description</span><div class="bc-description-block"><?= nl2br(bugcatcher_html($descriptionText !== '' ? $descriptionText : 'No description provided.')) ?></div></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="bc-grid">
        <div class="bc-panel">
            <div class="bc-section-head">
                <div>
                    <h2>Workflow</h2>
                    <?php if (!$canWorkItem): ?><p class="bc-meta">Only the assigned QA Tester or a checklist manager can update status.</p><?php endif; ?>
                </div>
                <span class="bc-badge <?= bugcatcher_html(bugcatcher_checklist_status_badge_class($item['status'])) ?>"><?= bugcatcher_html(ucfirst(str_replace('_', ' ', $item['status']))) ?></span>
            </div>
            <?php if ($canWorkItem): ?>
                <form method="post" class="bc-inline">
                    <input type="hidden" name="action" value="change_status">
                    <select class="bc-select" name="status"><?php foreach (BUGCATCHER_CHECKLIST_STATUSES as $status): ?><option value="<?= bugcatcher_html($status) ?>" <?= $item['status'] === $status ? 'selected' : '' ?>><?= bugcatcher_html(ucfirst(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select>
                    <button type="submit" class="bc-btn">Update Status</button>
                </form>
            <?php else: ?>
                <p class="bc-meta">Current state: <?= bugcatcher_html(ucfirst(str_replace('_', ' ', $item['status']))) ?></p>
            <?php endif; ?>
            <?php if ((int) $item['issue_id'] > 0): ?><div class="bc-alert warn">Linked issue #<?= (int) $item['issue_id'] ?> exists. It remains open even if this checklist item later passes. <a href="<?= bugcatcher_html(bugcatcher_path('zen/dashboard.php?page=issues&status=open')) ?>">Open issues</a></div><?php endif; ?>
        </div>

        <div class="bc-panel">
            <h2>Audit</h2>
            <div class="bc-kv">
                <div><span>Created by</span><?= bugcatcher_html($item['created_by_name'] ?: 'Unknown') ?></div>
                <div><span>Updated by</span><?= bugcatcher_html($item['updated_by_name'] ?: 'Unknown') ?></div>
                <div><span>Created at</span><?= bugcatcher_html(bugcatcher_checklist_format_datetime($item['created_at'])) ?></div>
                <div><span>Updated at</span><?= bugcatcher_html(bugcatcher_checklist_format_datetime($item['updated_at'] ?: $item['created_at'])) ?></div>
                <div><span>Started at</span><?= bugcatcher_html(bugcatcher_checklist_format_datetime($item['started_at'])) ?></div>
                <div><span>Completed at</span><?= bugcatcher_html(bugcatcher_checklist_format_datetime($item['completed_at'])) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="bc-panel">
    <div class="bc-section-head">
        <div>
            <h2>Attachments</h2>
            <?php if (!$canWorkItem): ?><p class="bc-meta">You can view attachments here, but only the assigned QA Tester or a checklist manager can change them.</p><?php endif; ?>
        </div>
        <span class="bc-badge <?= $canWorkItem ? '' : 'bc-badge-muted' ?>"><?= $canWorkItem ? 'Editable' : 'Read-only' ?></span>
    </div>

    <?php if ($canWorkItem): ?>
        <form method="post" enctype="multipart/form-data" class="bc-form-grid">
            <input type="hidden" name="action" value="upload_attachment">
            <div class="bc-field full"><label for="attachments">Attach images or videos</label><input class="bc-input" id="attachments" type="file" name="attachments[]" multiple accept="image/*,video/mp4,video/webm,video/quicktime"></div>
            <div class="bc-field full"><button type="submit" class="bc-btn">Upload Attachment</button></div>
        </form>
    <?php endif; ?>

    <div class="bc-attachment-grid">
        <?php if (!$attachments): ?>
            <div class="bc-empty">No attachments uploaded for this item yet.</div>
        <?php else: ?>
            <?php foreach ($attachments as $attachment): ?>
                <div class="bc-attachment">
                    <strong><?= bugcatcher_html($attachment['original_name']) ?></strong>
                    <div class="bc-meta"><?= bugcatcher_html($attachment['mime_type']) ?></div>
                    <div class="bc-meta"><?= number_format(((int) $attachment['file_size']) / 1024, 1) ?> KB</div>
                    <div class="bc-meta">Uploaded by <?= bugcatcher_html($attachment['uploaded_by_name'] ?: 'Bot/System') ?></div>
                    <div class="bc-inline">
                        <a href="<?= bugcatcher_html(bugcatcher_path((string) $attachment['file_path'])) ?>" target="_blank" rel="noopener">Open attachment</a>
                        <?php if ($canWorkItem): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="delete_attachment">
                                <input type="hidden" name="attachment_id" value="<?= (int) $attachment['id'] ?>">
                                <button type="submit" class="bc-btn secondary">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php bugcatcher_shell_end(); ?>
