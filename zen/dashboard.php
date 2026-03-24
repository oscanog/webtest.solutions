<?php
require_once dirname(__DIR__) . '/db.php';

if (empty($_SESSION['active_org_id']) || (int) $_SESSION['active_org_id'] <= 0) {
  $_SESSION['org_error'] = "You haven't joined an organization to access this, please do it first.";
  header("Location: " . bugcatcher_path('zen/organization.php'));
  exit;
}

// ---- Params ----
$page = 'dashboard';
$status = $_GET['status'] ?? 'open';             // open | closed
$author = $_GET['author'] ?? '';                 // user id
$label = $_GET['label'] ?? '';                  // label id
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
$status = ($status === 'closed') ? 'closed' : 'open';
$author = ($author !== '' && ctype_digit((string) $author)) ? (int) $author : '';
$label = ($label !== '' && ctype_digit((string) $label)) ? (int) $label : '';

function require_membership(mysqli $conn, int $orgId, int $userId): ?array
{
  $stmt = $conn->prepare("SELECT role FROM org_members WHERE org_id=? AND user_id=? LIMIT 1");
  $stmt->bind_param("ii", $orgId, $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
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
  $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM issues WHERE org_id=? AND status=?");
  $stmt->bind_param("is", $orgId, $status);
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
  $stmt = $conn->prepare("SELECT id, status FROM issues WHERE id=? AND org_id=? LIMIT 1");
  $stmt->bind_param("ii", $issueId, $orgIdPost);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$ok) {
    die("Issue not found in this organization.");
  }

  // ✅ NEW: owner cannot delete closed issues
  if (!$isSystemAdmin && $isOrgOwner && ($ok['status'] ?? '') === 'closed') {
    die("Closed issues cannot be deleted by the organization owner.");
  }

  // Delete safely (remove files + rows)
  $conn->begin_transaction();
  try {

    // 1) Collect attachment file paths (relative like "uploads/issues/xxx.jpg")
    $stmt = $conn->prepare("SELECT file_path FROM issue_attachments WHERE issue_id=?");
    $stmt->bind_param("i", $issueId);
    $stmt->execute();
    $attRes = $stmt->get_result();

    $files = [];
    while ($a = $attRes->fetch_assoc()) {
      $files[] = (string) ($a['file_path'] ?? '');
    }
    $stmt->close();

    // 2) Delete physical files safely from the configured shared uploads path
    foreach ($files as $rel) {
      if ($rel === '') {
        continue;
      }

      $abs = bugcatcher_upload_absolute_path($rel);
      if ($abs !== null) {
        @unlink($abs);
      }
    }

    // 3) Delete attachment rows (optional; if FK cascade exists, still OK)
    $stmt = $conn->prepare("DELETE FROM issue_attachments WHERE issue_id=?");
    $stmt->bind_param("i", $issueId);
    $stmt->execute();
    $stmt->close();

    // 4) Remove label links
    $stmt = $conn->prepare("DELETE FROM issue_labels WHERE issue_id=?");
    $stmt->bind_param("i", $issueId);
    $stmt->execute();
    $stmt->close();

    // 5) Delete issue row
    $stmt = $conn->prepare("DELETE FROM issues WHERE id=? AND org_id=?");
    $stmt->bind_param("ii", $issueId, $orgIdPost);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
      $stmt->close();
      throw new Exception("Failed to delete issue.");
    }
    $stmt->close();

    $conn->commit();

  } catch (Throwable $e) {
    $conn->rollback();
    die("Delete failed: " . $e->getMessage());
  }

  // Back to list (preserve filters)
  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    SELECT id, assigned_dev_id, assign_status, pm_id
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
  $st = ($issue['assign_status'] ?? 'unassigned');
  if (!in_array($st, ['unassigned', 'rejected'], true)) {
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

      assign_status='with_senior',
      assigned_at=NOW()
    WHERE id=? AND org_id=? AND status='open' AND assign_status IN ('unassigned','rejected')
  ");
  $stmt->bind_param("iiii", $current_user_id, $devId, $issueId, $orgId);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to assign (maybe already assigned).");
  }
  $stmt->close();

  // Back to list
  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    SELECT id, assigned_dev_id, assigned_junior_id, assign_status
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
    SET assigned_junior_id=?, junior_assigned_at=NOW(), assign_status='with_junior'
    WHERE id=? AND org_id=? AND assigned_dev_id=? AND assigned_junior_id IS NULL
  ");
  $stmt->bind_param("iiii", $juniorId, $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to assign Junior Developer.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    SELECT id, assigned_junior_id, assign_status, status
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

  if (($issue['status'] ?? '') !== 'open') {
    die("Only open issues can be marked DONE.");
  }

  if ((int) $issue['assigned_junior_id'] !== (int) $current_user_id) {
    die("You can only mark DONE issues assigned to you.");
  }

  if (($issue['assign_status'] ?? '') !== 'with_junior') {
    die("Issue is not currently with a Junior Developer.");
  }

  // Mark done
  $stmt = $conn->prepare("
    UPDATE issues
    SET assign_status='done_by_junior', junior_done_at=NOW()
    WHERE id=? AND org_id=? AND assigned_junior_id=? AND assign_status='with_junior'
  ");
  $stmt->bind_param("iii", $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to mark DONE.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    SELECT id, assigned_dev_id, assigned_qa_id, assign_status, status
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
  if (($issue['status'] ?? '') !== 'open')
    die("Only open issues can be analyzed.");

  if ((int) $issue['assigned_dev_id'] !== (int) $current_user_id) {
    die("You can only analyze issues assigned to you.");
  }

  if (($issue['assign_status'] ?? '') !== 'done_by_junior') {
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
    SET assigned_qa_id=?, qa_assigned_at=NOW(), assign_status='with_qa'
    WHERE id=? AND org_id=? AND assigned_dev_id=? AND assigned_qa_id IS NULL AND assign_status='done_by_junior'
  ");
  $stmt->bind_param("iiii", $qaId, $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to assign QA.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    SELECT id, assigned_qa_id, assigned_senior_qa_id, assign_status, status
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
  if (($issue['status'] ?? '') !== 'open')
    die("Only open issues can be reported.");
  if ((int) ($issue['assigned_qa_id'] ?? 0) !== (int) $current_user_id) {
    die("You can only report issues assigned to you.");
  }
  if (($issue['assign_status'] ?? '') !== 'with_qa') {
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
    SET assigned_senior_qa_id=?, senior_qa_assigned_at=NOW(), assign_status='with_senior_qa'
    WHERE id=? AND org_id=? 
      AND assigned_qa_id=? 
      AND assigned_senior_qa_id IS NULL
      AND assign_status='with_qa'
  ");
  $stmt->bind_param("iiii", $seniorQaId, $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to report to Senior QA.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    SELECT id, assigned_senior_qa_id, assigned_qa_lead_id, assign_status, status
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
  if (($issue['status'] ?? '') !== 'open')
    die("Only open issues can be reported.");
  if ((int) ($issue['assigned_senior_qa_id'] ?? 0) !== (int) $current_user_id) {
    die("You can only report issues assigned to you.");
  }
  if (($issue['assign_status'] ?? '') !== 'with_senior_qa') {
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
    SET assigned_qa_lead_id=?, qa_lead_assigned_at=NOW(), assign_status='with_qa_lead'
    WHERE id=? AND org_id=?
      AND assigned_senior_qa_id=?
      AND assigned_qa_lead_id IS NULL
      AND assign_status='with_senior_qa'
  ");
  $stmt->bind_param("iiii", $qaLeadId, $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to report to QA Lead.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    SELECT id, assigned_qa_lead_id, assign_status, status
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
  if (($issue['status'] ?? '') !== 'open')
    die("Only open issues can be approved.");
  if ((int) ($issue['assigned_qa_lead_id'] ?? 0) !== (int) $current_user_id)
    die("Not assigned to you.");
  if (($issue['assign_status'] ?? '') !== 'with_qa_lead')
    die("Issue is not with QA Lead.");

  // Approve -> goes back to PM (PM already sees all, we just set state)
  $stmt = $conn->prepare("
    UPDATE issues
    SET assign_status='approved'
    WHERE id=? AND org_id=? AND assigned_qa_lead_id=? AND assign_status='with_qa_lead' AND status='open'
  ");
  $stmt->bind_param("iii", $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to approve.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    SELECT id, assigned_qa_lead_id, assign_status, status
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
  if (($issue['status'] ?? '') !== 'open')
    die("Only open issues can be rejected.");
  if ((int) ($issue['assigned_qa_lead_id'] ?? 0) !== (int) $current_user_id)
    die("Not assigned to you.");
  if (($issue['assign_status'] ?? '') !== 'with_qa_lead')
    die("Issue is not with QA Lead.");

  // Reject -> PM can reassign again (cycle repeats)
  $stmt = $conn->prepare("
    UPDATE issues
    SET 
      assign_status='rejected',

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
      AND assign_status='with_qa_lead'
      AND status='open'
  ");
  $stmt->bind_param("iii", $issueId, $orgId, $current_user_id);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to reject.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    SELECT id, assign_status, status
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
  if (($issue['status'] ?? '') !== 'open')
    die("Issue is already closed.");
  if (($issue['assign_status'] ?? '') !== 'approved')
    die("Only APPROVED issues can be closed.");

  $stmt = $conn->prepare("
    UPDATE issues
    SET status='closed', assign_status='closed'
    WHERE id=? AND org_id=? AND status='open' AND assign_status='approved'
  ");
  $stmt->bind_param("ii", $issueId, $orgId);

  if (!$stmt->execute() || $stmt->affected_rows !== 1) {
    $stmt->close();
    die("Failed to close issue.");
  }
  $stmt->close();

  header("Location: " . bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
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
    AND i.status = 'closed'
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

$topTenRanking = array_slice($rankingRows, 0, 10);
$remainingRanking = array_slice($rankingRows, 10);

// ---- Issues query (prepared + optional author/label filters) ----
$sql = "
  SELECT issues.*, users.username
  FROM issues
  JOIN users ON issues.author_id = users.id
  WHERE issues.status = ? AND issues.org_id = ?
";

$params = [$status, $orgId];
$types = "si";

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
$issuesRes = $stmt->get_result();

// Helper to build URLs while preserving filters
function issues_url($status, $author, $label)
{
  $qs = [
    'page' => 'dashboard',
    'status' => $status,
  ];
  if ($author !== '')
    $qs['author'] = $author;
  if ($label !== '')
    $qs['label'] = $label;

  return bugcatcher_path("zen/dashboard.php?" . http_build_query($qs));
}

function issues_url_clear($status)
{
  return bugcatcher_path("zen/dashboard.php?" . http_build_query([
    'page' => 'dashboard',
    'status' => $status
  ]));
}

?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>BugCatcher</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(bugcatcher_path('favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(bugcatcher_path('zen/dashboard.css?v=12')) ?>">
</head>

<body>

  <button type="button" class="mobile-nav-toggle" data-drawer-toggle data-drawer-target="zen-sidebar"
    aria-controls="zen-sidebar" aria-expanded="false" aria-label="Open navigation menu">
    <span></span>
    <span></span>
    <span></span>
  </button>
  <div class="mobile-nav-backdrop" data-drawer-backdrop hidden></div>

  <aside class="sidebar" id="zen-sidebar" data-drawer data-drawer-breakpoint="900">
    <div class="logo">BugCatcher</div>
    <nav class="nav">
      <a href="<?= htmlspecialchars(bugcatcher_path('zen/dashboard.php?page=dashboard')) ?>" class="<?= $page === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
      <?php if ($scope === 'admin'): ?>
        <a href="#">Manage Users</a>
        <a href="#">All Reports</a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars(bugcatcher_path('zen/organization.php')) ?>">Organization</a>
      <a href="<?= htmlspecialchars(bugcatcher_path('melvin/project_list.php')) ?>">Projects</a>
      <a href="<?= htmlspecialchars(bugcatcher_path('melvin/checklist_list.php')) ?>">Checklist</a>
      <a href="<?= htmlspecialchars(bugcatcher_path('discord-link.php')) ?>">Discord Link</a>
      <?php if (bugcatcher_is_super_admin_role($current_role)): ?>
        <a href="<?= htmlspecialchars(bugcatcher_path('super-admin/openclaw.php')) ?>">Super Admin</a>
      <?php endif; ?>
      <a href="<?= htmlspecialchars(bugcatcher_path('rainier/logout.php')) ?>" class="nav-logout">Logout</a>
    </nav>
    <div class="sidebar-userbox">
      Logged in as<br>
      <strong><?= htmlspecialchars($current_username) ?></strong><br>
      <span class="sidebar-role">(<?= htmlspecialchars($current_role) ?>)</span>
    </div>
  </aside>

  <main class="main">

    <?php if ($page === 'dashboard'): ?>
      <div class="topbar">
        <h1>Dashboard</h1>
        <div class="topbar-right">
          <span>Welcome, <?= htmlspecialchars($current_username) ?> (<?= htmlspecialchars($current_role) ?>)</span>
          <a href="<?= htmlspecialchars(bugcatcher_path('rainier/logout.php')) ?>" class="topbar-logout">Logout</a>
        </div>
      </div>

      <div class="leaderboard-card">
        <div class="leaderboard-head">
          <div>
            <h2>Top 10 in <?= htmlspecialchars($orgName) ?></h2>
            <div class="leaderboard-sub">Based on the number of closed issues completed by role</div>
          </div>
        </div>

        <?php if (!empty($topTenRanking)): ?>
          <div class="leaderboard-list">
            <?php foreach ($topTenRanking as $index => $rankUser): ?>
              <?php
              $rankClass = '';
              if ($index === 0)
                $rankClass = 'rank-gold';
              elseif ($index === 1)
                $rankClass = 'rank-silver';
              elseif ($index === 2)
                $rankClass = 'rank-bronze';
              ?>
              <div class="leaderboard-row <?= $rankClass ?>">
                <?php if ($index === 0): ?>
                  <span class="leaderboard-crown">&#128081;</span>
                <?php endif; ?>
                <div class="leaderboard-left">
                  <span class="leaderboard-rank">#<?= $index + 1 ?></span>
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

          <?php if (!empty($remainingRanking)): ?>
            <div class="leaderboard-footer">
              <button type="button" class="leaderboard-viewmore-btn" id="viewMoreRankingBtn">View More</button>
            </div>
            <div class="leaderboard-all" id="allRankingWrap" style="display:none;">
              <div class="leaderboard-all-top">
                <div class="leaderboard-all-title">More ranked members</div>
                <div class="leaderboard-all-controls">
                  <input type="text" id="moreRankingSearch" class="leaderboard-search-input" placeholder="Search user..."
                    autocomplete="off">
                  <select id="moreRankingSort" class="leaderboard-sort-select">
                    <option value="desc">Highest to Lowest</option>
                    <option value="asc">Lowest to Highest</option>
                  </select>
                </div>
              </div>
              <div id="moreRankingList">
                <?php foreach ($remainingRanking as $index => $rankUser): ?>
                  <div class="leaderboard-row more-ranking-row" data-closed-total="<?= (int) $rankUser['closed_total'] ?>"
                    data-username="<?= htmlspecialchars(strtolower($rankUser['username']), ENT_QUOTES) ?>"
                    data-role="<?= htmlspecialchars(strtolower($rankUser['role']), ENT_QUOTES) ?>">
                    <div class="leaderboard-left">
                      <span class="leaderboard-rank more-ranking-number">#<?= $index + 11 ?></span>
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
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="leaderboard-empty">No ranked members yet.</div>
        <?php endif; ?>
      </div>

      <div class="topbar topbar-secondary">
        <h1 class="topbar-subtitle">Issues</h1>

        <?php if ($isProjectManager): ?>
          <a href="<?= htmlspecialchars(bugcatcher_path('zen/create_issue.php')) ?>" class="btn-green">New Issue</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="topbar">
        <h1>Issues</h1>
        <a href="<?= htmlspecialchars(bugcatcher_path('zen/create_issue.php')) ?>" class="btn-green">New Issue</a>
      </div>
    <?php endif; ?>

    <!-- Issues list (shown on dashboard too, but links go to page=issues) -->
    <div class="issue-container">

      <!-- Toolbar (Author/Labels dropdown with search) -->
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
                  <a class="gh-dd-item" href="<?= issues_url($status, '', $label) ?>">
                    <span class="chk <?= ($author === '' ? 'on' : '') ?>"></span>
                    <span class="txt">Any author</span>
                  </a>

                  <?php foreach ($usersArr as $u): ?>
                    <a class="gh-dd-item" data-text="<?= htmlspecialchars(strtolower($u['username'])) ?>"
                      href="<?= issues_url($status, (int) $u['id'], $label) ?>">
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
                <a class="gh-dd-item" href="<?= issues_url($status, $author, '') ?>">
                  <span class="chk <?= ($label === '' ? 'on' : '') ?>"></span>
                  <span class="txt">No labels</span>
                </a>

                <?php foreach ($labelsArr as $l): ?>
                  <?php
                  $t = strtolower(($l['name'] ?? '') . ' ' . ($l['description'] ?? ''));
                  ?>
                  <a class="gh-dd-item" data-text="<?= htmlspecialchars($t) ?>"
                    href="<?= issues_url($status, $author, (int) $l['id']) ?>">
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
          <button type="button" class="gh-clear-btn" onclick="window.location.href='<?= issues_url_clear($status) ?>'">
            Clear Filters
          </button>

        </div>
      </div>

      <!-- Open/Closed tabs -->
      <div class="issue-tabs">
        <a href="<?= issues_url('open', $author, $label) ?>" class="<?= $status === 'open' ? 'active' : '' ?>">
          Open <?= $openCount ?>
        </a>

        <a href="<?= issues_url('closed', $author, $label) ?>" class="<?= $status === 'closed' ? 'active' : '' ?>">
          Closed <?= $closedCount ?>
        </a>
      </div>

      <!-- Issues list -->
      <?php while ($row = $issuesRes->fetch_assoc()): ?>
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

        $badgeText = '';
        $badgeClass = '';

        if (($row['status'] ?? 'open') === 'closed') {
          $badgeText = 'Closed';
          $badgeClass = 'badge-closed';
        } else {
          switch ($assignStatus) {
            case 'with_senior':
              $badgeText = 'With Senior';
              $badgeClass = 'badge-senior';
              break;

            case 'with_junior':
              $badgeText = 'With Junior';
              $badgeClass = 'badge-junior';
              break;

            case 'done_by_junior':
              $badgeText = 'Done';
              $badgeClass = 'badge-done';
              break;

            case 'with_qa':
              $badgeText = 'With QA';
              $badgeClass = 'badge-qa';
              break;

            case 'with_senior_qa':
              $badgeText = 'With Senior QA';
              $badgeClass = 'badge-qa';
              break;

            case 'with_qa_lead':
              $badgeText = 'With QA Lead';
              $badgeClass = 'badge-qa';
              break;

            case 'approved':
              $badgeText = 'Approved';
              $badgeClass = 'badge-approved';
              break;

            case 'rejected':
              $badgeText = 'Rejected';
              $badgeClass = 'badge-rejected';
              break;

            case 'closed':
              $badgeText = 'Closed';
              $badgeClass = 'badge-closed';
              break;

            default:
              $badgeText = 'Unassigned';
              $badgeClass = 'badge-unassigned';
              break;
          }
        }
        ?>

        <div class="issue">
          <div class="issue-head">

            <!-- LEFT SIDE (title + meta + description + attachments) -->
            <div class="issue-left">

              <div class="issue-title">
                #<?= (int) $issueId ?>   <?= htmlspecialchars($row['title']) ?>
                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeText) ?></span>
              </div>

              <div class="issue-meta">
                opened by <?= htmlspecialchars($row['username']) ?>
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
            <?php
            $labRes = $conn->query("
        SELECT labels.name, labels.color
        FROM labels
        JOIN issue_labels ON labels.id = issue_labels.label_id
        WHERE issue_labels.issue_id = $issueId
      ");
            while ($lab = $labRes->fetch_assoc()) {
              $nm = htmlspecialchars($lab['name']);
              $cl = htmlspecialchars($lab['color']);
              echo "<span class='label' style='background:$cl'>$nm</span>";
            }
            ?>
          </div>

        </div>
      <?php endwhile; ?>

    </div>
  </main>

  <script src="<?= htmlspecialchars(bugcatcher_path('app/mobile_nav.js?v=1')) ?>"></script>
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
            item.innerText.toLowerCase().includes("no labels");

          const t = (item.getAttribute("data-text") || item.innerText).toLowerCase();
          item.style.display = (isSpecial || t.includes(q)) ? "flex" : "none";
        });
      });
    }

    <?php if (bugcatcher_is_system_admin_role($current_role)): ?>
      setupSearch("author");
    <?php endif; ?>
    setupSearch("labels");

    const viewMoreRankingBtn = document.getElementById("viewMoreRankingBtn");
    const allRankingWrap = document.getElementById("allRankingWrap");

    if (viewMoreRankingBtn && allRankingWrap) {
      viewMoreRankingBtn.addEventListener("click", () => {
        const isHidden = allRankingWrap.style.display === "none" || allRankingWrap.style.display === "";
        allRankingWrap.style.display = isHidden ? "block" : "none";
        viewMoreRankingBtn.textContent = isHidden ? "View Less" : "View More";
      });
    }

    const moreRankingSort = document.getElementById("moreRankingSort");
    const moreRankingSearch = document.getElementById("moreRankingSearch");
    const moreRankingList = document.getElementById("moreRankingList");

    function renumberMoreRankingRows(order = "desc") {
      if (!moreRankingList) return;

      const rows = Array.from(moreRankingList.querySelectorAll(".more-ranking-row"));
      const startRank = 11;
      const endRank = 10 + rows.length;

      rows.forEach((row, index) => {
        const num = row.querySelector(".more-ranking-number");
        if (!num) return;

        const rankNumber = (order === "asc") ? (endRank - index) : (startRank + index);
        row.dataset.rankNumber = rankNumber;
        num.textContent = `#${rankNumber}`;
      });
    }

    function sortMoreRanking(order) {
      if (!moreRankingList) return;

      const rows = Array.from(moreRankingList.querySelectorAll(".more-ranking-row"));
      rows.sort((a, b) => {
        const aTotal = parseInt(a.dataset.closedTotal || "0", 10);
        const bTotal = parseInt(b.dataset.closedTotal || "0", 10);
        const aName = (a.dataset.username || "").toLowerCase();
        const bName = (b.dataset.username || "").toLowerCase();

        if (order === "asc") {
          if (aTotal !== bTotal) return aTotal - bTotal;
          return aName.localeCompare(bName);
        }
        if (aTotal !== bTotal) return bTotal - aTotal;
        return aName.localeCompare(bName);
      });

      rows.forEach(row => moreRankingList.appendChild(row));
      renumberMoreRankingRows(order);
      filterMoreRankingRows();
    }

    function filterMoreRankingRows() {
      if (!moreRankingList) return;

      const q = (moreRankingSearch?.value || "").trim().toLowerCase();
      const rows = Array.from(moreRankingList.querySelectorAll(".more-ranking-row"));
      let hasVisible = false;

      rows.forEach(row => {
        const username = (row.dataset.username || "").toLowerCase();
        const role = (row.dataset.role || "").toLowerCase();
        const rank = String(row.dataset.rankNumber || "");

        const match = (q === "" || username.includes(q) || role.includes(q) || rank.includes(q));
        row.style.display = match ? "flex" : "none";
        if (match)
          hasVisible = true;
      });

      let empty = document.getElementById("moreRankingEmpty");
      if (!empty) {
        empty = document.createElement("div");
        empty.id = "moreRankingEmpty";
        empty.className = "leaderboard-empty";
        empty.textContent = "No matching ranked member found.";
        empty.style.display = "none";
        moreRankingList.insertAdjacentElement("afterend", empty);
      }

      empty.style.display = hasVisible ? "none" : "block";
    }

    if (moreRankingSort) {
      moreRankingSort.addEventListener("change", () => {
        sortMoreRanking(moreRankingSort.value);
      });
    }

    if (moreRankingSearch) {
      moreRankingSearch.addEventListener("input", () => {
        filterMoreRankingRows();
      });
    }

    renumberMoreRankingRows("desc");
    filterMoreRankingRows();
  </script>

</body>

</html>
