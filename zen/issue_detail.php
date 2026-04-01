<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/app/legacy_issue_helpers.php';
require_once dirname(__DIR__) . '/app/sidebar.php';

function issue_badge_class(string $workflowStatus): string
{
    switch (bugcatcher_issue_workflow_normalize($workflowStatus)) {
        case 'with_senior':
            return 'badge-senior';
        case 'with_junior':
            return 'badge-junior';
        case 'done_by_junior':
            return 'badge-ready';
        case 'with_qa':
            return 'badge-qa';
        case 'with_senior_qa':
            return 'badge-senior-qa';
        case 'with_qa_lead':
            return 'badge-qa-lead';
        case 'approved':
            return 'badge-approved';
        case 'rejected':
            return 'badge-rejected';
        case 'closed':
            return 'badge-closed';
        default:
            return 'badge-unassigned';
    }
}

$orgId = (int) ($_SESSION['active_org_id'] ?? 0);
if ($orgId <= 0) {
    header("Location: " . bugcatcher_path('zen/organization.php'));
    exit;
}

$membership = bugcatcher_issue_find_membership($conn, $orgId, (int) $current_user_id);
if (!$membership) {
    die("You are not a member of the active organization.");
}

$issueId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
if ($issueId <= 0) {
    die("Issue not found.");
}

$stmt = $conn->prepare("
    SELECT
        i.*,
        p.name AS project_name,
        p.code AS project_code,
        author.username AS author_username,
        senior.username AS senior_username,
        junior.username AS junior_username,
        qa.username AS qa_username,
        senior_qa.username AS senior_qa_username,
        qa_lead.username AS qa_lead_username,
        pm.username AS pm_username
    FROM issues i
    JOIN projects p ON p.id = i.project_id
    JOIN users author ON author.id = i.author_id
    LEFT JOIN users senior ON senior.id = i.assigned_dev_id
    LEFT JOIN users junior ON junior.id = i.assigned_junior_id
    LEFT JOIN users qa ON qa.id = i.assigned_qa_id
    LEFT JOIN users senior_qa ON senior_qa.id = i.assigned_senior_qa_id
    LEFT JOIN users qa_lead ON qa_lead.id = i.assigned_qa_lead_id
    LEFT JOIN users pm ON pm.id = i.pm_id
    WHERE i.id = ? AND i.org_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $issueId, $orgId);
$stmt->execute();
$issue = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$issue) {
    die("Issue not found in this organization.");
}

$workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
$workflowLabel = bugcatcher_issue_workflow_label($workflowStatus);
$badgeClass = issue_badge_class($workflowStatus);

$labels = [];
$labelsStmt = $conn->prepare("
    SELECT labels.name, labels.color
    FROM issue_labels
    JOIN labels ON labels.id = issue_labels.label_id
    WHERE issue_labels.issue_id = ?
    ORDER BY labels.name ASC
");
$labelsStmt->bind_param('i', $issueId);
$labelsStmt->execute();
$labels = $labelsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$labelsStmt->close();

$attachments = [];
$attachmentsStmt = $conn->prepare("
    SELECT file_path, original_name, mime_type
    FROM issue_attachments
    WHERE issue_id = ?
    ORDER BY id ASC
");
$attachmentsStmt->bind_param('i', $issueId);
$attachmentsStmt->execute();
$attachments = $attachmentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$attachmentsStmt->close();

$projectLabel = trim((string) ($issue['project_code'] ?? '')) !== ''
    ? ((string) ($issue['project_name'] ?? 'Unknown project') . ' (' . (string) $issue['project_code'] . ')')
    : (string) ($issue['project_name'] ?? 'Unknown project');

$metaRows = [
    'Project' => $projectLabel,
    'Author' => (string) ($issue['author_username'] ?? 'Unknown'),
    'Project Manager' => (string) ($issue['pm_username'] ?? 'Unassigned'),
    'Senior Developer' => (string) ($issue['senior_username'] ?? 'Unassigned'),
    'Junior Developer' => (string) ($issue['junior_username'] ?? 'Unassigned'),
    'QA Tester' => (string) ($issue['qa_username'] ?? 'Unassigned'),
    'Senior QA' => (string) ($issue['senior_qa_username'] ?? 'Unassigned'),
    'QA Lead' => (string) ($issue['qa_lead_username'] ?? 'Unassigned'),
    'Created' => date("M d, Y g:i A", strtotime((string) ($issue['created_at'] ?? 'now'))),
];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Issue Detail · BugCatcher</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('app/legacy_theme.css?v=5')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('app/legacy_issues.css?v=2')) ?>">
</head>

<body>
    <?php bugcatcher_render_sidebar('issues', $current_username, $current_role, (string) ($membership['role'] ?? ''), null); ?>

    <main class="main">
        <?php bugcatcher_render_page_header(
            'Issue #' . (int) $issueId,
            $current_username,
            $current_role,
            (string) ($membership['role'] ?? ''),
            (string) $issue['title'],
            [
                ['href' => '/zen/dashboard.php?page=issues&view=kanban&status=all', 'label' => 'Back to Issues', 'variant' => 'secondary'],
            ]
        ); ?>

        <div class="issue-detail-grid">
            <section class="issue-container issue-detail-main">
                <div class="issues-hero">
                    <div>
                        <div class="issues-eyebrow">Workflow Status</div>
                        <h2><?= htmlspecialchars($workflowLabel) ?></h2>
                        <p>Read-only detail view for the current issue record and evidence.</p>
                    </div>
                    <span class="badge <?= htmlspecialchars($badgeClass) ?>"><?= htmlspecialchars($workflowLabel) ?></span>
                </div>

                <div class="issue issue-form-shell">
                    <div class="issue-title">
                        <?= htmlspecialchars((string) $issue['title']) ?>
                    </div>

                    <div class="issue-meta">
                        <?= htmlspecialchars($projectLabel) ?>
                        Â· opened by <?= htmlspecialchars((string) ($issue['author_username'] ?? 'Unknown')) ?>
                        · <?= date("M d, Y", strtotime((string) ($issue['created_at'] ?? 'now'))) ?>
                    </div>

                    <div class="issue-detail-section">
                        <h3>Description</h3>
                        <div class="issue-desc">
                            <?php if (trim((string) ($issue['description'] ?? '')) === ''): ?>
                                No description provided.
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars((string) $issue['description'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="issue-detail-section">
                        <h3>Labels</h3>
                        <?php if ($labels): ?>
                            <div class="issue-labels">
                                <?php foreach ($labels as $label): ?>
                                    <span class="label" style="background:<?= htmlspecialchars((string) ($label['color'] ?? '#bbb')) ?>">
                                        <?= htmlspecialchars((string) ($label['name'] ?? 'Label')) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="issue-detail-empty">No labels attached.</div>
                        <?php endif; ?>
                    </div>

                    <div class="issue-detail-section">
                        <h3>Evidence</h3>
                        <?php if ($attachments): ?>
                            <div class="issue-attachments">
                                <?php foreach ($attachments as $attachment): ?>
                                    <?php
                                    $src = htmlspecialchars((string) ($attachment['file_path'] ?? ''));
                                    $name = htmlspecialchars((string) ($attachment['original_name'] ?? 'Attachment'));
                                    ?>
                                    <a href="<?= $src ?>" target="_blank" rel="noopener">
                                        <img src="<?= $src ?>" alt="<?= $name ?>">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="issue-detail-empty">No evidence uploaded yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <aside class="issue-container issue-detail-sidebar">
                <div class="issue issue-form-shell">
                    <div class="issues-eyebrow">Assignment Flow</div>
                    <div class="issue-detail-meta-list">
                        <?php foreach ($metaRows as $label => $value): ?>
                            <div class="issue-detail-meta-row">
                                <span><?= htmlspecialchars($label) ?></span>
                                <strong><?= htmlspecialchars($value) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <script src="<?= htmlspecialchars(bugcatcher_path('app/mobile_nav.js?v=3')) ?>"></script>
    <script src="<?= htmlspecialchars(bugcatcher_path('app/notifications_ui.js?v=1')) ?>"></script>
</body>

</html>
