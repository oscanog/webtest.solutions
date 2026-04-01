<?php

require_once dirname(__DIR__) . '/app/auth_org.php';
require_once dirname(__DIR__) . '/app/checklist_lib.php';
require_once dirname(__DIR__) . '/app/checklist_shell.php';

$context = bugcatcher_require_org_context($conn);
$isChecklistManager = bugcatcher_checklist_is_manager_role((string) $context['org_role']);

$projectId = bugcatcher_get_int('project_id');
$status = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

if (!in_array($status, array_merge([''], BUGCATCHER_BATCH_STATUSES), true)) {
    $status = '';
}

$itemPage = max(1, (int) ($_GET['item_page'] ?? 1));
$itemFilters = bugcatcher_checklist_normalize_item_filters([
    'q' => $_GET['item_q'] ?? '',
    'project_id' => bugcatcher_get_int('item_project_id'),
    'batch_id' => bugcatcher_get_int('item_batch_id'),
    'status' => $_GET['item_status'] ?? '',
    'assignment' => $_GET['item_assignment'] ?? '',
    'priority' => $_GET['item_priority'] ?? '',
    'issue' => $_GET['item_issue'] ?? '',
]);

$projects = bugcatcher_checklist_fetch_projects($conn, $context['org_id'], true);
$batchOptions = bugcatcher_checklist_fetch_batch_options($conn, $context['org_id']);
$itemTable = bugcatcher_checklist_fetch_org_items_overview($conn, $context['org_id'], $itemFilters, $itemPage, 25);
$itemFilters = $itemTable['filters'];
$itemRows = $itemTable['items'];
$itemSummary = $itemTable['summary'];
$itemPagination = $itemTable['pagination'];
$batches = bugcatcher_checklist_fetch_batches($conn, $context['org_id'], $projectId, $status, $search);
$qaTesters = $isChecklistManager ? bugcatcher_checklist_fetch_org_members($conn, $context['org_id'], ['QA Tester']) : [];
$assignmentEndpointBase = bugcatcher_path('api/checklist/v1/item.php');

$actions = [
    ['href' => '/melvin/project_list.php', 'label' => 'Projects', 'variant' => 'secondary'],
];
if ($isChecklistManager) {
    array_unshift($actions, ['href' => '/melvin/checklist_batch.php', 'label' => 'New Batch']);
}

$currentQuery = [];
if ($projectId > 0) {
    $currentQuery['project_id'] = $projectId;
}
if ($status !== '') {
    $currentQuery['status'] = $status;
}
if ($search !== '') {
    $currentQuery['q'] = $search;
}
if ($itemFilters['q'] !== '') {
    $currentQuery['item_q'] = $itemFilters['q'];
}
if ((int) $itemFilters['project_id'] > 0) {
    $currentQuery['item_project_id'] = (int) $itemFilters['project_id'];
}
if ((int) $itemFilters['batch_id'] > 0) {
    $currentQuery['item_batch_id'] = (int) $itemFilters['batch_id'];
}
if ($itemFilters['status'] !== '') {
    $currentQuery['item_status'] = $itemFilters['status'];
}
if ($itemFilters['assignment'] !== '') {
    $currentQuery['item_assignment'] = $itemFilters['assignment'];
}
if ($itemFilters['priority'] !== '') {
    $currentQuery['item_priority'] = $itemFilters['priority'];
}
if ($itemFilters['issue'] !== '') {
    $currentQuery['item_issue'] = $itemFilters['issue'];
}
if ((int) $itemPagination['page'] > 1) {
    $currentQuery['item_page'] = (int) $itemPagination['page'];
}

$buildChecklistListUrl = static function (array $overrides = [], array $remove = []) use ($currentQuery): string {
    $query = array_merge($currentQuery, $overrides);

    foreach ($remove as $key) {
        unset($query[$key]);
    }

    foreach ($query as $key => $value) {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            unset($query[$key]);
        }
    }

    return bugcatcher_path('melvin/checklist_list.php' . ($query ? '?' . http_build_query($query) : ''));
};

$clearItemFiltersHref = $buildChecklistListUrl([], [
    'item_q',
    'item_project_id',
    'item_batch_id',
    'item_status',
    'item_assignment',
    'item_priority',
    'item_issue',
    'item_page',
]);

$paginationWindow = [];
$pageCount = (int) $itemPagination['page_count'];
$currentPage = (int) $itemPagination['page'];
for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
    if ($pageNumber === 1 || $pageNumber === $pageCount || abs($pageNumber - $currentPage) <= 1) {
        $paginationWindow[] = $pageNumber;
    }
}

bugcatcher_shell_start('Checklist', 'checklist', $context, $actions);
?>

<div class="bc-panel" data-checklist-table data-checklist-filter-mode="server" data-checklist-assignment-filter="<?= bugcatcher_html($itemFilters['assignment']) ?>" data-checklist-org-id="<?= (int) $context['org_id'] ?>">
    <div class="bc-section-head">
        <div>
            <h2>Checklist Items</h2>
            <p class="bc-meta">Search item titles, QA ownership, and batch context across the active organization before opening a specific batch.</p>
        </div>
        <span class="bc-badge <?= $isChecklistManager ? '' : 'bc-badge-muted' ?>"><?= $isChecklistManager ? 'Inline QA Tester actions enabled' : 'Read-only view' ?></span>
    </div>

    <form method="get" class="bc-form-grid bc-cross-batch-filter-grid">
        <?php if ($projectId > 0): ?><input type="hidden" name="project_id" value="<?= (int) $projectId ?>"><?php endif; ?>
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= bugcatcher_html($status) ?>"><?php endif; ?>
        <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= bugcatcher_html($search) ?>"><?php endif; ?>

        <div class="bc-field full">
            <label for="item_q">Search items</label>
            <input class="bc-input" id="item_q" name="item_q" value="<?= bugcatcher_html($itemFilters['q']) ?>" placeholder="Search title, description, assignee, batch, or project">
        </div>
        <div class="bc-field">
            <label for="item_project_id">Project</label>
            <select class="bc-select" id="item_project_id" name="item_project_id">
                <option value="">All projects</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>" <?= (int) $itemFilters['project_id'] === (int) $project['id'] ? 'selected' : '' ?>>
                        <?= bugcatcher_html($project['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bc-field">
            <label for="item_batch_id">Batch</label>
            <select class="bc-select" id="item_batch_id" name="item_batch_id">
                <option value="">All batches</option>
                <?php foreach ($batchOptions as $batchOption): ?>
                    <option value="<?= (int) $batchOption['id'] ?>" <?= (int) $itemFilters['batch_id'] === (int) $batchOption['id'] ? 'selected' : '' ?>>
                        <?= bugcatcher_html($batchOption['title']) ?> | <?= bugcatcher_html($batchOption['project_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bc-field">
            <label for="item_status">Item status</label>
            <select class="bc-select" id="item_status" name="item_status">
                <option value="">All statuses</option>
                <?php foreach (BUGCATCHER_CHECKLIST_STATUSES as $value): ?>
                    <option value="<?= bugcatcher_html($value) ?>" <?= $itemFilters['status'] === $value ? 'selected' : '' ?>>
                        <?= bugcatcher_html(ucfirst(str_replace('_', ' ', $value))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bc-field">
            <label for="item_assignment">Assignment</label>
            <select class="bc-select" id="item_assignment" name="item_assignment">
                <option value="">All items</option>
                <option value="assigned" <?= $itemFilters['assignment'] === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                <option value="unassigned" <?= $itemFilters['assignment'] === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
            </select>
        </div>
        <div class="bc-field">
            <label for="item_priority">Priority</label>
            <select class="bc-select" id="item_priority" name="item_priority">
                <option value="">All priorities</option>
                <?php foreach (BUGCATCHER_CHECKLIST_PRIORITIES as $value): ?>
                    <option value="<?= bugcatcher_html($value) ?>" <?= $itemFilters['priority'] === $value ? 'selected' : '' ?>>
                        <?= bugcatcher_html(ucfirst($value)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bc-field">
            <label for="item_issue">Issue link</label>
            <select class="bc-select" id="item_issue" name="item_issue">
                <option value="">All items</option>
                <option value="with_issue" <?= $itemFilters['issue'] === 'with_issue' ? 'selected' : '' ?>>With linked issue</option>
                <option value="without_issue" <?= $itemFilters['issue'] === 'without_issue' ? 'selected' : '' ?>>Without linked issue</option>
            </select>
        </div>
        <div class="bc-field full">
            <div class="bc-inline">
                <button type="submit" class="bc-btn">Apply Filters</button>
                <a href="<?= bugcatcher_html($clearItemFiltersHref) ?>" class="bc-btn secondary">Clear Filters</a>
            </div>
        </div>
    </form>

    <div class="bc-summary-row">
        <div class="bc-summary-pill"><span>Total</span><strong data-checklist-count="total"><?= (int) $itemSummary['total'] ?></strong></div>
        <div class="bc-summary-pill"><span>Visible</span><strong data-checklist-count="visible"><?= (int) $itemSummary['visible'] ?></strong></div>
        <div class="bc-summary-pill"><span>Assigned</span><strong data-checklist-count="assigned"><?= (int) $itemSummary['assigned'] ?></strong></div>
        <div class="bc-summary-pill"><span>Unassigned</span><strong data-checklist-count="unassigned"><?= (int) $itemSummary['unassigned'] ?></strong></div>
        <div class="bc-summary-pill"><span>Open</span><strong data-checklist-count="open"><?= (int) $itemSummary['open'] ?></strong></div>
    </div>

    <div class="bc-alert bc-inline-alert info" data-checklist-feedback hidden aria-live="polite"></div>
    <div class="bc-table-wrap bc-table-wrap-structured">
        <table class="bc-table bc-checklist-table bc-cross-batch-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Checklist Item</th>
                    <th class="bc-checklist-col-batch">Batch</th>
                    <th class="bc-checklist-col-project">Project</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Required Role</th>
                    <th>QA Tester</th>
                    <th>Issue</th>
                    <th>Updated</th>
                    <?php if ($isChecklistManager): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (!$itemRows): ?>
                <tr>
                    <td colspan="<?= $isChecklistManager ? 11 : 10 ?>" class="bc-empty">No checklist items matched the current filters.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($itemRows as $item): ?>
                    <?php
                    $preview = trim((string) preg_replace('/\s+/', ' ', (string) ($item['description'] ?? '')));
                    if (strlen($preview) > 180) {
                        $preview = substr($preview, 0, 177) . '...';
                    }
                    $assigneeName = trim((string) ($item['assigned_to_name'] ?? ''));
                    $searchBase = strtolower(trim((string) ($item['full_title'] . ' ' . $item['description'] . ' ' . $item['required_role'] . ' ' . $item['status'] . ' ' . $item['priority'] . ' ' . $item['batch_title'] . ' ' . $item['project_name'])));
                    $itemHref = bugcatcher_path('melvin/checklist_item.php?id=' . (int) $item['id'] . '&org_id=' . (int) $context['org_id']);
                    $batchHref = bugcatcher_path('melvin/checklist_batch.php?id=' . (int) $item['batch_id'] . '&org_id=' . (int) $context['org_id']);
                    $issueHref = bugcatcher_path('zen/issue_detail.php?id=' . (int) $item['issue_id'] . '&org_id=' . (int) $context['org_id']);
                    ?>
                    <tr data-checklist-row data-status="<?= bugcatcher_html((string) $item['status']) ?>" data-assignment="<?= bugcatcher_html($assigneeName !== '' ? 'assigned' : 'unassigned') ?>" data-search-base="<?= bugcatcher_html($searchBase) ?>" data-search-assignee="<?= bugcatcher_html(strtolower($assigneeName)) ?>">
                        <td><?= (int) $item['sequence_no'] ?></td>
                        <td>
                            <div class="bc-table-primary">
                                <a href="<?= bugcatcher_html($itemHref) ?>"><?= bugcatcher_html($item['full_title']) ?></a>
                                <?php if ($preview !== ''): ?><div class="bc-meta bc-description-preview"><?= bugcatcher_html($preview) ?></div><?php endif; ?>
                                <div class="bc-checklist-mobile-meta">
                                    <div class="bc-meta"><strong>Batch:</strong> <a href="<?= bugcatcher_html($batchHref) ?>"><?= bugcatcher_html($item['batch_title']) ?></a></div>
                                    <div class="bc-meta"><strong>Project:</strong> <?= bugcatcher_html($item['project_name']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="bc-checklist-col-batch">
                            <div class="bc-table-primary bc-cross-batch-context">
                                <a href="<?= bugcatcher_html($batchHref) ?>"><?= bugcatcher_html($item['batch_title']) ?></a>
                                <div class="bc-inline bc-cross-batch-context-tags">
                                    <span class="bc-badge"><?= bugcatcher_html($item['batch_module_name']) ?></span>
                                    <?php if (!empty($item['batch_submodule_name'])): ?>
                                        <span class="bc-badge"><?= bugcatcher_html($item['batch_submodule_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="bc-checklist-col-project"><?= bugcatcher_html($item['project_name']) ?></td>
                        <td><span class="bc-badge <?= bugcatcher_html(bugcatcher_checklist_status_badge_class((string) $item['status'])) ?>"><?= bugcatcher_html(ucfirst(str_replace('_', ' ', (string) $item['status']))) ?></span></td>
                        <td><span class="bc-badge <?= bugcatcher_html('priority-' . bugcatcher_checklist_normalize_enum((string) ($item['priority'] ?? 'medium'), BUGCATCHER_CHECKLIST_PRIORITIES, 'medium')) ?>"><?= bugcatcher_html(ucfirst((string) $item['priority'])) ?></span></td>
                        <td><span class="bc-badge bc-badge-role"><?= bugcatcher_html($item['required_role']) ?></span></td>
                        <td>
                            <div class="bc-assignee-stack">
                                <span class="bc-badge <?= $assigneeName !== '' ? 'bc-badge-tester' : 'bc-badge-muted' ?>" data-checklist-assignee-label><?= bugcatcher_html($assigneeName !== '' ? $assigneeName : 'Unassigned') ?></span>
                                <span class="bc-meta" data-checklist-assignee-meta><?= bugcatcher_html($assigneeName !== '' ? 'QA Tester' : 'Needs QA Tester assignment') ?></span>
                            </div>
                        </td>
                        <td><?php if (!empty($item['issue_id'])): ?><a href="<?= bugcatcher_html($issueHref) ?>">#<?= (int) $item['issue_id'] ?></a><?php else: ?><span class="bc-meta">None</span><?php endif; ?></td>
                        <td data-checklist-updated-cell><?= bugcatcher_html(bugcatcher_checklist_format_datetime($item['updated_at'] ?: $item['created_at'])) ?></td>
                        <?php if ($isChecklistManager): ?>
                            <td>
                                <form class="bc-assignment-form" method="post" data-checklist-assignment-form data-endpoint="<?= bugcatcher_html($assignmentEndpointBase . '?id=' . (int) $item['id'] . '&org_id=' . (int) $context['org_id']) ?>">
                                    <label class="bc-screen-reader" for="assigned_to_user_id_<?= (int) $item['id'] ?>">QA Tester</label>
                                    <select class="bc-select bc-assignment-select" id="assigned_to_user_id_<?= (int) $item['id'] ?>" name="assigned_to_user_id">
                                        <option value="0">Unassigned</option>
                                        <?php foreach ($qaTesters as $qaTester): ?>
                                            <option value="<?= (int) $qaTester['id'] ?>" <?= (int) $item['assigned_to_user_id'] === (int) $qaTester['id'] ? 'selected' : '' ?>>
                                                <?= bugcatcher_html($qaTester['username']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="bc-assignment-actions">
                                        <button type="submit" class="bc-btn secondary" data-checklist-apply>Apply</button>
                                        <button type="button" class="bc-btn secondary" data-checklist-clear>Clear</button>
                                    </div>
                                    <span class="bc-meta bc-form-status" data-checklist-form-status aria-live="polite"></span>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($itemRows): ?><div class="bc-empty" data-checklist-empty hidden>No checklist items remain on this page for the current filters.</div><?php endif; ?>

    <?php if ($pageCount > 1): ?>
        <nav class="bc-pagination" aria-label="Checklist item pages">
            <?php if ($currentPage > 1): ?>
                <a class="bc-page-link" href="<?= bugcatcher_html($buildChecklistListUrl(['item_page' => $currentPage - 1])) ?>">Previous</a>
            <?php endif; ?>

            <?php
            $lastRenderedPage = 0;
            foreach ($paginationWindow as $pageNumber):
                if ($lastRenderedPage > 0 && $pageNumber - $lastRenderedPage > 1):
                    ?>
                    <span class="bc-page-gap" aria-hidden="true">...</span>
                <?php
                endif;
                ?>
                <a class="bc-page-link <?= $pageNumber === $currentPage ? 'active' : '' ?>" href="<?= bugcatcher_html($buildChecklistListUrl(['item_page' => $pageNumber])) ?>" <?= $pageNumber === $currentPage ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a>
                <?php
                $lastRenderedPage = $pageNumber;
            endforeach;
            ?>

            <?php if ($currentPage < $pageCount): ?>
                <a class="bc-page-link" href="<?= bugcatcher_html($buildChecklistListUrl(['item_page' => $currentPage + 1])) ?>">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>

<div class="bc-panel">
    <form method="get" class="bc-form-grid">
        <?php if ($itemFilters['q'] !== ''): ?><input type="hidden" name="item_q" value="<?= bugcatcher_html($itemFilters['q']) ?>"><?php endif; ?>
        <?php if ((int) $itemFilters['project_id'] > 0): ?><input type="hidden" name="item_project_id" value="<?= (int) $itemFilters['project_id'] ?>"><?php endif; ?>
        <?php if ((int) $itemFilters['batch_id'] > 0): ?><input type="hidden" name="item_batch_id" value="<?= (int) $itemFilters['batch_id'] ?>"><?php endif; ?>
        <?php if ($itemFilters['status'] !== ''): ?><input type="hidden" name="item_status" value="<?= bugcatcher_html($itemFilters['status']) ?>"><?php endif; ?>
        <?php if ($itemFilters['assignment'] !== ''): ?><input type="hidden" name="item_assignment" value="<?= bugcatcher_html($itemFilters['assignment']) ?>"><?php endif; ?>
        <?php if ($itemFilters['priority'] !== ''): ?><input type="hidden" name="item_priority" value="<?= bugcatcher_html($itemFilters['priority']) ?>"><?php endif; ?>
        <?php if ($itemFilters['issue'] !== ''): ?><input type="hidden" name="item_issue" value="<?= bugcatcher_html($itemFilters['issue']) ?>"><?php endif; ?>
        <?php if ((int) $itemPagination['page'] > 1): ?><input type="hidden" name="item_page" value="<?= (int) $itemPagination['page'] ?>"><?php endif; ?>
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
                    <a href="<?= bugcatcher_html(bugcatcher_path('melvin/checklist_batch.php?id=' . (int) $batch['id'] . '&org_id=' . (int) $context['org_id'])) ?>">Open Batch</a>
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

<?php bugcatcher_shell_end(['/app/checklist_batch_page.js?v=3']); ?>
