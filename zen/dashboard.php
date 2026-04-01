<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/app/legacy_issue_helpers.php';
require_once dirname(__DIR__) . '/app/sidebar.php';

if (empty($_SESSION['active_org_id']) || (int) $_SESSION['active_org_id'] <= 0) {
  $_SESSION['org_error'] = "You haven't joined an organization to access this, please do it first.";
  header("Location: " . bugcatcher_path('zen/organization.php'));
  exit;
}

// ---- Params ----
$page = $_GET['page'] ?? 'dashboard';
$view = $_GET['view'] ?? 'kanban';               // kanban | list
$status = $_GET['status'] ?? 'all';              // all | open | closed
$author = $_GET['author'] ?? '';                 // user id
$label = $_GET['label'] ?? '';                  // label id
$rankingPage = isset($_GET['ranking_page']) && ctype_digit((string) $_GET['ranking_page'])
  ? max(1, (int) $_GET['ranking_page'])
  : 1;
$orgId = (int) ($_SESSION['active_org_id'] ?? 0);

if ($orgId <= 0) {
  header("Location: " . bugcatcher_path('zen/organization.php'));
  exit;
}

// ---- Organization owner check ----
$orgOwnerId = 0;
$stmt = $conn->prepare("SELECT owner_id, name FROM organizations WHERE id=? LIMIT 1");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$rowOrg = $stmt->get_result()->fetch_assoc();
$stmt->close();

$orgOwnerId = (int) ($rowOrg['owner_id'] ?? 0);
$orgName = trim((string) ($rowOrg['name'] ?? 'Organization'));
$isOrgOwner = ($orgOwnerId > 0 && $orgOwnerId === (int) $current_user_id);

// normalize
$page = ($page === 'issues') ? 'issues' : 'dashboard';
$view = ($view === 'list') ? 'list' : 'kanban';
$status = bugcatcher_issue_workflow_filter($status);
$author = ($author !== '' && ctype_digit((string) $author)) ? (int) $author : '';
$label = ($label !== '' && ctype_digit((string) $label)) ? (int) $label : '';

function require_membership(mysqli $conn, int $orgId, int $userId): ?array
{
  return bugcatcher_issue_find_membership($conn, $orgId, $userId);
}

function post_int($key): int
{
  $v = $_POST[$key] ?? '';
  return ctype_digit((string) $v) ? (int) $v : 0;
}

$myOrgRole = null;
if ($orgId > 0) {
  $myOrgRole = require_membership($conn, $orgId, $current_user_id);
  if (!$myOrgRole) {
    die("Not a member of this organization.");
  }
}

$isProjectManager = ($myOrgRole && $myOrgRole['role'] === 'Project Manager');
$isSeniorDev = ($myOrgRole && $myOrgRole['role'] === 'Senior Developer');
$isJuniorDev = ($myOrgRole && $myOrgRole['role'] === 'Junior Developer');
$isQATester = ($myOrgRole && $myOrgRole['role'] === 'QA Tester');
$isSeniorQA = ($myOrgRole && $myOrgRole['role'] === 'Senior QA');
$isQALead = ($myOrgRole && $myOrgRole['role'] === 'QA Lead');

$isSystemAdmin = bugcatcher_is_system_admin_role($current_role);

// One workflow-role context for the whole page UI.
$scope = 'regular'; // regular | junior | senior | pm | admin | owner | qa | senior_qa | qa_lead

if ($isSystemAdmin) {
  $scope = 'admin';
} elseif ($isOrgOwner) {
  $scope = 'owner';
} elseif ($isProjectManager) {
  $scope = 'pm';
} elseif ($isSeniorDev) {
  $scope = 'senior';
} elseif ($isJuniorDev) {
  $scope = 'junior';
} elseif ($isQATester) {
  $scope = 'qa';
} elseif ($isSeniorQA) {
  $scope = 'senior_qa';
} elseif ($isQALead) {
  $scope = 'qa_lead';
} else {
  $scope = 'regular';
}

// Issue read visibility is organization-wide for every member.
// Only system admins can use the author filter.
if (!$isSystemAdmin) {
  $author = '';
}

// ---- Counts ----
function count_issues(mysqli $conn, int $orgId, string $status): int
{
  $filterSql = bugcatcher_issue_workflow_filter_sql('workflow_status', $status);
  $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM issues WHERE org_id=? AND {$filterSql}");
  $stmt->bind_param("i", $orgId);
  $stmt->execute();
  $total = (int) $stmt->get_result()->fetch_assoc()['total'];
  $stmt->close();
  return $total;
}

$openCount = count_issues($conn, $orgId, 'open');
$closedCount = count_issues($conn, $orgId, 'closed');

// ---- Dropdown data (store to arrays so they can be reused safely) ----
$usersArr = [];
$stmt = $conn->prepare("
  SELECT u.id, u.username
  FROM org_members om
  JOIN users u ON u.id = om.user_id
  WHERE om.org_id=?
  ORDER BY u.username ASC
");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$usersArr = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$labelsArr = [];
$lRes = $conn->query("SELECT id, name, description, color FROM labels ORDER BY name ASC");
while ($r = $lRes->fetch_assoc())
  $labelsArr[] = $r;

$projectOptions = bugcatcher_issue_project_catalog($conn, $orgId);
$createIssueSelectedProjectId = post_int('project_id');
if ($createIssueSelectedProjectId <= 0 && $projectOptions) {
  $createIssueSelectedProjectId = (int) ($projectOptions[0]['id'] ?? 0);
}

$createIssueError = '';
$createIssueSelectedLabels = array_map('strval', $_POST['labels'] ?? []);
$showCreateIssueModal = (($_GET['create'] ?? '') === 'open');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_issue') {
  try {
    bugcatcher_issue_create_from_form($conn, $orgId, (int) $current_user_id, $_POST, $_FILES);

    header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
      'page' => 'issues',
      'view' => $view,
      'status' => $status,
      'author' => $author,
      'label' => $label,
    ])));
    exit;
  } catch (Throwable $e) {
    $createIssueError = $e->getMessage();
    $showCreateIssueModal = true;
  }
}

/* ---------------- Handle DELETE Issue (by Org Owner or System Admin) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_issue') {
  $orgIdPost = post_int('org_id');
  $issueId = post_int('issue_id');

  if ($orgIdPost <= 0 || $issueId <= 0 || $orgIdPost !== $orgId) {
    die("Invalid request.");
  }

  // Allow: system admin OR org owner
  $allowed = false;

  if ($isSystemAdmin) {
    $allowed = true;
  } else {
    // Re-check organization owner from DB for safety
    $stmt = $conn->prepare("SELECT owner_id FROM organizations WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $orgIdPost);
    $stmt->execute();
    $orgRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $allowed = ((int) ($orgRow['owner_id'] ?? 0) === (int) $current_user_id);
  }

  if (!$allowed) {
    die("Only the organization owner can delete issues.");
  }

  // Make sure issue belongs to this org
  $stmt = $conn->prepare("SELECT id, workflow_status FROM issues WHERE id=? AND org_id=? LIMIT 1");
  $stmt->bind_param("ii", $issueId, $orgIdPost);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$ok) {
    die("Issue not found in this organization.");
  }

  // ✅ NEW: owner cannot delete closed issues
  if (
    !$isSystemAdmin
    && $isOrgOwner
    && bugcatcher_issue_workflow_is_closed((string) ($ok['workflow_status'] ?? ''))
  ) {
    die("Closed issues cannot be deleted by the organization owner.");
  }

  bugcatcher_file_storage_ensure_schema($conn);

  // Delete safely (remove files + rows)
  $conn->begin_transaction();
  try {

    // 1) Collect attachment references before deleting rows.
    $stmt = $conn->prepare("SELECT file_path, storage_key, storage_provider, mime_type FROM issue_attachments WHERE issue_id=?");
    $stmt->bind_param("i", $issueId);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $remoteFiles = [];
    $legacyPaths = [];
    foreach ($files as $file) {
      $storageKey = (string) ($file['storage_key'] ?? '');
      if ($storageKey !== '') {
        $remoteFiles[] = $file;
      } else {
        $abs = bugcatcher_upload_absolute_path((string) ($file['file_path'] ?? ''));
        if ($abs !== null) {
          $legacyPaths[] = $abs;
        }
      }
    }

    // 2) Delete attachment rows (optional; if FK cascade exists, still OK)
    $stmt = $conn->prepare("DELETE FROM issue_attachments WHERE issue_id=?");
    $stmt->bind_param("i", $issueId);
    $stmt->execute();
    $stmt->close();

    // 3) Remove label links
    $stmt = $conn->prepare("DELETE FROM issue_labels WHERE issue_id=?");
    $stmt->bind_param("i", $issueId);
    $stmt->execute();
    $stmt->close();

    // 4) Delete issue row
    $stmt = $conn->prepare("DELETE FROM issues WHERE id=? AND org_id=?");
    $stmt->bind_param("ii", $issueId, $orgIdPost);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
      $stmt->close();
      throw new Exception("Failed to delete issue.");
    }
    $stmt->close();

    $conn->commit();

    $deletedRemote = [];
    foreach ($remoteFiles as $remoteFile) {
      $storageKey = (string) ($remoteFile['storage_key'] ?? '');
      if ($storageKey === '') {
        continue;
      }

      $provider = bugcatcher_file_storage_provider_from_row($remoteFile);
      $deleteKey = $provider . '|' . $storageKey;
      if (isset($deletedRemote[$deleteKey])) {
        continue;
      }

      bugcatcher_file_storage_delete_if_unreferenced(
        $conn,
        $storageKey,
        null,
        null,
        (string) ($remoteFile['file_path'] ?? ''),
        $provider,
        (string) ($remoteFile['mime_type'] ?? '')
      );
      $deletedRemote[$deleteKey] = true;
    }
    foreach ($legacyPaths as $legacyPath) {
      bugcatcher_file_storage_delete_legacy_local($legacyPath);
    }

  } catch (Throwable $e) {
    $conn->rollback();
    die("Delete failed: " . $e->getMessage());
  }

  // Back to list (preserve filters)
  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

/* ---------------- Handle Assign Senior Developer ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_dev') {
  $orgId = post_int('org_id');
  $issueId = post_int('issue_id');
  $devId = post_int('dev_id');

  if ($orgId <= 0 || $issueId <= 0 || $devId <= 0) {
    die("Invalid request.");
  }

  $me = require_membership($conn, $orgId, $current_user_id);
  if (!$me || $me['role'] !== 'Project Manager') {
    die("Only Project Managers can assign issues.");
  }

  // Issue must belong to same org + must be unassigned
  $stmt = $conn->prepare("
    SELECT id, assigned_dev_id, workflow_status, pm_id
    FROM issues
    WHERE id=? AND org_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $issueId, $orgId);
  $stmt->execute();
  $issue = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$issue) {
    die("Issue not found in this organization.");
  }
  $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($issue['workflow_status'] ?? ''));
  if (!bugcatcher_issue_workflow_can_assign_dev($workflowStatus)) {
    die("Issue is not ready for PM assignment.");
  }

  // If issue already has a PM owner, only that PM can reassign
  if (!empty($issue['pm_id']) && (int) $issue['pm_id'] !== (int) $current_user_id) {
    die("Only the original Project Manager can re-assign this rejected issue.");
  }

  // Dev must be a member of same org AND role must be Senior Developer
  $stmt = $conn->prepare("
    SELECT 1
    FROM org_members
    WHERE org_id=? AND user_id=? AND role='Senior Developer'
    LIMIT 1
  ");
  $stmt->bind_param("ii", $orgId, $devId);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$ok) {
    die("Selected user is not a Senior Developer in this org.");
  }

  // Assign
  $stmt = $conn->prepare("
    UPDATE issues
    SET 
      pm_id = IFNULL(pm_id, ?),

      assigned_dev_id=?,
      assigned_junior_id=NULL,
      assigned_qa_id=NULL,
      assigned_senior_qa_id=NULL,
      assigned_qa_lead_id=NULL,

      junior_assigned_at=NULL,
      qa_assigned_at=NULL,
      senior_qa_assigned_at=NULL,
      qa_lead_assigned_at=NULL,
      junior_done_at=NULL,

      workflow_status='with_senior',
      assigned_at=NOW()
    WHERE id=? AND org_id=? AND workflow_status IN ('unassigned','rejected')
  ");
  $stmt->bind_param("iiii", $current_user_id, $devId, $issueId, $orgId);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to assign (maybe already assigned).");
  }
  $stmt->close();

  // Back to list
  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

/* ---------------- Handle Assign Junior Developer (by Senior Dev) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_junior') {
  $orgId = post_int('org_id');
  $issueId = post_int('issue_id');
  $juniorId = post_int('junior_id');

  if ($orgId <= 0 || $issueId <= 0 || $juniorId <= 0) {
    die("Invalid request.");
  }

  // Must be Senior Developer in this org
  $me = require_membership($conn, $orgId, $current_user_id);
  if (!$me || $me['role'] !== 'Senior Developer') {
    die("Only Senior Developers can assign to Junior Developers.");
  }

  // Issue must belong to org AND be assigned to THIS senior dev
  $stmt = $conn->prepare("
    SELECT id, assigned_dev_id, assigned_junior_id, workflow_status
    FROM issues
    WHERE id=? AND org_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $issueId, $orgId);
  $stmt->execute();
  $issue = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$issue) {
    die("Issue not found in this organization.");
  }

  if ((int) $issue['assigned_dev_id'] !== (int) $current_user_id) {
    die("You can only assign issues that are assigned to you.");
  }
  if (!bugcatcher_issue_workflow_can_assign_junior((string) ($issue['workflow_status'] ?? ''))) {
    die("Issue is not currently with a Senior Developer.");
  }

  // optional: prevent re-assigning junior if already assigned
  if (!empty($issue['assigned_junior_id'])) {
    die("Issue already assigned to a Junior Developer.");
  }

  // Junior must be member of same org with role Junior Developer
  $stmt = $conn->prepare("
    SELECT 1
    FROM org_members
    WHERE org_id=? AND user_id=? AND role='Junior Developer'
    LIMIT 1
  ");
  $stmt->bind_param("ii", $orgId, $juniorId);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$ok) {
    die("Selected user is not a Junior Developer in this org.");
  }

  // Assign junior
  $stmt = $conn->prepare("
    UPDATE issues
    SET assigned_junior_id=?, junior_assigned_at=NOW(), workflow_status='with_junior'
    WHERE id=? AND org_id=? AND assigned_dev_id=? AND assigned_junior_id IS NULL AND workflow_status='with_senior'
  ");
  $stmt->bind_param("iiii", $juniorId, $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to assign Junior Developer.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

/* ---------------- Handle Junior DONE (by Junior Dev) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'junior_done') {
  $orgId = post_int('org_id');
  $issueId = post_int('issue_id');

  if ($orgId <= 0 || $issueId <= 0) {
    die("Invalid request.");
  }

  // Must be Junior Developer in this org
  $me = require_membership($conn, $orgId, $current_user_id);
  if (!$me || $me['role'] !== 'Junior Developer') {
    die("Only Junior Developers can mark DONE.");
  }

  // Issue must belong to org AND be assigned to THIS junior
  $stmt = $conn->prepare("
    SELECT id, assigned_junior_id, workflow_status
    FROM issues
    WHERE id=? AND org_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $issueId, $orgId);
  $stmt->execute();
  $issue = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$issue) {
    die("Issue not found in this organization.");
  }

  if ((int) $issue['assigned_junior_id'] !== (int) $current_user_id) {
    die("You can only mark DONE issues assigned to you.");
  }

  if (!bugcatcher_issue_workflow_can_mark_junior_done((string) ($issue['workflow_status'] ?? ''))) {
    die("Issue is not currently with a Junior Developer.");
  }

  // Mark done
  $stmt = $conn->prepare("
    UPDATE issues
    SET workflow_status='done_by_junior', junior_done_at=NOW()
    WHERE id=? AND org_id=? AND assigned_junior_id=? AND workflow_status='with_junior'
  ");
  $stmt->bind_param("iii", $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to mark DONE.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

/* ---------------- Handle Assign QA Tester (by Senior Dev) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_qa') {
  $orgId = post_int('org_id');
  $issueId = post_int('issue_id');
  $qaId = post_int('qa_id');

  if ($orgId <= 0 || $issueId <= 0 || $qaId <= 0) {
    die("Invalid request.");
  }

  // Must be Senior Developer in this org
  $me = require_membership($conn, $orgId, $current_user_id);
  if (!$me || $me['role'] !== 'Senior Developer') {
    die("Only Senior Developers can assign QA Testers.");
  }

  // Issue must belong to org AND be assigned to THIS senior AND be done_by_junior
  $stmt = $conn->prepare("
    SELECT id, assigned_dev_id, assigned_qa_id, workflow_status
    FROM issues
    WHERE id=? AND org_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $issueId, $orgId);
  $stmt->execute();
  $issue = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$issue)
    die("Issue not found in this organization.");

  if ((int) $issue['assigned_dev_id'] !== (int) $current_user_id) {
    die("You can only analyze issues assigned to you.");
  }

  if (!bugcatcher_issue_workflow_can_assign_qa((string) ($issue['workflow_status'] ?? ''))) {
    die("Issue is not ready for QA (Junior must click DONE first).");
  }

  if (!empty($issue['assigned_qa_id'])) {
    die("QA already assigned.");
  }

  // QA must be member of same org with role QA Tester
  $stmt = $conn->prepare("
    SELECT 1
    FROM org_members
    WHERE org_id=? AND user_id=? AND role='QA Tester'
    LIMIT 1
  ");
  $stmt->bind_param("ii", $orgId, $qaId);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$ok) {
    die("Selected user is not a QA Tester in this org.");
  }

  // Assign QA + move state
  $stmt = $conn->prepare("
    UPDATE issues
    SET assigned_qa_id=?, qa_assigned_at=NOW(), workflow_status='with_qa'
    WHERE id=? AND org_id=? AND assigned_dev_id=? AND assigned_qa_id IS NULL AND workflow_status='done_by_junior'
  ");
  $stmt->bind_param("iiii", $qaId, $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to assign QA.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

/* ---------------- Handle REPORT to Senior QA (by QA Tester) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_senior_qa') {
  $orgId = post_int('org_id');
  $issueId = post_int('issue_id');
  $seniorQaId = post_int('senior_qa_id');

  if ($orgId <= 0 || $issueId <= 0 || $seniorQaId <= 0) {
    die("Invalid request.");
  }

  // Must be QA Tester in this org
  $me = require_membership($conn, $orgId, $current_user_id);
  if (!$me || $me['role'] !== 'QA Tester') {
    die("Only QA Testers can report issues to Senior QA.");
  }

  // Issue must belong to org AND be assigned to THIS QA AND be with_qa
  $stmt = $conn->prepare("
    SELECT id, assigned_qa_id, assigned_senior_qa_id, workflow_status
    FROM issues
    WHERE id=? AND org_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $issueId, $orgId);
  $stmt->execute();
  $issue = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$issue)
    die("Issue not found in this organization.");
  if ((int) ($issue['assigned_qa_id'] ?? 0) !== (int) $current_user_id) {
    die("You can only report issues assigned to you.");
  }
  if (!bugcatcher_issue_workflow_can_report_senior_qa((string) ($issue['workflow_status'] ?? ''))) {
    die("Issue is not currently with QA.");
  }
  if (!empty($issue['assigned_senior_qa_id'])) {
    die("Issue already reported to a Senior QA.");
  }

  // Senior QA must be member of same org with role Senior QA
  $stmt = $conn->prepare("
    SELECT 1
    FROM org_members
    WHERE org_id=? AND user_id=? AND role='Senior QA'
    LIMIT 1
  ");
  $stmt->bind_param("ii", $orgId, $seniorQaId);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$ok) {
    die("Selected user is not a Senior QA in this org.");
  }

  // Assign Senior QA + move state
  $stmt = $conn->prepare("
    UPDATE issues
    SET assigned_senior_qa_id=?, senior_qa_assigned_at=NOW(), workflow_status='with_senior_qa'
    WHERE id=? AND org_id=? 
      AND assigned_qa_id=? 
      AND assigned_senior_qa_id IS NULL
      AND workflow_status='with_qa'
  ");
  $stmt->bind_param("iiii", $seniorQaId, $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to report to Senior QA.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

/* ---------------- Handle REPORT to QA Lead (by Senior QA) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_qa_lead') {
  $orgId = post_int('org_id');
  $issueId = post_int('issue_id');
  $qaLeadId = post_int('qa_lead_id');

  if ($orgId <= 0 || $issueId <= 0 || $qaLeadId <= 0) {
    die("Invalid request.");
  }

  // Must be Senior QA in this org
  $me = require_membership($conn, $orgId, $current_user_id);
  if (!$me || $me['role'] !== 'Senior QA') {
    die("Only Senior QA can report issues to QA Lead.");
  }

  // Issue must belong to org AND be assigned to THIS senior QA AND be with_senior_qa
  $stmt = $conn->prepare("
    SELECT id, assigned_senior_qa_id, assigned_qa_lead_id, workflow_status
    FROM issues
    WHERE id=? AND org_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $issueId, $orgId);
  $stmt->execute();
  $issue = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$issue)
    die("Issue not found in this organization.");
  if ((int) ($issue['assigned_senior_qa_id'] ?? 0) !== (int) $current_user_id) {
    die("You can only report issues assigned to you.");
  }
  if (!bugcatcher_issue_workflow_can_report_qa_lead((string) ($issue['workflow_status'] ?? ''))) {
    die("Issue is not currently with Senior QA.");
  }
  if (!empty($issue['assigned_qa_lead_id'])) {
    die("Issue already reported to QA Lead.");
  }

  // QA Lead must be member of same org with role QA Lead
  $stmt = $conn->prepare("
    SELECT 1
    FROM org_members
    WHERE org_id=? AND user_id=? AND role='QA Lead'
    LIMIT 1
  ");
  $stmt->bind_param("ii", $orgId, $qaLeadId);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$ok) {
    die("Selected user is not a QA Lead in this org.");
  }

  // Assign QA Lead + move state
  $stmt = $conn->prepare("
    UPDATE issues
    SET assigned_qa_lead_id=?, qa_lead_assigned_at=NOW(), workflow_status='with_qa_lead'
    WHERE id=? AND org_id=?
      AND assigned_senior_qa_id=?
      AND assigned_qa_lead_id IS NULL
      AND workflow_status='with_senior_qa'
  ");
  $stmt->bind_param("iiii", $qaLeadId, $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to report to QA Lead.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

/* ---------------- Handle APPROVE (by QA Lead) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'qa_lead_approve') {
  $orgId = post_int('org_id');
  $issueId = post_int('issue_id');

  if ($orgId <= 0 || $issueId <= 0)
    die("Invalid request.");

  // Must be QA Lead in this org
  $me = require_membership($conn, $orgId, $current_user_id);
  if (!$me || $me['role'] !== 'QA Lead')
    die("Only QA Lead can approve.");

  // Must be assigned to THIS QA Lead and currently with_qa_lead
  $stmt = $conn->prepare("
    SELECT id, assigned_qa_lead_id, workflow_status
    FROM issues
    WHERE id=? AND org_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $issueId, $orgId);
  $stmt->execute();
  $issue = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$issue)
    die("Issue not found.");
  if ((int) ($issue['assigned_qa_lead_id'] ?? 0) !== (int) $current_user_id)
    die("Not assigned to you.");
  if (!bugcatcher_issue_workflow_can_qa_lead_decide((string) ($issue['workflow_status'] ?? '')))
    die("Issue is not with QA Lead.");

  // Approve -> goes back to PM (PM already sees all, we just set state)
  $stmt = $conn->prepare("
    UPDATE issues
    SET workflow_status='approved'
    WHERE id=? AND org_id=? AND assigned_qa_lead_id=? AND workflow_status='with_qa_lead'
  ");
  $stmt->bind_param("iii", $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to approve.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

/* ---------------- Handle REJECT (by QA Lead) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'qa_lead_reject') {
  $orgId = post_int('org_id');
  $issueId = post_int('issue_id');

  if ($orgId <= 0 || $issueId <= 0)
    die("Invalid request.");

  // Must be QA Lead in this org
  $me = require_membership($conn, $orgId, $current_user_id);
  if (!$me || $me['role'] !== 'QA Lead')
    die("Only QA Lead can reject.");

  $stmt = $conn->prepare("
    SELECT id, assigned_qa_lead_id, workflow_status
    FROM issues
    WHERE id=? AND org_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $issueId, $orgId);
  $stmt->execute();
  $issue = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$issue)
    die("Issue not found.");
  if ((int) ($issue['assigned_qa_lead_id'] ?? 0) !== (int) $current_user_id)
    die("Not assigned to you.");
  if (!bugcatcher_issue_workflow_can_qa_lead_decide((string) ($issue['workflow_status'] ?? '')))
    die("Issue is not with QA Lead.");

  // Reject -> PM can reassign again (cycle repeats)
  $stmt = $conn->prepare("
    UPDATE issues
    SET 
      workflow_status='rejected',

      assigned_dev_id=NULL,
      assigned_junior_id=NULL,
      assigned_qa_id=NULL,
      assigned_senior_qa_id=NULL,
      assigned_qa_lead_id=NULL,

      assigned_at=NULL,
      junior_assigned_at=NULL,
      junior_done_at=NULL,
      qa_assigned_at=NULL,
      senior_qa_assigned_at=NULL,
      qa_lead_assigned_at=NULL

    WHERE id=? AND org_id=? 
      AND assigned_qa_lead_id=? 
      AND workflow_status='with_qa_lead'
  ");
  $stmt->bind_param("iii", $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to reject.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

/* ---------------- Handle CLOSE (by Project Manager) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pm_close') {
  $orgId = post_int('org_id');
  $issueId = post_int('issue_id');

  if ($orgId <= 0 || $issueId <= 0)
    die("Invalid request.");

  // Must be PM
  $me = require_membership($conn, $orgId, $current_user_id);
  if (!$me || $me['role'] !== 'Project Manager')
    die("Only Project Managers can close issues.");

  // Must be approved + open
  $stmt = $conn->prepare("
    SELECT id, workflow_status
    FROM issues
    WHERE id=? AND org_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $issueId, $orgId);
  $stmt->execute();
  $issue = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$issue)
    die("Issue not found.");
  if (bugcatcher_issue_workflow_is_closed((string) ($issue['workflow_status'] ?? '')))
    die("Issue is already closed.");
  if (!bugcatcher_issue_workflow_can_pm_close((string) ($issue['workflow_status'] ?? '')))
    die("Only APPROVED issues can be closed.");

  $stmt = $conn->prepare("
    UPDATE issues
    SET workflow_status='closed'
    WHERE id=? AND org_id=? AND workflow_status='approved'
  ");
  $stmt->bind_param("ii", $issueId, $orgId);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to close issue.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => 'closed',
    'author' => $author,
    'label' => $label,
  ])));
  exit;
}

$seniorDevs = [];
$stmt = $conn->prepare("
  SELECT u.id, u.username
  FROM org_members om
  JOIN users u ON u.id = om.user_id
  WHERE om.org_id=? AND om.role='Senior Developer'
  ORDER BY u.username ASC
");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$seniorDevs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$juniorDevs = [];
$stmt = $conn->prepare("
  SELECT u.id, u.username
  FROM org_members om
  JOIN users u ON u.id = om.user_id
  WHERE om.org_id=? AND om.role='Junior Developer'
  ORDER BY u.username ASC
");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$juniorDevs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$qaTesters = [];
$stmt = $conn->prepare("
  SELECT u.id, u.username
  FROM org_members om
  JOIN users u ON u.id = om.user_id
  WHERE om.org_id=? AND om.role='QA Tester'
  ORDER BY u.username ASC
");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$qaTesters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$seniorQAs = [];
$stmt = $conn->prepare("
  SELECT u.id, u.username
  FROM org_members om
  JOIN users u ON u.id = om.user_id
  WHERE om.org_id=? AND om.role='Senior QA'
  ORDER BY u.username ASC
");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$seniorQAs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$qaLeads = [];
$stmt = $conn->prepare("
  SELECT u.id, u.username
  FROM org_members om
  JOIN users u ON u.id = om.user_id
  WHERE om.org_id=? AND om.role='QA Lead'
  ORDER BY u.username ASC
");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$qaLeads = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------------- Ranking: Closed Issues by Role ---------------- */

$rankingRoles = [
  'Project Manager',
  'QA Lead',
  'Senior QA',
  'Senior Developer',
  'QA Tester',
  'Junior Developer'
];

$rankingPlaceholders = implode(',', array_fill(0, count($rankingRoles), '?'));
$rankingTypes = "i" . str_repeat("s", count($rankingRoles));

$rankingSql = "
  SELECT
    u.id,
    u.username,
    om.role,
    COUNT(i.id) AS closed_total
  FROM org_members om
  JOIN users u
    ON u.id = om.user_id
  LEFT JOIN issues i
    ON i.org_id = om.org_id
    AND i.workflow_status = 'closed'
    AND (
      (om.role = 'Project Manager'   AND i.pm_id = om.user_id) OR
      (om.role = 'QA Lead'           AND i.assigned_qa_lead_id = om.user_id) OR
      (om.role = 'Senior QA'         AND i.assigned_senior_qa_id = om.user_id) OR
      (om.role = 'Senior Developer'  AND i.assigned_dev_id = om.user_id) OR
      (om.role = 'QA Tester'         AND i.assigned_qa_id = om.user_id) OR
      (om.role = 'Junior Developer'  AND i.assigned_junior_id = om.user_id)
    )
  WHERE om.org_id = ?
    AND om.role IN ($rankingPlaceholders)
  GROUP BY u.id, u.username, om.role
  ORDER BY closed_total DESC, u.username ASC
";

$rankingStmt = $conn->prepare($rankingSql);
$rankingParams = array_merge([$orgId], $rankingRoles);
$rankingStmt->bind_param($rankingTypes, ...$rankingParams);
$rankingStmt->execute();
$rankingRows = $rankingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rankingStmt->close();

$rankingPageSize = 10;
$rankingTotal = count($rankingRows);
$rankingPageCount = max(1, (int) ceil($rankingTotal / $rankingPageSize));
$rankingPage = min($rankingPage, $rankingPageCount);
$rankingOffset = ($rankingPage - 1) * $rankingPageSize;
$leaderboardRows = array_slice($rankingRows, $rankingOffset, $rankingPageSize);
$leaderboardStart = $rankingTotal > 0 ? ($rankingOffset + 1) : 0;
$leaderboardEnd = min($rankingOffset + $rankingPageSize, $rankingTotal);

// ---- Issues query (prepared + optional author/label filters) ----
$statusSql = bugcatcher_issue_workflow_filter_sql('issues.workflow_status', $status);
$sql = "
  SELECT
    issues.*,
    users.username,
    projects.name AS project_name,
    projects.code AS project_code,
    issues.workflow_status,
    CASE
      WHEN issues.workflow_status = 'closed' THEN 'closed'
      ELSE 'open'
    END AS status,
    issues.workflow_status AS assign_status
  FROM issues
  JOIN users ON issues.author_id = users.id
  JOIN projects ON projects.id = issues.project_id
  WHERE issues.org_id = ? AND {$statusSql}
";

$params = [$orgId];
$types = "i";

// Admin can filter by author freely
// PM can also filter by author if you want, but you said "no author dropdown", so it likely stays hidden anyway.
if ($author !== '' && $scope === 'admin') {
  $sql .= " AND issues.author_id = ?";
  $params[] = (int) $author;
  $types .= "i";
}

// Label filter (works for everyone)
if ($label !== '') {
  $sql .= " AND issues.id IN (SELECT issue_id FROM issue_labels WHERE label_id = ?)";
  $params[] = (int) $label;
  $types .= "i";
}

$sql .= " ORDER BY issues.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$issuesRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Helper to build URLs while preserving filters
function issues_url($status, $author, $label, $view)
{
  $qs = [
    'page' => 'issues',
    'view' => $view,
    'status' => $status,
  ];
  if ($author !== '')
    $qs['author'] = $author;
  if ($label !== '')
    $qs['label'] = $label;

  return bugcatcher_path("zen/dashboard.php?" . http_build_query($qs));
}

function issues_url_clear($status, $view)
{
  return bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'issues',
    'view' => $view,
    'status' => $status
  ]));
}

function leaderboard_url(int $rankingPage): string
{
  return bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
    'ranking_page' => max(1, $rankingPage),
  ]));
}

function issue_detail_url(int $issueId): string
{
  return bugcatcher_path("zen/issue_detail.php?" . http_build_query([
    'id' => $issueId,
  ]));
}

function issue_project_label(array $issue): string
{
  $name = trim((string) ($issue['project_name'] ?? ''));
  $code = trim((string) ($issue['project_code'] ?? ''));
  if ($name === '') {
    return 'No project';
  }
  return $code !== '' ? ($name . ' (' . $code . ')') : $name;
}

function issue_workflow_badge_class(string $workflowStatus): string
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

function issue_lane_class(string $laneKey): string
{
  return 'issue-lane-' . preg_replace('/[^a-z0-9_-]/i', '-', $laneKey);
}

$issueIds = array_map(static function (array $row): int {
  return (int) ($row['id'] ?? 0);
}, $issuesRows);
$issueIds = array_values(array_filter($issueIds));

$attachmentCounts = [];
$issueLabels = [];
if ($issueIds) {
  $issueIdsSql = implode(',', $issueIds);

  $attachmentRes = $conn->query("
    SELECT issue_id, COUNT(*) AS total
    FROM issue_attachments
    WHERE issue_id IN ({$issueIdsSql})
    GROUP BY issue_id
  ");
  while ($attachmentRes && ($attachmentRow = $attachmentRes->fetch_assoc())) {
    $attachmentCounts[(int) $attachmentRow['issue_id']] = (int) $attachmentRow['total'];
  }

  $labelsRes = $conn->query("
    SELECT issue_labels.issue_id, labels.name, labels.color
    FROM issue_labels
    JOIN labels ON labels.id = issue_labels.label_id
    WHERE issue_labels.issue_id IN ({$issueIdsSql})
    ORDER BY labels.name ASC
  ");
  while ($labelsRes && ($labelRow = $labelsRes->fetch_assoc())) {
    $issueId = (int) ($labelRow['issue_id'] ?? 0);
    if (!isset($issueLabels[$issueId])) {
      $issueLabels[$issueId] = [];
    }
    $issueLabels[$issueId][] = [
      'name' => (string) ($labelRow['name'] ?? ''),
      'color' => (string) ($labelRow['color'] ?? '#bbb'),
    ];
  }
}

$issuesByLane = [];
foreach (bugcatcher_issue_workflow_lanes() as $lane) {
  $issuesByLane[(string) $lane['key']] = [];
}

foreach ($issuesRows as &$row) {
  $issueId = (int) ($row['id'] ?? 0);
  $workflowStatus = bugcatcher_issue_workflow_normalize((string) ($row['workflow_status'] ?? ''));
  $row['workflow_status'] = $workflowStatus;
  $row['status'] = bugcatcher_issue_workflow_status_alias($workflowStatus);
  $row['assign_status'] = bugcatcher_issue_workflow_assign_status_alias($workflowStatus);
  $row['workflow_label'] = bugcatcher_issue_workflow_label($workflowStatus);
  $row['badge_class'] = issue_workflow_badge_class($workflowStatus);
  $row['attachment_count'] = $attachmentCounts[$issueId] ?? 0;
  $row['labels'] = $issueLabels[$issueId] ?? [];

  foreach (bugcatcher_issue_workflow_lanes() as $lane) {
    $laneKey = (string) ($lane['key'] ?? '');
    $laneStates = $lane['states'] ?? [];
    if (in_array($workflowStatus, $laneStates, true)) {
      $issuesByLane[$laneKey][] = $row;
    }
  }
}
unset($row);

?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>BugCatcher</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('app/legacy_theme.css?v=5')) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('app/legacy_issues.css?v=2')) ?>">
</head>

<body>
  <?php bugcatcher_render_sidebar($page, $current_username, $current_role, (string) ($myOrgRole['role'] ?? ''), $orgName); ?>

  <main class="main">

    <?php if ($page === 'dashboard'): ?>
      <?php bugcatcher_render_page_header(
        'Dashboard',
        $current_username,
        $current_role,
        (string) ($myOrgRole['role'] ?? '')
      ); ?>

      <div class="leaderboard-card">
        <div class="leaderboard-head">
          <div>
            <h2>Leaderboard in <?= htmlspecialchars($orgName) ?></h2>
            <div class="leaderboard-sub">
              Showing ranks <?= (int) $leaderboardStart ?>-<?= (int) $leaderboardEnd ?> of <?= (int) $rankingTotal ?>
              based on the number of closed issues completed by role
            </div>
          </div>
        </div>

        <?php if (!empty($leaderboardRows)): ?>
          <div class="leaderboard-list">
            <?php foreach ($leaderboardRows as $index => $rankUser): ?>
              <?php
              $rankNumber = $leaderboardStart + $index;
              $rankClass = '';
              if ($rankNumber === 1)
                $rankClass = 'rank-gold';
              elseif ($rankNumber === 2)
                $rankClass = 'rank-silver';
              elseif ($rankNumber === 3)
                $rankClass = 'rank-bronze';
              ?>
              <div class="leaderboard-row <?= $rankClass ?>">
                <?php if ($rankNumber === 1): ?>
                  <span class="leaderboard-crown">&#128081;</span>
                <?php endif; ?>
                <div class="leaderboard-left">
                  <span class="leaderboard-rank">#<?= $rankNumber ?></span>
                  <span class="leaderboard-avatar"><?= strtoupper(substr($rankUser['username'], 0, 1)) ?></span>
                  <div class="leaderboard-userinfo">
                    <div class="leaderboard-name"><?= htmlspecialchars($rankUser['username']) ?></div>
                    <div class="leaderboard-role"><?= htmlspecialchars($rankUser['role']) ?></div>
                  </div>
                </div>
                <div class="leaderboard-score"><?= (int) $rankUser['closed_total'] ?> closed</div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($rankingPageCount > 1): ?>
            <div class="leaderboard-footer">
              <div class="leaderboard-pagination">
                <a
                  href="<?= htmlspecialchars(leaderboard_url(max(1, $rankingPage - 1))) ?>"
                  class="leaderboard-page-btn <?= $rankingPage <= 1 ? 'is-disabled' : '' ?>"
                  <?= $rankingPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                >
                  Previous
                </a>
                <div class="leaderboard-page-list">
                  <?php
                  $pageStart = max(1, $rankingPage - 2);
                  $pageEnd = min($rankingPageCount, $rankingPage + 2);
                  for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++):
                    ?>
                    <a
                      href="<?= htmlspecialchars(leaderboard_url($pageNumber)) ?>"
                      class="leaderboard-page-btn <?= $pageNumber === $rankingPage ? 'is-active' : '' ?>"
                    >
                      <?= $pageNumber ?>
                    </a>
                  <?php endfor; ?>
                </div>
                <a
                  href="<?= htmlspecialchars(leaderboard_url(min($rankingPageCount, $rankingPage + 1))) ?>"
                  class="leaderboard-page-btn <?= $rankingPage >= $rankingPageCount ? 'is-disabled' : '' ?>"
                  <?= $rankingPage >= $rankingPageCount ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                >
                  Next
                </a>
              </div>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="leaderboard-empty">No ranked members yet.</div>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <?php bugcatcher_render_page_header(
        'Issues',
        $current_username,
        $current_role,
        (string) ($myOrgRole['role'] ?? '')
      ); ?>
    <?php endif; ?>

    <?php if ($page === 'issues'): ?>
    <div class="issue-container issue-board-shell">
      <div class="issues-hero">
        <div>
          <div class="issues-eyebrow"><?= htmlspecialchars($orgName) ?></div>
          <h2>Workflow queue</h2>
          <p>Kanban is read-only, while list view keeps role actions for the active org workflow.</p>
        </div>

        <div class="issues-hero-actions">
          <div class="view-switcher" role="tablist" aria-label="Issue view mode">
            <a href="<?= issues_url($status, $author, $label, 'kanban') ?>"
              class="view-switcher-link <?= $view === 'kanban' ? 'active' : '' ?>">Kanban</a>
            <a href="<?= issues_url($status, $author, $label, 'list') ?>"
              class="view-switcher-link <?= $view === 'list' ? 'active' : '' ?>">List</a>
          </div>

          <button type="button" class="btn-green issue-create-trigger" data-modal-open="createIssueModal" <?= $projectOptions ? '' : 'disabled' ?>>
            Create Issue
          </button>
        </div>
      </div>

      <div class="gh-toolbar">
        <div class="gh-filters">

          <?php if (bugcatcher_is_system_admin_role($current_role)): ?>
            <!-- Author dropdown -->
            <div class="gh-dd" data-dd="author">
              <button type="button" class="gh-dd-btn">
                Author <span class="caret">▾</span>
              </button>

              <div class="gh-dd-menu">
                <div class="gh-dd-header">Filter by author</div>
                <div class="gh-dd-search">
                  <input type="text" placeholder="Filter authors" data-search="author">
                </div>

                <div class="gh-dd-list" data-list="author">
                  <a class="gh-dd-item" href="<?= issues_url($status, '', $label, $view) ?>">
                    <span class="chk <?= ($author === '' ? 'on' : '') ?>"></span>
                    <span class="txt">Any author</span>
                  </a>

                  <?php foreach ($usersArr as $u): ?>
                    <a class="gh-dd-item" data-text="<?= htmlspecialchars(strtolower($u['username'])) ?>"
                      href="<?= issues_url($status, (int) $u['id'], $label, $view) ?>">
                      <span class="chk <?= ((string) $author === (string) $u['id'] ? 'on' : '') ?>"></span>
                      <span class="avatar"><?= strtoupper(substr($u['username'], 0, 1)) ?></span>
                      <span class="txt"><?= htmlspecialchars($u['username']) ?></span>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <!-- Labels dropdown -->
          <div class="gh-dd" data-dd="labels">
            <button type="button" class="gh-dd-btn">
              Labels <span class="caret">▾</span>
            </button>

            <div class="gh-dd-menu">
              <div class="gh-dd-header">Filter by label</div>
              <div class="gh-dd-search">
                <input type="text" placeholder="Filter labels" data-search="labels">
              </div>

              <div class="gh-dd-list" data-list="labels">
                <a class="gh-dd-item" href="<?= issues_url($status, $author, '', $view) ?>">
                  <span class="chk <?= ($label === '' ? 'on' : '') ?>"></span>
                  <span class="txt">No labels</span>
                </a>

                <?php foreach ($labelsArr as $l): ?>
                  <?php
                  $t = strtolower(($l['name'] ?? '') . ' ' . ($l['description'] ?? ''));
                  ?>
                  <a class="gh-dd-item" data-text="<?= htmlspecialchars($t) ?>"
                    href="<?= issues_url($status, $author, (int) $l['id'], $view) ?>">
                    <span class="chk <?= ((string) $label === (string) $l['id'] ? 'on' : '') ?>"></span>
                    <span class="dot" style="background:<?= htmlspecialchars($l['color'] ?? '#bbb') ?>"></span>
                    <span class="txt">
                      <?= htmlspecialchars($l['name']) ?>
                      <?php if (!empty($l['description'])): ?>
                        <span class="sub"><?= htmlspecialchars($l['description']) ?></span>
                      <?php endif; ?>
                    </span>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Clear filters -->
          <button type="button" class="gh-clear-btn" onclick="window.location.href='<?= issues_url_clear($status, $view) ?>'">
            Clear Filters
          </button>

        </div>
      </div>

      <div class="issue-summary-bar">
        <span><?= (int) $openCount ?> open</span>
        <span><?= (int) $closedCount ?> closed</span>
        <span><?= count($issuesRows) ?> visible</span>
      </div>

      <div class="issue-filter-chips">
        <a href="<?= issues_url('all', $author, $label, $view) ?>"
          class="issue-filter-chip <?= $status === 'all' ? 'active' : '' ?>">All Statuses</a>
        <a href="<?= issues_url('open', $author, $label, $view) ?>"
          class="issue-filter-chip <?= $status === 'open' ? 'active' : '' ?>">Open Only</a>
        <a href="<?= issues_url('closed', $author, $label, $view) ?>"
          class="issue-filter-chip <?= $status === 'closed' ? 'active' : '' ?>">Closed Only</a>
      </div>

      <?php if ($view === 'kanban'): ?>
        <div class="issue-kanban-shell" data-kanban-shell>
          <button type="button" class="issue-kanban-nav issue-kanban-nav--prev" data-kanban-nav="prev" aria-label="Scroll issue lanes left">
            <span aria-hidden="true">&lt;</span>
          </button>
          <div class="issue-kanban-scroll" data-kanban-scroll>
            <div class="issue-kanban-board">
              <?php foreach (bugcatcher_issue_workflow_lanes() as $lane): ?>
                <?php
                $laneKey = (string) ($lane['key'] ?? '');
                if (($status === 'closed' && $laneKey !== 'closed') || ($status === 'open' && $laneKey === 'closed')) {
                  continue;
                }
                $laneIssues = $issuesByLane[$laneKey] ?? [];
                ?>
                <section class="issue-lane <?= htmlspecialchars(issue_lane_class($laneKey)) ?>">
                  <div class="issue-lane-head">
                    <div>
                      <h3><?= htmlspecialchars((string) ($lane['label'] ?? 'Lane')) ?></h3>
                      <p><?= count($laneIssues) ?> issue<?= count($laneIssues) === 1 ? '' : 's' ?></p>
                    </div>
                  </div>

                  <div class="issue-lane-stack">
                    <?php if (!$laneIssues): ?>
                      <div class="issue-lane-empty">No issues in this lane yet.</div>
                    <?php else: ?>
                      <?php foreach ($laneIssues as $laneIssue): ?>
                        <?php $laneIssueId = (int) ($laneIssue['id'] ?? 0); ?>
                        <a href="<?= htmlspecialchars(issue_detail_url($laneIssueId)) ?>" class="issue-kanban-card">
                          <div class="issue-kanban-card-top">
                            <span class="badge <?= htmlspecialchars((string) ($laneIssue['badge_class'] ?? 'badge-unassigned')) ?>">
                              <?= htmlspecialchars((string) ($laneIssue['workflow_label'] ?? 'Unassigned')) ?>
                            </span>
                            <span class="issue-kanban-id">#<?= $laneIssueId ?></span>
                          </div>
                          <div class="issue-kanban-title">
                            <?= htmlspecialchars((string) ($laneIssue['title'] ?? 'Untitled issue')) ?>
                          </div>
                          <div class="issue-kanban-meta">
                            <span><?= htmlspecialchars(issue_project_label($laneIssue)) ?></span>
                            <span><?= htmlspecialchars((string) ($laneIssue['username'] ?? 'Unknown')) ?></span>
                          </div>
                          <div class="issue-kanban-meta">
                            <span><?= date("M d, Y", strtotime((string) ($laneIssue['created_at'] ?? 'now'))) ?></span>
                            <span><?= (int) ($laneIssue['attachment_count'] ?? 0) ?> evidence</span>
                          </div>
                          <div class="issue-kanban-meta">
                            <span><?= count($laneIssue['labels'] ?? []) ?> labels</span>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </section>
              <?php endforeach; ?>
            </div>
          </div>
          <button type="button" class="issue-kanban-nav issue-kanban-nav--next" data-kanban-nav="next" aria-label="Scroll issue lanes right">
            <span aria-hidden="true">&gt;</span>
          </button>
        </div>
      <?php else: ?>
      <!-- Issues list -->
      <?php if (!$issuesRows): ?>
        <div class="issue-list-empty">No issues matched your current filters.</div>
      <?php endif; ?>
      <?php foreach ($issuesRows as $row): ?>
        <?php
        $issueId = (int) $row['id'];

        $hasSenior = !empty($row['assigned_dev_id']);
        $hasJunior = !empty($row['assigned_junior_id']);

        $isUnassigned = (($row['assign_status'] ?? 'unassigned') === 'unassigned') && !$hasSenior;
        $pmShouldSeePending = ($isProjectManager && $hasSenior && ($row['status'] ?? '') === 'open');
        $assignStatus = $row['assign_status'] ?? 'unassigned';

        $isAssignedToMeAsSenior = ($isSeniorDev && (int) ($row['assigned_dev_id'] ?? 0) === (int) $current_user_id);
        $isAssignedToMeAsJunior = ($isJuniorDev && (int) ($row['assigned_junior_id'] ?? 0) === (int) $current_user_id);

        $isJuniorUnassigned = empty($row['assigned_junior_id']);
        $qaUnassigned = empty($row['assigned_qa_id']);
        $isReadyForQA = (($row['assign_status'] ?? '') === 'done_by_junior');

        $isAssignedToMeAsQA = ($isQATester && (int) ($row['assigned_qa_id'] ?? 0) === (int) $current_user_id);
        $seniorQaUnassigned = empty($row['assigned_senior_qa_id']);
        $isReadyToReport = (($row['assign_status'] ?? '') === 'with_qa');

        $isAssignedToMeAsSeniorQA = ($isSeniorQA && (int) ($row['assigned_senior_qa_id'] ?? 0) === (int) $current_user_id);
        $qaLeadUnassigned = empty($row['assigned_qa_lead_id']);
        $isReadyForQALead = (($row['assign_status'] ?? '') === 'with_senior_qa');

        $isAssignedToMeAsQALead = ($isQALead && (int) ($row['assigned_qa_lead_id'] ?? 0) === (int) $current_user_id);
        $isWithQALead = (($row['assign_status'] ?? '') === 'with_qa_lead');

        $badgeText = (string) ($row['workflow_label'] ?? bugcatcher_issue_workflow_label($assignStatus));
        $badgeClass = (string) ($row['badge_class'] ?? issue_workflow_badge_class($assignStatus));
        ?>

        <div class="issue issue-list-card" data-issue-link="<?= htmlspecialchars(issue_detail_url($issueId)) ?>">
          <div class="issue-head">

            <!-- LEFT SIDE (title + meta + description + attachments) -->
            <div class="issue-left">

              <div class="issue-title">
                #<?= (int) $issueId ?>   <?= htmlspecialchars($row['title']) ?>
                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeText) ?></span>
              </div>

              <div class="issue-meta">
                <?= htmlspecialchars(issue_project_label($row)) ?>
                Â· opened by <?= htmlspecialchars($row['username']) ?>
                · <?= date("M d, Y", strtotime($row['created_at'])) ?>
              </div>

              <?php if (!empty(trim($row['description'] ?? ''))): ?>
                <div class="issue-desc">
                  <?= nl2br(htmlspecialchars($row['description'])) ?>
                </div>
              <?php endif; ?>

              <?php
              // Attachments (safe)
              $attStmt = $conn->prepare("
                SELECT file_path, original_name
                FROM issue_attachments
                WHERE issue_id=?
                ORDER BY id ASC
              ");
              $attStmt->bind_param("i", $issueId);
              $attStmt->execute();
              $attRes = $attStmt->get_result();
              ?>

              <?php if ($attRes && $attRes->num_rows > 0): ?>
                <div class="issue-attachments">
                  <?php while ($att = $attRes->fetch_assoc()): ?>
                    <?php
                    $src = htmlspecialchars($att['file_path']);
                    $nm = htmlspecialchars($att['original_name']);
                    ?>
                    <a href="<?= $src ?>" target="_blank" rel="noopener">
                      <img src="<?= $src ?>" alt="<?= $nm ?>">
                    </a>
                  <?php endwhile; ?>
                </div>
              <?php endif; ?>

              <?php $attStmt->close(); ?>

            </div>

            <!-- RIGHT ACTION -->
            <div class="issue-action">

              <?php if ($isSystemAdmin || ($isOrgOwner && ($row['status'] ?? '') !== 'closed')): ?>
                <form method="POST" class="assign-form"
                  onsubmit="return confirm('Delete this issue permanently? This cannot be undone.');">
                  <input type="hidden" name="action" value="delete_issue">
                  <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
                  <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">

                  <button class="btn btn-delete" type="submit">DELETE</button>
                </form>
              <?php endif; ?>

              <?php
              $pmShouldSeePending = ($isProjectManager && $hasSenior);
              ?>

              <?php if ($isProjectManager): ?>

                <?php if (!$hasSenior && in_array(($row['assign_status'] ?? 'unassigned'), ['unassigned', 'rejected'], true)): ?>
                  <!-- PM assigns Senior -->
                  <?php if (empty($seniorDevs)): ?>
                    <button class="btn btn-disabled" type="button" disabled>No Senior Dev</button>
                  <?php else: ?>
                    <form method="POST" class="assign-form">
                      <input type="hidden" name="action" value="assign_dev">
                      <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
                      <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">

                      <div class="assign-bar">
                        <select name="dev_id" class="assign-select" required>
                          <option value="">Assign to...</option>
                          <?php foreach ($seniorDevs as $d): ?>
                            <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['username']) ?></option>
                          <?php endforeach; ?>
                        </select>

                        <button class="btn btn-assign" type="submit">
                          <span class="btn-assign-icon">➜</span> ASSIGN
                        </button>
                      </div>
                    </form>
                  <?php endif; ?>

                <?php elseif ($isProjectManager && ($row['status'] ?? '') === 'open' && $hasSenior): ?>
                  <?php
                  $st = $row['assign_status'] ?? '';

                  // ✅ If approved, do NOT show the "WITH SENIOR" pending button
                  if ($st === 'approved') {
                    // do nothing here (CLOSE button is shown below)
                  } else {
                    if ($st === 'with_junior') {
                      $txt = 'WITH JUNIOR';
                    } elseif ($st === 'done_by_junior') {
                      $txt = 'DONE (JUNIOR)';
                    } elseif ($st === 'with_qa') {
                      $txt = 'WITH QA';
                    } elseif ($st === 'with_senior_qa') {
                      $txt = 'WITH SENIOR QA';
                    } elseif ($st === 'with_qa_lead') {
                      $txt = 'WITH QA LEAD';
                    } elseif ($st === 'with_senior') {
                      $txt = 'WITH SENIOR';
                    } elseif ($st === 'rejected') {
                      $txt = 'REJECTED';
                    } else {
                      $txt = 'PENDING';
                    }
                    ?>
                    <button class="btn btn-pending" type="button" disabled><?= $txt ?></button>
                    <?php
                  }
                  ?>
                <?php endif; ?>

                <?php if ($isProjectManager && ($row['status'] ?? '') === 'open' && ($row['assign_status'] ?? '') === 'approved'): ?>
                  <form method="POST" class="assign-form" onsubmit="return confirm('Close this approved issue?');">
                    <input type="hidden" name="action" value="pm_close">
                    <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">
                    <button class="btn btn-done" type="submit">CLOSE</button>
                  </form>
                <?php endif; ?>

              <?php endif; ?>

              <?php if ($isAssignedToMeAsSenior && $isJuniorUnassigned): ?>
                <!-- Senior assigns Junior -->
                <?php if (empty($juniorDevs)): ?>
                  <button class="btn btn-disabled" type="button" disabled>No Junior Dev</button>
                <?php else: ?>
                  <form method="POST" class="assign-form">
                    <input type="hidden" name="action" value="assign_junior">
                    <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">

                    <div class="assign-bar">
                      <select name="junior_id" class="assign-select" required>
                        <option value="">Assign Junior...</option>
                        <?php foreach ($juniorDevs as $j): ?>
                          <option value="<?= (int) $j['id'] ?>"><?= htmlspecialchars($j['username']) ?></option>
                        <?php endforeach; ?>
                      </select>

                      <button class="btn btn-assign" type="submit">
                        <span class="btn-assign-icon">➜</span> ASSIGN
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($isAssignedToMeAsJunior && ($row['status'] ?? '') === 'open'): ?>
                <?php if (($row['assign_status'] ?? '') === 'with_junior'): ?>
                  <form method="POST" class="assign-form">
                    <input type="hidden" name="action" value="junior_done">
                    <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">

                    <button class="btn btn-done" type="submit">DONE</button>
                  </form>
                <?php elseif (($row['assign_status'] ?? '') === 'done_by_junior' || ($row['assign_status'] ?? '') === 'with_qa'): ?>
                  <button class="btn btn-pending" type="button" disabled>DONE</button>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($isAssignedToMeAsSenior && $qaUnassigned && $isReadyForQA && ($row['status'] ?? '') === 'open'): ?>
                <?php if (empty($qaTesters)): ?>
                  <button class="btn btn-disabled" type="button" disabled>No QA Tester</button>
                <?php else: ?>
                  <form method="POST" class="assign-form">
                    <input type="hidden" name="action" value="assign_qa">
                    <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">

                    <div class="assign-bar">
                      <select name="qa_id" class="assign-select" required>
                        <option value="">Assign QA...</option>
                        <?php foreach ($qaTesters as $q): ?>
                          <option value="<?= (int) $q['id'] ?>">
                            <?= htmlspecialchars($q['username']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>

                      <button class="btn btn-assign" type="submit">
                        ANALYZE
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
              <?php elseif ($isAssignedToMeAsSenior && ($row['assign_status'] ?? '') === 'with_qa'): ?>
                <button class="btn btn-pending" type="button" disabled>WITH QA</button>
              <?php endif; ?>

              <?php if ($isAssignedToMeAsQA && $seniorQaUnassigned && $isReadyToReport && ($row['status'] ?? '') === 'open'): ?>
                <?php if (empty($seniorQAs)): ?>
                  <button class="btn btn-disabled" type="button" disabled>No Senior QA</button>
                <?php else: ?>
                  <form method="POST" class="assign-form">
                    <input type="hidden" name="action" value="report_senior_qa">
                    <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">

                    <div class="assign-bar">
                      <select name="senior_qa_id" class="assign-select" required>
                        <option value="">Senior QA...</option>
                        <?php foreach ($seniorQAs as $sq): ?>
                          <option value="<?= (int) $sq['id'] ?>">
                            <?= htmlspecialchars($sq['username']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>

                      <button class="btn btn-assign" type="submit">
                        REPORT
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
              <?php elseif ($isAssignedToMeAsQA && (($row['assign_status'] ?? '') === 'with_senior_qa')): ?>
                <button class="btn btn-pending" type="button" disabled>REPORTED</button>
              <?php endif; ?>

              <?php if ($isAssignedToMeAsSeniorQA && $qaLeadUnassigned && $isReadyForQALead && ($row['status'] ?? '') === 'open'): ?>
                <?php if (empty($qaLeads)): ?>
                  <button class="btn btn-disabled" type="button" disabled>No QA Lead</button>
                <?php else: ?>
                  <form method="POST" class="assign-form">
                    <input type="hidden" name="action" value="report_qa_lead">
                    <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
                    <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">

                    <div class="assign-bar">
                      <select name="qa_lead_id" class="assign-select" required>
                        <option value="">QA Lead...</option>
                        <?php foreach ($qaLeads as $lead): ?>
                          <option value="<?= (int) $lead['id'] ?>">
                            <?= htmlspecialchars($lead['username']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>

                      <button class="btn btn-assign" type="submit">REPORT</button>
                    </div>
                  </form>
                <?php endif; ?>

              <?php elseif ($isAssignedToMeAsSeniorQA && (($row['assign_status'] ?? '') === 'with_qa_lead')): ?>
                <button class="btn btn-pending" type="button" disabled>REPORTED</button>
              <?php endif; ?>

              <?php if ($isAssignedToMeAsQALead && $isWithQALead && ($row['status'] ?? '') === 'open'): ?>
                <form method="POST" class="assign-form inline-actions">
                  <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
                  <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">

                  <button class="btn btn-approve" type="submit" name="action" value="qa_lead_approve">
                    ✓ APPROVE
                  </button>

                  <button class="btn btn-reject" type="submit" name="action" value="qa_lead_reject"
                    onclick="return confirm('Reject this issue and send back to PM for reassignment?');">
                    ✕ REJECT
                  </button>
                </form>
              <?php endif; ?>

            </div>
          </div>

          <!-- labels etc stay the same -->
          <div style="margin-top:6px;">
            <?php foreach (($row['labels'] ?? []) as $issueLabel): ?>
              <span class="label" style="background:<?= htmlspecialchars((string) ($issueLabel['color'] ?? '#bbb')) ?>">
                <?= htmlspecialchars((string) ($issueLabel['name'] ?? 'Label')) ?>
              </span>
            <?php endforeach; ?>
          </div>

        </div>
      <?php endforeach; ?>
      <?php endif; ?>

    </div>

    <div class="issue-modal-backdrop <?= $showCreateIssueModal ? 'is-visible' : '' ?>" data-modal-backdrop="createIssueModal"
      <?= $showCreateIssueModal ? '' : 'hidden' ?>></div>
    <div class="issue-modal-shell <?= $showCreateIssueModal ? 'is-visible' : '' ?>" id="createIssueModal" role="dialog"
      aria-modal="true" aria-labelledby="createIssueTitle" <?= $showCreateIssueModal ? '' : 'hidden' ?>>
      <div class="issue-modal-card">
        <div class="issue-modal-head">
          <div>
            <div class="issues-eyebrow">Create Issue</div>
            <h3 id="createIssueTitle">Open a new issue</h3>
            <p>Any organization member can open an issue for this workspace.</p>
          </div>
          <button type="button" class="issue-modal-close" data-modal-close="createIssueModal"
            aria-label="Close create issue form">×</button>
        </div>

        <?php if ($createIssueError !== ''): ?>
          <div class="bc-alert error">
            <?= htmlspecialchars($createIssueError) ?>
          </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="issue-modal-form">
          <input type="hidden" name="action" value="create_issue">

          <label class="issue-form-label">Project</label>
          <select name="project_id" required class="issue-input">
            <option value="">Select a project</option>
            <?php foreach ($projectOptions as $project): ?>
              <?php
              $projectId = (int) ($project['id'] ?? 0);
              $projectCode = trim((string) ($project['code'] ?? ''));
              $projectLabel = $projectCode !== ''
                ? ((string) ($project['name'] ?? 'Project') . ' (' . $projectCode . ')')
                : (string) ($project['name'] ?? 'Project');
              ?>
              <option value="<?= $projectId ?>" <?= $createIssueSelectedProjectId === $projectId ? 'selected' : '' ?>>
                <?= htmlspecialchars($projectLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$projectOptions): ?>
            <small class="issue-help">Create an active project first before opening a new issue.</small>
          <?php endif; ?>

          <label class="issue-form-label">Title</label>
          <input type="text" name="title" required class="issue-input"
            value="<?= htmlspecialchars((string) ($_POST['title'] ?? '')) ?>">

          <label class="issue-form-label">Description</label>
          <textarea name="description"
            class="issue-textarea"><?= htmlspecialchars((string) ($_POST['description'] ?? '')) ?></textarea>

          <label class="issue-form-label">Attach Images</label>
          <input type="file" id="dashboardImagesInput" name="images[]" accept="image/*" multiple class="issue-file-input">
          <small class="issue-help">
            You can upload JPG/PNG/GIF/WebP. Max 10 MB each.
          </small>

          <div id="dashboardImgPreview" class="issue-preview"></div>

          <div class="issue-label-row">
            <span class="issue-form-label">Labels</span>
            <button type="button" id="dashboardClearLabelsBtn">Clear Labels</button>
          </div>

          <div class="label-pills">
            <?php foreach ($labelsArr as $l):
              $labelId = (int) $l['id'];
              $checked = in_array((string) $labelId, $createIssueSelectedLabels, true);
              ?>
              <label class="pill <?= $checked ? 'selected' : '' ?>" data-pill>
                <span class="dot" style="background: <?= htmlspecialchars((string) ($l['color'] ?? '#bbb')) ?>;"></span>
                <input type="checkbox" name="labels[]" value="<?= $labelId ?>" <?= $checked ? 'checked' : '' ?>>
                <span class="pill-text"><?= htmlspecialchars((string) ($l['name'] ?? 'Label')) ?></span>
                <span class="pill-close">&times;</span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="issue-modal-actions">
            <button type="button" class="btn btn-modal-secondary" data-modal-close="createIssueModal">Cancel</button>
            <button type="submit" id="dashboardSubmitBtn" class="btn-green" <?= ($createIssueSelectedLabels && $projectOptions) ? '' : 'disabled' ?>>
              Create Issue
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </main>

  <script src="<?= htmlspecialchars(bugcatcher_path('app/mobile_nav.js?v=3')) ?>"></script>
  <script src="<?= htmlspecialchars(bugcatcher_path('app/notifications_ui.js?v=1')) ?>"></script>
  <script>
    // Toggle dropdown open/close
    document.querySelectorAll(".gh-dd-btn").forEach(btn => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const dd = btn.closest(".gh-dd");
        const willOpen = !dd.classList.contains("open");
        document.querySelectorAll(".gh-dd").forEach(x => x.classList.remove("open"));
        if (willOpen) dd.classList.add("open");
      });
    });

    // Close when clicking outside
    document.addEventListener("click", () => {
      document.querySelectorAll(".gh-dd").forEach(x => x.classList.remove("open"));
    });

    // Search filter
    function setupSearch(type) {
      const input = document.querySelector(`[data-search="${type}"]`);
      const list = document.querySelector(`[data-list="${type}"]`);
      if (!input || !list) return;

      input.addEventListener("input", () => {
        const q = input.value.trim().toLowerCase();
        list.querySelectorAll(".gh-dd-item").forEach(item => {
          const isSpecial =
            item.innerText.toLowerCase().includes("any author") ||
            item.innerText.toLowerCase().includes("any label");

          const t = (item.getAttribute("data-text") || item.innerText).toLowerCase();
          item.style.display = (isSpecial || t.includes(q)) ? "flex" : "none";
        });
      });
    }

    <?php if (bugcatcher_is_system_admin_role($current_role)): ?>
      setupSearch("author");
    <?php endif; ?>
    setupSearch("labels");

    function setModalState(modalId, open) {
      const modal = document.getElementById(modalId);
      const backdrop = document.querySelector(`[data-modal-backdrop="${modalId}"]`);
      if (!modal || !backdrop) return;

      modal.hidden = !open;
      backdrop.hidden = !open;
      modal.classList.toggle("is-visible", open);
      backdrop.classList.toggle("is-visible", open);
      document.body.classList.toggle("modal-open", open);
    }

    document.querySelectorAll("[data-modal-open]").forEach(trigger => {
      trigger.addEventListener("click", () => {
        setModalState(trigger.dataset.modalOpen, true);
      });
    });

    document.querySelectorAll("[data-modal-close]").forEach(trigger => {
      trigger.addEventListener("click", () => {
        setModalState(trigger.dataset.modalClose, false);
      });
    });

    document.querySelectorAll("[data-modal-backdrop]").forEach(backdrop => {
      backdrop.addEventListener("click", () => {
        setModalState(backdrop.dataset.modalBackdrop, false);
      });
    });

    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      document.querySelectorAll(".issue-modal-shell.is-visible").forEach(modal => {
        setModalState(modal.id, false);
      });
    });

    function syncIssuePill(pill) {
      const checkbox = pill.querySelector('input[type="checkbox"]');
      if (!checkbox) return;
      pill.classList.toggle("selected", checkbox.checked);
    }

    function updateIssueSubmitState() {
      const submitButton = document.getElementById("dashboardSubmitBtn");
      if (!submitButton) return;
      const checked = document.querySelectorAll('#createIssueModal input[name="labels[]"]:checked');
      submitButton.disabled = checked.length === 0;
    }

    document.querySelectorAll("#createIssueModal [data-pill]").forEach(pill => {
      const checkbox = pill.querySelector('input[type="checkbox"]');
      if (!checkbox) return;

      pill.addEventListener("click", (event) => {
        if (event.target.tagName === "INPUT") return;
        event.preventDefault();

        if (event.target.classList.contains("pill-close")) {
          checkbox.checked = false;
        } else {
          checkbox.checked = !checkbox.checked;
        }

        syncIssuePill(pill);
        updateIssueSubmitState();
      });

      checkbox.addEventListener("change", () => {
        syncIssuePill(pill);
        updateIssueSubmitState();
      });

      syncIssuePill(pill);
    });

    document.getElementById("dashboardClearLabelsBtn")?.addEventListener("click", () => {
      document.querySelectorAll('#createIssueModal input[name="labels[]"]:checked').forEach(checkbox => {
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event("change", { bubbles: true }));
      });
    });

    updateIssueSubmitState();

    const dashboardImagesInput = document.getElementById("dashboardImagesInput");
    const dashboardImgPreview = document.getElementById("dashboardImgPreview");
    let dashboardImageUrls = [];

    dashboardImagesInput?.addEventListener("change", () => {
      dashboardImageUrls.forEach(url => URL.revokeObjectURL(url));
      dashboardImageUrls = [];

      if (!dashboardImgPreview) return;
      dashboardImgPreview.innerHTML = "";

      Array.from(dashboardImagesInput.files || []).forEach(file => {
        if (!file.type.startsWith("image/")) return;

        const url = URL.createObjectURL(file);
        dashboardImageUrls.push(url);

        const wrap = document.createElement("div");
        wrap.className = "issue-preview-card";

        const link = document.createElement("a");
        link.href = url;
        link.target = "_blank";

        const img = document.createElement("img");
        img.src = url;
        img.alt = file.name;

        link.appendChild(img);
        wrap.appendChild(link);
        dashboardImgPreview.appendChild(wrap);
      });
    });

    document.querySelectorAll("[data-issue-link]").forEach(card => {
      card.addEventListener("click", (event) => {
        if (event.target.closest("button, a, input, select, textarea, form, label")) {
          return;
        }
        const href = card.getAttribute("data-issue-link");
        if (href) {
          window.location.href = href;
        }
      });
    });

    document.querySelectorAll("[data-kanban-shell]").forEach(shell => {
      const scrollRegion = shell.querySelector("[data-kanban-scroll]");
      const prevButton = shell.querySelector('[data-kanban-nav="prev"]');
      const nextButton = shell.querySelector('[data-kanban-nav="next"]');

      if (!scrollRegion || !prevButton || !nextButton) {
        return;
      }

      const syncKanbanButtons = () => {
        const maxScrollLeft = Math.max(scrollRegion.scrollWidth - scrollRegion.clientWidth, 0);
        const currentLeft = scrollRegion.scrollLeft;
        const hasOverflow = maxScrollLeft > 6;

        prevButton.disabled = !hasOverflow || currentLeft <= 6;
        nextButton.disabled = !hasOverflow || currentLeft >= maxScrollLeft - 6;
      };

      const scrollStep = () => Math.max(scrollRegion.clientWidth * 0.82, 220);

      prevButton.addEventListener("click", () => {
        scrollRegion.scrollBy({ left: -scrollStep(), behavior: "smooth" });
      });

      nextButton.addEventListener("click", () => {
        scrollRegion.scrollBy({ left: scrollStep(), behavior: "smooth" });
      });

      scrollRegion.addEventListener("scroll", syncKanbanButtons, { passive: true });
      window.addEventListener("resize", syncKanbanButtons);
      requestAnimationFrame(syncKanbanButtons);
    });

    <?php if ($showCreateIssueModal): ?>
      setModalState("createIssueModal", true);
    <?php endif; ?>
  </script>

</body>

</html>
