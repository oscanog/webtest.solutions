<?php

require_once dirname(__DIR__) . '/app/auth_org.php';
require_once dirname(__DIR__) . '/app/checklist_lib.php';
require_once dirname(__DIR__) . '/app/checklist_shell.php';
require_once dirname(__DIR__) . '/app/openclaw_lib.php';

$context = bugcatcher_require_org_context($conn);
$isChecklistManager = bugcatcher_checklist_is_manager_role((string) $context['org_role']);
$batchId = bugcatcher_get_int('id');
$projectSeedId = bugcatcher_get_int('project_id');
if ($batchId <= 0 && !$isChecklistManager) {
    http_response_code(403);
    die('Only checklist managers can create checklist batches.');
}

$projects = bugcatcher_checklist_fetch_projects($conn, $context['org_id'], false);
$qaLeads = bugcatcher_checklist_fetch_org_members($conn, $context['org_id'], ['QA Lead']);
$qaTesters = bugcatcher_checklist_fetch_org_members($conn, $context['org_id'], ['QA Tester']);
$batch = $batchId > 0 ? bugcatcher_checklist_fetch_batch($conn, $context['org_id'], $batchId) : null;
if ($batchId > 0 && !$batch) {
    die('Checklist batch not found.');
}

$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_batch') {
        bugcatcher_checklist_require_manager($context);
        $projectId = bugcatcher_post_int('project_id');
        $title = trim($_POST['title'] ?? '');
        $moduleName = trim($_POST['module_name'] ?? '');
        $submoduleName = trim($_POST['submodule_name'] ?? '');
        $status = bugcatcher_checklist_normalize_enum($_POST['status'] ?? 'open', BUGCATCHER_BATCH_STATUSES, 'open');
        $assignedQaLeadId = bugcatcher_post_int('assigned_qa_lead_id');
        $notes = trim($_POST['notes'] ?? '');
        $project = $projectId > 0 ? bugcatcher_checklist_fetch_project($conn, $context['org_id'], $projectId) : null;

        if (!$project) {
            $error = 'Select a valid project in the active organization.';
        } elseif ($title === '' || $moduleName === '') {
            $error = 'Title and module name are required.';
        } elseif ($assignedQaLeadId > 0 && !bugcatcher_checklist_member_has_role($conn, $context['org_id'], $assignedQaLeadId, ['QA Lead'])) {
            $error = 'Assigned QA Lead must be a QA Lead in this organization.';
        } elseif ($batch) {
            $stmt = $conn->prepare("
                UPDATE checklist_batches
                SET project_id = ?, title = ?, module_name = ?, submodule_name = NULLIF(?, ''),
                    status = ?, assigned_qa_lead_id = NULLIF(?, 0), notes = NULLIF(?, ''), updated_by = ?
                WHERE id = ? AND org_id = ?
            ");
            $stmt->bind_param('issssisiii', $projectId, $title, $moduleName, $submoduleName, $status, $assignedQaLeadId, $notes, $context['current_user_id'], $batchId, $context['org_id']);
            $stmt->execute();
            $stmt->close();
            $flash = 'Checklist batch updated.';
            $batch = bugcatcher_checklist_fetch_batch($conn, $context['org_id'], $batchId);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO checklist_batches
                    (org_id, project_id, title, module_name, submodule_name, status, created_by, updated_by, assigned_qa_lead_id, notes)
                VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, NULLIF(?, 0), NULLIF(?, ''))
            ");
            $stmt->bind_param('iissssiiis', $context['org_id'], $projectId, $title, $moduleName, $submoduleName, $status, $context['current_user_id'], $context['current_user_id'], $assignedQaLeadId, $notes);
            $stmt->execute();
            $newBatchId = (int) $conn->insert_id;
            $stmt->close();
            header('Location: ' . bugcatcher_path('melvin/checklist_batch.php?id=' . $newBatchId));
            exit;
        }
    } elseif ($action === 'add_item') {
        bugcatcher_checklist_require_manager($context);
        if (!$batch) {
            $error = 'Create the batch first before adding items.';
        } else {
            $sequenceNo = bugcatcher_post_int('sequence_no');
            $title = trim($_POST['item_title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $submoduleName = trim($_POST['item_submodule_name'] ?? '');
            $requiredRole = bugcatcher_checklist_normalize_enum($_POST['required_role'] ?? 'QA Tester', BUGCATCHER_CHECKLIST_ALLOWED_REQUIRED_ROLES, 'QA Tester');
            $priority = bugcatcher_checklist_normalize_enum($_POST['priority'] ?? 'medium', BUGCATCHER_CHECKLIST_PRIORITIES, 'medium');
            $assignedToUserId = bugcatcher_post_int('assigned_to_user_id');
            $moduleName = trim($_POST['item_module_name'] ?? $batch['module_name']);
            if ($moduleName === '') {
                $moduleName = $batch['module_name'];
            }
            if ($sequenceNo <= 0) {
                $sequenceNo = bugcatcher_checklist_next_sequence($conn, $batch['id']);
            }

            if ($title === '') {
                $error = 'Checklist item title is required.';
            } elseif ($assignedToUserId > 0 && !bugcatcher_checklist_member_has_role($conn, $context['org_id'], $assignedToUserId, ['QA Tester'])) {
                $error = 'Assigned tester must be a QA Tester in the active organization.';
            } else {
                $fullTitle = bugcatcher_checklist_full_title($moduleName, $submoduleName, $title);
                $stmt = $conn->prepare("
                    INSERT INTO checklist_items
                        (batch_id, org_id, project_id, sequence_no, title, module_name, submodule_name, full_title, description,
                         status, priority, required_role, assigned_to_user_id, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), 'open', ?, ?, NULLIF(?, 0), ?, ?)
                ");
                $stmt->bind_param('iiiisssssssiii', $batch['id'], $context['org_id'], $batch['project_id'], $sequenceNo, $title, $moduleName, $submoduleName, $fullTitle, $description, $priority, $requiredRole, $assignedToUserId, $context['current_user_id'], $context['current_user_id']);
                $ok = $stmt->execute();
                $stmt->close();
                $flash = $ok ? 'Checklist item added.' : 'Failed to add item.';
            }
        }
    }
}

$batchForm = [
    'project_id' => $batch['project_id'] ?? $projectSeedId,
    'title' => $batch['title'] ?? '',
    'module_name' => $batch['module_name'] ?? '',
    'submodule_name' => $batch['submodule_name'] ?? '',
    'status' => $batch['status'] ?? 'open',
    'assigned_qa_lead_id' => (int) ($batch['assigned_qa_lead_id'] ?? 0),
    'notes' => $batch['notes'] ?? '',
];

$items = $batch ? bugcatcher_checklist_fetch_items_for_batch($conn, $batch['id']) : [];
$nextSequence = $batch ? bugcatcher_checklist_next_sequence($conn, $batch['id']) : 1;
$batchAttachments = $batch ? bugcatcher_openclaw_fetch_batch_attachments($conn, $batch['id']) : [];
$sourceReference = $batch ? bugcatcher_openclaw_parse_source_reference($batch['source_reference'] ?? '') : [];
$assignmentEndpointBase = bugcatcher_path('api/checklist/v1/item.php');
$totalItemCount = count($items);
$openItemCount = 0;
$assignedItemCount = 0;
foreach ($items as $row) {
    if ((string) $row['status'] === 'open') {
        $openItemCount++;
    }
    if ((int) ($row['assigned_to_user_id'] ?? 0) > 0) {
        $assignedItemCount++;
    }
}
$unassignedItemCount = $totalItemCount - $assignedItemCount;

bugcatcher_shell_start($batch ? 'Checklist Batch' : 'New Checklist Batch', 'checklist', $context, [
    ['href' => '/melvin/checklist_list.php', 'label' => 'Back to Checklist', 'variant' => 'secondary'],
]);
?>

<?php if ($flash): ?><div class="bc-alert success"><?= bugcatcher_html($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="bc-alert error"><?= bugcatcher_html($error) ?></div><?php endif; ?>

<div class="bc-panel">
    <div class="bc-section-head">
        <div>
            <h2><?= $batch ? 'Batch Summary' : 'Create Batch' ?></h2>
            <?php if ($batch && !$isChecklistManager): ?><p class="bc-meta">Only owners, Project Managers, and QA Leads can edit this batch.</p><?php endif; ?>
        </div>
        <?php if ($batch): ?><span class="bc-badge"><?= bugcatcher_html(ucfirst((string) $batch['status'])) ?></span><?php endif; ?>
    </div>

    <?php if ($isChecklistManager): ?>
        <form method="post" class="bc-form-grid">
            <input type="hidden" name="action" value="save_batch">
            <div class="bc-field"><label for="project_id">Project</label><select class="bc-select" id="project_id" name="project_id" required><option value="">Select project</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>" <?= (int) $batchForm['project_id'] === (int) $project['id'] ? 'selected' : '' ?>><?= bugcatcher_html($project['name']) ?></option><?php endforeach; ?></select></div>
            <div class="bc-field"><label for="title">Checklist title</label><input class="bc-input" id="title" name="title" required value="<?= bugcatcher_html($batchForm['title']) ?>" placeholder="Module Login"></div>
            <div class="bc-field"><label for="module_name">Module name</label><input class="bc-input" id="module_name" name="module_name" required value="<?= bugcatcher_html($batchForm['module_name']) ?>" placeholder="Login"></div>
            <div class="bc-field"><label for="submodule_name">Submodule name</label><input class="bc-input" id="submodule_name" name="submodule_name" value="<?= bugcatcher_html($batchForm['submodule_name']) ?>" placeholder="Optional"></div>
            <div class="bc-field"><label for="status">Batch status</label><select class="bc-select" id="status" name="status"><?php foreach (BUGCATCHER_BATCH_STATUSES as $value): ?><option value="<?= bugcatcher_html($value) ?>" <?= $batchForm['status'] === $value ? 'selected' : '' ?>><?= bugcatcher_html(ucfirst($value)) ?></option><?php endforeach; ?></select></div>
            <div class="bc-field"><label for="assigned_qa_lead_id">Assigned QA Lead</label><select class="bc-select" id="assigned_qa_lead_id" name="assigned_qa_lead_id"><option value="0">Unassigned</option><?php foreach ($qaLeads as $qaLead): ?><option value="<?= (int) $qaLead['id'] ?>" <?= (int) $batchForm['assigned_qa_lead_id'] === (int) $qaLead['id'] ? 'selected' : '' ?>><?= bugcatcher_html($qaLead['username']) ?></option><?php endforeach; ?></select></div>
            <div class="bc-field full"><label for="notes">Notes</label><textarea class="bc-textarea" id="notes" name="notes" placeholder="Optional notes or source references."><?= bugcatcher_html($batchForm['notes']) ?></textarea></div>
            <div class="bc-field full"><button type="submit" class="bc-btn"><?= $batch ? 'Save Batch' : 'Create Batch' ?></button></div>
        </form>
    <?php elseif ($batch): ?>
        <div class="bc-kv">
            <div><span>Project</span><?= bugcatcher_html((string) $batch['project_name']) ?></div>
            <div><span>Checklist title</span><?= bugcatcher_html((string) $batch['title']) ?></div>
            <div><span>Module</span><?= bugcatcher_html((string) $batch['module_name']) ?></div>
            <div><span>Submodule</span><?= bugcatcher_html((string) ($batch['submodule_name'] ?: 'Not set')) ?></div>
            <div><span>Batch status</span><?= bugcatcher_html(ucfirst((string) $batch['status'])) ?></div>
            <div><span>Assigned QA Lead</span><?= bugcatcher_html((string) ($batch['qa_lead_name'] ?: 'Unassigned')) ?></div>
            <div class="bc-kv-span-2"><span>Notes</span><div class="bc-description-block"><?= nl2br(bugcatcher_html((string) ($batch['notes'] ?: 'No notes recorded.'))) ?></div></div>
        </div>
    <?php endif; ?>
</div>

<?php if ($batch): ?>
    <div class="bc-grid cols-3">
        <div class="bc-stat"><span>Project</span><strong><?= bugcatcher_html($batch['project_name']) ?></strong></div>
        <div class="bc-stat"><span>Source</span><strong><?= bugcatcher_html($batch['source_type']) ?>/<?= bugcatcher_html($batch['source_channel']) ?></strong></div>
        <div class="bc-stat"><span>QA Lead</span><strong><?= bugcatcher_html($batch['qa_lead_name'] ?: 'Unassigned') ?></strong></div>
    </div>

    <?php if ($isChecklistManager): ?>
        <div class="bc-panel">
            <div class="bc-section-head"><div><h2>Add Checklist Item</h2><p class="bc-meta">Create items here, then use the item detail page for attachments and status work.</p></div><span class="bc-badge bc-badge-tester">QA Tester assignment only</span></div>
            <form method="post" class="bc-form-grid">
                <input type="hidden" name="action" value="add_item">
                <div class="bc-field"><label for="sequence_no">Sequence no.</label><input class="bc-input" id="sequence_no" name="sequence_no" value="<?= $nextSequence ?>"></div>
                <div class="bc-field"><label for="item_title">Checklist title</label><input class="bc-input" id="item_title" name="item_title" required placeholder="User can sign in with valid credentials"></div>
                <div class="bc-field"><label for="item_module_name">Module name</label><input class="bc-input" id="item_module_name" name="item_module_name" value="<?= bugcatcher_html($batch['module_name']) ?>"></div>
                <div class="bc-field"><label for="item_submodule_name">Submodule name</label><input class="bc-input" id="item_submodule_name" name="item_submodule_name" value="<?= bugcatcher_html($batch['submodule_name']) ?>"></div>
                <div class="bc-field"><label for="required_role">Required role</label><select class="bc-select" id="required_role" name="required_role"><?php foreach (BUGCATCHER_CHECKLIST_ALLOWED_REQUIRED_ROLES as $role): ?><option value="<?= bugcatcher_html($role) ?>"><?= bugcatcher_html($role) ?></option><?php endforeach; ?></select></div>
                <div class="bc-field"><label for="priority">Priority</label><select class="bc-select" id="priority" name="priority"><?php foreach (BUGCATCHER_CHECKLIST_PRIORITIES as $priority): ?><option value="<?= bugcatcher_html($priority) ?>"><?= bugcatcher_html(ucfirst($priority)) ?></option><?php endforeach; ?></select></div>
                <div class="bc-field"><label for="assigned_to_user_id">QA Tester</label><select class="bc-select" id="assigned_to_user_id" name="assigned_to_user_id"><option value="0">Unassigned</option><?php foreach ($qaTesters as $qaTester): ?><option value="<?= (int) $qaTester['id'] ?>"><?= bugcatcher_html($qaTester['username']) ?> (<?= bugcatcher_html($qaTester['role']) ?>)</option><?php endforeach; ?></select></div>
                <div class="bc-field full"><label for="description">Description</label><textarea class="bc-textarea" id="description" name="description" placeholder="Steps to replicate, expected result, and notes."></textarea></div>
                <div class="bc-field full"><button type="submit" class="bc-btn">Add Item</button></div>
            </form>
        </div>
    <?php else: ?>
        <div class="bc-alert info">Everyone in this organization can review assignment here. Only owners, Project Managers, and QA Leads can add items or change QA Tester assignment.</div>
    <?php endif; ?>

    <div class="bc-panel" data-checklist-table data-checklist-org-id="<?= (int) $context['org_id'] ?>">
        <div class="bc-section-head"><div><h2>Checklist Items</h2><p class="bc-meta">Search, filter, and review QA Tester assignment across the whole batch.</p></div><span class="bc-badge <?= $isChecklistManager ? '' : 'bc-badge-muted' ?>"><?= $isChecklistManager ? 'Inline QA Tester actions enabled' : 'Read-only view' ?></span></div>
        <div class="bc-toolbar">
            <label class="bc-toolbar-field"><span>Search items</span><input class="bc-input" type="search" placeholder="Search title, description, role, or assignee" data-checklist-filter-search></label>
            <label class="bc-toolbar-field"><span>Status</span><select class="bc-select" data-checklist-filter-status><option value="">All statuses</option><?php foreach (BUGCATCHER_CHECKLIST_STATUSES as $value): ?><option value="<?= bugcatcher_html($value) ?>"><?= bugcatcher_html(ucfirst(str_replace('_', ' ', $value))) ?></option><?php endforeach; ?></select></label>
            <label class="bc-toolbar-field"><span>Assignment</span><select class="bc-select" data-checklist-filter-assignment><option value="">All items</option><option value="assigned">Assigned</option><option value="unassigned">Unassigned</option></select></label>
        </div>
        <div class="bc-summary-row">
            <div class="bc-summary-pill"><span>Total</span><strong data-checklist-count="total"><?= $totalItemCount ?></strong></div>
            <div class="bc-summary-pill"><span>Visible</span><strong data-checklist-count="visible"><?= $totalItemCount ?></strong></div>
            <div class="bc-summary-pill"><span>Assigned</span><strong data-checklist-count="assigned"><?= $assignedItemCount ?></strong></div>
            <div class="bc-summary-pill"><span>Unassigned</span><strong data-checklist-count="unassigned"><?= $unassignedItemCount ?></strong></div>
            <div class="bc-summary-pill"><span>Open</span><strong data-checklist-count="open"><?= $openItemCount ?></strong></div>
        </div>
        <div class="bc-alert bc-inline-alert info" data-checklist-feedback hidden aria-live="polite"></div>
        <div class="bc-table-wrap bc-table-wrap-structured">
            <table class="bc-table bc-checklist-table">
                <thead><tr><th>#</th><th>Checklist Item</th><th>Status</th><th>Priority</th><th>Required Role</th><th>QA Tester</th><th>Issue</th><th>Updated</th><?php if ($isChecklistManager): ?><th>Action</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="<?= $isChecklistManager ? 9 : 8 ?>" class="bc-empty">No checklist items added yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $preview = trim((string) preg_replace('/\s+/', ' ', (string) ($item['description'] ?? '')));
                        if (strlen($preview) > 140) {
                            $preview = substr($preview, 0, 137) . '...';
                        }
                        $assigneeName = trim((string) ($item['assigned_to_name'] ?? ''));
                        $searchBase = strtolower(trim((string) ($item['full_title'] . ' ' . $item['description'] . ' ' . $item['required_role'] . ' ' . $item['status'] . ' ' . $item['priority'])));
                        ?>
                        <tr data-checklist-row data-status="<?= bugcatcher_html((string) $item['status']) ?>" data-assignment="<?= bugcatcher_html($assigneeName !== '' ? 'assigned' : 'unassigned') ?>" data-search-base="<?= bugcatcher_html($searchBase) ?>" data-search-assignee="<?= bugcatcher_html(strtolower($assigneeName)) ?>">
                            <td><?= (int) $item['sequence_no'] ?></td>
                            <td><div class="bc-table-primary"><a href="<?= bugcatcher_html(bugcatcher_path('melvin/checklist_item.php?id=' . (int) $item['id'])) ?>"><?= bugcatcher_html($item['full_title']) ?></a><?php if ($preview !== ''): ?><div class="bc-meta bc-description-preview"><?= bugcatcher_html($preview) ?></div><?php endif; ?></div></td>
                            <td><span class="bc-badge <?= bugcatcher_html(bugcatcher_checklist_status_badge_class((string) $item['status'])) ?>"><?= bugcatcher_html(ucfirst(str_replace('_', ' ', (string) $item['status']))) ?></span></td>
                            <td><span class="bc-badge <?= bugcatcher_html('priority-' . bugcatcher_checklist_normalize_enum((string) ($item['priority'] ?? 'medium'), BUGCATCHER_CHECKLIST_PRIORITIES, 'medium')) ?>"><?= bugcatcher_html(ucfirst((string) $item['priority'])) ?></span></td>
                            <td><span class="bc-badge bc-badge-role"><?= bugcatcher_html($item['required_role']) ?></span></td>
                            <td><div class="bc-assignee-stack"><span class="bc-badge <?= $assigneeName !== '' ? 'bc-badge-tester' : 'bc-badge-muted' ?>" data-checklist-assignee-label><?= bugcatcher_html($assigneeName !== '' ? $assigneeName : 'Unassigned') ?></span><span class="bc-meta" data-checklist-assignee-meta><?= bugcatcher_html($assigneeName !== '' ? 'QA Tester' : 'Needs QA Tester assignment') ?></span></div></td>
                            <td><?php if (!empty($item['issue_id'])): ?><a href="<?= bugcatcher_html(bugcatcher_path('zen/dashboard.php?page=issues&status=open')) ?>">#<?= (int) $item['issue_id'] ?></a><?php else: ?><span class="bc-meta">None</span><?php endif; ?></td>
                            <td data-checklist-updated-cell><?= bugcatcher_html(bugcatcher_checklist_format_datetime($item['updated_at'] ?: $item['created_at'])) ?></td>
                            <?php if ($isChecklistManager): ?>
                                <td><form class="bc-assignment-form" method="post" data-checklist-assignment-form data-endpoint="<?= bugcatcher_html($assignmentEndpointBase . '?id=' . (int) $item['id'] . '&org_id=' . (int) $context['org_id']) ?>"><label class="bc-screen-reader" for="assigned_to_user_id_<?= (int) $item['id'] ?>">QA Tester</label><select class="bc-select bc-assignment-select" id="assigned_to_user_id_<?= (int) $item['id'] ?>" name="assigned_to_user_id"><option value="0">Unassigned</option><?php foreach ($qaTesters as $qaTester): ?><option value="<?= (int) $qaTester['id'] ?>" <?= (int) $item['assigned_to_user_id'] === (int) $qaTester['id'] ? 'selected' : '' ?>><?= bugcatcher_html($qaTester['username']) ?></option><?php endforeach; ?></select><div class="bc-assignment-actions"><button type="submit" class="bc-btn secondary" data-checklist-apply>Apply</button><button type="button" class="bc-btn secondary" data-checklist-clear>Clear</button></div><span class="bc-meta bc-form-status" data-checklist-form-status aria-live="polite"></span></form></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($items): ?><div class="bc-empty" data-checklist-empty hidden>No checklist items match the current filters.</div><?php endif; ?>
    </div>

    <div class="bc-card"><h2>Batch Notes</h2><?php if (!empty($batch['notes'])): ?><p><?= nl2br(bugcatcher_html($batch['notes'])) ?></p><?php else: ?><p class="bc-meta">No batch notes or external attachment references were recorded.</p><?php endif; ?></div>
    <div class="bc-grid cols-2">
        <div class="bc-card"><h2>Source Metadata</h2><div class="bc-kv"><div class="bc-kv-row"><strong>Created by</strong><span><?= bugcatcher_html($batch['created_by_name'] ?: 'Unknown') ?></span></div><div class="bc-kv-row"><strong>Source</strong><span><?= bugcatcher_html($batch['source_type'] . '/' . $batch['source_channel']) ?></span></div><div class="bc-kv-row"><strong>Reference</strong><span><?= bugcatcher_html(($sourceReference['message_id'] ?? $sourceReference['raw'] ?? $batch['source_reference'] ?? '') ?: 'None') ?></span></div></div></div>
        <div class="bc-card"><h2>Batch Attachments</h2><?php if (!$batchAttachments): ?><p class="bc-meta">No source images were stored for this batch.</p><?php else: ?><div class="bc-media-grid"><?php foreach ($batchAttachments as $attachment): ?><div class="bc-media-card"><strong><?= bugcatcher_html($attachment['original_name']) ?></strong><div class="bc-meta"><?= bugcatcher_html($attachment['mime_type']) ?></div><div class="bc-meta">Uploaded by <?= bugcatcher_html($attachment['uploaded_by_name'] ?: 'Bot/System') ?></div><?php if (strpos((string) $attachment['mime_type'], 'image/') === 0): ?><img src="<?= bugcatcher_html(bugcatcher_path((string) $attachment['file_path'])) ?>" alt="<?= bugcatcher_html($attachment['original_name']) ?>"><?php endif; ?><a href="<?= bugcatcher_html(bugcatcher_path((string) $attachment['file_path'])) ?>" target="_blank" rel="noopener">Open attachment</a></div><?php endforeach; ?></div><?php endif; ?></div>
    </div>
    <script src="<?= bugcatcher_html(bugcatcher_path('app/checklist_batch_page.js?v=3')) ?>"></script>
<?php endif; ?>

<?php bugcatcher_shell_end(); ?>
